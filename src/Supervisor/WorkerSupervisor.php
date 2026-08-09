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

namespace Coretsia\Platform\Worker\Supervisor;

use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel;
use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use Coretsia\Platform\Worker\Communication\WorkerControlOperation;
use Coretsia\Platform\Worker\Communication\WorkerControlServer;
use Coretsia\Platform\Worker\Communication\WorkerControlSession;
use Coretsia\Platform\Worker\Exception\WorkerAlreadyRunningException;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerForkFailedException;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverResolverInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessGuardianInterface;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;
use Coretsia\Platform\Worker\Runtime\WorkerHealthState;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocator;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocatorStore;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Runtime\WorkerShutdownBudget;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Runtime\WorkerStopSignal;
use Psr\Log\LoggerInterface;

/**
 * Persistent foreground orchestrator for one guardian-owned worker generation.
 *
 * The supervisor owns the private lifecycle locator, control listener, child
 * table, readiness, state publication, signal intent, recycle policy and normal
 * shutdown orchestration. WorkerProcessGuardianInterface owns worker processes
 * and the canonical generation fence, including supervisor-death containment.
 *
 * It does not resolve the container, build WorkerPoolSpec, daemonize, write
 * stdout/stderr, or own the lifecycle lock directly.
 */
final class WorkerSupervisor implements WorkerSupervisorInterface
{
    private const int EXIT_SUCCESS = 0;
    private const int EXIT_FAILURE = 1;
    private const int EVENT_LOOP_TICK_MS = 50;
    private const int REAP_POLL_INTERVAL_US = 10_000;
    private const int PROCESS_OPERATION_TIMEOUT_MS = 1_000;

    private const string SPAN_WORKER_PROCESS = 'worker.process';
    private const string METRIC_WORKER_PROCESS_TOTAL = 'worker.process_total';

    private const string LOG_EVENT_WORKER_START = 'worker.process.start';

    private const string LOG_EVENT_WORKER_STOP = 'worker.process.stop';

    private const string STATUS_START_SUCCESS = 'start_success';

    private const string STATUS_START_FAILURE = 'start_failure';

    private const string STATUS_STOP_SUCCESS = 'stop_success';

    private const string STATUS_STOP_FAILURE = 'stop_failure';

    private const string OUTCOME_SUCCESS = 'success';
    private const string OUTCOME_FAILURE = 'failure';

    public function __construct(
        private readonly WorkerProcessDriverResolverInterface $driverResolver,
        private readonly WorkerProcessGuardianInterface $guardian,
        private readonly WorkerLifecycleLocatorStore $locatorStore,
        private readonly WorkerControlServer $controlServer,
        private readonly WorkerChildReadinessChannel $readinessChannel,
        private readonly WorkerChildTable $children,
        private readonly WorkerSignalController $signals,
        private readonly WorkerStateStore $stateStore,
        private readonly WorkerStopSignal $stopSignal,
        private readonly TracerPortInterface $tracer,
        private readonly MeterPortInterface $meter,
        private readonly LoggerInterface $logger,
        private readonly Stopwatch $stopwatch,
    ) {
    }

