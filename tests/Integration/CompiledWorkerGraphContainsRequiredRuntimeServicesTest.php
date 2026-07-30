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

namespace Coretsia\Platform\Worker\Tests\Integration;

use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Contracts\Context\ContextAccessorInterface;
use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionContext;
use Coretsia\Foundation\Observability\Metrics\NoopMeter;
use Coretsia\Foundation\Observability\Tracing\NoopTracer;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Container\ContainerCompiler;
use Coretsia\Kernel\Container\ContainerGraphCompletenessValidator;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Entrypoint\RuntimeEntrypointGuard;
use Coretsia\Kernel\Runtime\RuntimePathContext;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CompiledWorkerGraphContainsRequiredRuntimeServicesTest extends TestCase
{
    /**
     * @var list<class-string>
     */
    private const array GRAPH_DEPENDENCY_IDS = [
        ContextAccessorInterface::class,
        KernelRuntimeInterface::class,
        LoggerInterface::class,
        MeterPortInterface::class,
        RuntimeEntrypointGuard::class,
        Stopwatch::class,
        TracerPortInterface::class,
    ];

    /**
     * @var list<class-string>
     */
    private const array REQUIRED_WORKER_SERVICE_IDS = [
        ApplicationWorker::class,
        HttpTaskFactory::class,
        QueueTaskFactory::class,
        WorkerManager::class,
        WorkerPoolSpec::class,
        WorkerRuntimeEntrypointGuard::class,
    ];

    public function testCompiledWorkerGraphContainsServicesRequiredByArtifactOnlyChildBoot(): void
    {
        $definitions = new ContainerDefinitionBuilder();

        foreach (self::GRAPH_DEPENDENCY_IDS as $serviceId) {
            $definitions->classService(
                id: $serviceId,
                class: CompiledWorkerGraphRuntimeDependency::class,
            );
        }

        new WorkerServiceProvider()->define(
            $definitions,
            new ContainerDefinitionContext([]),
        );

        $definitionSet = $definitions->build();
        $graph = new ContainerCompiler(
            tracer: new NoopTracer(),
            meter: new NoopMeter(),
            logger: new NullLogger(),
            stopwatch: new Stopwatch(),
        )->compile($definitionSet);

        new ContainerGraphCompletenessValidator()->validate(
            graph: $graph,
            definitions: $definitionSet,
        );

        $payload = $graph->toArray();
        $services = $payload['services'];

        foreach (
            self::REQUIRED_WORKER_SERVICE_IDS as $serviceId
        ) {
            self::assertArrayHasKey($serviceId, $services);
        }

        self::assertArrayNotHasKey(
            ConfigRepositoryInterface::class,
            $services,
        );
        self::assertArrayNotHasKey(
            ModulePlan::class,
            $services,
        );
        self::assertArrayNotHasKey(
            RuntimePathContext::class,
            $services,
        );

        self::assertSame(
            [
                ConfigRepositoryInterface::class,
            ],
            self::serviceReferenceIds(
                $services[WorkerPoolSpec::class]['arguments'],
            ),
        );

        self::assertSame(
            [
                ConfigRepositoryInterface::class,
                ModulePlan::class,
                WorkerRuntimeEntrypointGuard::class,
                ContainerInterface::class,
            ],
            self::serviceReferenceIds(
                $services[HttpTaskFactory::class]['arguments'],
            ),
        );

        self::assertSame(
            RuntimePathContext::class,
            self::serviceReferenceIds(
                $services[ApplicationWorker::class]['arguments'],
            )[0] ?? null,
        );
    }

    /**
     * @param list<mixed> $arguments
     *
     * @return list<string>
     */
    private static function serviceReferenceIds(
        array $arguments,
    ): array {
        $ids = [];

        foreach ($arguments as $argument) {
            if (
                !\is_array($argument)
                || ($argument['type'] ?? null) !== 'service'
                || !\is_string($argument['id'] ?? null)
            ) {
                throw new \LogicException(
                    'compiled-worker-graph-service-reference-invalid',
                );
            }

            $ids[] = $argument['id'];
        }

        return $ids;
    }
}

final class CompiledWorkerGraphRuntimeDependency
{
}
