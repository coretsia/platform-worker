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
use Coretsia\Contracts\Module\ModuleDescriptor;
use Coretsia\Contracts\Module\ModuleId;
use Coretsia\Contracts\Module\ModuleManifest;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Foundation\Provider\FoundationServiceProvider;
use Coretsia\Kernel\Boot\ArtifactRuntimeInput;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Module\ModulePlanEntry;
use Coretsia\Kernel\Module\ModuleResolution;
use Coretsia\Kernel\Provider\KernelServiceProvider;
use Coretsia\Kernel\Runtime\RuntimePathContext;
use Coretsia\Kernel\Tests\Integration\ArtifactPipelineTestSupport;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use PHPUnit\Framework\TestCase;

final class ArtifactOnlyWorkerContainerBootTest extends TestCase
{
    public function testArtifactOnlyContainerResolvesAndRunsRequiredWorkerServices(): void
    {
        self::loadKernelArtifactTestSupport();

        $root = ArtifactPipelineTestSupport::temporaryRoot(
            'artifact-only-worker-container-boot',
        );
        $moduleResolution = self::workerModuleResolution();
        $config = self::workerRuntimeConfig(maxRequests: 1);

        try {
            ArtifactPipelineTestSupport::compileArtifacts(
                testCase: $this,
                skeletonRoot: $root,
                config: $config,
                moduleResolution: $moduleResolution,
            );

            $configPayload = ArtifactPipelineTestSupport::configPayloadFromArtifact(
                $root,
            );
            $expectedConfig = $configPayload['config'] ?? null;

            self::assertIsArray($expectedConfig);

            $runtimeInput = new ArtifactRuntimeInput(
                skeletonRoot: $root,
                artifactRoot: ArtifactPipelineTestSupport::artifactRoot($root),
            );

            $container = ArtifactPipelineTestSupport::runtimeContainerFromArtifacts(
                skeletonRoot: $root,
            );

            $configRepository = $container->get(
                ConfigRepositoryInterface::class,
            );
            $modulePlan = $container->get(ModulePlan::class);
            $runtimePaths = $container->get(RuntimePathContext::class);
            $kernelRuntime = $container->get(KernelRuntimeInterface::class);
            $spec = $container->get(WorkerPoolSpec::class);
            $guard = $container->get(WorkerRuntimeEntrypointGuard::class);
            $worker = $container->get(ApplicationWorker::class);

            self::assertInstanceOf(
                ConfigRepositoryInterface::class,
                $configRepository,
            );
            self::assertInstanceOf(ModulePlan::class, $modulePlan);
            self::assertInstanceOf(RuntimePathContext::class, $runtimePaths);
            self::assertInstanceOf(
                KernelRuntimeInterface::class,
                $kernelRuntime,
            );
            self::assertInstanceOf(WorkerPoolSpec::class, $spec);
            self::assertInstanceOf(
                WorkerRuntimeEntrypointGuard::class,
                $guard,
            );
            self::assertInstanceOf(ApplicationWorker::class, $worker);

            self::assertSame(
                $expectedConfig,
                $configRepository->all(),
            );
            self::assertSame(
                $moduleResolution->plan()->toArray(),
                $modulePlan->toArray(),
            );
            self::assertTrue(
                $modulePlan->hasEnabledModule('platform.worker'),
            );
            self::assertSame(
                $runtimeInput->skeletonRoot(),
                $runtimePaths->skeletonRoot(),
            );
            self::assertSame(
                $runtimeInput->artifactRoot(),
                $runtimePaths->artifactRoot(),
            );

            self::assertSame(1, $spec->workers());
            self::assertSame(1, $spec->maxRequests());
            self::assertSame('queue', $spec->taskType());
            self::assertSame('proc', $spec->driver());
            self::assertSame('tcp', $spec->controlTransport());

            $guard->assertEntrypointAllowed(
                config: $configRepository,
                modulePlan: $modulePlan,
                spec: $spec,
            );

            \ob_start();

            try {
                $processed = $worker->run($spec);
            } finally {
                $output = \ob_get_clean();
            }

            self::assertIsString($output);
            self::assertSame('', $output);
            self::assertSame(1, $processed);
        } finally {
            ArtifactPipelineTestSupport::removeTree($root);
        }
    }

    private static function loadKernelArtifactTestSupport(): void
    {
        if (\class_exists(ArtifactPipelineTestSupport::class)) {
            return;
        }

        require_once self::kernelPackageRoot()
            . '/tests/Integration/ArtifactPipelineTestSupport.php';
    }

    private static function workerModuleResolution(): ModuleResolution
    {
        $foundationId = ModuleId::fromString('core.foundation');
        $kernelId = ModuleId::fromString('core.kernel');
        $workerId = ModuleId::fromString('platform.worker');

        return new ModuleResolution(
            manifest: new ModuleManifest([
                new ModuleDescriptor(
                    id: $foundationId,
                    composerName: 'coretsia/core-foundation',
                    packageKind: 'runtime',
                    metadata: [
                        'providers' => [
                            FoundationServiceProvider::class,
                        ],
                    ],
                ),
                new ModuleDescriptor(
                    id: $kernelId,
                    composerName: 'coretsia/core-kernel',
                    packageKind: 'runtime',
                    metadata: [
                        'providers' => [
                            KernelServiceProvider::class,
                        ],
                    ],
                ),
                new ModuleDescriptor(
                    id: $workerId,
                    composerName: 'coretsia/platform-worker',
                    packageKind: 'runtime',
                    metadata: [
                        'providers' => [
                            WorkerServiceProvider::class,
                        ],
                    ],
                ),
            ]),
            plan: new ModulePlan(
                app: 'web',
                preset: 'default',
                enabled: [
                    $foundationId,
                    $kernelId,
                    $workerId,
                ],
                disabled: [],
                optionalMissing: [],
                topologicalOrder: [
                    $foundationId,
                    $kernelId,
                    $workerId,
                ],
                modules: [
                    new ModulePlanEntry(
                        moduleId: $foundationId,
                        composerName: 'coretsia/core-foundation',
                    ),
                    new ModulePlanEntry(
                        moduleId: $kernelId,
                        composerName: 'coretsia/core-kernel',
                        requires: [
                            $foundationId,
                        ],
                    ),
                    new ModulePlanEntry(
                        moduleId: $workerId,
                        composerName: 'coretsia/platform-worker',
                        requires: [
                            $kernelId,
                        ],
                    ),
                ],
                warnings: [],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerRuntimeConfig(int $maxRequests): array
    {
        $worker = require self::workerPackageRoot() . '/config/worker.php';

        self::assertIsArray($worker);

        $control = $worker['control'] ?? null;

        self::assertIsArray($control);

        $control['transport'] = 'tcp';
        $worker['workers'] = 1;
        $worker['max_requests'] = $maxRequests;
        $worker['task_type'] = 'queue';
        $worker['driver'] = 'proc';
        $worker['control'] = $control;
        $worker['stop_timeout_ms'] = 0;

        return [
            'foundation' => [
                'reset' => [
                    'priority' => [
                        'enabled' => false,
                    ],
                ],
            ],
            'kernel' => [
                'boot' => [
                    'default_env' => 'prod',
                ],
                'runtime' => [
                    'http_driver' => 'http.classic',
                ],
                'uow' => [
                    'attributes' => [
                        'max_depth' => 10,
                        'max_keys' => 200,
                    ],
                ],
            ],
            'worker' => $worker,
        ];
    }

    private static function kernelPackageRoot(): string
    {
        return \dirname(__DIR__, 4) . '/core/kernel';
    }

    private static function workerPackageRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
