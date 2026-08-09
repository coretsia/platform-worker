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

use Coretsia\Platform\Worker\Communication\WorkerChildReadinessEndpoint;
use Coretsia\Platform\Worker\Process\WorkerChildProcess;
use Coretsia\Platform\Worker\Supervisor\WorkerChildTable;
use PHPUnit\Framework\TestCase;

final class WorkerChildTableTest extends TestCase
{
    public function testTableMaintainsSortedTypedSlotsAndReadiness(): void
    {
        $table = new WorkerChildTable();
        $second = self::child(1, 2002);
        $first = self::child(0, 2001);

        $table->add($second);
        $table->add($first);

        self::assertSame(
            [0, 1],
            \array_map(
                static fn (WorkerChildProcess $child): int => $child->workerIndex(),
                $table->all(),
            )
        );
        self::assertSame(2, $table->count());
        self::assertSame(0, $table->readyCount());

        $table->markReady(0);

        self::assertTrue($table->isReady(0));
        self::assertFalse($table->isReady(1));
        self::assertSame(
            [1],
            \array_map(
                static fn (WorkerChildProcess $child): int => $child->workerIndex(),
                $table->unready(),
            )
        );

        self::assertSame($first, $table->remove(0));
        self::assertSame(1, $table->count());
    }

    public function testDuplicateAndMissingSlotsAreRejected(): void
    {
        $table = new WorkerChildTable();
        $child = self::child(0, 2001);
        $table->add($child);

        try {
            $table->add(self::child(0, 2002));
            self::fail('Expected duplicate slot rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('worker-child-table-duplicate', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $table->markReady(9);
    }

    private static function child(int $slot, int $pid): WorkerChildProcess
    {
        $stream = \tmpfile();

        self::assertIsResource($stream);

        return new WorkerChildProcess(
            workerIndex: $slot,
            pid: $pid,
            driverName: 'pcntl',
            processHandle: 'child-' . ($slot + 1),
            readinessEndpoint: WorkerChildReadinessEndpoint::stream($stream),
            generation: 1,
            startedAtNs: \hrtime(true),
        );
    }
}
