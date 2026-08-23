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

use Coretsia\Contracts\Cli\Command\CommandInterface;
use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Contracts\Worker\WorkerTaskSourceInterface;
use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Foundation\Tag\TagRegistry;
use Coretsia\Kernel\Artifacts\ArtifactEnvelopeFactory;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGeneration;
use Coretsia\Kernel\Boot\AppTarget;
use Coretsia\Kernel\Boot\ArtifactRuntimeBooter;
use Coretsia\Kernel\Boot\ArtifactRuntimeInput;
use Coretsia\Kernel\Boot\BootstrapEnvSourcePolicy;
use Coretsia\Kernel\Boot\BootstrapInput;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\KernelRuntime;
use Coretsia\Kernel\Runtime\RuntimePathContext;
use Coretsia\Platform\Worker\Communication\WorkerControlClient;
use Coretsia\Platform\Worker\Console\WorkerHealthCommand;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerControlClientInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverResolverInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessGuardianInterface;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorResolverInterface;
use Coretsia\Platform\Worker\Process\ContainerWorkerProcessDriverResolver;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianClient;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Supervisor\ContainerWorkerSupervisorResolver;
use Coretsia\Platform\Worker\Supervisor\WorkerSupervisor;
use Coretsia\Platform\Worker\Task\WorkerTaskSourceResolver;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerArtifactPipelineTestSupport;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;

final class WorkerCompileArtifactBootE2ETest extends PackageTestCase
{
    public function testRealWorkerPackageCompilesVerifiesAndBootsFromImmutableArtifacts(): void
    {
        $skeletonRoot = $this->temporaryDirectory('worker-artifact-e2e-skeleton');
        $foundationInstallRoot = $this->temporaryDirectory('worker-artifact-e2e-foundation');
        $kernelInstallRoot = $this->temporaryDirectory('worker-artifact-e2e-kernel');
        $workerInstallRoot = $this->temporaryDirectory('worker-artifact-e2e-worker');

        $frameworkRoot = self::frameworkRoot();
        $foundationPackageRoot = $frameworkRoot . '/packages/core/foundation';
        $kernelPackageRoot = $frameworkRoot . '/packages/core/kernel';
        $workerPackageRoot = self::packageRoot();

        $foundationComposer = self::readComposerJson($foundationPackageRoot . '/composer.json');
        $kernelComposer = self::readComposerJson($kernelPackageRoot . '/composer.json');
        $workerComposer = self::readComposerJson($workerPackageRoot . '/composer.json');

        self::assertPackageMetadata(
            composer: $foundationComposer,
            expectedName: 'coretsia/core-foundation',
            expectedModuleId: 'core.foundation',
            expectedProvider: 'Coretsia\\Foundation\\Provider\\FoundationServiceProvider',
            expectedRequires: [],
            expectedDefaultsConfigPath: 'config/foundation.php',
        );
        self::assertPackageMetadata(
            composer: $kernelComposer,
            expectedName: 'coretsia/core-kernel',
            expectedModuleId: 'core.kernel',
            expectedProvider: 'Coretsia\\Kernel\\Provider\\KernelServiceProvider',
            expectedRequires: ['core.foundation'],
            expectedDefaultsConfigPath: 'config/kernel.php',
        );
        self::assertPackageMetadata(
            composer: $workerComposer,
            expectedName: 'coretsia/platform-worker',
            expectedModuleId: 'platform.worker',
            expectedProvider: 'Coretsia\\Platform\\Worker\\Provider\\WorkerServiceProvider',
            expectedRequires: ['core.kernel'],
            expectedDefaultsConfigPath: 'config/worker.php',
        );

        WorkerArtifactPipelineTestSupport::copyPackageInputs(
            sourceRoot: $foundationPackageRoot,
            targetRoot: $foundationInstallRoot,
            composer: $foundationComposer,
        );
        WorkerArtifactPipelineTestSupport::copyPackageInputs(
            sourceRoot: $kernelPackageRoot,
            targetRoot: $kernelInstallRoot,
            composer: $kernelComposer,
        );
        WorkerArtifactPipelineTestSupport::copyPackageInputs(
            sourceRoot: $workerPackageRoot,
            targetRoot: $workerInstallRoot,
            composer: $workerComposer,
        );

        $presetPath = $skeletonRoot . '/config/modes/worker-artifact-e2e.php';

        WorkerArtifactPipelineTestSupport::writePhpReturn(
            $presetPath,
            [
                'schemaVersion' => 1,
                'name' => 'worker-artifact-e2e',
                'description' => 'Real platform/worker artifact acceptance mode.',
                'required' => [
                    'platform.worker',
                ],
                'optional' => [],
                'disabled' => [],
                'featureBundles' => [],
                'metadata' => [],
            ],
        );

        self::assertFileDoesNotExist($skeletonRoot . '/config/roots.php');

        $bootstrapInput = new BootstrapInput(
            skeletonRoot: $skeletonRoot,
            appTarget: AppTarget::Worker,
            appEnv: 'test',
            preset: 'worker-artifact-e2e',
            debug: false,
            envSourcePolicy: BootstrapEnvSourcePolicy::StrictDotenv,
            artifactsCacheDir: 'var/cache',
        );

        $kernelConfig = require $kernelPackageRoot . '/config/kernel.php';

        self::assertIsArray($kernelConfig);

        $installedData = self::installedData(
            foundationComposer: $foundationComposer,
            kernelComposer: $kernelComposer,
            workerComposer: $workerComposer,
        );

        $operation = WorkerArtifactPipelineTestSupport::operation(
            kernelConfig: $kernelConfig,
            installedData: $installedData,
            installRoots: [
                'coretsia/core-foundation' => $foundationInstallRoot,
                'coretsia/core-kernel' => $kernelInstallRoot,
                'coretsia/platform-worker' => $workerInstallRoot,
            ],
            kernelPackageRoot: $kernelPackageRoot,
        );

        $compileResult = $operation->compile($bootstrapInput);
        $generationId = $compileResult['generationId'] ?? null;

        self::assertIsString($generationId);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $generationId);

