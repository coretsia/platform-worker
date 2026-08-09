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

final class WorkerSupervisorChildFailureTest extends SupervisorIntegrationTestCase
{
    public function testUnexpectedNonZeroExitStopsPoolAndSupervisorFails(): void
    {
        ['harness' => $harness] = $this->newHarness(
            workerOverride: ['workers' => 1],
            behavior: [
                'exit_after_ready' => [
                    'slot' => 0,
                    'code' => 17,
                    'delay_ms' => 100,
                    'first_generation_only' => true,
                    'wait_for_release' => true,
                ],
            ],
        );

        $harness->startAndWaitForSummary();

        $harness->releaseChildExit();

        $finished = $harness->finishStart(
            \PHP_OS_FAMILY === 'Windows'
                ? 10_000
                : 5_000,
        );

        self::assertNotSame(0, $finished['exit_code']);
        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
        self::assertCount(1, $harness->pidLog(), 'Unexpected exit must not enter a crash loop.');
    }
}
