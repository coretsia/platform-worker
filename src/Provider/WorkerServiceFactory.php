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
use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Contracts\Worker\WorkerTaskSourceInterface;
use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Foundation\Tag\TagRegistry;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverResolver;
use Coretsia\Kernel\Runtime\RuntimePathContext;
use Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel;
use Coretsia\Platform\Worker\Communication\WorkerControlClient;
use Coretsia\Platform\Worker\Communication\WorkerControlProtocol;
use Coretsia\Platform\Worker\Communication\WorkerControlServer;
use Coretsia\Platform\Worker\Communication\WorkerControlTransport;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverResolverInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessGuardianInterface;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\ContainerWorkerProcessDriverResolver;
use Coretsia\Platform\Worker\Process\Driver\PcntlWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\Driver\ProcWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianClient;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol;
use Coretsia\Platform\Worker\Process\WorkerChildCommandBuilder;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocatorStore;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLock;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Runtime\WorkerStopSignal;
use Coretsia\Platform\Worker\Supervisor\WorkerChildTable;
use Coretsia\Platform\Worker\Supervisor\WorkerSignalController;
use Coretsia\Platform\Worker\Supervisor\WorkerSupervisor;
use Coretsia\Platform\Worker\Task\WorkerTaskSourceResolver;
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
     * explicit values to avoid depending on host guardian-backed PCNTL, secure proc-host
     * transport, or Unix socket support.
     */
    public function workerPoolSpec(
        ConfigRepositoryInterface $config,
        ?bool $pcntlDriverAvailable = null,
        ?string $platformFamily = null,
        ?bool $unixDomainSocketsSupported = null,
        ?bool $procDriverAvailable = null,
    ): WorkerPoolSpec {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfigRoot($config),
            pcntlDriverAvailable: $pcntlDriverAvailable,
            platformFamily: $platformFamily,
            unixDomainSocketsSupported: $unixDomainSocketsSupported,
            procDriverAvailable: $procDriverAvailable,
        );
    }

    public function workerRuntimeEntrypointGuard(
        RuntimeDriverResolver $resolver,
    ): WorkerRuntimeEntrypointGuard {
        return new WorkerRuntimeEntrypointGuard($resolver);
    }

    public function workerStateStore(
        RuntimePathContext $runtimePaths,
    ): WorkerStateStore {
        return new WorkerStateStore($runtimePaths->skeletonRoot());
    }

    public function workerLifecycleLocatorStore(
        RuntimePathContext $runtimePaths,
    ): WorkerLifecycleLocatorStore {
        return new WorkerLifecycleLocatorStore(
            skeletonRoot: $runtimePaths->skeletonRoot(),
        );
    }

    public function workerLifecycleLock(RuntimePathContext $runtimePaths): WorkerLifecycleLock
    {
        return new WorkerLifecycleLock($runtimePaths->skeletonRoot());
    }

    public function workerStopSignal(RuntimePathContext $runtimePaths): WorkerStopSignal
    {
        return new WorkerStopSignal($runtimePaths->skeletonRoot());
    }

    public function workerControlTransport(RuntimePathContext $runtimePaths): WorkerControlTransport
    {
        return new WorkerControlTransport($runtimePaths->skeletonRoot());
    }

    public function workerControlProtocol(): WorkerControlProtocol
    {
        return new WorkerControlProtocol();
    }

    public function workerControlServer(
        WorkerControlTransport $transport,
        WorkerControlProtocol $protocol,
    ): WorkerControlServer {
        return new WorkerControlServer($transport, $protocol);
    }

    public function workerControlClient(
        WorkerControlTransport $transport,
        WorkerControlProtocol $protocol,
        WorkerLifecycleLock $lifecycleLock,
        WorkerLifecycleLocatorStore $locatorStore,
        TracerPortInterface $tracer,
        MeterPortInterface $meter,
        LoggerInterface $logger,
        Stopwatch $stopwatch,
    ): WorkerControlClient {
        return new WorkerControlClient(
            transport: $transport,
            protocol: $protocol,
            lifecycleLock: $lifecycleLock,
            locatorStore: $locatorStore,
            tracer: $tracer,
            meter: $meter,
            logger: $logger,
            stopwatch: $stopwatch,
        );
    }

    public function workerChildReadinessChannel(): WorkerChildReadinessChannel
    {
        return new WorkerChildReadinessChannel();
    }

    public function workerChildTable(): WorkerChildTable
    {
        return new WorkerChildTable();
    }

    public function workerSignalController(): WorkerSignalController
    {
        return new WorkerSignalController();
    }

    public function workerTaskSourceResolver(
        ContainerInterface $container,
        TagRegistry $tags,
    ): WorkerTaskSourceResolver {
        return new WorkerTaskSourceResolver(
            container: $container,
            tags: $tags,
        );
    }

    public function workerTaskSource(
        WorkerPoolSpec $spec,
        WorkerTaskSourceResolver $resolver,
    ): WorkerTaskSourceInterface {
        return $resolver->resolve(
            WorkerTaskType::from($spec->taskType()),
        );
    }

    public function applicationWorker(
        WorkerStopSignal $stopSignal,
        KernelRuntimeInterface $kernelRuntime,
        WorkerTaskSourceInterface $taskSource,
        Stopwatch $stopwatch,
        TracerPortInterface $tracer,
        MeterPortInterface $meter,
    ): ApplicationWorker {
        return new ApplicationWorker(
            stopSignal: $stopSignal,
            kernelRuntime: $kernelRuntime,
            taskSource: $taskSource,
            stopwatch: $stopwatch,
            tracer: $tracer,
            meter: $meter,
        );
    }

    /**
     * Builds the canonical child launcher argv builder from runtime path input.
     */
    public function workerChildCommandBuilder(
        RuntimePathContext $runtimePaths,
    ): WorkerChildCommandBuilder {
        return new WorkerChildCommandBuilder(
            self::relativeArtifactRoot($runtimePaths),
        );
    }

    /**
     * Builds the PCNTL fork-and-exec adapter without capturing the container.
     */
    public function pcntlWorkerProcessDriver(
        RuntimePathContext $runtimePaths,
        WorkerChildCommandBuilder $commandBuilder,
        WorkerChildReadinessChannel $readinessChannel,
        WorkerProcessGuardianInterface $guardian,
        ?bool $driverAvailable = null,
        ?string $platformFamily = null,
    ): PcntlWorkerProcessDriver {
        $platformFamily ??= \PHP_OS_FAMILY;

        return new PcntlWorkerProcessDriver(
            skeletonRoot: $runtimePaths->skeletonRoot(),
            workerCommand: [
                self::phpBinary(),
                \dirname(__DIR__, 2) . '/bin/coretsia-worker',
            ],
            commandBuilder: $commandBuilder,
            readinessChannel: $readinessChannel,
            guardian: $guardian,
            driverAvailable: $driverAvailable ?? WorkerProcessCapabilities::pcntlDriverAvailable($platformFamily),
            platformFamily: $platformFamily,
        );
    }

    public function procWorkerProcessDriver(
        RuntimePathContext $runtimePaths,
        ConfigRepositoryInterface $config,
        WorkerChildCommandBuilder $commandBuilder,
        WorkerChildReadinessChannel $readinessChannel,
        WorkerProcessGuardianInterface $guardian,
        ?bool $driverAvailable = null,
        ?string $platformFamily = null,
    ): ProcWorkerProcessDriver {
        return new ProcWorkerProcessDriver(
            skeletonRoot: $runtimePaths->skeletonRoot(),
            workerCommand: $this->procWorkerCommand($config),
            commandBuilder: $commandBuilder,
            readinessChannel: $readinessChannel,
            guardian: $guardian,
            driverAvailable: $driverAvailable ?? WorkerProcessCapabilities::procDriverAvailable($platformFamily),
        );
    }

    /**
     * Builds the package-internal lazy process-driver resolver.
     */
    public function workerProcessDriverResolver(
        ContainerInterface $container,
    ): ContainerWorkerProcessDriverResolver {
        return new ContainerWorkerProcessDriverResolver($container);
    }

    public function workerProcessGuardianProtocol(): WorkerProcessGuardianProtocol
    {
        return new WorkerProcessGuardianProtocol();
    }

    public function workerProcessGuardianClient(
        RuntimePathContext $runtimePaths,
        WorkerProcessGuardianProtocol $protocol,
    ): WorkerProcessGuardianClient {
        $bootstrapProtocol = new WorkerProcessBootstrapProtocol();

        return new WorkerProcessGuardianClient(
            command: [
                self::phpBinary(),
                \dirname(__DIR__, 2) . '/bin/coretsia-worker-guardian',
            ],
            bootstrapWorkingDirectory: $runtimePaths->skeletonRoot(),
            skeletonRoot: $runtimePaths->skeletonRoot(),
            protocol: $protocol,
            bootstrapLauncher: new WorkerProcessBootstrapLauncher($bootstrapProtocol),
        );
    }

    public function workerSupervisor(
        WorkerProcessDriverResolverInterface $driverResolver,
        WorkerProcessGuardianInterface $guardian,
        WorkerLifecycleLocatorStore $locatorStore,
        WorkerControlServer $controlServer,
        WorkerChildReadinessChannel $readinessChannel,
        WorkerChildTable $childTable,
        WorkerSignalController $signals,
        WorkerStateStore $stateStore,
        WorkerStopSignal $stopSignal,
        TracerPortInterface $tracer,
        MeterPortInterface $meter,
        LoggerInterface $logger,
        Stopwatch $stopwatch,
    ): WorkerSupervisor {
        return new WorkerSupervisor(
            driverResolver: $driverResolver,
            guardian: $guardian,
            locatorStore: $locatorStore,
            controlServer: $controlServer,
            readinessChannel: $readinessChannel,
            children: $childTable,
            signals: $signals,
            stateStore: $stateStore,
            stopSignal: $stopSignal,
            tracer: $tracer,
            meter: $meter,
            logger: $logger,
            stopwatch: $stopwatch,
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
            throw WorkerLifecycleFailedException::invalidState();
        }
        $normalized = [];
        foreach ($command as $part) {
            if (!\is_string($part) || $part === '' || \trim($part) !== $part || \preg_match(
                '/[\x00-\x1F\x7F]/',
                $part
            ) === 1) {
                throw WorkerLifecycleFailedException::invalidState();
            }
            $normalized[] = $part === '@php' ? self::phpBinary() : $part;
        }
        return $normalized;
    }

    /** @return array<string, mixed> */
    private static function workerConfigRoot(ConfigRepositoryInterface $config): array
    {
        $value = self::requiredConfigValue($config, 'worker');
        if (!\is_array($value) || \array_is_list($value)) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        return $value;
    }

    private static function relativeArtifactRoot(RuntimePathContext $runtimePaths): string
    {
        $artifactRoot = $runtimePaths->artifactRoot();
        $skeletonRoot = $runtimePaths->skeletonRoot();
        $prefix = \rtrim($skeletonRoot, '/') . '/';
        if (\str_starts_with($artifactRoot, $prefix)) {
            return \substr($artifactRoot, \strlen($prefix));
        }
        if (\str_starts_with($artifactRoot, '/') || \preg_match(
            '/\A[A-Za-z]:\//',
            $artifactRoot
        ) === 1 || $artifactRoot === '') {
            throw WorkerLifecycleFailedException::invalidState();
        }
        return $artifactRoot;
    }

    private static function phpBinary(): string
    {
        if (\trim(\PHP_BINARY) !== \PHP_BINARY || \preg_match(
            '/[\x00-\x1F\x7F]/',
            \PHP_BINARY
        ) === 1) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        return \PHP_BINARY;
    }

    private static function requiredConfigValue(ConfigRepositoryInterface $config, string $key): mixed
    {
        try {
            if (!$config->has($key)) {
                throw WorkerLifecycleFailedException::invalidState();
            }
            return $config->get($key);
        } catch (WorkerLifecycleFailedException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::invalidState();
        }
    }
}
