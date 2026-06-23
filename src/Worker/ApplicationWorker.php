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

use Coretsia\Contracts\Context\ContextAccessorInterface;
use Coretsia\Contracts\Context\ContextKeys;
use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Sequential long-running application worker.
 *
 * ApplicationWorker owns the child-process task loop. It executes many tasks
 * sequentially without restarting PHP and delegates each task to the canonical
 * KernelRuntime UoW boundary.
 *
 * It does not create task sources, does not implement queue adapter logic, does
 * not implement HTTP adapter logic, does not invoke kernel hooks directly, does
 * not enumerate reset tags, and does not call ResetOrchestrator directly.
 *
 * KernelRuntime owns:
 *
 * - UoW id creation;
 * - correlation id creation;
 * - base ContextStore writes;
 * - before/after hook invocation;
 * - reset orchestration.
 *
 * ApplicationWorker may read only safe context keys from the public
 * contract-level ContextKeys vocabulary for observability correlation. It must
 * not write context values.
 *
 * Observability dependencies are injected. This class must not instantiate noop
 * logger, tracer, meter, or observability adapters directly.
 *
 * Task metrics use only allowlisted labels:
 *
 * - operation
 * - outcome
 *
 * The resolved worker task type is passed to KernelRuntime as the UoW type. It
 * must not be read from WorkerPoolSpec and used directly as a metric label.
 * Metric label `operation` comes from package-internal task work only.
 *
 * This class must not write to stdout/stderr and must not log raw payloads,
 * raw endpoints, absolute paths, headers, cookies, Authorization values,
 * tokens, body fragments, config dumps, or environment values.
 *
 * @phpstan-import-type WorkerTaskWork from TaskFactoryInternalInterface
 */
