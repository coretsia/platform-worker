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
use Coretsia\Platform\Worker\Tests\Support\WorkerCommandHarness;

final class WorkerSupervisorGuardianFenceRaceTest extends SupervisorIntegrationTestCase
{
    public function testReplacementNeverOverlapsOldGenerationAfterSupervisorDeath(): void
    {
        $windows = \PHP_OS_FAMILY === 'Windows';

        $behavior = $windows
            ? []
            : ['ignore_termination_signal_slots' => [0]];

        ['root' => $root, 'harness' => $first] = $this->newHarness(
            workerOverride: [
                'workers' => 1,
                'stop_timeout_ms' => 1_500,
                'force_kill_timeout_ms' => 500,
            ],
            behavior: $behavior,
        );

        $summary = $first->startAndWaitForSummary();

        self::assertSame('running', $summary['status']);

        self::waitUntil(
            static fn (): bool => \count($first->pidLog()) >= 1,
            3_000,
        );

        $oldPid = $first->pidLog()[0]['pid'];

        $first->crashSupervisorOnly();

        if (!$windows) {
            /*
             * The TERM-ignoring POSIX worker keeps the old guardian fence held
             * long enough to prove fail-fast generation exclusion.
             */
            self::assertFalse(
                $first->lifecycleLockAvailable(),
            );
        }

        $racing = new WorkerCommandHarness(
            skeletonRoot: $root,
            workerOverride: $first->workerConfig(),
            behavior: $behavior,
        );

        try {
            $racing->start();

            $message = $racing->waitForStartMessage(
                $windows ? 20_000 : 5_000,
            );

            if (($message['type'] ?? null) === 'json') {
                /*
                 * The racing start may reach the generation fence either before
                 * or after guardian cleanup releases it. A successful start is
                 * valid only after the old worker generation is completely gone;
                 * otherwise the two generations overlapped.
                 */
                $payload = $message['payload'] ?? null;

                self::assertIsArray($payload);
                self::assertSame(
                    'running',
                    $payload['status'] ?? null,
                );

                self::assertFalse(
                    self::processExists($oldPid),
                    'Replacement became running while an old worker was still alive.',
                );

                self::assertSame(
                    'stopped',
                    self::onlyPayload(
                        $racing->invoke('stop'),
                    )['status'],
                );

                self::assertSame(
                    0,
                    $racing->finishStart()['exit_code'],
                );

                return;
            }

            self::assertSame(
                'error',
                $message['type'] ?? null,
            );

            self::assertSame(
                'CORETSIA_WORKER_ALREADY_RUNNING',
                $message['code'] ?? null,
            );

            self::assertNotSame(
                0,
                $racing->finishStart(10_000)['exit_code'],
            );
        } finally {
            $racing->close();
        }

        /*
         * If racing start was rejected, the old guardian was still recovering.
         * Wait until the old generation is completely gone and its fence is free.
         */
        $first->waitForLoggedChildrenExit(
            [$oldPid],
            10_000,
        );

        self::waitUntil(
            static fn (): bool => $first->lifecycleLockAvailable(),
            10_000,
            'Old guardian did not release the fence after worker cleanup.',
        );

        self::assertFalse(
            self::processExists($oldPid),
        );

        $replacement = new WorkerCommandHarness(
            skeletonRoot: $root,
            workerOverride: $first->workerConfig(),
        );

        try {
            $replacementSummary = $replacement->startAndWaitForSummary(
                $windows ? 20_000 : 5_000,
            );

            self::assertSame(
                'running',
                $replacementSummary['status'],
            );

            self::assertSame(
                'stopped',
                self::onlyPayload(
                    $replacement->invoke('stop'),
                )['status'],
            );

            self::assertSame(
                0,
                $replacement->finishStart()['exit_code'],
            );
        } finally {
            $replacement->close();
        }
    }
}
