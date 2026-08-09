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

namespace Coretsia\Platform\Worker\Tests\Unit;

use Coretsia\Platform\Worker\Runtime\WorkerShutdownBudget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerShutdownBudgetTest extends TestCase
{
    public function testBuildsCanonicalStopRequestTimeout(): void
    {
        self::assertSame(
            5_100,
            WorkerShutdownBudget::stopRequestTimeoutMs(
                stopTimeoutMs: 2_300,
                forceKillTimeoutMs: 400,
            ),
        );
        self::assertSame(
            2_000,
            WorkerShutdownBudget::CLEANUP_TIMEOUT_MS,
        );
    }

    public function testTaskSourceBlockingWaitUsesCanonicalBoundedBudget(): void
    {
        self::assertSame(
            1_000,
            WorkerShutdownBudget::taskSourceBlockingWaitMs(10_000),
        );
        self::assertSame(
            750,
            WorkerShutdownBudget::taskSourceBlockingWaitMs(750),
        );
    }

    #[DataProvider('invalidTaskSourceTimeoutProvider')]
    public function testTaskSourceBlockingWaitRejectsInvalidTimeout(int $stopTimeoutMs): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorkerShutdownBudget::taskSourceBlockingWaitMs($stopTimeoutMs);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidTaskSourceTimeoutProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'oversized' => [86_400_001];
    }

    #[DataProvider('invalidTimeoutProvider')]
    public function testRejectsInvalidPhaseTimeouts(
        int $stopTimeoutMs,
        int $forceKillTimeoutMs,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        WorkerShutdownBudget::stopRequestTimeoutMs(
            $stopTimeoutMs,
            $forceKillTimeoutMs,
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidTimeoutProvider(): iterable
    {
        yield 'zero stop' => [0, 1];
        yield 'oversized stop' => [86_400_001, 1];
        yield 'zero force' => [1, 0];
        yield 'oversized force' => [1, 86_400_001];
    }
}
