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

use Coretsia\Contracts\Module\ModuleDescriptor;
use Coretsia\Contracts\Module\ModuleId;
use Coretsia\Contracts\Module\ModuleManifest;
use Coretsia\Foundation\Provider\FoundationServiceProvider;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Module\ModulePlanEntry;
use Coretsia\Kernel\Module\ModuleResolution;
use Coretsia\Kernel\Provider\KernelServiceProvider;
use Coretsia\Kernel\Tests\Integration\ArtifactPipelineTestSupport;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use PHPUnit\Framework\TestCase;

final class CoretsiaWorkerChildBootsCurrentGenerationTest extends TestCase
{
    public function testChildBootsGenerationSelectedByCurrentPointer(): void
    {
        self::loadKernelArtifactTestSupport();

        $root = ArtifactPipelineTestSupport::temporaryRoot(
            'coretsia-worker-child-current-generation',
        );
        $moduleResolution = self::workerModuleResolution();

        try {
            ArtifactPipelineTestSupport::compileArtifacts(
                testCase: $this,
                skeletonRoot: $root,
                config: self::workerRuntimeConfig(maxRequests: 1),
                moduleResolution: $moduleResolution,
            );

            $firstGeneration = ArtifactPipelineTestSupport::currentGeneration(
                $root,
            );

            ArtifactPipelineTestSupport::compileArtifacts(
                testCase: $this,
                skeletonRoot: $root,
                config: self::workerRuntimeConfig(maxRequests: 2),
                moduleResolution: $moduleResolution,
            );

            $currentGeneration = ArtifactPipelineTestSupport::currentGeneration(
                $root,
            );

            self::assertNotSame(
                $firstGeneration->generationId()->value(),
                $currentGeneration->generationId()->value(),
            );
            self::assertDirectoryExists(
                $firstGeneration->generationDirectory(),
            );
            self::assertDirectoryExists(
                $currentGeneration->generationDirectory(),
            );

            self::writeAutoloadBridge($root);

            $result = self::runChild(
                skeletonRoot: $root,
                args: [
                    '--coretsia-worker-index=0',
                    '--coretsia-worker-count=1',
                    '--coretsia-worker-max-requests=2',
                    '--coretsia-worker-task-type=queue',
                    '--coretsia-worker-driver=proc',
                    '--coretsia-worker-artifact-root=var/cache/web',
                ],
            );

            self::assertSame(0, $result['exit_code']);
            self::assertSame('', $result['stdout']);
            self::assertSame('', $result['stderr']);
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

    private static function writeAutoloadBridge(string $skeletonRoot): void
    {
        $frameworkAutoload = self::frameworkRoot() . '/vendor/autoload.php';

        self::assertFileExists($frameworkAutoload);

        $vendorDirectory = $skeletonRoot . '/vendor';

        if (
            !\is_dir($vendorDirectory)
            && !\mkdir($vendorDirectory, 0777, true)
            && !\is_dir($vendorDirectory)
        ) {
            self::fail('Failed to create temporary vendor directory.');
        }

        $bytes = "<?php\n\ndeclare(strict_types=1);\n\nrequire "
            . \var_export($frameworkAutoload, true)
            . ";\n";

        self::assertNotFalse(
            \file_put_contents(
                $vendorDirectory . '/autoload.php',
                $bytes,
            ),
        );
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private static function runChild(
        string $skeletonRoot,
        array $args,
    ): array {
        $command = [
            \PHP_BINARY,
            self::launcherPath(),
            ...$args,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        \set_error_handler(static fn (): bool => true);

        try {
            $process = \proc_open(
                command: $command,
                descriptor_spec: $descriptors,
                pipes: $pipes,
                cwd: $skeletonRoot,
                env_vars: null,
                options: [],
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($process)) {
            self::fail('Failed to start Coretsia Worker child process.');
        }

        \fclose($pipes[0]);

        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);

        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);
        self::assertIsInt($exitCode);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
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

    private static function launcherPath(): string
    {
        $path = self::workerPackageRoot() . '/bin/coretsia-worker';

        self::assertFileExists($path);

        return $path;
    }

    private static function frameworkRoot(): string
    {
        return \dirname(__DIR__, 5);
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
