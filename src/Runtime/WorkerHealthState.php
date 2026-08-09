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

namespace Coretsia\Platform\Worker\Runtime;

/**
 * Immutable live health projection returned by the supervisor.
 *
 * Health is true only while the pool is running, every configured child is
 * ready, and no terminal child failure is pending.
 */
final readonly class WorkerHealthState
{
    public const string REASON_HEALTHY = 'healthy';
    public const string REASON_STARTING = 'worker-pool-starting';
    public const string REASON_STOPPING = 'worker-pool-stopping';
    public const string REASON_NOT_READY = 'worker-pool-not-ready';
    public const string REASON_CHILD_FAILURE = 'worker-child-failure';

    private const array REASONS = [
        self::REASON_HEALTHY => true,
        self::REASON_STARTING => true,
        self::REASON_STOPPING => true,
        self::REASON_NOT_READY => true,
        self::REASON_CHILD_FAILURE => true,
    ];

    public function __construct(
        private int $pid,
        private WorkerPoolStatus $status,
        private int $workerCount,
        private int $readyWorkerCount,
        private bool $healthy,
        private string $reason,
        private string $driver,
        private string $controlTransport,
        private string $endpointHash,
    ) {
        if ($pid < 1 || $workerCount < 1 || $readyWorkerCount < 0 || $readyWorkerCount > $workerCount) {
            throw new \InvalidArgumentException('worker-health-count-invalid');
        }
        if (!isset(self::REASONS[$reason]) || !\in_array($driver, ['pcntl', 'proc'], true) || !\in_array(
            $controlTransport,
            ['unix', 'tcp'],
            true
        ) || \preg_match('/\A[a-f0-9]{64}\z/', $endpointHash) !== 1) {
            throw new \InvalidArgumentException('worker-health-state-invalid');
        }
        if ($healthy && ($status !== WorkerPoolStatus::RUNNING || $readyWorkerCount !== $workerCount || $reason !== self::REASON_HEALTHY)) {
            throw new \InvalidArgumentException('worker-health-state-invalid');
        }
        if (!$healthy && $reason === self::REASON_HEALTHY) {
            throw new \InvalidArgumentException('worker-health-state-invalid');
        }

        $validForStatus = match ($status) {
            WorkerPoolStatus::STARTING => !$healthy && $reason === self::REASON_STARTING,
            WorkerPoolStatus::STOPPING => !$healthy && \in_array(
                $reason,
                [
                        self::REASON_STOPPING,
                        self::REASON_CHILD_FAILURE,
                    ],
                true,
            ),
            WorkerPoolStatus::RUNNING => $healthy
                ? $reason === self::REASON_HEALTHY
                : \in_array(
                    $reason,
                    [self::REASON_NOT_READY, self::REASON_CHILD_FAILURE],
                    true,
                ),
        };

        if (!$validForStatus) {
            throw new \InvalidArgumentException('worker-health-state-invalid');
        }
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function status(): WorkerPoolStatus
    {
        return $this->status;
    }

    public function workerCount(): int
    {
        return $this->workerCount;
    }

    public function readyWorkerCount(): int
    {
        return $this->readyWorkerCount;
    }

    public function healthy(): bool
    {
        return $this->healthy;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function controlTransport(): string
    {
        return $this->controlTransport;
    }

    public function endpointHash(): string
    {
        return $this->endpointHash;
    }

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'status' => $this->status->value,
            'worker_count' => $this->workerCount,
            'ready_worker_count' => $this->readyWorkerCount,
            'healthy' => $this->healthy,
            'reason' => $this->reason,
            'driver' => $this->driver,
            'control_transport' => $this->controlTransport,
            'endpoint_hash' => $this->endpointHash,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        $expected = [
            'control_transport',
            'driver',
            'endpoint_hash',
            'healthy',
            'pid',
            'ready_worker_count',
            'reason',
            'status',
            'worker_count'
        ];
        $actual = \array_keys($value);
        \sort($actual, \SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('worker-health-schema-invalid');
        }
        foreach (['pid', 'worker_count', 'ready_worker_count'] as $key) {
            if (!\is_int($value[$key])) {
                throw new \InvalidArgumentException('worker-health-schema-invalid');
            }
        }
        foreach (['status', 'reason', 'driver', 'control_transport', 'endpoint_hash'] as $key) {
            if (!\is_string($value[$key])) {
                throw new \InvalidArgumentException('worker-health-schema-invalid');
            }
        }
        if (!\is_bool($value['healthy'])) {
            throw new \InvalidArgumentException('worker-health-schema-invalid');
        }
        return new self(
            pid: $value['pid'],
            status: WorkerPoolStatus::from($value['status']),
            workerCount: $value['worker_count'],
            readyWorkerCount: $value['ready_worker_count'],
            healthy: $value['healthy'],
            reason: $value['reason'],
            driver: $value['driver'],
            controlTransport: $value['control_transport'],
            endpointHash: $value['endpoint_hash'],
        );
    }
}