    public function run(WorkerPoolSpec $spec, \Closure $onReady): int
    {
        if (!$this->children->empty()) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        $driver = $this->driverResolver->resolve($spec);

        $guardianClaimed = false;
        $serverListening = false;
        $signalsInstalled = false;
        $state = null;

        /** @var list<WorkerControlSession> $pendingStops */
        $pendingStops = [];

        $terminalChildFailure = false;
        $signalDrivenShutdown = false;
        $startupCompleted = false;
        $exitCode = self::EXIT_SUCCESS;

        $span = $this->safeStartSpan();
        $startedAt = $this->safeStartTimer();
        $startupStartedAt = $this->safeStartTimer();
        $startupDeadlineNs = self::deadlineNs(
            $spec->startTimeoutMs(),
        );

        try {
            $this->guardian->claim($spec, $driver->name());
            $guardianClaimed = true;

            if (\hrtime(true) >= $startupDeadlineNs) {
                throw WorkerStartFailedException::readinessTimeout();
            }

            $this->locatorStore->delete();
            $this->stateStore->delete($spec);
            $this->stopSignal->clear($spec);

            $controlCredential = WorkerControlCredential::generate();

            $this->controlServer->listen(
                $spec,
                $controlCredential,
            );
            $serverListening = true;

            $this->signals->install();
            $signalsInstalled = true;

            $state = $this->stateStore->createState(
                spec: $spec,
                pid: self::currentPid(),
                status: WorkerPoolStatus::STARTING,
                readyWorkerCount: 0,
            );
            $this->stateStore->write($spec, $state);
            $this->locatorStore->write(
                WorkerLifecycleLocator::fromPoolSpec(
                    $spec,
                    $controlCredential,
                ),
            );

            for ($index = 0; $index < $spec->workers(); $index++) {
                if ($this->signals->shutdownRequested()) {
                    $signalDrivenShutdown = true;

                    break;
                }

                if (
                    $this->serviceControlRequest(
                        state: $state,
                        pendingStops: $pendingStops,
                        terminalChildFailure: $terminalChildFailure,
                        timeoutMs: 0,
                    )
                ) {
                    if ($this->signals->shutdownRequested()) {
                        $signalDrivenShutdown = true;
                    }

                    break;
                }

                if (\hrtime(true) >= $startupDeadlineNs) {
                    throw WorkerStartFailedException::readinessTimeout();
                }

                $this->children->add(
                    $driver->spawn($spec, $index),
                );

                if (\hrtime(true) >= $startupDeadlineNs) {
                    throw WorkerStartFailedException::readinessTimeout();
                }
            }

            while (
                !$this->signals->shutdownRequested()
                && $pendingStops === []
                && $this->children->readyCount() < $spec->workers()
            ) {
                if ($this->signals->shutdownRequested()) {
                    $signalDrivenShutdown = true;

                    break;
                }

                if (
                    $this->serviceControlRequest(
                        state: $state,
                        pendingStops: $pendingStops,
                        terminalChildFailure: $terminalChildFailure,
                    )
                ) {
                    if ($this->signals->shutdownRequested()) {
                        $signalDrivenShutdown = true;
                    }

                    break;
                }

                $state = $this->pollReadiness(
                    spec: $spec,
                    state: $state,
                    startupDeadlineNs: $startupDeadlineNs,
                );

                $reapOutcome = $this->reapAndRecycle(
                    driver: $driver,
                    spec: $spec,
                    state: $state,
                );

                if ($reapOutcome === WorkerReapOutcome::SHUTDOWN_REQUESTED) {
                    $signalDrivenShutdown = true;

                    break;
                }

                if ($reapOutcome === WorkerReapOutcome::CHILD_FAILURE) {
                    $terminalChildFailure = true;
                    $exitCode = self::EXIT_FAILURE;

                    break;
                }

                $state = $this->publishReadyCount($spec, $state);
            }

            $startupCompleted = $exitCode === self::EXIT_SUCCESS
                && !$this->signals->shutdownRequested()
                && $pendingStops === []
                && $this->children->readyCount() === $spec->workers();

            if ($startupCompleted) {
                $state = $state->withStatus(
                    WorkerPoolStatus::RUNNING,
                    $this->children->readyCount(),
                );
                $this->stateStore->write($spec, $state);

                $startupDurationMs = $this->safeStopTimer($startupStartedAt);
                $startupStartedAt = null;
                $this->safeEmitProcessMetric(self::STATUS_START_SUCCESS);

                $this->safeLogProcessSummary(
                    event: self::LOG_EVENT_WORKER_START,
                    status: self::STATUS_START_SUCCESS,
                    outcome: self::OUTCOME_SUCCESS,
                    durationMs: $startupDurationMs,
                    state: $state,
                );

                $onReady($state);

                while (true) {
                    if ($this->signals->shutdownRequested()) {
                        $signalDrivenShutdown = true;

                        break;
                    }

                    if (
                        $this->serviceControlRequest(
                            state: $state,
                            pendingStops: $pendingStops,
                            terminalChildFailure: $terminalChildFailure,
                        )
                    ) {
                        if ($this->signals->shutdownRequested()) {
                            $signalDrivenShutdown = true;
                        }

                        break;
                    }

                    $state = $this->pollReadiness(
                        spec: $spec,
                        state: $state,
                        startupDeadlineNs: null,
                    );

                    $reapOutcome = $this->reapAndRecycle(
                        driver: $driver,
                        spec: $spec,
                        state: $state,
                    );

                    if ($reapOutcome === WorkerReapOutcome::SHUTDOWN_REQUESTED) {
                        $signalDrivenShutdown = true;

                        break;
                    }

                    if ($reapOutcome === WorkerReapOutcome::CHILD_FAILURE) {
                        $terminalChildFailure = true;
                        $exitCode = self::EXIT_FAILURE;

                        break;
                    }

                    $state = $this->publishReadyCount($spec, $state);
                }
            }

            $state = $state->withStatus(
                WorkerPoolStatus::STOPPING,
                $this->children->readyCount(),
            );
            $this->stateStore->write($spec, $state);
            $this->stopSignal->request($spec);

            $shutdownTick = function () use (
                $spec,
                &$state,
                &$pendingStops,
                &$terminalChildFailure,
                &$signalDrivenShutdown,
            ): void {
                if ($this->signals->shutdownRequested()) {
                    $signalDrivenShutdown = true;
                }

                $state = $this->publishReadyCount($spec, $state);

                $this->serviceControlRequest(
                    state: $state,
                    pendingStops: $pendingStops,
                    terminalChildFailure: $terminalChildFailure,
                    timeoutMs: 0,
                );
            };

            $onUnexpectedExit = function (WorkerProcessExit $exit) use (
                &$terminalChildFailure,
                &$signalDrivenShutdown,
                &$exitCode,
            ): void {
                /*
                 * A service manager may signal the complete process group. In that case a
                 * signaled child exit belongs to the requested signal-driven shutdown.
                 */
                if (
                    $signalDrivenShutdown
                    && $exit->signaled()
                    && $this->signals->wasShutdownSignal(
                        $exit->terminatingSignal(),
                    )
                ) {
                    return;
                }

                $terminalChildFailure = true;
                $exitCode = self::EXIT_FAILURE;
            };

            $this->shutdownChildren(
                driver: $driver,
                spec: $spec,
                onTick: $shutdownTick,
                onUnexpectedExit: $onUnexpectedExit,
            );

            $cleanupDeadlineNs = self::deadlineNs(
                WorkerShutdownBudget::CLEANUP_TIMEOUT_MS,
            );

            $state = $state->withStatus(
                WorkerPoolStatus::STOPPING,
                0,
            );

            $this->stateStore->delete($spec);
            $this->stopSignal->clear($spec);
            $this->controlServer->closeListener();
            $serverListening = false;
            $this->locatorStore->delete();

            $this->guardian->release(
                self::remainingMs($cleanupDeadlineNs),
            );
            $guardianClaimed = false;

            foreach ($pendingStops as $session) {
                $this->bestEffortRespondStopped($session, $state);
            }

            $pendingStops = [];

            return $exitCode;
        } catch (WorkerAlreadyRunningException $exception) {
            $exitCode = self::EXIT_FAILURE;

            throw $exception;
        } catch (
            WorkerStartFailedException
            |WorkerLifecycleFailedException
            |WorkerForkFailedException
            |WorkerCommunicationFailedException $exception
        ) {
            $exitCode = self::EXIT_FAILURE;

            if ($guardianClaimed) {
                $this->bestEffortShutdown($driver, $spec);
            }

            throw $exception;
        } catch (\Throwable) {
            $exitCode = self::EXIT_FAILURE;

            if ($guardianClaimed) {
                $this->bestEffortShutdown($driver, $spec);
            }

            throw $startupCompleted
                ? WorkerLifecycleFailedException::lifecycleFailed()
                : WorkerStartFailedException::startFailed();
        } finally {
            foreach ($pendingStops as $session) {
                $this->controlServer->closeSession($session);
            }

            if ($serverListening) {
                try {
                    $this->controlServer->close();
                } catch (\Throwable) {
                }
            } else {
                $this->controlServer->reset();
            }

            if ($signalsInstalled) {
                $this->signals->uninstall();
            }

            if ($guardianClaimed) {
                try {
                    $this->locatorStore->delete();
                } catch (\Throwable) {
                }

                try {
                    $this->guardian->release(
                        WorkerShutdownBudget::CLEANUP_TIMEOUT_MS,
                    );
                } catch (\Throwable) {
                    /* Closing the client connection lets the guardian own crash cleanup. */
                }
            }

            $this->children->clear();
            $this->safeFinishSpan(
                span: $span,
                state: $state,
                exitCode: $exitCode,
                startedAt: $startedAt,
                startupCompleted: $startupCompleted,
                startupStartedAt: $startupStartedAt,
            );
        }
    }

