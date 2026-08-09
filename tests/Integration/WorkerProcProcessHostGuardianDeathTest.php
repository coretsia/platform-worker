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
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerProcProcessHostGuardianDeathTest extends PackageTestCase
{
    public function testProcHostCleansChildrenWhenItsGuardianOwnerDisappears(): void
    {
        self::assertTrue(
            WorkerProcessCapabilities::procDriverAvailable(),
            'PROC guardian backend must be available in the integration-test environment.',
        );

        $root = $this->temporaryDirectory('proc-host-guardian-death');
        ['process' => $owner, 'pipes' => $pipes] = self::startProcess(
            [
                \PHP_BINARY,
                self::packageRoot() . '/tests/Fixtures/proc-host-owner.php',
                self::frameworkRoot(),
                $root,
            ],
            self::frameworkRoot(),
        );

        try {
            $readyPath = $root . '/proc-host-owner.ready';
            self::waitUntil(
                static fn (): bool => \is_file($readyPath),
                7_000,
                'Guardian-side proc-host owner did not become ready.',
            );

            $bytes = @\file_get_contents($readyPath);
            self::assertIsString($bytes);
            $ready = \json_decode($bytes, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($ready);
            $ownerPid = $ready['owner_pid'] ?? null;
            $childPid = $ready['child_pid'] ?? null;
            self::assertIsInt($ownerPid);
            self::assertIsInt($childPid);
            self::assertTrue(self::processExists($childPid));

            if (\PHP_OS_FAMILY === 'Windows') {
                self::assertTrue(
                    @\proc_terminate($owner, 9),
                    'Failed to terminate the guardian-side proc-host owner on Windows.',
                );
            } else {
                self::assertTrue(
                    \function_exists('posix_kill'),
                    'POSIX guardian-death coverage requires posix_kill().',
                );
                self::assertTrue(
                    \defined('SIGKILL'),
                    'POSIX guardian-death coverage requires SIGKILL.',
                );
                self::assertTrue(
                    @\posix_kill($ownerPid, \SIGKILL),
                    'Failed to kill the guardian-side proc-host owner.',
                );

                /*
                 * Defensive proc-handle termination only. The PID-directed SIGKILL above
                 * is the operation whose success establishes the intended failure mode.
                 */
                @\proc_terminate($owner, \SIGKILL);
            }

            self::waitUntil(
                static fn (): bool => !self::processExists($childPid),
                10_000,
                'ProcHost did not clean its child after guardian-side owner death.',
            );
        } finally {
            $result = self::finishProcess($owner, $pipes, 3_000);
            self::assertNotSame(0, $result['exit_code']);
        }
    }
}
