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

final class PcntlWorkerContainerIsolationContractTest extends PackageTestCase
{
    public function testPcntlDriverDelegatesProcessOwnershipToGuardian(): void
    {
        $driver = self::source('src/Process/Driver/PcntlWorkerProcessDriver.php');
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');

        foreach (['pcntl_fork(', 'pcntl_exec(', 'pcntl_waitpid(', 'posix_kill('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $driver);
        }
        self::assertStringContainsString('WorkerProcessGuardianInterface', $driver);
        self::assertStringContainsString('$this->guardian->spawn(', $driver);

        foreach (['pcntl_fork(', 'pcntl_exec(', 'pcntl_waitpid(', 'posix_kill('] as $required) {
            self::assertStringContainsString($required, $guardian);
        }

        $childBranch = \strstr($guardian, 'if ($pid === 0)');
        self::assertIsString($childBranch);
        $close = \strpos($childBranch, '@\\fclose($this->connection)');
        $detach = \strpos($childBranch, 'detachInForkedChild()');
        $reset = \strpos($childBranch, 'resetForkedChildSignals()');
        $chdir = \strpos($childBranch, '@\\chdir($workingDirectory)');
        $exec = \strpos($childBranch, '@\\pcntl_exec(');
        foreach ([$close, $detach, $reset, $chdir, $exec] as $offset) {
            self::assertNotFalse($offset);
        }
        self::assertTrue($close < $detach && $detach < $reset && $reset < $chdir && $chdir < $exec);
    }
}
