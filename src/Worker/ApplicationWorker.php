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

namespace Coretsia\Platform\Worker\Worker;

use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Contracts\Worker\WorkerTaskInterface;
use Coretsia\Contracts\Worker\WorkerTaskSourceInterface;
use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerShutdownBudget;
use Coretsia\Platform\Worker\Runtime\WorkerStopSignal;
use Coretsia\Platform\Worker\Runtime\WorkerTaskSourceContext;

/**
 * Sequential long-running application worker.
 *
 * ApplicationWorker owns the child-process task loop. It receives real tasks
 * from the selected WorkerTaskSourceInterface and delegates each task body to
 * the canonical KernelRuntime UoW boundary.
 *
 * It does not implement queue or HTTP transport logic, does not invoke kernel
 * hooks directly, does not enumerate reset tags, and does not call the reset
 * orchestrator directly.
 *
 * KernelRuntime owns UoW ids, correlation ids, base context, hooks, and reset.
 * The task source owns acquisition; WorkerTaskInterface owns transport-specific
 * success and failure settlement.
 *
 * Task metrics use only allowlisted labels:
 *
 * - operation
 * - outcome
 *
 * `operation` is restricted to the closed WorkerTaskType vocabulary.
 *
 * This class must not write to stdout/stderr and must not log raw payloads,
 * endpoints, absolute paths, headers, cookies, authorization data, tokens,
 * body fragments, config dumps, or environment values.
 */
