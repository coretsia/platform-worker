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
use Coretsia\Foundation\Container\ContainerBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionContext;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionProviderInterface;
use Coretsia\Foundation\Container\Definition\ContainerValueReference;
use Coretsia\Foundation\Container\ServiceProviderInterface;
use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Entrypoint\RuntimeEntrypointGuard;
use Coretsia\Kernel\Runtime\RuntimePathContext;
use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Internal\WorkerManagerResolverInterface;
use Coretsia\Platform\Worker\Manager\ContainerWorkerManagerResolver;
use Coretsia\Platform\Worker\Manager\Driver\PcntlWorkerManagerDriver;
use Coretsia\Platform\Worker\Manager\Driver\ProcWorkerManagerDriver;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Platform worker DI wiring entrypoint.
 *
 * Runtime wiring is produced through one canonical declarative contribution.
 * Source registration delegates that contribution to ContainerBuilder, while
 * compile-mode orchestration may invoke define() directly.
 *
 * Wiring decisions:
 *
 * - WorkerServiceFactory is a shared stateless service;
 * - WorkerPoolSpec remains lazy and reads only the active config repository;
 * - TaskFactoryInternalInterface resolves only the task-factory service selected
 *   by the canonical WorkerPoolSpec task type;
 * - ApplicationWorker and process drivers consume RuntimePathContext instead of
 *   BootstrapConfig or raw provider-owned path closures;
 * - WorkerStartCommand resolves WorkerManager lazily through
 *   WorkerManagerResolverInterface;
 * - worker command metadata remains static and owner-approved;
 * - command tags preserve canonical TagRegistry ordering and first-wins policy.
 *
 * Definition production must not resolve services, execute worker lifecycle,
 * inspect environment or filesystem state, start processes, open sockets, write
 * runtime files, invoke KernelRuntimeInterface, or emit stdout/stderr.
 */