        $currentGeneration = WorkerArtifactPipelineTestSupport::currentGeneration($skeletonRoot);

        self::assertSame(
            $generationId,
            $currentGeneration->generationId()->value(),
        );

        foreach (
            [
                $currentGeneration->moduleManifestPath(),
                $currentGeneration->configPath(),
                $currentGeneration->containerPath(),
                $currentGeneration->generationManifestPath(),
            ] as $artifactPath
        ) {
            self::assertFileExists($artifactPath);
        }

        $moduleManifestPayload = WorkerArtifactPipelineTestSupport::artifactPayload(
            skeletonRoot: $skeletonRoot,
            basename: ArtifactGeneration::MODULE_MANIFEST_BASENAME,
            expectedName: ArtifactEnvelopeFactory::ARTIFACT_MODULE_MANIFEST,
        );

        self::assertSame(
            [
                'core.foundation',
                'core.kernel',
                'platform.worker',
            ],
            $moduleManifestPayload['enabled'] ?? null,
        );
        self::assertSame(
            [
                'core.foundation',
                'core.kernel',
                'platform.worker',
            ],
            $moduleManifestPayload['topologicalOrder'] ?? null,
        );

        self::assertSame(
            'worker',
            $moduleManifestPayload['app'] ?? null,
        );
        self::assertSame(
            'worker-artifact-e2e',
            $moduleManifestPayload['preset'] ?? null,
        );
        self::assertSame(
            [],
            $moduleManifestPayload['disabled'] ?? null,
        );
        self::assertSame(
            [],
            $moduleManifestPayload['optionalMissing'] ?? null,
        );
        self::assertSame(
            [],
            $moduleManifestPayload['warnings'] ?? null,
        );
        self::assertSame(
            [
                'core.foundation' => [
                    'composerName' => 'coretsia/core-foundation',
                    'conflicts' => [],
                    'moduleId' => 'core.foundation',
                    'requires' => [],
                ],
                'core.kernel' => [
                    'composerName' => 'coretsia/core-kernel',
                    'conflicts' => [],
                    'moduleId' => 'core.kernel',
                    'requires' => [
                        'core.foundation',
                    ],
                ],
                'platform.worker' => [
                    'composerName' => 'coretsia/platform-worker',
                    'conflicts' => [],
                    'moduleId' => 'platform.worker',
                    'requires' => [
                        'core.kernel',
                    ],
                ],
            ],
            $moduleManifestPayload['modules'] ?? null,
        );

