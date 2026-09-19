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
 * Immutable safe worker pool state.
 *
 * This DTO represents only the safe state fields allowed in
 * `worker.state.json`.
 *
 * It intentionally stores no timestamps, environment values, raw socket paths,
 * raw TCP endpoints, absolute paths, payloads, headers, or tokens.
 *
 * @phpstan-type WorkerPoolStateArray array{
 *     version: 1,
 *     pid: positive-int,
 *     status: 'starting'|'running'|'stopping',
 *     worker_count: positive-int,
 *     ready_worker_count: non-negative-int,
 *     driver_requested: 'auto'|'pcntl'|'proc',
 *     driver: 'pcntl'|'proc',
 *     control_transport_requested: 'auto'|'unix'|'tcp',
 *     control_transport: 'unix'|'tcp',
 *     endpoint_hash: non-empty-string
 * }
 */
final readonly class WorkerPoolState
{
    private const int VERSION = 1;
    private const string ENDPOINT_HASH_PATTERN = '/\A[a-f0-9]{64}\z/';

    public function __construct(
        private int $pid,
        private WorkerPoolStatus $status,
        private int $workerCount,
        private int $readyWorkerCount,
        private string $driverRequested,
        private string $driver,
        private string $controlTransportRequested,
        private string $controlTransport,
        private string $endpointHash,
    ) {
        if ($pid < 1 || $workerCount < 1 || $readyWorkerCount < 0 || $readyWorkerCount > $workerCount) {
            throw new \InvalidArgumentException('worker-pool-state-count-invalid');
        }

        if (!\in_array($driverRequested, ['auto', 'pcntl', 'proc'], true) || !\in_array(
            $driver,
            ['pcntl', 'proc'],
            true
        )) {
            throw new \InvalidArgumentException('worker-pool-state-driver-invalid');
        }

        if (!\in_array($controlTransportRequested, ['auto', 'unix', 'tcp'], true) || !\in_array(
            $controlTransport,
            ['unix', 'tcp'],
            true
        )) {
            throw new \InvalidArgumentException('worker-pool-state-control-transport-invalid');
        }

        if (\preg_match(self::ENDPOINT_HASH_PATTERN, $endpointHash) !== 1) {
            throw new \InvalidArgumentException('worker-pool-state-endpoint-hash-invalid');
        }
    }

    public function version(): int
    {
        return self::VERSION;
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

    public function driverRequested(): string
    {
        return $this->driverRequested;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function controlTransportRequested(): string
    {
        return $this->controlTransportRequested;
    }

    public function controlTransport(): string
    {
        return $this->controlTransport;
    }

    public function endpointHash(): string
    {
        return $this->endpointHash;
    }

    public function withStatus(WorkerPoolStatus $status, int $readyWorkerCount): self
    {
        return new self(
            pid: $this->pid,
            status: $status,
            workerCount: $this->workerCount,
            readyWorkerCount: $readyWorkerCount,
            driverRequested: $this->driverRequested,
            driver: $this->driver,
            controlTransportRequested: $this->controlTransportRequested,
            controlTransport: $this->controlTransport,
            endpointHash: $this->endpointHash,
        );
    }

    /**
     * Returns the canonical safe `worker.state.json` shape.
     *
     * Key order is stable and matches the cemented state schema.
     *
     * @return WorkerPoolStateArray
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'pid' => $this->pid,
            'status' => $this->status->value,
            'worker_count' => $this->workerCount,
            'ready_worker_count' => $this->readyWorkerCount,
            'driver_requested' => $this->driverRequested,
            'driver' => $this->driver,
            'control_transport_requested' => $this->controlTransportRequested,
            'control_transport' => $this->controlTransport,
            'endpoint_hash' => $this->endpointHash,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        $keys = [
            'control_transport',
            'control_transport_requested',
            'driver',
            'driver_requested',
            'endpoint_hash',
            'pid',
            'ready_worker_count',
            'status',
            'version',
            'worker_count',
        ];
        $actual = \array_keys($value);
        \sort($actual, \SORT_STRING);

        if ($actual !== $keys || ($value['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('worker-pool-state-schema-invalid');
        }

        foreach (['pid', 'worker_count', 'ready_worker_count'] as $key) {
            if (!\is_int($value[$key])) {
                throw new \InvalidArgumentException('worker-pool-state-schema-invalid');
            }
        }

        foreach (
            [
                'status',
                'driver_requested',
                'driver',
                'control_transport_requested',
                'control_transport',
                'endpoint_hash'
            ] as $key
        ) {
            if (!\is_string($value[$key])) {
                throw new \InvalidArgumentException('worker-pool-state-schema-invalid');
            }
        }

        return new self(
            pid: $value['pid'],
            status: WorkerPoolStatus::from($value['status']),
            workerCount: $value['worker_count'],
            readyWorkerCount: $value['ready_worker_count'],
            driverRequested: $value['driver_requested'],
            driver: $value['driver'],
            controlTransportRequested: $value['control_transport_requested'],
            controlTransport: $value['control_transport'],
            endpointHash: $value['endpoint_hash'],
        );
    }
}
