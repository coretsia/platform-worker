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
use Coretsia\Foundation\Container\Container;
use Coretsia\Foundation\Container\ContainerBuilder;
use Coretsia\Foundation\Container\Exception\ContainerException;
use Coretsia\Foundation\Container\ServiceProviderInterface;
use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Boot\BootstrapConfig;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverGuard;
use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Manager\Driver\PcntlWorkerManagerDriver;
use Coretsia\Platform\Worker\Manager\Driver\ProcWorkerManagerDriver;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use Psr\Log\LoggerInterface;

/**
 * Platform worker DI wiring entrypoint.
 *
 * This provider registers platform/worker runtime services without changing
 * provider ordering semantics. ContainerBuilder preserves the exact
 * caller-supplied provider order.
 *
 * Wiring decisions:
 *
 * - WorkerServiceFactory is registered as an explicit stateless instance;
 * - WorkerPoolSpec is registered as a factory-only binding;
 * - registering WorkerPoolSpec does not start workers and does not perform CLI
 *   command discovery;
 * - WorkerStateStore is registered as a factory-only binding;
 * - WorkerSocketServer is registered as a factory-only binding;
 * - QueueTaskFactory and HttpTaskFactory are registered as factory-only bindings;
 * - TaskFactoryInternalInterface is selected lazily from the already-built
 *   WorkerPoolSpec, so queue mode does not resolve HttpTaskFactory;
 * - ApplicationWorker is registered as a factory-only binding;
 * - registering ApplicationWorker does not start a UnitOfWork and does not run
 *   the long-running worker loop.
 *
 * Worker command services are registered as factory-only bindings and tagged
 * with the canonical reserved cli.command tag. The provider contributes only
 * owner-approved static command metadata. CLI discovery, metadata validation,
 * catalog construction, input parsing, and command dispatch remain owned by
 * the CLI runtime.
 *
 * WorkerStartCommand receives WorkerManager through a lazy factory. Resolving the
 * command service must not resolve WorkerManager, process drivers,
 * ApplicationWorker, TaskFactoryInternalInterface, or WorkerPoolSpec before the
 * command run path has checked the disabled-worker short-circuit and enforced
 * RuntimeDriverGuard ordering.
 *
 * Process drivers and WorkerManager are registered as factory-only bindings.
 * ProcWorkerManagerDriver receives its worker command argv vector only from the
 * cemented worker process command-vector source. This provider must not invent
 * a fallback argv vector.
 *
 * This provider must not:
 *
 * - inspect runtime-driver compatibility;
 * - call RuntimeDriverGuard;
 * - resolve ModulePlan during provider registration;
 * - run WorkerPoolSpec normalization during provider registration;
 * - start worker processes;
 * - fork;
 * - call proc_open();
 * - open sockets;
 * - write pid/state/socket/stop files;
 * - invoke KernelRuntimeInterface;
 * - enumerate kernel hooks or reset tags;
 * - call ResetOrchestrator;
 * - depend on platform/cli;
 * - instantiate noop logger/tracer/meter implementations;
 * - emit stdout/stderr.
 */
