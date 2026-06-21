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

namespace Coretsia\Platform\Worker\Manager;

use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerForkFailedException;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Psr\Log\LoggerInterface;

/**
 * High-level worker pool manager.
 *
 * WorkerManager owns pool lifecycle orchestration:
 *
 * - start
 * - stop
 * - status
 *
 * It accepts an already-built WorkerPoolSpec and delegates process-specific
 * behavior to package-internal WorkerManagerDriverInterface implementations.
 *
 * This class must not fork, call proc_open(), open sockets, write pid files,
 * write stop files, or write worker.state.json directly. Those responsibilities
 * belong to process drivers and dedicated runtime/storage/control
 * collaborators.
 *
 * Runtime-driver compatibility is intentionally not enforced here.
 * WorkerStartCommand must invoke RuntimeDriverGuard before manager start.
 *
 * Task execution is intentionally not owned here. Individual task execution
 * belongs to ApplicationWorker and KernelRuntimeInterface.
 *
 * This class must not enumerate kernel.reset, kernel.hook.before_uow, or
 * kernel.hook.after_uow. It must not call ResetOrchestrator::resetAll().
 *
 * Observability dependencies are injected. This class must not instantiate noop
 * logger, tracer, meter, or observability adapters directly.
 *
 * Public lifecycle failures are surfaced only through deterministic worker
 * exceptions. Previous throwable messages must not be exposed through public
 * diagnostics.
 *
 * Metric labels use only:
 *
 * - status
 *
 * The status label is intentionally low-cardinality:
 *
 * - start_success
 * - start_failure
 * - stop_success
 * - stop_failure
 * - status_success
 * - status_failure
 *
 * Span attributes are restricted to safe policy attributes:
 *
 * - pid
 * - outcome
 *
 * This class must not log payloads, raw socket paths, raw TCP endpoints,
 * absolute paths, config dumps, environment values, headers, cookies,
 * Authorization values, tokens, body fragments, command lines, or stack traces.
 */
