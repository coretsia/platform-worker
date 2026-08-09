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

use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerStopSignal;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\RecordingKernelRuntime;
use Coretsia\Platform\Worker\Tests\Support\RecordingMeter;
use Coretsia\Platform\Worker\Tests\Support\RecordingTracer;
use Coretsia\Platform\Worker\Tests\Support\RecordingWorkerTask;
use Coretsia\Platform\Worker\Tests\Support\RecordingWorkerTaskSource;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;

final class ApplicationWorkerTest extends PackageTestCase
{
    public function testReadyPreflightReceivesSafeWorkerContext(): void
    {
        $root = $this->temporaryDirectory('worker-application-ready');
        $source = new RecordingWorkerTaskSource();
        $worker = $this->worker($root, $source);
        $spec = WorkerSpecFactory::create([
            'workers' => 3,
            'stop_timeout_ms' => 700,
        ]);

        $worker->assertReady($spec, 1);

        self::assertSame(1, $source->assertReadyCalls);
        self::assertSame(0, $source->receiveCalls);
        self::assertSame(
            [
                'worker_index' => 1,
                'worker_count' => 3,
                'max_blocking_wait_ms' => 700,
            ],
            $source->contexts[0],
        );
    }

    public function testReadyRejectsTaskSourceTypeMismatch(): void
    {
        $root = $this->temporaryDirectory('worker-application-ready-type-mismatch');
        $source = new RecordingWorkerTaskSource(
            WorkerTaskType::Http,
        );

        try {
            $this->worker($root, $source)->assertReady(
                WorkerSpecFactory::create(['task_type' => 'queue']),
                0,
            );
            self::fail('Expected worker task-source type failure.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(
                WorkerStartFailedException::REASON_TASK_SOURCE_INVALID,
                $exception->reason(),
            );
        }

        self::assertSame(0, $source->assertReadyCalls);
        self::assertSame(0, $source->receiveCalls);
    }

    public function testReadyFailureIsMappedToSafeStartupReason(): void
    {
        $root = $this->temporaryDirectory('worker-application-ready-failure');
        $source = new RecordingWorkerTaskSource();
        $source->readyFailure = new \RuntimeException('private-ready-failure');

        try {
            $this->worker($root, $source)->assertReady(
                WorkerSpecFactory::create(),
                0,
            );
            self::fail('Expected worker startup failure.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(
                WorkerStartFailedException::REASON_TASK_SOURCE_NOT_READY,
                $exception->reason(),
            );
            self::assertStringNotContainsString(
                'private-ready-failure',
                $exception->getMessage(),
            );
        }
    }

    public function testAcquiredTaskUsesKernelRuntimeSettlementAndCanonicalObservability(): void
    {
        $root = $this->temporaryDirectory('worker-application');
        $kernel = new RecordingKernelRuntime();
        $task = new RecordingWorkerTask('done');
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [$task];
        $tracer = new RecordingTracer();
        $meter = new RecordingMeter();

        $worker = new ApplicationWorker(
            stopSignal: new WorkerStopSignal($root),
            kernelRuntime: $kernel,
            taskSource: $source,
            stopwatch: new Stopwatch(),
            tracer: $tracer,
            meter: $meter,
        );

        $processed = $worker->run(
            WorkerSpecFactory::create(['max_requests' => 1]),
            0,
        );

        self::assertSame(1, $processed);
        self::assertSame(['queue'], $kernel->types);
        self::assertSame(1, $kernel->calls);
        self::assertSame(1, $task->executeCalls);
        self::assertSame(1, $task->completeCalls);
        self::assertSame('done', $task->completedResult);
        self::assertSame(0, $task->failCalls);

        self::assertCount(1, $tracer->spans);
        self::assertSame('worker.task', $tracer->spans[0]->name());
        self::assertSame(
            ['operation' => 'queue', 'outcome' => 'success'],
            $tracer->spans[0]->attributes,
        );
        self::assertSame('worker.task_total', $meter->increments[0]['name']);
        self::assertSame(
            ['operation' => 'queue', 'outcome' => 'success'],
            $meter->increments[0]['labels'],
        );
        self::assertSame('worker.task_duration_ms', $meter->observations[0]['name']);
    }