        $configPayload = WorkerArtifactPipelineTestSupport::artifactPayload(
            skeletonRoot: $skeletonRoot,
            basename: ArtifactGeneration::CONFIG_BASENAME,
            expectedName: ArtifactEnvelopeFactory::ARTIFACT_CONFIG,
        );

        self::assertSame(
            true,
            $configPayload['config']['foundation']['container']['autowire_concrete'] ?? null,
        );
        self::assertSame(
            'http.classic',
            $configPayload['config']['kernel']['runtime']['http_driver'] ?? null,
        );
        self::assertSame(
            4,
            $configPayload['config']['worker']['workers'] ?? null,
        );
        self::assertSame(
            1000,
            $configPayload['config']['worker']['max_requests'] ?? null,
        );
        self::assertSame(
            'queue',
            $configPayload['config']['worker']['task_type'] ?? null,
        );
        self::assertSame(
            'auto',
            $configPayload['config']['worker']['driver'] ?? null,
        );

        $validatedSubjects = $configPayload['validationSubjects']['validated'] ?? null;
        $unvalidatedSubjects = $configPayload['validationSubjects']['unvalidated'] ?? null;

        self::assertIsArray($validatedSubjects);
        self::assertIsArray($unvalidatedSubjects);

        foreach (['foundation', 'kernel', 'worker'] as $validatedRoot) {
            self::assertContains(
                [
                    'ownership' => 'ruleset_owned',
                    'root' => $validatedRoot,
                    'validation' => 'validated',
                ],
                $validatedSubjects,
            );

            foreach ($unvalidatedSubjects as $subject) {
                self::assertIsArray($subject);
                self::assertNotSame(
                    $validatedRoot,
                    $subject['root'] ?? null,
                );
            }
        }

        $containerPayload = WorkerArtifactPipelineTestSupport::artifactPayload(
            skeletonRoot: $skeletonRoot,
            basename: ArtifactGeneration::CONTAINER_BASENAME,
            expectedName: ArtifactEnvelopeFactory::ARTIFACT_CONTAINER,
        );

        self::assertSame('compiled', $containerPayload['kind'] ?? null);
        self::assertTrue($containerPayload['compiled'] ?? false);

        $services = $containerPayload['services'] ?? null;
        $aliases = $containerPayload['aliases'] ?? null;

        self::assertIsArray($services);
        self::assertIsArray($aliases);

        foreach (
            [
                WorkerPoolSpec::class,
                WorkerRuntimeEntrypointGuard::class,
                WorkerTaskSourceResolver::class,
                ContainerWorkerProcessDriverResolver::class,
                WorkerSupervisor::class,
                ContainerWorkerSupervisorResolver::class,
                WorkerStartCommand::class,
                WorkerStopCommand::class,
                WorkerStatusCommand::class,
                WorkerHealthCommand::class,
            ] as $workerServiceId
        ) {
            self::assertArrayHasKey(
                $workerServiceId,
                $services,
            );
        }

        self::assertSame(
            WorkerControlClient::class,
            $aliases[WorkerControlClientInterface::class] ?? null,
        );
        self::assertSame(
            ContainerWorkerProcessDriverResolver::class,
            $aliases[WorkerProcessDriverResolverInterface::class] ?? null,
        );
        self::assertSame(
            WorkerProcessGuardianClient::class,
            $aliases[WorkerProcessGuardianInterface::class] ?? null,
        );
        self::assertSame(
            WorkerSupervisor::class,
            $aliases[WorkerSupervisorInterface::class] ?? null,
        );
        self::assertSame(
            ContainerWorkerSupervisorResolver::class,
            $aliases[WorkerSupervisorResolverInterface::class] ?? null,
        );

