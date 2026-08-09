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

final class WorkerSupervisorRecycleTest extends SupervisorIntegrationTestCase
{
    public function testExpectedExitCreatesNewGenerationForSameSlot(): void
    {
        ['harness' => $harness] = $this->newHarness(
            workerOverride: ['workers' => 1],
            behavior: [
                'exit_after_ready' => [
                    'slot' => 0,
                    'code' => 0,
                    'delay_ms' => 100,
                    'first_generation_only' => true,
                    'wait_for_release' => true,
                ],
            ],
        );

        $harness->startAndWaitForSummary();
        $harness->releaseChildExit();

        self::waitUntil(
            static fn (): bool => \count($harness->pidLog()) >= 2,
            3000,
            'Supervisor did not recycle the expected child exit.',
        );

        $records = $harness->pidLog();
        self::assertSame(0, $records[0]['slot']);
        self::assertSame(0, $records[1]['slot']);
        self::assertSame(1, $records[0]['generation']);
        self::assertSame(2, $records[1]['generation']);
        self::assertNotSame($records[0]['pid'], $records[1]['pid']);

        $status = self::onlyPayload($harness->invoke('status'));
        self::assertSame('running', $status['status']);

        self::onlyPayload($harness->invoke('stop'));
        self::assertSame(0, $harness->finishStart()['exit_code']);
    }
}
