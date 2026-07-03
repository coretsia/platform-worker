<?php

declare(strict_types=1);

/*
 * Coretsia Framework (Monorepo)
 *
 * Project: Coretsia Framework (Monorepo)
 * Authors: Vladyslav Mudrichenko and contributors
 * Copyright (c) 2026 Vladyslav Mudrichenko
 *
 * SPDX-FileCopyrightText: 2026 Vladyslav Mudrichenko
 * SPDX-License-Identifier: Apache-2.0
 *
 * For contributors list, see git history.
 * See LICENSE and NOTICE in the project root for full license information.
 */

namespace Coretsia\Platform\Worker\Provider;

use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Contracts\Context\ContextAccessorInterface;
use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Entrypoint\RuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Manager\Driver\PcntlWorkerManagerDriver;
use Coretsia\Platform\Worker\Manager\Driver\ProcWorkerManagerDriver;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless worker service factory.
 *
 * This class is a wiring helper only. It builds platform/worker services from
 * already-available DI dependencies and merged configuration after the
 * config validation pipeline.
 *
 * It intentionally keeps no mutable runtime state:
 *
 * - no retained container;
 * - no retained config repository;
 * - no cached WorkerPoolSpec;
 * - no service caches;
 * - no runtime buffers.
 *
 * It must not perform CLI command discovery, must not depend on platform/cli,
 * must not read environment variables for config defaults, must not instantiate
 * noop logger/tracer/meter implementations, and must not write stdout/stderr.
 *
 * Defaults are owned by config/worker.php and merged config. This factory reads
 * the validated worker config root and passes it to WorkerPoolSpec. Proc child
 * command-vector config is read separately by procWorkerCommand() because it
 * performs package-owned `@php` token normalization.
 *
 * Missing or invalid worker config root data fails deterministically instead of
 * being silently invented here.
 */