        $verification = $operation->verify($bootstrapInput);

        self::assertSame('clean', $verification['outcome'] ?? null);
        self::assertTrue($verification['clean'] ?? false);
        self::assertFalse($verification['dirty'] ?? true);
        self::assertFalse($verification['invalid'] ?? true);
        self::assertSame(
            $generationId,
            $verification['expectedGenerationId'] ?? null,
        );
        self::assertSame(
            $generationId,
            $verification['currentGenerationId'] ?? null,
        );

        $verifiedArtifacts = $verification['artifacts'] ?? null;

        self::assertIsArray($verifiedArtifacts);
        self::assertCount(4, $verifiedArtifacts);
        self::assertSame(
            [
                'expected_artifact_count' => 4,
                'existing_artifact_count' => 4,
                'missing_artifact_count' => 0,
                'dirty_artifact_count' => 0,
                'invalid_artifact_count' => 0,
            ],
            $verification['counts'] ?? null,
        );

        foreach ($verifiedArtifacts as $artifact) {
            self::assertIsArray($artifact);
            self::assertSame('clean', $artifact['status'] ?? null);
            self::assertSame('ok', $artifact['reason'] ?? null);
        }

        self::removePath($foundationInstallRoot);
        self::removePath($kernelInstallRoot);
        self::removePath($workerInstallRoot);
        self::removePath($skeletonRoot . '/config');

        self::assertDirectoryDoesNotExist($foundationInstallRoot);
        self::assertDirectoryDoesNotExist($kernelInstallRoot);
        self::assertDirectoryDoesNotExist($workerInstallRoot);
        self::assertFileDoesNotExist($presetPath);

        $container = new ArtifactRuntimeBooter()->boot(
            new ArtifactRuntimeInput(
                skeletonRoot: $skeletonRoot,
                artifactRoot: WorkerArtifactPipelineTestSupport::artifactRoot($skeletonRoot),
            ),
        );

        $configRepository = $container->get(ConfigRepositoryInterface::class);

        self::assertInstanceOf(
            ConfigRepositoryInterface::class,
            $configRepository,
        );
        self::assertSame(4, $configRepository->get('worker.workers'));
        self::assertSame(1000, $configRepository->get('worker.max_requests'));
        self::assertSame('queue', $configRepository->get('worker.task_type'));
        self::assertSame('auto', $configRepository->get('worker.driver'));
        self::assertSame(
            'http.classic',
            $configRepository->get('kernel.runtime.http_driver'),
        );

        $modulePlan = $container->get(ModulePlan::class);

        self::assertInstanceOf(ModulePlan::class, $modulePlan);
        self::assertSame(
            $moduleManifestPayload,
            $modulePlan->toArray(),
        );

        $runtimePaths = $container->get(RuntimePathContext::class);

        self::assertInstanceOf(RuntimePathContext::class, $runtimePaths);
        self::assertSame(
            self::normalizedPath($skeletonRoot),
            $runtimePaths->skeletonRoot(),
        );
        self::assertSame(
            self::normalizedPath(
                WorkerArtifactPipelineTestSupport::artifactRoot($skeletonRoot),
            ),
            $runtimePaths->artifactRoot(),
        );

        $kernelRuntime = $container->get(KernelRuntime::class);
        $kernelRuntimePort = $container->get(KernelRuntimeInterface::class);

        self::assertInstanceOf(KernelRuntime::class, $kernelRuntime);
        self::assertSame($kernelRuntime, $kernelRuntimePort);

        $workerPoolSpec = $container->get(WorkerPoolSpec::class);

