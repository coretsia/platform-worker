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

use Coretsia\Platform\Worker\Tests\Support\SupervisorIntegrationTestCase;

final class WorkerSupervisorSignalShutdownTest extends SupervisorIntegrationTestCase
{
    public function testPlatformShutdownUsesDeterministicCleanup(): void
    {
        ['harness' => $harness] = $this->newHarness();

        $harness->startAndWaitForSummary();

        if (\PHP_OS_FAMILY === 'Windows') {
            /*
             * A non-interactive PHPUnit subprocess cannot reliably generate a
             * console CTRL+C/CTRL+BREAK event. Verify the same supervisor-owned
             * shutdown and cleanup path through the live control channel.
             */
            $stop = self::onlyPayload(
                $harness->invoke('stop'),
            );

            self::assertSame(
                'stopped',
                $stop['status'],
            );
        } else {
            self::assertTrue(
                \defined('SIGTERM'),
            );

            $harness->terminateStart(
                \SIGTERM,
            );
        }

        $finished = $harness->finishStart();

        self::assertSame(
            0,
            $finished['exit_code'],
            $finished['stderr'],
        );

        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }
}
