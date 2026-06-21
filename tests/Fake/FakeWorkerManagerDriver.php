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

namespace Coretsia\Platform\Worker\Tests\Fake;

use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

/**
 * Deterministic in-memory worker manager driver for tests.
 *
 * This fake implements the package-internal worker manager driver seam without
 * touching operating-system process or transport resources.
 *
 * It simulates:
 *
 * - start;
 * - stop;
 * - status;
 * - selected driver support;
 * - worker spawn counts;
 * - max_requests task budget.
 *
 * It is intended for unit and integration tests that need to verify
 * WorkerManager lifecycle behavior without depending on host runtime
 * capabilities.
 */
final class FakeWorkerManagerDriver implements WorkerManagerDriverInterface
{
    private string $name;

    private bool $supported;

    private int $pid;

    private bool $running = false;

    private ?WorkerPoolState $state = null;

    private int $startCalls = 0;

    private int $stopCalls = 0;

    private int $statusCalls = 0;

    private int $spawnedWorkerCount = 0;

    private int $plannedTaskIterations = 0;

    private ?\Throwable $startFailure = null;

    private ?\Throwable $stopFailure = null;

    private ?\Throwable $statusFailure = null;

    public function __construct(
        string $name = self::DRIVER_PROC,
        bool $supported = true,
        int $pid = 4242,
    ) {
        if ($name !== self::DRIVER_PROC && $name !== self::DRIVER_PCNTL) {
            throw new \InvalidArgumentException('fake-worker-manager-driver-name-invalid');
        }

        if ($pid < 1) {
            throw new \InvalidArgumentException('fake-worker-manager-driver-pid-invalid');
        }

        $this->name = $name;
        $this->supported = $supported;
        $this->pid = $pid;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        return $this->supported && $spec->driver() === $this->name;
    }

    public function start(WorkerPoolSpec $spec): WorkerPoolState
    {
        $this->startCalls++;

        if ($this->startFailure !== null) {
            throw $this->startFailure;
        }

        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        $this->spawnedWorkerCount = $spec->workers();
        $this->plannedTaskIterations = $spec->workers() * $spec->maxRequests();
        $this->state = self::stateFromSpec($spec, $this->pid);
        $this->running = true;

        return $this->state;
    }

    public function stop(WorkerPoolSpec $spec): WorkerPoolState
    {
        $this->stopCalls++;

        if ($this->stopFailure !== null) {
            throw $this->stopFailure;
        }

        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        if (!$this->running || $this->state === null) {
            throw WorkerNotRunningException::notRunning();
        }

        $this->running = false;

        return $this->state;
    }

    public function status(WorkerPoolSpec $spec): WorkerPoolState
    {
        $this->statusCalls++;

        if ($this->statusFailure !== null) {
            throw $this->statusFailure;
        }

        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        if (!$this->running || $this->state === null) {
            throw WorkerNotRunningException::notRunning();
        }

        return $this->state;
    }

    public function throwOnStart(\Throwable $throwable): void
    {
        $this->startFailure = $throwable;
    }

    public function throwOnStop(\Throwable $throwable): void
    {
        $this->stopFailure = $throwable;
    }

    public function throwOnStatus(\Throwable $throwable): void
    {
        $this->statusFailure = $throwable;
    }

    public function setSupported(bool $supported): void
    {
        $this->supported = $supported;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function startCalls(): int
    {
        return $this->startCalls;
    }

    public function stopCalls(): int
    {
        return $this->stopCalls;
    }

    public function statusCalls(): int
    {
        return $this->statusCalls;
    }

    public function spawnedWorkerCount(): int
    {
        return $this->spawnedWorkerCount;
    }

    public function plannedTaskIterations(): int
    {
        return $this->plannedTaskIterations;
    }

    public function lastState(): ?WorkerPoolState
    {
        return $this->state;
    }

    public function reset(): void
    {
        $this->running = false;
        $this->state = null;
        $this->startCalls = 0;
        $this->stopCalls = 0;
        $this->statusCalls = 0;
        $this->spawnedWorkerCount = 0;
        $this->plannedTaskIterations = 0;
        $this->startFailure = null;
        $this->stopFailure = null;
        $this->statusFailure = null;
    }

    private static function stateFromSpec(WorkerPoolSpec $spec, int $pid): WorkerPoolState
    {
        return new WorkerPoolState(
            pid: $pid,
            workerCount: $spec->workers(),
            driverRequested: $spec->driverRequested(),
            driver: $spec->driver(),
            controlTransportRequested: $spec->controlTransportRequested(),
            controlTransport: $spec->controlTransport(),
            endpointHash: \hash('sha256', $spec->endpointIdentifier()),
        );
    }
}