final class WorkerServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $factory = new WorkerServiceFactory();

        $builder->instance(WorkerServiceFactory::class, $factory);

        $builder->factory(
            WorkerPoolSpec::class,
            static fn (Container $container): WorkerPoolSpec => $factory->workerPoolSpec(
                config: self::service($container, ConfigRepositoryInterface::class),
            ),
        );

        $builder->factory(
            WorkerStateStore::class,
            static fn (Container $container): WorkerStateStore => $factory->workerStateStore(
                encoder: self::service($container, StableJsonEncoder::class),
                decoder: self::service($container, StableJsonDecoder::class),
            ),
        );

        $builder->factory(
            WorkerSocketServer::class,
            static fn (Container $_container): WorkerSocketServer => $factory->workerSocketServer(),
        );

        $builder->factory(
            QueueTaskFactory::class,
            static fn (Container $_container): QueueTaskFactory => $factory->queueTaskFactory(),
        );

        $builder->factory(
            HttpTaskFactory::class,
            static fn (Container $container): HttpTaskFactory => $factory->httpTaskFactory(
                config: self::service($container, ConfigRepositoryInterface::class),
                modulePlan: self::service($container, ModulePlan::class),
                runtimeDriverGuard: self::service($container, RuntimeDriverGuard::class),
                container: $container,
            ),
        );

        $builder->factory(
            TaskFactoryInternalInterface::class,
            static fn (Container $container): TaskFactoryInternalInterface => $factory->taskFactory(
                spec: self::service($container, WorkerPoolSpec::class),
                queueTaskFactory: static fn (): QueueTaskFactory => self::service($container, QueueTaskFactory::class),
                httpTaskFactory: static fn (): HttpTaskFactory => self::service($container, HttpTaskFactory::class),
            ),
        );

        $builder->factory(
            ApplicationWorker::class,
            static fn (Container $container): ApplicationWorker => $factory->applicationWorker(
                skeletonRoot: self::bootstrapConfig($container)->skeletonRoot(),
                kernelRuntime: self::service($container, KernelRuntimeInterface::class),
                taskFactory: self::service($container, TaskFactoryInternalInterface::class),
                context: self::service($container, ContextAccessorInterface::class),
                stopwatch: self::service($container, Stopwatch::class),
                tracer: self::service($container, TracerPortInterface::class),
                meter: self::service($container, MeterPortInterface::class),
            ),
        );

        $builder->factory(
            PcntlWorkerManagerDriver::class,
            static fn (Container $container): PcntlWorkerManagerDriver => $factory->pcntlWorkerManagerDriver(
                skeletonRoot: self::bootstrapConfig($container)->skeletonRoot(),
                stateStore: self::service($container, WorkerStateStore::class),
                controlChannel: self::service($container, WorkerSocketServer::class),
                applicationWorker: self::service($container, ApplicationWorker::class),
            ),
        );

        $builder->factory(
            ProcWorkerManagerDriver::class,
            static fn (Container $container): ProcWorkerManagerDriver => $factory->procWorkerManagerDriver(
                skeletonRoot: self::bootstrapConfig($container)->skeletonRoot(),
                stateStore: self::service($container, WorkerStateStore::class),
                controlChannel: self::service($container, WorkerSocketServer::class),
                workerCommand: $factory->procWorkerCommand(
                    config: self::service($container, ConfigRepositoryInterface::class),
                ),
                configArtifactPath: self::configArtifactPath(
                    bootstrapConfig: self::bootstrapConfig($container),
                ),
                containerArtifactPath: self::containerArtifactPath(
                    bootstrapConfig: self::bootstrapConfig($container),
                ),
            ),
        );

        $builder->factory(
            WorkerManager::class,
            static fn (Container $container): WorkerManager => $factory->workerManager(
                pcntlDriver: self::service($container, PcntlWorkerManagerDriver::class),
                procDriver: self::service($container, ProcWorkerManagerDriver::class),
                tracer: self::service($container, TracerPortInterface::class),
                meter: self::service($container, MeterPortInterface::class),
                logger: self::service($container, LoggerInterface::class),
                stopwatch: self::service($container, Stopwatch::class),
            ),
        );

        $builder->factory(
            WorkerStartCommand::class,
            static fn (Container $container): WorkerStartCommand => new WorkerStartCommand(
                config: self::service($container, ConfigRepositoryInterface::class),
                modulePlan: self::service($container, ModulePlan::class),
                runtimeDriverGuard: self::service($container, RuntimeDriverGuard::class),
                factory: $factory,
                managerFactory: static fn (): WorkerManager => self::service($container, WorkerManager::class),
            ),
        );

        $builder->factory(
            WorkerStopCommand::class,
            static fn (Container $container): WorkerStopCommand => new WorkerStopCommand(
                config: self::service($container, ConfigRepositoryInterface::class),
                factory: $factory,
                manager: self::service($container, WorkerManager::class),
            ),
        );

        $builder->factory(
            WorkerStatusCommand::class,
            static fn (Container $container): WorkerStatusCommand => new WorkerStatusCommand(
                config: self::service($container, ConfigRepositoryInterface::class),
                factory: $factory,
                manager: self::service($container, WorkerManager::class),
            ),
        );

        $builder->tag(
            ReservedTags::CLI_COMMAND,
            WorkerStartCommand::class,
            meta: self::commandMeta(
                name: WorkerStartCommand::NAME,
                summary: WorkerStartCommand::SUMMARY,
                group: WorkerStartCommand::GROUP,
                hidden: WorkerStartCommand::HIDDEN,
                mode: WorkerStartCommand::MODE,
                arguments: WorkerStartCommand::ARGUMENTS,
                options: WorkerStartCommand::OPTIONS,
            ),
        );

        $builder->tag(
            ReservedTags::CLI_COMMAND,
            WorkerStopCommand::class,
            meta: self::commandMeta(
                name: WorkerStopCommand::NAME,
                summary: WorkerStopCommand::SUMMARY,
                group: WorkerStopCommand::GROUP,
                hidden: WorkerStopCommand::HIDDEN,
                mode: WorkerStopCommand::MODE,
                arguments: WorkerStopCommand::ARGUMENTS,
                options: WorkerStopCommand::OPTIONS,
            ),
        );

        $builder->tag(
            ReservedTags::CLI_COMMAND,
            WorkerStatusCommand::class,
            meta: self::commandMeta(
                name: WorkerStatusCommand::NAME,
                summary: WorkerStatusCommand::SUMMARY,
                group: WorkerStatusCommand::GROUP,
                hidden: WorkerStatusCommand::HIDDEN,
                mode: WorkerStatusCommand::MODE,
                arguments: WorkerStatusCommand::ARGUMENTS,
                options: WorkerStatusCommand::OPTIONS,
            ),
        );
    }

    private static function bootstrapConfig(Container $container): BootstrapConfig
    {
        return self::service($container, BootstrapConfig::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(Container $container, string $id): object
    {
        $service = $container->get($id);

        if (!$service instanceof $id) {
            throw new ContainerException('worker-provider-dependency-invalid');
        }

        /** @var T $service */
        return $service;
    }

    /**
     * @param list<array<string, mixed>> $arguments
     * @param list<array<string, mixed>> $options
     *
     * @return array{
     *     name: string,
     *     summary: string,
     *     group: string,
     *     hidden: bool,
     *     mode: string,
     *     arguments: list<array<string, mixed>>,
     *     options: list<array<string, mixed>>
     * }
     */
    private static function commandMeta(
        string $name,
        string $summary,
        string $group,
        bool $hidden,
        string $mode,
        array $arguments,
        array $options,
    ): array {
        return [
            'name' => $name,
            'summary' => $summary,
            'group' => $group,
            'hidden' => $hidden,
            'mode' => $mode,
            'arguments' => $arguments,
            'options' => $options,
        ];
    }

    private static function configArtifactPath(BootstrapConfig $bootstrapConfig): string
    {
        $appTarget = self::safeAppTarget($bootstrapConfig);

        return 'var/cache/' . $appTarget . '/config.php';
    }

    private static function containerArtifactPath(BootstrapConfig $bootstrapConfig): string
    {
        $appTarget = self::safeAppTarget($bootstrapConfig);

        return 'var/cache/' . $appTarget . '/container.php';
    }

    private static function safeAppTarget(BootstrapConfig $bootstrapConfig): string
    {
        $appTarget = $bootstrapConfig->appTarget()->value;

        if ($appTarget === '') {
            throw new ContainerException('worker-provider-app-target-invalid');
        }

        if (\preg_match('/\A[a-z][a-z0-9_-]*\z/', $appTarget) !== 1) {
            throw new ContainerException('worker-provider-app-target-invalid');
        }

        return $appTarget;
    }
}