    /**
     * Services one control request.
     *
     * Stop sessions remain open until the supervisor has fully reaped children,
     * removed runtime artifacts, closed the listener, and received guardian
     * acknowledgement that the generation fence was released.
     *
     * @param list<WorkerControlSession> $pendingStops
     */
    private function serviceControlRequest(
        WorkerPoolState $state,
        #[\SensitiveParameter]
        array &$pendingStops,
        bool $terminalChildFailure,
        int $timeoutMs = self::EVENT_LOOP_TICK_MS,
    ): bool {
        /*
         * Clear a previously handled SIGCHLD marker before the next blocking
         * control tick. A child that exited before this call no longer needs to
         * explain a future transport failure; the synchronous reap pass will
         * still observe it.
         */
        $this->signals->consumeChildExitSignal();

        try {
            $session = $this->controlServer->accept($timeoutMs);
        } catch (WorkerCommunicationFailedException $exception) {
            /*
             * stream_select() may be interrupted by SIGTERM/SIGINT after the
             * signal handler has recorded shutdown intent.
             */
            if ($this->signals->shutdownRequested()) {
                return true;
            }

            /*
             * A normal child exit may interrupt stream_select() through
             * SIGCHLD. That interruption is not a control-channel failure: the
             * next supervisor step must reap and, for exit code 0, recycle the
             * slot.
             */
            if ($this->signals->consumeChildExitSignal()) {
                return false;
            }

            throw $exception;
        }

        /*
         * Prevent a successfully handled SIGCHLD from remaining stale and
         * masking an unrelated transport failure during a later tick.
         */
        $this->signals->consumeChildExitSignal();

        if ($session === null) {
            return false;
        }

        $operation = $session->request()->operation();

        if ($operation === WorkerControlOperation::STOP) {
            $pendingStops[] = $session;

            return true;
        }

        try {
            if ($operation === WorkerControlOperation::STATUS) {
                $this->controlServer->respondState(
                    $session,
                    $state,
                );
            } elseif ($operation === WorkerControlOperation::HEALTH) {
                $this->controlServer->respondHealth(
                    $session,
                    $this->health(
                        state: $state,
                        terminalChildFailure: $terminalChildFailure,
                    ),
                );
            }
        } catch (WorkerCommunicationFailedException) {
            // A disconnected status/health client must not terminate the pool.
        } finally {
            $this->controlServer->closeSession($session);
        }

        return false;
    }