final readonly class WorkerManager
{
    private const string SPAN_WORKER_PROCESS = 'worker.process';

    private const string METRIC_WORKER_PROCESS_TOTAL = 'worker.process_total';

    private const string LOG_EVENT_WORKER_START = 'worker.process.start';
    private const string LOG_EVENT_WORKER_STOP = 'worker.process.stop';
    private const string LOG_EVENT_WORKER_STATUS = 'worker.process.status';

    private const string OPERATION_START = 'start';
    private const string OPERATION_STOP = 'stop';
    private const string OPERATION_STATUS = 'status';

    private const string OUTCOME_SUCCESS = 'success';
    private const string OUTCOME_FAILURE = 'failure';

    /**
     * @var array<WorkerManagerDriverInterface::DRIVER_PCNTL|WorkerManagerDriverInterface::DRIVER_PROC, WorkerManagerDriverInterface>
     */
    private array $drivers;

    /**
     * @param iterable<WorkerManagerDriverInterface> $drivers
     */
    public function __construct(
        iterable $drivers,
        private TracerPortInterface $tracer,
        private MeterPortInterface $meter,
        private LoggerInterface $logger,
        private Stopwatch $stopwatch,
    ) {
        $this->drivers = self::normalizeDrivers($drivers);
    }

    public function start(WorkerPoolSpec $spec): WorkerPoolState
    {
        return $this->runLifecycleOperation(
            operation: self::OPERATION_START,
            logEvent: self::LOG_EVENT_WORKER_START,
            callback: fn (WorkerManagerDriverInterface $driver): WorkerPoolState => $driver->start($spec),
            spec: $spec,
        );
    }

    public function stop(WorkerPoolSpec $spec): WorkerPoolState
    {
        return $this->runLifecycleOperation(
            operation: self::OPERATION_STOP,
            logEvent: self::LOG_EVENT_WORKER_STOP,
            callback: fn (WorkerManagerDriverInterface $driver): WorkerPoolState => $driver->stop($spec),
            spec: $spec,
        );
    }

    public function status(WorkerPoolSpec $spec): WorkerPoolState
    {
        return $this->runLifecycleOperation(
            operation: self::OPERATION_STATUS,
            logEvent: self::LOG_EVENT_WORKER_STATUS,
            callback: fn (WorkerManagerDriverInterface $driver): WorkerPoolState => $driver->status($spec),
            spec: $spec,
        );
    }

    /**
     * @param callable(WorkerManagerDriverInterface): WorkerPoolState $callback
     */
    private function runLifecycleOperation(
        string $operation,
        string $logEvent,
        callable $callback,
        WorkerPoolSpec $spec,
    ): WorkerPoolState {
        $operation = self::safeOperation($operation);
        $driver = $this->selectDriver($spec);

        $span = $this->safeStartSpan();
        $startedAt = $this->safeStartTimer();
        $outcome = self::OUTCOME_FAILURE;
        $state = null;

        try {
            $state = $callback($driver);
            $outcome = self::OUTCOME_SUCCESS;

            return $state;
        } catch (
            WorkerNotRunningException
            |WorkerStartFailedException
            |WorkerForkFailedException
            |WorkerCommunicationFailedException $exception
        ) {
            throw $exception;
        } catch (\Throwable) {
            throw WorkerStartFailedException::startFailed();
        } finally {
            $durationMs = $this->safeStopTimer($startedAt);
            $status = self::statusLabel($operation, $outcome);

            $this->safeFinishSpan($span, $state, $outcome);
            $this->safeEmitMetric($status);
            $this->safeLogLifecycleSummary(
                event: $logEvent,
                status: $status,
                outcome: $outcome,
                durationMs: $durationMs,
                state: $state,
            );
        }
    }

    private function selectDriver(WorkerPoolSpec $spec): WorkerManagerDriverInterface
    {
        $requestedDriver = $spec->driver();

        if (!isset($this->drivers[$requestedDriver])) {
            throw WorkerStartFailedException::startFailed();
        }

        $driver = $this->drivers[$requestedDriver];

        if (!$driver->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        return $driver;
    }

    private function safeStartSpan(): ?SpanInterface
    {
        try {
            return $this->tracer->startSpan(self::SPAN_WORKER_PROCESS);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeFinishSpan(
        ?SpanInterface $span,
        ?WorkerPoolState $state,
        string $outcome,
    ): void {
        if ($span === null) {
            return;
        }

        try {
            $span->setAttributes(self::spanAttributes($state, $outcome));
        } catch (\Throwable) {
        }

        try {
            $span->end();
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, int|string>
     */
    private static function spanAttributes(?WorkerPoolState $state, string $outcome): array
    {
        $attributes = [
            'outcome' => self::safeOutcome($outcome),
        ];

        if ($state !== null) {
            $attributes['pid'] = $state->pid();
        }

        return $attributes;
    }

    private function safeEmitMetric(string $status): void
    {
        try {
            $this->meter->increment(
                self::METRIC_WORKER_PROCESS_TOTAL,
                1,
                [
                    'status' => $status,
                ],
            );
        } catch (\Throwable) {
        }
    }

    private function safeLogLifecycleSummary(
        string $event,
        string $status,
        string $outcome,
        int $durationMs,
        ?WorkerPoolState $state,
    ): void {
        try {
            $this->logger->info(
                $event,
                self::logContext(
                    status: $status,
                    outcome: $outcome,
                    durationMs: $durationMs,
                    state: $state,
                ),
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, int|string>
     */
    private static function logContext(
        string $status,
        string $outcome,
        int $durationMs,
        ?WorkerPoolState $state,
    ): array {
        $context = [
            'status' => $status,
            'outcome' => self::safeOutcome($outcome),
            'duration_ms' => $durationMs,
        ];

        if ($state === null) {
            return $context;
        }

        return [
            ...$context,
            'pid' => $state->pid(),
            'worker_count' => $state->workerCount(),
            'driver' => $state->driver(),
            'control_transport' => $state->controlTransport(),
            'endpoint_hash' => $state->endpointHash(),
        ];
    }

    private function safeStartTimer(): mixed
    {
        try {
            return $this->stopwatch->start();
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeStopTimer(mixed $startedAt): int
    {
        if (!\is_int($startedAt)) {
            return 0;
        }

        try {
            return $this->stopwatch->stop($startedAt);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function safeOperation(string $operation): string
    {
        return match ($operation) {
            self::OPERATION_START,
            self::OPERATION_STOP,
            self::OPERATION_STATUS => $operation,
            default => throw WorkerStartFailedException::startFailed(),
        };
    }

    private static function safeOutcome(string $outcome): string
    {
        return $outcome === self::OUTCOME_SUCCESS
            ? self::OUTCOME_SUCCESS
            : self::OUTCOME_FAILURE;
    }

    private static function statusLabel(string $operation, string $outcome): string
    {
        $operation = self::safeOperation($operation);
        $outcome = self::safeOutcome($outcome);

        return $operation . '_' . $outcome;
    }

    /**
     * @param iterable<WorkerManagerDriverInterface> $drivers
     * @return array<WorkerManagerDriverInterface::DRIVER_PCNTL|WorkerManagerDriverInterface::DRIVER_PROC, WorkerManagerDriverInterface>
     */
    private static function normalizeDrivers(iterable $drivers): array
    {
        $normalized = [];

        foreach ($drivers as $driver) {
            $name = $driver->name();

            if (
                $name !== WorkerManagerDriverInterface::DRIVER_PCNTL
                && $name !== WorkerManagerDriverInterface::DRIVER_PROC
            ) {
                throw new \InvalidArgumentException('worker-manager-driver-name-invalid');
            }

            if (isset($normalized[$name])) {
                throw new \InvalidArgumentException('worker-manager-driver-duplicate');
            }

            $normalized[$name] = $driver;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('worker-manager-drivers-empty');
        }

        \ksort($normalized, \SORT_STRING);

        /** @var array<WorkerManagerDriverInterface::DRIVER_PCNTL|WorkerManagerDriverInterface::DRIVER_PROC, WorkerManagerDriverInterface> $normalized */
        return $normalized;
    }
}
