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

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerRuntimeArtifactPathContractTest extends PackageTestCase
{
    public function testRuntimeArtifactsRemainRelativeAndOwnedByDedicatedCollaborators(): void
    {
        $factory = self::source('src/Provider/WorkerServiceFactory.php');
        foreach (
            [
                'workerLifecycleLock',
                'workerLifecycleLocatorStore',
                'workerStopSignal',
                'workerStateStore',
                'workerControlTransport',
                'workerChildCommandBuilder',
                'pcntlWorkerProcessDriver',
                'procWorkerProcessDriver',
            ] as $method
        ) {
            self::assertStringContainsString('function ' . $method, $factory);
        }
        self::assertStringContainsString(
            'self::relativeArtifactRoot($runtimePaths)',
            $factory,
        );

        foreach (['procWorkerManagerDriver', 'workerManager', 'workerSocketServer'] as $old) {
            self::assertStringNotContainsString($old, $factory);
        }
    }
}