    private function pollReadiness(
        WorkerPoolSpec $spec,
        WorkerPoolState $state,
        ?int $startupDeadlineNs,
    ): WorkerPoolState {
        $changed = false;
        $nowNs = \hrtime(true);

        foreach ($this->children->unready() as $child) {
            if ($this->signals->shutdownRequested()) {
                return $state;
            }

            try {
                $ready = $this->readinessChannel->poll($child);
            } catch (WorkerStartFailedException $exception) {
                if (
                    $this->signals->shutdownRequested()
                    && $exception->reason()
                    === WorkerStartFailedException::REASON_READINESS_INVALID
                ) {
                    return $state;
                }

                throw $exception;
            }

            if ($this->signals->shutdownRequested()) {
                return $state;
            }

            if ($ready) {
                $this->children->markReady(
                    $child->workerIndex(),
                );

                $changed = true;

                continue;
            }

            $deadlineNs = $startupDeadlineNs
                ?? self::deadlineNsFrom(
                    startNs: $child->startedAtNs(),
                    timeoutMs: $spec->startTimeoutMs(),
                );

            if ($nowNs >= $deadlineNs) {
                throw WorkerStartFailedException::readinessTimeout();
            }
        }

        if (!$changed) {
            return $state;
        }

        return $this->publishReadyCount(
            $spec,
            $state,
        );
    }

