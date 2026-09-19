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

use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLock;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerLifecycleLockCloseOnExecTest extends PackageTestCase
{
    public function testLifecycleLockRequestsCloseOnExecWhenPcntlExecIsAvailable(): void
    {
        $root = $this->temporaryDirectory('worker-lock-close-on-exec');
        $readyFile = $root . '/ready';
        $releaseFile = $root . '/release';

        if (!self::pcntlExecAvailable()) {
            self::assertFalse(self::pcntlExecAvailable());

            $first = new WorkerLifecycleLock($root);
            $first->acquire();
            $first->release();

            $second = new WorkerLifecycleLock($root);
            $second->acquire();
            $second->release();

            self::assertFalse($second->isHeld());

            return;
        }

        $pid = @\pcntl_fork();

        if ($pid === -1) {
            self::fail('Failed to fork the close-on-exec lock fixture.');
        }

        if ($pid === 0) {
            try {
                $lock = new WorkerLifecycleLock($root);
                $lock->acquire();

                @\pcntl_exec(
                    \PHP_BINARY,
                    [
                        self::packageRoot() . '/tests/Fixtures/exec-hold-fixture.php',
                        '--ready-file=' . $readyFile,
                        '--release-file=' . $releaseFile,
                        '--timeout-ms=5000',
                    ],
                );
            } catch (\Throwable) {
                // Fall through to a deterministic child failure.
            }

            exit(1);
        }

        $status = 0;

        try {
            self::waitUntil(
                static fn (): bool => @\file_exists($readyFile),
                failureMessage: 'The exec-created lock fixture did not become ready.',
            );

            $contender = new WorkerLifecycleLock($root);
            $contender->acquire();
            $contender->release();

            self::assertTrue(
                @\file_put_contents($releaseFile, "release\n", \LOCK_EX) !== false,
            );

            self::waitUntil(
                static function () use ($pid, &$status): bool {
                    return @\pcntl_waitpid($pid, $status, \WNOHANG) === $pid;
                },
                failureMessage: 'The exec-created lock fixture did not exit.',
            );

            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
        } finally {
            if (self::processExists($pid)) {
                @\posix_kill($pid, \SIGKILL);
                @\pcntl_waitpid($pid, $status);
            }
        }
    }

    public function testLifecycleLockCanBeExplicitlyDetachedInForkedChildBoundary(): void
    {
        $root = $this->temporaryDirectory('worker-lock-explicit-fork-detach');

        $owner = new WorkerLifecycleLock($root);
        $owner->acquire();

        self::assertTrue($owner->isHeld());

        $owner->detachInForkedChild();

        $contender = new WorkerLifecycleLock($root);

        try {
            $contender->acquire();

            self::assertTrue($contender->isHeld());
        } finally {
            $contender->release();
        }

        self::assertFalse($contender->isHeld());
    }

    private static function pcntlExecAvailable(): bool
    {
        return \PHP_OS_FAMILY !== 'Windows'
            && \function_exists('pcntl_fork')
            && \function_exists('pcntl_exec')
            && \function_exists('pcntl_waitpid')
            && \function_exists('pcntl_wifexited')
            && \function_exists('pcntl_wexitstatus')
            && \function_exists('posix_kill');
    }
}
