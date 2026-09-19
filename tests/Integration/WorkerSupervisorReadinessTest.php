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

use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Tests\Support\SupervisorIntegrationTestCase;

final class WorkerSupervisorReadinessTest extends SupervisorIntegrationTestCase
{
    public function testStatusAndHealthExposeStartingUntilEveryChildIsReady(): void
    {
        ['harness' => $harness] = $this->newHarness(
            workerOverride: [
                'start_timeout_ms' => 10_000,
            ],
            behavior: [
                'ready_gate_slots' => [1],
            ],
        );

        $harness->start();

        self::waitForStateStatus(
            $harness,
            WorkerPoolStatus::STARTING,
        );

        $status = self::onlyPayload(
            $harness->invoke('status'),
        );

        self::assertSame(
            'starting',
            $status['status'],
        );

        $health = self::onlyPayload(
            $harness->invoke('health'),
            expectedExitCode: 1,
        );

        self::assertFalse($health['healthy']);
        self::assertSame(
            'worker-pool-starting',
            $health['reason'],
        );

        /*
         * Readiness is released explicitly only after both live control
         * operations have observed the starting state.
         */
        $harness->releaseReadiness();

        $message = $harness->waitForStartMessage();

        self::assertSame(
            'json',
            $message['type'] ?? null,
        );

        self::assertSame(
            'running',
            $message['payload']['status'] ?? null,
        );

        self::onlyPayload(
            $harness->invoke('stop'),
        );

        self::assertSame(
            0,
            $harness->finishStart()['exit_code'],
        );
    }

    public function testOneUnreadyChildRollsBackEntireStartup(): void
    {
        $startTimeoutMs = 5_000;

        ['harness' => $harness] = $this->newHarness(
            workerOverride: [
                'workers' => 1,
                'start_timeout_ms' => $startTimeoutMs,
            ],
            behavior: [
                'never_ready_slots' => [0],
            ],
        );

        $harness->start();

        self::waitForStateStatus(
            $harness,
            WorkerPoolStatus::STARTING,
        );

        self::waitUntil(
            static fn (): bool => \count($harness->pidLog()) >= 1,
            5_000,
            'The worker child was not spawned before the readiness timeout assertion.',
        );

        $message = $harness->waitForStartMessage(
            $startTimeoutMs + 5_000,
        );

        self::assertSame(
            'error',
            $message['type'] ?? null,
        );

        self::assertSame(
            'worker-readiness-timeout',
            $message['message'] ?? null,
        );

        self::assertNotSame(
            0,
            $harness->finishStart(10_000)['exit_code'],
        );

        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }

    public function testCrashBeforeReadinessNeverPublishesRunning(): void
    {
        ['harness' => $harness] = $this->newHarness(
            behavior: ['crash_before_ready_slots' => [0]],
        );

        $harness->start();
        $message = $harness->waitForStartMessage();
        self::assertSame('error', $message['type'] ?? null);
        self::assertNotSame(0, $harness->finishStart()['exit_code']);
        self::assertFileDoesNotExist($harness->statePath());
        self::assertLoggedChildrenExited($harness);
    }

    public function testStopDuringStartingTerminatesAllSpawnedChildren(): void
    {
        ['harness' => $harness] = $this->newHarness(
            behavior: ['ready_delay_ms' => 1500],
        );

        $harness->start();
        self::waitForStateStatus($harness, WorkerPoolStatus::STARTING);
        $stop = self::onlyPayload($harness->invoke('stop'));
        self::assertSame('stopped', $stop['status']);
        self::assertSame(0, $harness->finishStart()['exit_code']);
        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }
}
