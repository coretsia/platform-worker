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

use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Runtime\WorkerStopSignal;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\RecordingKernelRuntime;
use Coretsia\Platform\Worker\Tests\Support\RecordingMeter;
use Coretsia\Platform\Worker\Tests\Support\RecordingTracer;
use Coretsia\Platform\Worker\Tests\Support\RecordingWorkerTask;
use Coretsia\Platform\Worker\Tests\Support\RecordingWorkerTaskSource;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;

final class WorkerHandlesMultipleTasksSequentiallyTest extends PackageTestCase
{
    public function testEachAcquiredTaskUsesSeparateKernelRuntimeBoundary(): void
    {
        $root = $this->temporaryDirectory('worker-sequential');
        $runtime = new RecordingKernelRuntime();
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [
            new RecordingWorkerTask(1),
            new RecordingWorkerTask(2),
            new RecordingWorkerTask(3),
        ];
        $worker = new ApplicationWorker(
            new WorkerStopSignal($root),
            $runtime,
            $source,
            new Stopwatch(),
            new RecordingTracer(),
            new RecordingMeter(),
        );

        $processed = $worker->run(
            WorkerSpecFactory::create(['workers' => 1, 'max_requests' => 3]),
            0,
        );

        self::assertSame(3, $processed);
        self::assertSame(['queue', 'queue', 'queue'], $runtime->types);
        self::assertSame(3, $source->receiveCalls);
        foreach ($source->tasks as $_remaining) {
            self::fail('No task should remain after max_requests=3.');
        }
    }
}
