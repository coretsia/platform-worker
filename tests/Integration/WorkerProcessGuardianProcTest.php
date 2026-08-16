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

use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianClient;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLock;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerProcessGuardianProcTest extends PackageTestCase
{
    public function testGuardianOwnsProcHostGenerationAndTerminalRelease(): void
    {
        self::assertTrue(
            WorkerProcessCapabilities::procDriverAvailable(),
            'PROC guardian backend must be available in the integration-test environment.',
        );

        $root = $this->temporaryDirectory('guardian-proc');
        $spec = WorkerSpecFactory::create(['workers' => 1, 'driver' => 'proc']);
        $guardian = new WorkerProcessGuardianClient(
            command: [\PHP_BINARY, self::packageRoot() . '/bin/coretsia-worker-guardian'],
            bootstrapWorkingDirectory: self::frameworkRoot(),
            skeletonRoot: $root,
            protocol: new WorkerProcessGuardianProtocol(),
            bootstrapLauncher: new WorkerProcessBootstrapLauncher(
                new WorkerProcessBootstrapProtocol(),
            ),
        );
        $probe = new WorkerLifecycleLock($root);

        $guardian->claim($spec, 'proc');
        self::assertTrue($probe->isHeld());
        $child = $guardian->spawn([\PHP_BINARY, '-r', 'usleep(5000000);'], $root, 2_000);
        self::assertNull($guardian->pollExit($child->id(), 1_000));
        $guardian->terminate($child->id(), 1_000);
        self::waitUntil(static fn (): bool => $guardian->pollExit($child->id(), 1_000) !== null, 5_000);
        $guardian->close($child->id(), 1_000);
        $guardian->release(5_000);
        self::assertFalse($probe->isHeld());
    }
}
