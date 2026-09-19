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

use Coretsia\Platform\Worker\Runtime\WorkerStopSignal;
use Coretsia\Platform\Worker\Runtime\WorkerTaskSourceContext;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkerTaskSourceContextTest extends PackageTestCase
{
    #[DataProvider('invalidContextProvider')]
    public function testRejectsInvalidContextValues(
        int $workerIndex,
        int $workerCount,
        int $maxBlockingWaitMs,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new WorkerTaskSourceContext(
            workerIndex: $workerIndex,
            workerCount: $workerCount,
            maxBlockingWaitMs: $maxBlockingWaitMs,
            stopSignal: new WorkerStopSignal($this->temporaryDirectory('worker-task-context-invalid')),
            spec: WorkerSpecFactory::create(),
        );
    }

    public function testExposesOnlySafeWorkerCoordinatesBudgetAndCancellation(): void
    {
        $root = $this->temporaryDirectory('worker-task-context');
        $spec = WorkerSpecFactory::create(['workers' => 3]);
        $stopSignal = new WorkerStopSignal($root);
        $context = new WorkerTaskSourceContext(
            workerIndex: 1,
            workerCount: 3,
            maxBlockingWaitMs: 750,
            stopSignal: $stopSignal,
            spec: $spec,
        );

        self::assertSame(1, $context->workerIndex());
        self::assertSame(3, $context->workerCount());
        self::assertSame(750, $context->maxBlockingWaitMs());
        self::assertFalse($context->cancellationRequested());

        $stopSignal->request($spec);

        self::assertTrue($context->cancellationRequested());
    }

    /** @return iterable<string, array{int,int,int}> */
    public static function invalidContextProvider(): iterable
    {
        yield 'negative index' => [-1, 1, 1];
        yield 'zero workers' => [0, 0, 1];
        yield 'index outside pool' => [2, 2, 1];
        yield 'zero blocking wait' => [0, 1, 0];
    }
}