        self::assertInstanceOf(WorkerPoolSpec::class, $workerPoolSpec);
        self::assertSame(4, $workerPoolSpec->workers());
        self::assertSame(1000, $workerPoolSpec->maxRequests());
        self::assertSame('queue', $workerPoolSpec->taskType());
        self::assertSame('auto', $workerPoolSpec->driverRequested());
        self::assertContains(
            $workerPoolSpec->driver(),
            [
                WorkerProcessDriverInterface::DRIVER_PCNTL,
                WorkerProcessDriverInterface::DRIVER_PROC,
            ],
        );
        self::assertSame('auto', $workerPoolSpec->controlTransportRequested());
        self::assertContains(
            $workerPoolSpec->controlTransport(),
            ['unix', 'tcp'],
        );

        $runtimeEntrypointGuard = $container->get(
            WorkerRuntimeEntrypointGuard::class,
        );

        self::assertInstanceOf(
            WorkerRuntimeEntrypointGuard::class,
            $runtimeEntrypointGuard,
        );
        $runtimeEntrypointGuard->assertEntrypointAllowed(
            config: $configRepository,
            modulePlan: $modulePlan,
            spec: $workerPoolSpec,
        );

        $taskSourceResolver = $container->get(WorkerTaskSourceResolver::class);

        self::assertInstanceOf(
            WorkerTaskSourceResolver::class,
            $taskSourceResolver,
        );
        self::assertTrue(
            $container->has(WorkerTaskSourceInterface::class),
        );
        self::assertTrue(
            $container->has(ApplicationWorker::class),
        );

        try {
            $taskSourceResolver->resolve(WorkerTaskType::Queue);

            self::fail('Expected the real worker package to require an application-owned queue task source.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(
                WorkerStartFailedException::REASON_TASK_SOURCE_MISSING,
                $exception->reason(),
            );
        }

        $driverResolver = $container->get(WorkerProcessDriverResolverInterface::class);

        self::assertInstanceOf(
            WorkerProcessDriverResolverInterface::class,
            $driverResolver,
        );

        $selectedDriver = $driverResolver->resolve($workerPoolSpec);

        self::assertInstanceOf(
            WorkerProcessDriverInterface::class,
            $selectedDriver,
        );
        self::assertSame(
            $workerPoolSpec->driver(),
            $selectedDriver->name(),
        );
        self::assertTrue(
            $selectedDriver->supports($workerPoolSpec),
        );

        $guardian = $container->get(WorkerProcessGuardianInterface::class);

        self::assertInstanceOf(
            WorkerProcessGuardianClient::class,
            $guardian,
        );
        self::assertSame(
            $guardian,
            $container->get(WorkerProcessGuardianClient::class),
        );

        $supervisor = $container->get(WorkerSupervisorInterface::class);
        $supervisorResolver = $container->get(WorkerSupervisorResolverInterface::class);

        self::assertInstanceOf(
            WorkerSupervisorInterface::class,
            $supervisor,
        );
        self::assertInstanceOf(
            WorkerSupervisorResolverInterface::class,
            $supervisorResolver,
        );
        self::assertSame(
            $supervisor,
            $supervisorResolver->resolve(),
        );

        $workerCommands = [
            WorkerStartCommand::class => WorkerStartCommand::NAME,
            WorkerStopCommand::class => WorkerStopCommand::NAME,
            WorkerStatusCommand::class => WorkerStatusCommand::NAME,
            WorkerHealthCommand::class => WorkerHealthCommand::NAME,
        ];

        $workerCommandMetadata = [
            WorkerStartCommand::class => self::commandMetadata(WorkerStartCommand::class),
            WorkerStopCommand::class => self::commandMetadata(WorkerStopCommand::class),
            WorkerStatusCommand::class => self::commandMetadata(WorkerStatusCommand::class),
            WorkerHealthCommand::class => self::commandMetadata(WorkerHealthCommand::class),
        ];

        foreach ($workerCommands as $commandClass => $commandName) {
            $command = $container->get($commandClass);

            self::assertInstanceOf($commandClass, $command);
            self::assertInstanceOf(CommandInterface::class, $command);
            self::assertSame($commandName, $command->name());
        }

        $tagRegistry = $container->get(TagRegistry::class);

