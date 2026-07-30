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

namespace Coretsia\Platform\Worker\Tests\Contract;

use Coretsia\Kernel\Provider\KernelServiceFactory;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use PHPUnit\Framework\TestCase;

final class WorkerRuntimeArtifactPathContractTest extends TestCase
{
    public function testKernelSourceHostBuildsRuntimePathContextFromResolvedBootstrapConfig(): void
    {
        $source = self::methodSource(
            KernelServiceFactory::class,
            'runtimePathContext',
        );

        self::assertStringContainsString(
            'BootstrapConfig::class',
            $source,
        );
        self::assertStringContainsString(
            'skeletonRoot: $bootstrapConfig->skeletonRoot()',
            $source,
        );
        self::assertStringContainsString(
            'artifactRoot: new ArtifactPathResolver()->artifactRoot($bootstrapConfig)',
            $source,
        );

        self::assertStringNotContainsString(
            'BootstrapConfigResolver',
            $source,
        );
        self::assertStringNotContainsString(
            'resolveBootstrap',
            $source,
        );
        self::assertStringNotContainsString(
            'artifactsCacheDir()',
            $source,
        );
    }

    public function testProcWorkerArtifactRootIsDerivedOnlyFromRuntimePathContext(): void
    {
        $source = self::methodSource(
            WorkerServiceFactory::class,
            'procWorkerManagerDriver',
        )
            . "\n"
            . self::methodSource(
                WorkerServiceFactory::class,
                'relativeArtifactRoot',
            );

        self::assertStringContainsString(
            'RuntimePathContext $runtimePaths',
            $source,
        );
        self::assertStringContainsString(
            'skeletonRoot: $runtimePaths->skeletonRoot()',
            $source,
        );
        self::assertStringContainsString(
            'artifactRoot: self::relativeArtifactRoot($runtimePaths)',
            $source,
        );
        self::assertStringContainsString(
            '$runtimePaths->artifactRoot()',
            $source,
        );
        self::assertStringContainsString(
            '$runtimePaths->skeletonRoot()',
            $source,
        );

        self::assertStringNotContainsString(
            'moduleManifestArtifactPath:',
            $source,
        );
        self::assertStringNotContainsString(
            'configArtifactPath:',
            $source,
        );
        self::assertStringNotContainsString(
            'containerArtifactPath:',
            $source,
        );
        self::assertStringNotContainsString(
            'runtimeArtifactPath',
            $source,
        );
        self::assertStringNotContainsString(
            'MODULE_MANIFEST_ARTIFACT_BASENAME',
            $source,
        );
        self::assertStringNotContainsString(
            'CONFIG_ARTIFACT_BASENAME',
            $source,
        );
        self::assertStringNotContainsString(
            'CONTAINER_ARTIFACT_BASENAME',
            $source,
        );
        self::assertStringNotContainsString(
            'BootstrapConfig',
            $source,
        );
        self::assertStringNotContainsString(
            'ArtifactPathResolver',
            $source,
        );
        self::assertStringNotContainsString(
            "'var/cache",
            $source,
        );
        self::assertStringNotContainsString(
            'artifactsCacheDir()',
            $source,
        );
    }

    /**
     * @param class-string $className
     */
    private static function methodSource(
        string $className,
        string $methodName,
    ): string {
        $method = new \ReflectionMethod(
            $className,
            $methodName,
        );
        $file = $method->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        $lines = \explode("\n", $source);
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertGreaterThanOrEqual($start, $end);

        return \implode(
            "\n",
            \array_slice(
                $lines,
                $start - 1,
                $end - $start + 1,
            ),
        );
    }
}