final readonly class ApplicationWorker
{
    private const string SPAN_WORKER_TASK = 'worker.task';

    private const string METRIC_WORKER_TASK_TOTAL = 'worker.task_total';
    private const string METRIC_WORKER_TASK_DURATION_MS = 'worker.task_duration_ms';

    private const string OUTCOME_SUCCESS = 'success';
    private const string OUTCOME_FAILURE = 'failure';

    private readonly string $skeletonRoot;

    public function __construct(
        string $skeletonRoot,
        private KernelRuntimeInterface $kernelRuntime,
        private TaskFactoryInternalInterface $taskFactory,
        private ContextAccessorInterface $context,
        private Stopwatch $stopwatch,
        private TracerPortInterface $tracer,
        private MeterPortInterface $meter,
    ) {
        $this->skeletonRoot = self::normalizeSkeletonRoot($skeletonRoot);
    }

    /**
     * Runs the worker child loop until max_requests is reached or a graceful
     * stop request is observed between tasks.
     *
     * The loop checks the stop flag only between tasks. It must not interrupt an
     * in-flight task unless future cancellation semantics are explicitly
     * introduced.
     *
     * Returns the number of task iterations completed by this worker process.
     */
    public function run(WorkerPoolSpec $spec): int
    {
        $processed = 0;

        while ($processed < $spec->maxRequests()) {
            if ($this->stopRequested($spec)) {
                break;
            }

            $this->runOne($spec);

            $processed++;
        }

        return $processed;
    }

    /**
     * Executes exactly one task as a KernelRuntime-owned UnitOfWork.
     */
    public function runOne(WorkerPoolSpec $spec): mixed
    {
        [$operationId, $body] = $this->taskWork($spec);

        $labels = self::taskLabels(
            operationId: $operationId,
            outcome: self::OUTCOME_SUCCESS,
        );

        $span = $this->startTaskSpan($labels);
        $startedAt = $this->stopwatch->start();
        $outcome = self::OUTCOME_SUCCESS;

        try {
            return $this->kernelRuntime->runUnitOfWork(
                type: $spec->taskType(),
                body: function () use ($body, $span): mixed {
                    $this->attachSafeContextAttributes($span);

                    return $body();
                },
            );
        } catch (\Throwable $throwable) {
            $outcome = self::OUTCOME_FAILURE;

            throw $throwable;
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

    /**
     * @return array{0: string, 1: \Closure(): mixed}
     */
    private function taskWork(WorkerPoolSpec $spec): array
    {
        if (!$this->taskFactory->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        $work = $this->taskFactory->create($spec);

        if (!\array_key_exists('operation_id', $work) || !\is_string($work['operation_id'])) {
            throw WorkerStartFailedException::startFailed();
        }

        if (!\array_key_exists('run', $work) || !$work['run'] instanceof \Closure) {
            throw WorkerStartFailedException::startFailed();
        }

        $operationId = $work['operation_id'];

        if (!self::isSafeOperationId($operationId)) {
            throw WorkerStartFailedException::startFailed();
        }

        return [$operationId, $work['run']];
    }

    private static function isSafeOperationId(string $operationId): bool
    {
        return $operationId === TaskFactoryInternalInterface::TASK_TYPE_QUEUE
            || $operationId === TaskFactoryInternalInterface::TASK_TYPE_HTTP;
    }

    /**
     * @return array{operation: string, outcome: string}
     */
    private static function taskLabels(string $operationId, string $outcome): array
    {
        if (!self::isSafeOperationId($operationId)) {
            throw WorkerStartFailedException::startFailed();
        }

        if ($outcome !== self::OUTCOME_SUCCESS && $outcome !== self::OUTCOME_FAILURE) {
            throw WorkerStartFailedException::startFailed();
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

    private function attachSafeContextAttributes(?SpanInterface $span): void
    {
        if ($span === null) {
            return;
        }

        $attributes = $this->safeContextAttributes();

        if ($attributes === []) {
            return;
        }

        try {
            $span->setAttributes($attributes);
        } catch (\Throwable) {
        }
    }

    /**
     * Reads only safe context values for observability correlation.
     *
     * Context read failures must not change task control-flow semantics.
     *
     * @return array<string, string>
     */
    private function safeContextAttributes(): array
    {
        $attributes = [];

        foreach (
            [
                ContextKeys::CORRELATION_ID,
                ContextKeys::UOW_ID,
                ContextKeys::UOW_TYPE,
            ] as $key
        ) {
            try {
                if (!$this->context->has($key)) {
                    continue;
                }

                $value = $this->context->get($key);
            } catch (\Throwable) {
                continue;
            }

            if (!\is_string($value) || $value === '') {
                continue;
            }

            $attributes[$key] = $value;
        }

        return $attributes;
    }

    private function emitTaskMetrics(
        int $durationMs,
        string $operationId,
        string $outcome,
    ): void {
        if (!self::isSafeOperationId($operationId)) {
            throw WorkerStartFailedException::startFailed();
        }

        if ($outcome !== self::OUTCOME_SUCCESS && $outcome !== self::OUTCOME_FAILURE) {
            throw WorkerStartFailedException::startFailed();
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

    private function safeDurationMs(int $startedAt): int
    {
        try {
            return $this->stopwatch->stop($startedAt);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function stopRequested(WorkerPoolSpec $spec): bool
    {
        $path = $this->resolveRelativePath($spec->stopFlagPath());

        \set_error_handler(static fn (): bool => true);

        try {
            return \is_file($path);
        } finally {
            \restore_error_handler();
        }
    }

    private function resolveRelativePath(string $relativePath): string
    {
        if ($relativePath === '' || \str_contains($relativePath, "\0")) {
            throw WorkerStartFailedException::startFailed();
        }

        return $this->skeletonRoot . '/' . $relativePath;
    }

    private static function normalizeSkeletonRoot(string $skeletonRoot): string
    {
        $root = \trim($skeletonRoot);

        if ($root === '' || \str_contains($root, "\0")) {
            throw new \InvalidArgumentException('application-worker-skeleton-root-invalid');
        }

        $root = \rtrim(\str_replace('\\', '/', $root), '/');

        if ($root === '') {
            throw new \InvalidArgumentException('application-worker-skeleton-root-invalid');
        }

        return $root;
    }
}