    private function reapAndRecycle(
        WorkerProcessDriverInterface $driver,
        WorkerPoolSpec $spec,
        WorkerPoolState $state,
    ): WorkerReapOutcome {
        if ($this->signals->shutdownRequested()) {
            return WorkerReapOutcome::SHUTDOWN_REQUESTED;
        }

        foreach ($this->children->all() as $child) {
            $exit = $driver->pollExit(
                $child,
                self::PROCESS_OPERATION_TIMEOUT_MS,
            );

            if ($exit === null) {
                if ($this->signals->shutdownRequested()) {
                    return WorkerReapOutcome::SHUTDOWN_REQUESTED;
                }

                continue;
            }

            $index = $child->workerIndex();
            $generation = $child->generation();
            $wasReady = $this->children->isReady($index);

            $driver->close(
                $child,
                self::PROCESS_OPERATION_TIMEOUT_MS,
            );
            $this->children->remove($index);

            $this->stateStore->write(
                $spec,
                $state->withStatus(
                    $state->status(),
                    $this->children->readyCount(),
                ),
            );

            if ($this->signals->shutdownRequested()) {
                if (
                    $exit->expected()
                    || (
                        $exit->signaled()
                        && $this->signals->wasShutdownSignal(
                            $exit->terminatingSignal(),
                        )
                    )
                ) {
                    return WorkerReapOutcome::SHUTDOWN_REQUESTED;
                }

                return WorkerReapOutcome::CHILD_FAILURE;
            }

            if (!$wasReady || !$exit->expected()) {
                if ($state->status() === WorkerPoolStatus::STARTING) {
                    throw WorkerLifecycleFailedException::childExited();
                }

                return WorkerReapOutcome::CHILD_FAILURE;
            }

            $replacement = $driver
                ->spawn($spec, $index)
                ->withGeneration($generation + 1);

            $this->children->add($replacement);

            $this->stateStore->write(
                $spec,
                $state->withStatus(
                    $state->status(),
                    $this->children->readyCount(),
                ),
            );

            if ($this->signals->shutdownRequested()) {
                return WorkerReapOutcome::SHUTDOWN_REQUESTED;
            }
        }

        return $this->signals->shutdownRequested()
            ? WorkerReapOutcome::SHUTDOWN_REQUESTED
            : WorkerReapOutcome::CONTINUE;
    }

    private function publishReadyCount(
        WorkerPoolSpec $spec,
        WorkerPoolState $state,
    ): WorkerPoolState {
        $readyCount = $this->children->readyCount();

        if ($readyCount === $state->readyWorkerCount()) {
            return $state;
        }

        $state = $state->withStatus($state->status(), $readyCount);
        $this->stateStore->write($spec, $state);

        return $state;
    }