        self::assertInstanceOf(TagRegistry::class, $tagRegistry);

        $taggedCommands = $tagRegistry->all(ReservedTags::CLI_COMMAND);

        self::assertCount(
            4,
            $taggedCommands,
        );

        $cliCommandIds = [];

        foreach ($taggedCommands as $taggedCommand) {
            self::assertSame(
                0,
                $taggedCommand->priority(),
            );
            self::assertSame(
                $workerCommandMetadata[$taggedCommand->id()] ?? null,
                $taggedCommand->meta(),
                'Artifact-only boot must preserve deterministic CLI discovery metadata.',
            );

            $cliCommandIds[] = $taggedCommand->id();
        }

        self::assertSame(
            [
                WorkerHealthCommand::class,
                WorkerStartCommand::class,
                WorkerStatusCommand::class,
                WorkerStopCommand::class,
            ],
            $cliCommandIds,
        );
    }

    /**
     * @param class-string<CommandInterface> $commandClass
     *
     * @return array{
     *     arguments: list<array<string, mixed>>,
     *     group: string,
     *     hidden: bool,
     *     mode: string,
     *     name: string,
     *     options: list<array<string, mixed>>,
     *     summary: string
     * }
     */
    private static function commandMetadata(string $commandClass): array
    {
        return [
            'arguments' => $commandClass::ARGUMENTS,
            'group' => $commandClass::GROUP,
            'hidden' => $commandClass::HIDDEN,
            'mode' => $commandClass::MODE,
            'name' => $commandClass::NAME,
            'options' => $commandClass::OPTIONS,
            'summary' => $commandClass::SUMMARY,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function readComposerJson(string $path): array
    {
        $bytes = \file_get_contents($path);

        self::assertIsString($bytes);

        $decoded = \json_decode(
            $bytes,
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);
        self::assertFalse(\array_is_list($decoded));

        return $decoded;
    }

    /**
     * @param array<string,mixed> $composer
     * @param list<string> $expectedRequires
     */
    private static function assertPackageMetadata(
        array $composer,
        string $expectedName,
        string $expectedModuleId,
        string $expectedProvider,
        array $expectedRequires,
        string $expectedDefaultsConfigPath,
    ): void {
        self::assertSame($expectedName, $composer['name'] ?? null);
        self::assertSame('library', $composer['type'] ?? null);
        self::assertSame(
            $expectedModuleId,
            $composer['extra']['coretsia']['moduleId'] ?? null,
        );
        self::assertSame(
            [$expectedProvider],
            $composer['extra']['coretsia']['providers'] ?? null,
        );
        self::assertSame(
            $expectedRequires,
            $composer['extra']['coretsia']['requires'] ?? null,
        );
        self::assertSame(
            $expectedDefaultsConfigPath,
            $composer['extra']['coretsia']['defaultsConfigPath'] ?? null,
        );
    }

    /**
     * @param array<string,mixed> $foundationComposer
     * @param array<string,mixed> $kernelComposer
     * @param array<string,mixed> $workerComposer
     *
     * @return list<array<string,mixed>>
     */
    private static function installedData(
        array $foundationComposer,
        array $kernelComposer,
        array $workerComposer,
    ): array {
        return [
            [
                'root' => [
                    'name' => 'coretsia/worker-artifact-e2e-app',
                    'type' => 'project',
                    'extra' => [],
                ],
                'versions' => [
                    'coretsia/core-foundation' => [
                        'type' => $foundationComposer['type'],
                        'extra' => $foundationComposer['extra'],
                    ],
                    'coretsia/core-kernel' => [
                        'type' => $kernelComposer['type'],
                        'extra' => $kernelComposer['extra'],
                    ],
                    'coretsia/platform-worker' => [
                        'type' => $workerComposer['type'],
                        'extra' => $workerComposer['extra'],
                    ],
                ],
            ],
        ];
    }

    private static function normalizedPath(string $path): string
    {
        return \rtrim(
            \str_replace('\\', '/', $path),
            '/',
        );
    }
}
