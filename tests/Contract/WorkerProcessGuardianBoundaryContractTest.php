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

final class WorkerProcessGuardianBoundaryContractTest extends PackageTestCase
{
    public function testGuardianIsTheOnlyRuntimeOwnerOfGenerationFenceAndRawPcntlLifecycle(): void
    {
        $supervisor = self::source('src/Supervisor/WorkerSupervisor.php');
        $pcntl = self::source('src/Process/Driver/PcntlWorkerProcessDriver.php');
        $proc = self::source('src/Process/Driver/ProcWorkerProcessDriver.php');
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        $procHost = self::source('bin/coretsia-worker-proc-host');

        self::assertStringNotContainsString('WorkerLifecycleLock', $supervisor);
        self::assertStringContainsString('$this->guardian->claim(', $supervisor);
        self::assertStringContainsString('$this->guardian->release(', $supervisor);

        foreach (['pcntl_fork(', 'pcntl_exec(', 'pcntl_waitpid(', 'posix_kill('] as $rawPcntl) {
            self::assertStringNotContainsString($rawPcntl, $pcntl);
            self::assertStringContainsString($rawPcntl, $guardian);
        }
        self::assertStringNotContainsString('proc_open(', $proc);
        self::assertStringNotContainsString('WorkerProcProcessHostClient', $proc);
        self::assertStringContainsString('proc_open(', $procHost);

        self::assertStringContainsString('WorkerLifecycleLock', $guardian);
        self::assertStringContainsString('$lock->acquire()', $guardian);
        self::assertStringContainsString('$this->lifecycleLock?->release()', $guardian);
    }

    public function testGuardianCannotOwnSupervisorArtifacts(): void
    {
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        foreach (
            [
                'WorkerStateStore',
                'WorkerLifecycleLocatorStore',
                'WorkerControlServer',
                'WorkerStopSignal'
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $guardian);
        }
    }
}