    /**
     * Performs cooperative, graceful, and forced child shutdown.
     *
     * Unexpected exits are inspected only during the cooperative phase. After the
     * supervisor has explicitly sent SIGTERM or SIGKILL, signaled exits belong to
     * supervisor-orchestrated shutdown.
     *
     * @param (\Closure(): void)|null $onTick
     * @param (\Closure(WorkerProcessExit): void)|null $onUnexpectedExit
     */
    private function shutdownChildren(
        WorkerProcessDriverInterface $driver,
        WorkerPoolSpec $spec,
        ?\Closure $onTick = null,
        ?\Closure $onUnexpectedExit = null,
    ): void {
        $cooperativeDeadlineNs = self::deadlineNs(
            $spec->stopTimeoutMs(),
        );
        $this->reapUntil(
            driver: $driver,
            deadlineNs: $cooperativeDeadlineNs,
            onTick: $onTick,
            onExit: static function (WorkerProcessExit $exit) use ($onUnexpectedExit): void {
                if (!$exit->expected() && $onUnexpectedExit instanceof \Closure) {
                    $onUnexpectedExit($exit);
                }
            },
        );

        if ($this->children->empty()) {
            return;
        }

        $terminateDeadlineNs = self::deadlineNs(
            $spec->forceKillTimeoutMs(),
        );

        foreach ($this->children->all() as $child) {
            $remainingMs = self::remainingMsOrNull($terminateDeadlineNs);

            if ($remainingMs === null) {
                break;
            }

            $this->children->markTerminating(
                $child->workerIndex(),
            );
            $driver->terminate($child, $remainingMs);
        }

        $this->reapUntil(
            driver: $driver,
            deadlineNs: $terminateDeadlineNs,
            onTick: $onTick,
        );

        if ($this->children->empty()) {
            return;
        }

        $killDeadlineNs = self::deadlineNs(
            $spec->forceKillTimeoutMs(),
        );

        foreach ($this->children->all() as $child) {
            $remainingMs = self::remainingMsOrNull($killDeadlineNs);

            if ($remainingMs === null) {
                break;
            }

            $this->children->markKilling(
                $child->workerIndex(),
            );
            $driver->kill($child, $remainingMs);
        }

        $this->reapUntil(
            driver: $driver,
            deadlineNs: $killDeadlineNs,
            onTick: $onTick,
        );

        if (!$this->children->empty()) {
            throw WorkerLifecycleFailedException::shutdownFailed();
        }
    }

    /**
     * Reaps exited children until the table is empty or the caller-owned phase
     * deadline expires. Every potentially blocking driver operation receives
     * only the remaining phase budget.
     *
     * @param (\Closure(): void)|null $onTick
     * @param (\Closure(WorkerProcessExit): void)|null $onExit
     */
    private function reapUntil(
        WorkerProcessDriverInterface $driver,
        int $deadlineNs,
        ?\Closure $onTick = null,
        ?\Closure $onExit = null,
    ): void {
        do {
            if ($onTick instanceof \Closure) {
                $onTick();
            }

            foreach ($this->children->all() as $child) {
                $remainingMs = self::remainingMsOrNull($deadlineNs);

                if ($remainingMs === null) {
                    return;
                }

                $exit = $driver->pollExit($child, $remainingMs);

                if ($exit === null) {
                    continue;
                }

                if ($onExit instanceof \Closure) {
                    $onExit($exit);
                }

                $remainingMs = self::remainingMsOrNull($deadlineNs);

                if ($remainingMs === null) {
                    return;
                }

                $driver->close($child, $remainingMs);
                $this->children->remove(
                    $child->workerIndex(),
                );
            }

            if ($this->children->empty()) {
                return;
            }

            $remainingMs = self::remainingMsOrNull($deadlineNs);

            if ($remainingMs === null) {
                return;
            }

            \usleep(
                \min(
                    self::REAP_POLL_INTERVAL_US,
                    $remainingMs * 1_000,
                )
            );
        } while (true);
    }

