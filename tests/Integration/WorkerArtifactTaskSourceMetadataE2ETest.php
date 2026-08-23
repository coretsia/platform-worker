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

use Coretsia\Contracts\Worker\WorkerTaskInterface;
use Coretsia\Contracts\Worker\WorkerTaskSourceContextInterface;
use Coretsia\Contracts\Worker\WorkerTaskSourceInterface;
use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionContext;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionProviderInterface;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Foundation\Tag\TagRegistry;
use Coretsia\Kernel\Artifacts\ArtifactEnvelopeFactory;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGeneration;
use Coretsia\Kernel\Boot\AppTarget;
use Coretsia\Kernel\Boot\ArtifactRuntimeBooter;
use Coretsia\Kernel\Boot\ArtifactRuntimeInput;
use Coretsia\Kernel\Boot\BootstrapEnvSourcePolicy;
use Coretsia\Kernel\Boot\BootstrapInput;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Task\WorkerTaskSourceResolver;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerArtifactPipelineTestSupport;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;

final class WorkerArtifactTaskSourceMetadataE2ETest extends PackageTestCase
{
    public function testApplicationTaskSourceMetadataSurvivesCompiledArtifactsAndResolvesAtRuntime(): void
    {
        WorkerArtifactTaskSourceFixtureProvider::resetInvocations();

        $skeletonRoot = $this->temporaryDirectory('worker-task-source-artifact-e2e-skeleton');
        $foundationInstallRoot = $this->temporaryDirectory('worker-task-source-artifact-e2e-foundation');
        $kernelInstallRoot = $this->temporaryDirectory('worker-task-source-artifact-e2e-kernel');
        $workerInstallRoot = $this->temporaryDirectory('worker-task-source-artifact-e2e-worker');

        $frameworkRoot = self::frameworkRoot();
        $foundationPackageRoot = $frameworkRoot . '/packages/core/foundation';
        $kernelPackageRoot = $frameworkRoot . '/packages/core/kernel';
        $workerPackageRoot = self::packageRoot();

        $foundationComposer = self::readComposerJson($foundationPackageRoot . '/composer.json');
        $kernelComposer = self::readComposerJson($kernelPackageRoot . '/composer.json');
        $workerComposer = self::readComposerJson($workerPackageRoot . '/composer.json');

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

        WorkerArtifactPipelineTestSupport::writePhpReturn(
            $skeletonRoot . '/config/modes/worker-task-source-artifact-e2e.php',
            [
                'schemaVersion' => 1,
                'name' => 'worker-task-source-artifact-e2e',
                'description' => 'Worker task-source metadata artifact acceptance mode.',
                'required' => [
                    'integrations.worker-task-source-e2e',
                ],
                'optional' => [],
                'disabled' => [],
                'featureBundles' => [],
                'metadata' => [],
            ],
        );

        $bootstrapInput = new BootstrapInput(
            skeletonRoot: $skeletonRoot,
            appTarget: AppTarget::Worker,
            appEnv: 'test',
            preset: 'worker-task-source-artifact-e2e',
            debug: false,
            envSourcePolicy: BootstrapEnvSourcePolicy::StrictDotenv,
            artifactsCacheDir: 'var/cache',
        );

        $kernelConfig = require $kernelPackageRoot . '/config/kernel.php';

        self::assertIsArray($kernelConfig);

        $operation = WorkerArtifactPipelineTestSupport::operation(
            kernelConfig: $kernelConfig,
            installedData: self::installedData(
                foundationComposer: $foundationComposer,
                kernelComposer: $kernelComposer,
                workerComposer: $workerComposer,
            ),
            installRoots: [
                'coretsia/core-foundation' => $foundationInstallRoot,
                'coretsia/core-kernel' => $kernelInstallRoot,
                'coretsia/platform-worker' => $workerInstallRoot,
            ],
            kernelPackageRoot: $kernelPackageRoot,
        );

        $compileResult = $operation->compile($bootstrapInput);

        self::assertSame(
            1,
            WorkerArtifactTaskSourceFixtureProvider::defineInvocations(),
        );

        $generationId = $compileResult['generationId'] ?? null;

        self::assertIsString($generationId);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $generationId);

        $moduleManifestPayload = WorkerArtifactPipelineTestSupport::artifactPayload(
            skeletonRoot: $skeletonRoot,
            basename: ArtifactGeneration::MODULE_MANIFEST_BASENAME,
            expectedName: ArtifactEnvelopeFactory::ARTIFACT_MODULE_MANIFEST,
        );

        self::assertSame(
            [
                'core.foundation',
                'core.kernel',
                'integrations.worker-task-source-e2e',
                'platform.worker',
            ],
            $moduleManifestPayload['enabled'] ?? null,
        );
        self::assertSame(
            [
                'core.foundation',
                'core.kernel',
                'platform.worker',
                'integrations.worker-task-source-e2e',
            ],
            $moduleManifestPayload['topologicalOrder'] ?? null,
        );

        $containerPayload = WorkerArtifactPipelineTestSupport::artifactPayload(
            skeletonRoot: $skeletonRoot,
            basename: ArtifactGeneration::CONTAINER_BASENAME,
            expectedName: ArtifactEnvelopeFactory::ARTIFACT_CONTAINER,
        );