final class WorkerServiceProvider implements
    ServiceProviderInterface,
    ContainerDefinitionProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->assertDefinitionProviderRegistrationAllowed();
        $builder->registerDefinitionProvider($this);
    }

    public function define(
        ContainerDefinitionBuilder $definitions,
        ContainerDefinitionContext $context,
    ): void {
        $definitions
            ->requireService(ConfigRepositoryInterface::class)
            ->requireService(ModulePlan::class)
            ->requireService(RuntimePathContext::class)
            ->requireService(WorkerPoolSpec::class)
            ->requireService(WorkerRuntimeEntrypointGuard::class)
            ->requireService(ApplicationWorker::class)
            ->requireService(WorkerManager::class)
            ->requireService(QueueTaskFactory::class)
            ->requireService(HttpTaskFactory::class)
            ->classService(
                WorkerServiceFactory::class,
                WorkerServiceFactory::class,
            )
            ->serviceMethodFactory(
                id: WorkerPoolSpec::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'workerPoolSpec',
                arguments: [
                    ContainerValueReference::service(ConfigRepositoryInterface::class),
                ],
            )
            ->serviceMethodFactory(
                id: WorkerRuntimeEntrypointGuard::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'workerRuntimeEntrypointGuard',
                arguments: [
                    ContainerValueReference::service(RuntimeEntrypointGuard::class),
                ],
            )
            ->classService(
                StableJsonEncoder::class,
                StableJsonEncoder::class,
            )
            ->classService(
                StableJsonDecoder::class,
                StableJsonDecoder::class,
            )
            ->serviceMethodFactory(
                id: WorkerStateStore::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'workerStateStore',
                arguments: [
                    ContainerValueReference::service(StableJsonEncoder::class),
                    ContainerValueReference::service(StableJsonDecoder::class),
                ],
            )
            ->serviceMethodFactory(
                id: WorkerSocketServer::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'workerSocketServer',
            )
            ->serviceMethodFactory(
                id: QueueTaskFactory::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'queueTaskFactory',
            )
            ->serviceMethodFactory(
                id: HttpTaskFactory::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'httpTaskFactory',
                arguments: [
                    ContainerValueReference::service(ConfigRepositoryInterface::class),
                    ContainerValueReference::service(ModulePlan::class),
                    ContainerValueReference::service(WorkerRuntimeEntrypointGuard::class),
                    ContainerValueReference::service(ContainerInterface::class),
                ],
            )
            ->serviceMethodFactory(
                id: TaskFactoryInternalInterface::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'taskFactory',
                arguments: [
                    ContainerValueReference::service(WorkerPoolSpec::class),
                    ContainerValueReference::service(ContainerInterface::class),
                ],
            )
            ->serviceMethodFactory(
                id: ApplicationWorker::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'applicationWorker',
                arguments: [
                    ContainerValueReference::service(RuntimePathContext::class),
                    ContainerValueReference::service(KernelRuntimeInterface::class),
                    ContainerValueReference::service(TaskFactoryInternalInterface::class),
                    ContainerValueReference::service(ContextAccessorInterface::class),
                    ContainerValueReference::service(Stopwatch::class),
                    ContainerValueReference::service(TracerPortInterface::class),
                    ContainerValueReference::service(MeterPortInterface::class),
                ],
            )
            ->serviceMethodFactory(
                id: PcntlWorkerManagerDriver::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'pcntlWorkerManagerDriver',
                arguments: [
                    ContainerValueReference::service(RuntimePathContext::class),
                    ContainerValueReference::service(WorkerStateStore::class),
                    ContainerValueReference::service(WorkerSocketServer::class),
                    ContainerValueReference::service(ApplicationWorker::class),
                ],
            )
            ->serviceMethodFactory(
                id: ProcWorkerManagerDriver::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'procWorkerManagerDriver',
                arguments: [
                    ContainerValueReference::service(RuntimePathContext::class),
                    ContainerValueReference::service(WorkerStateStore::class),
                    ContainerValueReference::service(WorkerSocketServer::class),
                    ContainerValueReference::service(ConfigRepositoryInterface::class),
                ],
            )
            ->serviceMethodFactory(
                id: WorkerManager::class,
                factoryServiceId: WorkerServiceFactory::class,
                method: 'workerManager',
                arguments: [
                    ContainerValueReference::service(PcntlWorkerManagerDriver::class),
                    ContainerValueReference::service(ProcWorkerManagerDriver::class),
                    ContainerValueReference::service(TracerPortInterface::class),
                    ContainerValueReference::service(MeterPortInterface::class),
                    ContainerValueReference::service(LoggerInterface::class),
                    ContainerValueReference::service(Stopwatch::class),
                ],
            )
            ->classService(
                id: ContainerWorkerManagerResolver::class,
                class: ContainerWorkerManagerResolver::class,
                arguments: [
                    ContainerValueReference::service(ContainerInterface::class),
                ],
            )
            ->alias(
                WorkerManagerResolverInterface::class,
                ContainerWorkerManagerResolver::class,
            )
            ->classService(
                id: WorkerStartCommand::class,
                class: WorkerStartCommand::class,
                arguments: [
                    ContainerValueReference::service(ConfigRepositoryInterface::class),
                    ContainerValueReference::service(ModulePlan::class),
                    ContainerValueReference::service(WorkerRuntimeEntrypointGuard::class),
                    ContainerValueReference::service(WorkerServiceFactory::class),
                    ContainerValueReference::service(WorkerManagerResolverInterface::class),
                ],
            )
            ->classService(
                id: WorkerStopCommand::class,
                class: WorkerStopCommand::class,
                arguments: [
                    ContainerValueReference::service(ConfigRepositoryInterface::class),
                    ContainerValueReference::service(WorkerServiceFactory::class),
                    ContainerValueReference::service(WorkerManager::class),
                ],
            )
            ->classService(
                id: WorkerStatusCommand::class,
                class: WorkerStatusCommand::class,
                arguments: [
                    ContainerValueReference::service(ConfigRepositoryInterface::class),
                    ContainerValueReference::service(WorkerServiceFactory::class),
                    ContainerValueReference::service(WorkerManager::class),
                ],
            )
            ->tag(
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
            )
            ->tag(
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
            )
            ->tag(
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
}