    private function bestEffortShutdown(
        WorkerProcessDriverInterface $driver,
        WorkerPoolSpec $spec,
    ): void {
        try {
            $this->stopSignal->request($spec);
        } catch (\Throwable) {
        }

        try {
            $this->shutdownChildren($driver, $spec);
        } catch (\Throwable) {
            $killDeadlineNs = self::deadlineNs(
                $spec->forceKillTimeoutMs(),
            );

            foreach ($this->children->all() as $child) {
                $remainingMs = self::remainingMsOrNull($killDeadlineNs);

                if ($remainingMs === null) {
                    break;
                }

                try {
                    $driver->kill($child, $remainingMs);
                } catch (\Throwable) {
                }
            }

            try {
                $this->reapUntil(
                    $driver,
                    $killDeadlineNs,
                );
            } catch (\Throwable) {
            }

            $cleanupDeadlineNs = self::deadlineNs(
                WorkerShutdownBudget::CLEANUP_TIMEOUT_MS,
            );

            foreach ($this->children->all() as $child) {
                $remainingMs = self::remainingMsOrNull($cleanupDeadlineNs);

                if ($remainingMs === null) {
                    break;
                }

                try {
                    $driver->close($child, $remainingMs);
                } catch (\Throwable) {
                }
            }
        }

        try {
            $this->stateStore->delete($spec);
        } catch (\Throwable) {
        }

        try {
            $this->stopSignal->clear($spec);
        } catch (\Throwable) {
        }

        try {
            $this->locatorStore->delete();
        } catch (\Throwable) {
        }
    }

    private function bestEffortRespondStopped(
        #[\SensitiveParameter]
        WorkerControlSession $session,
        WorkerPoolState $state,
    ): void {
        try {
            $this->controlServer->respondStopped($session, $state);
        } catch (WorkerCommunicationFailedException) {
        } finally {
            $this->controlServer->closeSession($session);
        }
    }

    private function health(
        WorkerPoolState $state,
        bool $terminalChildFailure,
    ): WorkerHealthState {
        $healthy = $state->status() === WorkerPoolStatus::RUNNING
            && $state->readyWorkerCount() === $state->workerCount()
            && !$terminalChildFailure;

        $reason = match (true) {
            $terminalChildFailure => WorkerHealthState::REASON_CHILD_FAILURE,
            $state->status() === WorkerPoolStatus::STARTING => WorkerHealthState::REASON_STARTING,
            $state->status() === WorkerPoolStatus::STOPPING => WorkerHealthState::REASON_STOPPING,
            !$healthy => WorkerHealthState::REASON_NOT_READY,
            default => WorkerHealthState::REASON_HEALTHY,
        };

        return new WorkerHealthState(
            pid: $state->pid(),
            status: $state->status(),
            workerCount: $state->workerCount(),
            readyWorkerCount: $state->readyWorkerCount(),
            healthy: $healthy,
            reason: $reason,
            driver: $state->driver(),
            controlTransport: $state->controlTransport(),
            endpointHash: $state->endpointHash(),
        );
    }

    private static function currentPid(): int
    {
        $pid = \getmypid();

        if (!\is_int($pid) || $pid < 1) {
            throw WorkerStartFailedException::startFailed();
        }

        return $pid;
    }

    private static function remainingMs(int $deadlineNs): int
    {
        $remainingMs = self::remainingMsOrNull($deadlineNs);

        if ($remainingMs === null) {
            throw WorkerLifecycleFailedException::shutdownFailed();
        }

        return $remainingMs;
    }