final readonly class ApplicationWorker
{
    private const string SPAN_WORKER_TASK = 'worker.task';

    private const string METRIC_WORKER_TASK_TOTAL = 'worker.task_total';
    private const string METRIC_WORKER_TASK_DURATION_MS = 'worker.task_duration_ms';

    private const string OUTCOME_SUCCESS = 'success';
    private const string OUTCOME_FAILURE = 'failure';

    public function __construct(
        private WorkerStopSignal $stopSignal,
        private KernelRuntimeInterface $kernelRuntime,
        private WorkerTaskSourceInterface $taskSource,
        private Stopwatch $stopwatch,
        private TracerPortInterface $tracer,
        private MeterPortInterface $meter,
    ) {
    }

    /**
     * Verifies selected task-source readiness before the child readiness frame
     * is published.
     *
     * This preflight must not acquire, acknowledge, execute, or consume a task.
     */
    public function assertReady(WorkerPoolSpec $spec, int $workerIndex): void
    {
        $expectedType = WorkerTaskType::from($spec->taskType());

        try {
            $actualType = $this->taskSource->taskType();
        } catch (\Throwable) {
            throw WorkerStartFailedException::taskSourceInvalid();
        }

        if ($actualType !== $expectedType) {
            throw WorkerStartFailedException::taskSourceInvalid();
        }

        try {
            $this->taskSource->assertReady(
                $this->sourceContext($spec, $workerIndex),
            );
        } catch (\Throwable) {
            throw WorkerStartFailedException::taskSourceNotReady();
        }
    }

    /**
     * Runs until max_requests real tasks have been acquired or cooperative
     * cancellation is observed.
     *
     * max_requests counts acquired task attempts. Idle transport wake-ups and
     * cooperative cancellation do not consume the budget.
     */
    public function run(WorkerPoolSpec $spec, int $workerIndex): int
    {
        $processed = 0;
        $context = $this->sourceContext($spec, $workerIndex);

        while ($processed < $spec->maxRequests()) {
            if ($context->cancellationRequested()) {
                break;
            }

            try {
                $task = $this->taskSource->receive($context);
            } catch (\Throwable) {
                throw WorkerLifecycleFailedException::taskSourceReceiveFailed();
            }

            if ($task === null) {
                if ($context->cancellationRequested()) {
                    break;
                }

                throw WorkerLifecycleFailedException::taskSourceTerminated();
            }

            ++$processed;

            $this->runTask(
                spec: $spec,
                task: $task,
            );
        }

        return $processed;
    }

    /**
     * Executes one acquired task and performs exactly one settlement path.
     */
    private function runTask(
        WorkerPoolSpec $spec,
        WorkerTaskInterface $task,
    ): mixed {
        $taskType = WorkerTaskType::tryFrom(
            $spec->taskType(),
        );

        if ($taskType === null) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        $operationId = $taskType->value;

        $labels = self::taskLabels(
            operationId: $operationId,
            outcome: self::OUTCOME_SUCCESS,
        );

        $span = $this->startTaskSpan($labels);
        $startedAt = $this->safeStartTimer();
        $outcome = self::OUTCOME_SUCCESS;

        try {
            try {
                $result = $this->kernelRuntime->runUnitOfWork(
                    type: $spec->taskType(),
                    body: static fn (): mixed => $task->execute(),
                );
            } catch (\Throwable $failure) {
                $outcome = self::OUTCOME_FAILURE;

                try {
                    $task->fail($failure);
                } catch (\Throwable) {
                    throw WorkerLifecycleFailedException::taskSettlementFailed();
                }

                throw $failure;
            }

            try {
                $task->complete($result);
            } catch (\Throwable) {
                $outcome = self::OUTCOME_FAILURE;

                throw WorkerLifecycleFailedException::taskSettlementFailed();
            }

            return $result;
        } finally {
            $durationMs = $this->safeDurationMs($startedAt);
            $labels = self::taskLabels(
                operationId: $operationId,
                outcome: $outcome,
            );

            $this->finishTaskSpan($span, $labels);
            $this->emitTaskMetrics(
                durationMs: $durationMs,
                operationId: $operationId,
                outcome: $outcome,
            );
        }
    }

    private function sourceContext(
        WorkerPoolSpec $spec,
        int $workerIndex,
    ): WorkerTaskSourceContext {
        return new WorkerTaskSourceContext(
            workerIndex: $workerIndex,
            workerCount: $spec->workers(),
            maxBlockingWaitMs: WorkerShutdownBudget::taskSourceBlockingWaitMs(
                $spec->stopTimeoutMs(),
            ),
            stopSignal: $this->stopSignal,
            spec: $spec,
        );
    }

    private static function isSafeOperationId(string $operationId): bool
    {
        return WorkerTaskType::tryFrom($operationId) !== null;
    }

    /**
     * @return array{operation: string, outcome: string}
     */
    private static function taskLabels(string $operationId, string $outcome): array
    {
        if (!self::isSafeOperationId($operationId)) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        if ($outcome !== self::OUTCOME_SUCCESS && $outcome !== self::OUTCOME_FAILURE) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        return [
            'operation' => $operationId,
            'outcome' => $outcome,
        ];
    }

    /**
     * @param array{operation: string, outcome: string} $labels
     */
    private function startTaskSpan(array $labels): ?SpanInterface
    {
        try {
            return $this->tracer->startSpan(self::SPAN_WORKER_TASK, $labels);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{operation: string, outcome: string} $labels
     */
    private function finishTaskSpan(?SpanInterface $span, array $labels): void
    {
        if ($span === null) {
            return;
        }

        try {
            $span->setAttributes($labels);
        } catch (\Throwable) {
        }

        try {
            $span->end();
        } catch (\Throwable) {
        }
    }

    private function emitTaskMetrics(
        int $durationMs,
        string $operationId,
        string $outcome,
    ): void {
        if (!self::isSafeOperationId($operationId)) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        if ($outcome !== self::OUTCOME_SUCCESS && $outcome !== self::OUTCOME_FAILURE) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        $labels = [
            'operation' => $operationId,
            'outcome' => $outcome,
        ];

        try {
            $this->meter->increment(self::METRIC_WORKER_TASK_TOTAL, 1, $labels);
            $this->meter->observe(self::METRIC_WORKER_TASK_DURATION_MS, $durationMs, $labels);
        } catch (\Throwable) {
        }
    }

    private function safeStartTimer(): mixed
    {
        try {
            return $this->stopwatch->start();
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeDurationMs(mixed $startedAt): int
    {
        if (!\is_int($startedAt) || $startedAt <= 0) {
            return 0;
        }

        try {
            $durationMs = $this->stopwatch->stop($startedAt);

            return $durationMs >= 0 ? $durationMs : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
