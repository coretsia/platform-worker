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

use PHPUnit\Framework\TestCase;

final class WorkerServiceProviderArtifactPathContractTest extends TestCase
{
    public function testProcWorkerArtifactPathsUseResolvedBootstrapCacheDirectory(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 2)
            . '/src/Provider/WorkerServiceProvider.php',
        );

        self::assertIsString($source);

        self::assertSame(
            2,
            \substr_count(
                $source,
                '$bootstrapConfig->artifactsCacheDir()',
            ),
            'Both config.php and container.php proc child paths must use the '
            . 'resolved BootstrapConfig artifact cache directory.',
        );

        self::assertStringNotContainsString(
            "'var/cache",
            $source,
            'WorkerServiceProvider must not contain a fixed artifact cache root.',
        );

        self::assertStringContainsString(
            "configArtifactPath: self::configArtifactPath(",
            $source,
        );

        self::assertStringContainsString(
            "containerArtifactPath: self::containerArtifactPath(",
            $source,
        );
    }
}