    private static function remainingMsOrNull(int $deadlineNs): ?int
    {
        $remainingNs = $deadlineNs - \hrtime(true);

        if ($remainingNs <= 0) {
            return null;
        }

        return \max(1, (int)\ceil($remainingNs / 1_000_000));
    }

    private static function deadlineNs(int $timeoutMs): int
    {
        $nowNs = \hrtime(true);

        if (!\is_int($nowNs)) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        return self::deadlineNsFrom(
            startNs: $nowNs,
            timeoutMs: $timeoutMs,
        );
    }

    private static function deadlineNsFrom(
        int $startNs,
        int $timeoutMs,
    ): int {
        if (
            $startNs < 1
            || $timeoutMs < 1
            || $timeoutMs > \intdiv(
                \PHP_INT_MAX - $startNs,
                1_000_000,
            )
        ) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        return $startNs + ($timeoutMs * 1_000_000);
    }

    private function safeStartSpan(): ?SpanInterface
    {
        try {
            return $this->tracer->startSpan(self::SPAN_WORKER_PROCESS);
        } catch (\Throwable) {
            return null;
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

    private function safeStopTimer(mixed $startedAt): int
    {
        if (!\is_int($startedAt) || $startedAt <= 0) {
            return 0;
        }

        try {
            $durationMs = $this->stopwatch->stop($startedAt);

            return $durationMs >= 0
                ? $durationMs
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safeFinishSpan(
        ?SpanInterface $span,
        ?WorkerPoolState $state,
        int $exitCode,
        mixed $startedAt,
        bool $startupCompleted,
        mixed $startupStartedAt,
    ): void {
        $outcome = $exitCode === self::EXIT_SUCCESS
            ? self::OUTCOME_SUCCESS
            : self::OUTCOME_FAILURE;

        if ($startupCompleted) {
            $status = $exitCode === self::EXIT_SUCCESS
                ? self::STATUS_STOP_SUCCESS
                : self::STATUS_STOP_FAILURE;

            $this->safeEmitProcessMetric($status);

            $this->safeLogProcessSummary(
                event: self::LOG_EVENT_WORKER_STOP,
                status: $status,
                outcome: $outcome,
                durationMs: $this->safeStopTimer($startedAt),
                state: $state,
            );
        } else {
            $this->safeEmitProcessMetric(self::STATUS_START_FAILURE);
            $this->safeLogProcessSummary(
                event: self::LOG_EVENT_WORKER_START,
                status: self::STATUS_START_FAILURE,
                outcome: self::OUTCOME_FAILURE,
                durationMs: $this->safeStopTimer($startupStartedAt),
                state: $state,
            );
        }

        if ($span === null) {
            return;
        }

        $attributes = [
            'outcome' => $outcome,
        ];

        if ($state !== null) {
            $attributes['pid'] = $state->pid();
        }

        try {
            $span->setAttributes($attributes);
        } catch (\Throwable) {
        }

        try {
            $span->end();
        } catch (\Throwable) {
        }
    }

    private function safeEmitProcessMetric(
        string $status,
    ): void {
        if (
            !\in_array(
                $status,
                [
                    self::STATUS_START_SUCCESS,
                    self::STATUS_START_FAILURE,
                    self::STATUS_STOP_SUCCESS,
                    self::STATUS_STOP_FAILURE,
                ],
                true,
            )
        ) {
            return;
        }

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

    private function safeLogProcessSummary(
        string $event,
        string $status,
        string $outcome,
        int $durationMs,
        ?WorkerPoolState $state,
    ): void {
        $context = [
            'status' => $status,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
        ];

        if ($state !== null) {
            $context = [
                ...$context,
                'pid' => $state->pid(),
                'worker_count' => $state->workerCount(),
                'driver' => $state->driver(),
                'control_transport' => $state->controlTransport(),
                'endpoint_hash' => $state->endpointHash(),
            ];
        }

        try {
            $this->logger->info(
                $event,
                $context,
            );
        } catch (\Throwable) {
        }
    }
}
