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

use Coretsia\Contracts\Worker\WorkerTaskInterface;
use Coretsia\Contracts\Worker\WorkerTaskSourceContextInterface;
use Coretsia\Contracts\Worker\WorkerTaskSourceInterface;
use Coretsia\Contracts\Worker\WorkerTaskType;
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

final class ApplicationWorkerMaxRequestsTest extends PackageTestCase
{
    public function testLoopStopsExactlyAtMaxRequestsAndCountsAcquiredTasks(): void
    {
        $root = $this->temporaryDirectory('worker-max-requests');
        $kernel = new RecordingKernelRuntime();
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [
            new RecordingWorkerTask(1),
            new RecordingWorkerTask(2),
            new RecordingWorkerTask(3),
            new RecordingWorkerTask(4),
        ];

        $worker = new ApplicationWorker(
            stopSignal: new WorkerStopSignal($root),
            kernelRuntime: $kernel,
            taskSource: $source,
            stopwatch: new Stopwatch(),
            tracer: new RecordingTracer(),
            meter: new RecordingMeter(),
        );

        $processed = $worker->run(
            WorkerSpecFactory::create(['max_requests' => 3]),
            0,
        );

        self::assertSame(3, $processed);
        self::assertSame(3, $kernel->calls);
        self::assertSame(3, $source->receiveCalls);
    }

    public function testStopFlagIsObservedBeforeNextReceive(): void
    {
        $root = $this->temporaryDirectory('worker-stop-between-tasks');
        $spec = WorkerSpecFactory::create(['max_requests' => 3]);
        $stopSignal = new WorkerStopSignal($root);
        $kernel = new class($stopSignal, $spec) extends RecordingKernelRuntime {
            public function __construct(
                private readonly WorkerStopSignal $stopSignal,
                private readonly \Coretsia\Platform\Worker\Runtime\WorkerPoolSpec $spec,
            ) {
            }

            public function runUnitOfWork(
                string $type,
                callable $body,
                array $attributes = [],
            ): mixed {
                $result = parent::runUnitOfWork($type, $body, $attributes);
                $this->stopSignal->request($this->spec);

                return $result;
            }
        };
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [
            new RecordingWorkerTask(1),
            new RecordingWorkerTask(2),
        ];

        $worker = new ApplicationWorker(
            stopSignal: $stopSignal,
            kernelRuntime: $kernel,
            taskSource: $source,
            stopwatch: new Stopwatch(),
            tracer: new RecordingTracer(),
            meter: new RecordingMeter(),
        );

        self::assertSame(1, $worker->run($spec, 0));
        self::assertSame(1, $kernel->calls);
        self::assertSame(1, $source->receiveCalls);
    }

    public function testCancellationRaisedDuringReceiveReturnsNullAndExitsGracefully(): void
    {
        $root = $this->temporaryDirectory('worker-cancel-during-receive');
        $spec = WorkerSpecFactory::create();
        $stopSignal = new WorkerStopSignal($root);
        $source = new class($stopSignal, $spec) implements WorkerTaskSourceInterface {
            public int $receiveCalls = 0;

            public function __construct(
                private readonly WorkerStopSignal $stopSignal,
                private readonly \Coretsia\Platform\Worker\Runtime\WorkerPoolSpec $spec,
            ) {
            }

            public function taskType(): WorkerTaskType
            {
                return WorkerTaskType::Queue;
            }

            public function assertReady(WorkerTaskSourceContextInterface $context): void
            {
            }

            public function receive(WorkerTaskSourceContextInterface $context): ?WorkerTaskInterface
            {
                ++$this->receiveCalls;
                $this->stopSignal->request($this->spec);

                return null;
            }
        };

        $worker = new ApplicationWorker(
            stopSignal: $stopSignal,
            kernelRuntime: new RecordingKernelRuntime(),
            taskSource: $source,
            stopwatch: new Stopwatch(),
            tracer: new RecordingTracer(),
            meter: new RecordingMeter(),
        );

        self::assertSame(0, $worker->run($spec, 0));
        self::assertSame(1, $source->receiveCalls);
    }

    public function testCancellationBeforeReceiveReturnsZeroWithoutAcquisition(): void
    {
        $root = $this->temporaryDirectory('worker-cancel-before-receive');
        $spec = WorkerSpecFactory::create();
        $stopSignal = new WorkerStopSignal($root);
        $stopSignal->request($spec);
        $source = new RecordingWorkerTaskSource();

        $worker = new ApplicationWorker(
            stopSignal: $stopSignal,
            kernelRuntime: new RecordingKernelRuntime(),
            taskSource: $source,
            stopwatch: new Stopwatch(),
            tracer: new RecordingTracer(),
            meter: new RecordingMeter(),
        );

        self::assertSame(0, $worker->run($spec, 0));
        self::assertSame(0, $source->receiveCalls);
    }
}