final class WorkerServiceFactory
{
    /**
     * Builds WorkerPoolSpec from the complete merged worker config after the
     * config validation pipeline.
     *
     * Capability arguments are nullable for production wiring. Tests may pass
     * explicit values to avoid depending on host pcntl or unix socket support.
     */
    public function workerPoolSpec(
        ConfigRepositoryInterface $config,
        ?bool $pcntlForkAvailable = null,
        ?string $platformFamily = null,
        ?bool $unixDomainSocketsSupported = null,
    ): WorkerPoolSpec {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfigRoot($config),
            pcntlForkAvailable: $pcntlForkAvailable,
            platformFamily: $platformFamily,
            unixDomainSocketsSupported: $unixDomainSocketsSupported,
        );
    }

    public function workerStateStore(
        StableJsonEncoder $encoder,
        StableJsonDecoder $decoder,
    ): WorkerStateStore {
        return new WorkerStateStore(
            encoder: $encoder,
            decoder: $decoder,
        );
    }

    public function workerSocketServer(): WorkerSocketServer
    {
        return new WorkerSocketServer();
    }

    public function queueTaskFactory(): QueueTaskFactory
    {
        return new QueueTaskFactory();
    }

    public function httpTaskFactory(
        ConfigRepositoryInterface $config,
        ModulePlan $modulePlan,
        RuntimeEntrypointGuard $runtimeEntrypointGuard,
        ContainerInterface $container,
    ): HttpTaskFactory {
        return new HttpTaskFactory(
            config: $config,
            modulePlan: $modulePlan,
            runtimeEntrypointGuard: $runtimeEntrypointGuard,
            container: $container,
        );
    }

    /**
     * @param \Closure(): QueueTaskFactory $queueTaskFactory
     * @param \Closure(): HttpTaskFactory $httpTaskFactory
     */
    public function taskFactory(
        WorkerPoolSpec $spec,
        \Closure $queueTaskFactory,
        \Closure $httpTaskFactory,
    ): TaskFactoryInternalInterface {
        return match ($spec->taskType()) {
            TaskFactoryInternalInterface::TASK_TYPE_QUEUE => $this->supportedTaskFactory(
                spec: $spec,
                taskFactory: $queueTaskFactory(),
            ),

            TaskFactoryInternalInterface::TASK_TYPE_HTTP => $this->supportedTaskFactory(
                spec: $spec,
                taskFactory: $httpTaskFactory(),
            ),

            default => throw WorkerStartFailedException::startFailed(),
        };
    }

    private function supportedTaskFactory(
        WorkerPoolSpec $spec,
        TaskFactoryInternalInterface $taskFactory,
    ): TaskFactoryInternalInterface {
        if (!$taskFactory->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        return $taskFactory;
    }

    public function applicationWorker(
        string $skeletonRoot,
        KernelRuntimeInterface $kernelRuntime,
        TaskFactoryInternalInterface $taskFactory,
        ContextAccessorInterface $context,
        Stopwatch $stopwatch,
        TracerPortInterface $tracer,
        MeterPortInterface $meter,
    ): ApplicationWorker {
        return new ApplicationWorker(
            skeletonRoot: $skeletonRoot,
            kernelRuntime: $kernelRuntime,
            taskFactory: $taskFactory,
            context: $context,
            stopwatch: $stopwatch,
            tracer: $tracer,
            meter: $meter,
        );
    }

    public function pcntlWorkerManagerDriver(
        string $skeletonRoot,
        WorkerStateStore $stateStore,
        WorkerSocketServer $controlChannel,
        ApplicationWorker $applicationWorker,
        ?bool $pcntlForkAvailable = null,
        ?string $platformFamily = null,
    ): PcntlWorkerManagerDriver {
        return new PcntlWorkerManagerDriver(
            skeletonRoot: $skeletonRoot,
            stateStore: $stateStore,
            controlChannel: $controlChannel,
            childRunner: static function (WorkerPoolSpec $spec, int $_workerIndex) use ($applicationWorker): int {
                $applicationWorker->run($spec);

                return 0;
            },
            pcntlForkAvailable: $pcntlForkAvailable,
            platformFamily: $platformFamily,
        );
    }

    /**
     * Reads and normalizes the configured base argv vector for proc-based worker
     * children.
     *
     * The `@php` token is package-owned shorthand for the current PHP binary.
     * It keeps package defaults portable without storing an absolute PHP binary
     * path in config defaults.
     *
     * @return list<non-empty-string>
     */
    public function procWorkerCommand(ConfigRepositoryInterface $config): array
    {
        $command = self::requiredConfigValue($config, 'worker.proc.command');

        if (!\is_array($command) || !\array_is_list($command) || $command === []) {
            throw WorkerStartFailedException::invalidState();
        }

        $normalized = [];

        foreach ($command as $part) {
            if (!\is_string($part) || $part === '') {
                throw WorkerStartFailedException::invalidState();
            }

            if (\trim($part) !== $part) {
                throw WorkerStartFailedException::invalidState();
            }

            if (\preg_match('/[\x00-\x1F\x7F]/', $part) === 1) {
                throw WorkerStartFailedException::invalidState();
            }

            $normalized[] = $part === '@php'
                ? self::phpBinary()
                : $part;
        }

        /** @var list<non-empty-string> $normalized */
        return $normalized;
    }

    /**
     * @param list<non-empty-string> $workerCommand
     */
    public function procWorkerManagerDriver(
        string $skeletonRoot,
        WorkerStateStore $stateStore,
        WorkerSocketServer $controlChannel,
        array $workerCommand,
        string $configArtifactPath,
        string $containerArtifactPath,
    ): ProcWorkerManagerDriver {
        return new ProcWorkerManagerDriver(
            skeletonRoot: $skeletonRoot,
            stateStore: $stateStore,
            controlChannel: $controlChannel,
            workerCommand: $workerCommand,
            configArtifactPath: $configArtifactPath,
            containerArtifactPath: $containerArtifactPath,
        );
    }

    public function workerManager(
        PcntlWorkerManagerDriver $pcntlDriver,
        ProcWorkerManagerDriver $procDriver,
        TracerPortInterface $tracer,
        MeterPortInterface $meter,
        LoggerInterface $logger,
        Stopwatch $stopwatch,
    ): WorkerManager {
        return new WorkerManager(
            drivers: [
                $pcntlDriver,
                $procDriver,
            ],
            tracer: $tracer,
            meter: $meter,
            logger: $logger,
            stopwatch: $stopwatch,
        );
    }

    /**
     * Reads the validated `worker` config root from the active config repository.
     *
     * The repository may be backed by generated config artifacts. This factory does
     * not read package config files and does not invent worker defaults.
     *
     * @return array<string, mixed>
     */
    private static function workerConfigRoot(ConfigRepositoryInterface $config): array
    {
        $workerConfig = self::requiredConfigValue($config, 'worker');

        if (!\is_array($workerConfig) || \array_is_list($workerConfig)) {
            throw WorkerStartFailedException::invalidState();
        }

        /** @var array<string, mixed> $workerConfig */
        return $workerConfig;
    }

    private static function phpBinary(): string
    {
        $binary = \PHP_BINARY;

        if (\trim($binary) !== $binary) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $binary) === 1) {
            throw WorkerStartFailedException::invalidState();
        }

        return $binary;
    }

    private static function requiredConfigValue(
        ConfigRepositoryInterface $config,
        string $keyPath,
    ): mixed {
        try {
            if (!$config->has($keyPath)) {
                throw WorkerStartFailedException::invalidState();
            }

            return $config->get($keyPath);
        } catch (WorkerStartFailedException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw WorkerStartFailedException::invalidState();
        }
    }
}