    public function testTaskFailureUsesFailureSettlementKeepsFailureLabelsAndRethrows(): void
    {
        $root = $this->temporaryDirectory('worker-application-failure');
        $failure = new \RuntimeException('task-failure');
        $task = new RecordingWorkerTask();
        $task->executeFailure = $failure;
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [$task];
        $tracer = new RecordingTracer();
        $meter = new RecordingMeter();

        try {
            $this->worker($root, $source, new RecordingKernelRuntime(), $tracer, $meter)
                ->run(WorkerSpecFactory::create(['max_requests' => 1]), 0);
            self::fail('Expected task failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $task->failCalls);
        self::assertSame($failure, $task->failedWith);
        self::assertSame(0, $task->completeCalls);
        self::assertSame(
            ['operation' => 'queue', 'outcome' => 'failure'],
            $tracer->spans[0]->attributes,
        );
        self::assertSame(
            ['operation' => 'queue', 'outcome' => 'failure'],
            $meter->increments[0]['labels'],
        );
    }

    public function testCompleteFailureDoesNotInvokeFailureSettlement(): void
    {
        $root = $this->temporaryDirectory('worker-complete-failure');
        $task = new RecordingWorkerTask('done');
        $task->completeFailure = new \RuntimeException('private-complete-failure');
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [$task];

        self::assertLifecycleReason(
            WorkerLifecycleFailedException::REASON_TASK_SETTLEMENT_FAILED,
            fn (): int => $this->worker($root, $source)
                ->run(WorkerSpecFactory::create(['max_requests' => 1]), 0),
        );

        self::assertSame(1, $task->completeCalls);
        self::assertSame(0, $task->failCalls);
    }

    public function testFailureSettlementFailureUsesDeterministicLifecycleReason(): void
    {
        $root = $this->temporaryDirectory('worker-fail-settlement-failure');
        $task = new RecordingWorkerTask();
        $task->executeFailure = new \RuntimeException('private-task-failure');
        $task->failFailure = new \RuntimeException('private-settlement-failure');
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [$task];

        self::assertLifecycleReason(
            WorkerLifecycleFailedException::REASON_TASK_SETTLEMENT_FAILED,
            fn (): int => $this->worker($root, $source)
                ->run(WorkerSpecFactory::create(['max_requests' => 1]), 0),
        );
    }

    public function testReceiveFailureUsesDeterministicLifecycleReason(): void
    {
        $root = $this->temporaryDirectory('worker-receive-failure');
        $source = new RecordingWorkerTaskSource();
        $source->receiveFailure = new \RuntimeException('private-receive-failure');

        self::assertLifecycleReason(
            WorkerLifecycleFailedException::REASON_TASK_SOURCE_RECEIVE_FAILED,
            fn (): int => $this->worker($root, $source)
                ->run(WorkerSpecFactory::create(), 0),
        );
    }

    public function testNullWithoutCancellationIsUnexpectedSourceTermination(): void
    {
        $root = $this->temporaryDirectory('worker-source-terminated');
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [null];

        self::assertLifecycleReason(
            WorkerLifecycleFailedException::REASON_TASK_SOURCE_TERMINATED,
            fn (): int => $this->worker($root, $source)
                ->run(WorkerSpecFactory::create(), 0),
        );
    }

    public function testObservabilityFailuresDoNotChangeTaskOutcome(): void
    {
        $root = $this->temporaryDirectory('worker-application-noop');
        $task = new RecordingWorkerTask(42);
        $source = new RecordingWorkerTaskSource();
        $source->tasks = [$task];
        $tracer = new RecordingTracer();
        $tracer->throwOnStart = true;
        $meter = new RecordingMeter();
        $meter->throw = true;

        self::assertSame(
            1,
            $this->worker($root, $source, new RecordingKernelRuntime(), $tracer, $meter)
                ->run(WorkerSpecFactory::create(['max_requests' => 1]), 0),
        );
        self::assertSame(42, $task->completedResult);
    }

    private function worker(
        string $root,
        RecordingWorkerTaskSource $source,
        ?RecordingKernelRuntime $kernel = null,
        ?RecordingTracer $tracer = null,
        ?RecordingMeter $meter = null,
    ): ApplicationWorker {
        return new ApplicationWorker(
            stopSignal: new WorkerStopSignal($root),
            kernelRuntime: $kernel ?? new RecordingKernelRuntime(),
            taskSource: $source,
            stopwatch: new Stopwatch(),
            tracer: $tracer ?? new RecordingTracer(),
            meter: $meter ?? new RecordingMeter(),
        );
    }

    private static function assertLifecycleReason(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected worker lifecycle failure.');
        } catch (WorkerLifecycleFailedException $exception) {
            self::assertSame($reason, $exception->reason());
        }
    }
}