        self::assertSame(
            [
                [
                    'id' => WorkerArtifactQueueTaskSource::class,
                    'meta' => [
                        'task_type' => WorkerTaskType::Queue->value,
                    ],
                    'priority' => 0,
                ],
            ],
            $containerPayload['tags'][ReservedTags::WORKER_TASK_SOURCE] ?? null,
        );

        $verification = $operation->verify($bootstrapInput);

        self::assertSame('clean', $verification['outcome'] ?? null);
        self::assertTrue($verification['clean'] ?? false);
        self::assertFalse($verification['dirty'] ?? true);
        self::assertFalse($verification['invalid'] ?? true);
        self::assertSame($generationId, $verification['expectedGenerationId'] ?? null);
        self::assertSame($generationId, $verification['currentGenerationId'] ?? null);

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

        $providerCallsBeforeBoot = WorkerArtifactTaskSourceFixtureProvider::defineInvocations();

        self::assertSame(
            2,
            $providerCallsBeforeBoot,
        );

        self::removePath($foundationInstallRoot);
        self::removePath($kernelInstallRoot);
        self::removePath($workerInstallRoot);
        self::removePath($skeletonRoot . '/config');

        self::assertDirectoryDoesNotExist($foundationInstallRoot);
        self::assertDirectoryDoesNotExist($kernelInstallRoot);
        self::assertDirectoryDoesNotExist($workerInstallRoot);
        self::assertDirectoryDoesNotExist($skeletonRoot . '/config');

        $container = new ArtifactRuntimeBooter()->boot(
            new ArtifactRuntimeInput(
                skeletonRoot: $skeletonRoot,
                artifactRoot: WorkerArtifactPipelineTestSupport::artifactRoot($skeletonRoot),
            ),
        );

        $tagRegistry = $container->get(TagRegistry::class);

        self::assertInstanceOf(TagRegistry::class, $tagRegistry);

        $registeredSources = $tagRegistry->all(ReservedTags::WORKER_TASK_SOURCE);

        self::assertCount(1, $registeredSources);
        self::assertSame(
            WorkerArtifactQueueTaskSource::class,
            $registeredSources[0]->id(),
        );
        self::assertSame(0, $registeredSources[0]->priority());
        self::assertSame(
            [
                'task_type' => WorkerTaskType::Queue->value,
            ],
            $registeredSources[0]->meta(),
        );

        $resolver = $container->get(WorkerTaskSourceResolver::class);

        self::assertInstanceOf(WorkerTaskSourceResolver::class, $resolver);

        $resolvedSource = $resolver->resolve(WorkerTaskType::Queue);

        self::assertInstanceOf(WorkerArtifactQueueTaskSource::class, $resolvedSource);
        self::assertSame(WorkerTaskType::Queue, $resolvedSource->taskType());
        self::assertSame(
            $resolvedSource,
            $container->get(WorkerTaskSourceInterface::class),
        );

        $applicationWorker = $container->get(ApplicationWorker::class);
        $workerPoolSpec = $container->get(WorkerPoolSpec::class);

        self::assertInstanceOf(ApplicationWorker::class, $applicationWorker);
        self::assertInstanceOf(WorkerPoolSpec::class, $workerPoolSpec);

        $applicationWorker->assertReady(
            spec: $workerPoolSpec,
            workerIndex: 0,
        );

        self::assertSame(
            $providerCallsBeforeBoot,
            WorkerArtifactTaskSourceFixtureProvider::defineInvocations(),
        );

        WorkerArtifactTaskSourceFixtureProvider::resetInvocations();
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
                    'name' => 'coretsia/worker-task-source-artifact-e2e-app',
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
                    'coretsia/worker-task-source-artifact-e2e-fixture' => [
                        'type' => 'library',
                        'extra' => [
                            'coretsia' => [
                                'kind' => 'runtime',
                                'moduleId' => 'integrations.worker-task-source-e2e',
                                'providers' => [
                                    WorkerArtifactTaskSourceFixtureProvider::class,
                                ],
                                'requires' => [
                                    'platform.worker',
                                ],
                                'conflicts' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

final class WorkerArtifactTaskSourceFixtureProvider implements ContainerDefinitionProviderInterface
{
    private static int $defineInvocations = 0;

    public static function resetInvocations(): void
    {
        self::$defineInvocations = 0;
    }

    public static function defineInvocations(): int
    {
        return self::$defineInvocations;
    }

    public function define(
        ContainerDefinitionBuilder $definitions,
        ContainerDefinitionContext $context,
    ): void {
        ++self::$defineInvocations;

        unset($context);

        $definitions
            ->classService(
                WorkerArtifactQueueTaskSource::class,
                WorkerArtifactQueueTaskSource::class,
            )
            ->tag(
                tag: ReservedTags::WORKER_TASK_SOURCE,
                serviceId: WorkerArtifactQueueTaskSource::class,
                meta: [
                    'task_type' => WorkerTaskType::Queue->value,
                ],
            );
    }
}

final class WorkerArtifactQueueTaskSource implements WorkerTaskSourceInterface
{
    public function taskType(): WorkerTaskType
    {
        return WorkerTaskType::Queue;
    }

    public function assertReady(
        WorkerTaskSourceContextInterface $context,
    ): void {
        unset($context);
    }

    public function receive(
        WorkerTaskSourceContextInterface $context,
    ): ?WorkerTaskInterface {
        unset($context);

        return null;
    }
}
