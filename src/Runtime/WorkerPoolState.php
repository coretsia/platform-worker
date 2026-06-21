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
 *     pid: int,
 *     worker_count: int,
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

    private const string DRIVER_REQUESTED_AUTO = 'auto';
    private const string DRIVER_PCNTL = 'pcntl';
    private const string DRIVER_PROC = 'proc';

    private const string CONTROL_TRANSPORT_REQUESTED_AUTO = 'auto';
    private const string CONTROL_TRANSPORT_UNIX = 'unix';
    private const string CONTROL_TRANSPORT_TCP = 'tcp';

    private const string ENDPOINT_HASH_PATTERN = '/\A[a-f0-9]{64}\z/';

    public function __construct(
        private int $pid,
        private int $workerCount,
        private string $driverRequested,
        private string $driver,
        private string $controlTransportRequested,
        private string $controlTransport,
        private string $endpointHash,
    ) {
        self::assertPositiveInt($pid, 'worker-pool-state-pid-invalid');
        self::assertPositiveInt($workerCount, 'worker-pool-state-worker-count-invalid');
        self::assertRequestedDriver($driverRequested);
        self::assertResolvedDriver($driver);
        self::assertRequestedControlTransport($controlTransportRequested);
        self::assertResolvedControlTransport($controlTransport);
        self::assertEndpointHash($endpointHash);
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function workerCount(): int
    {
        return $this->workerCount;
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
            'worker_count' => $this->workerCount,
            'driver_requested' => $this->driverRequested,
            'driver' => $this->driver,
            'control_transport_requested' => $this->controlTransportRequested,
            'control_transport' => $this->controlTransport,
            'endpoint_hash' => $this->endpointHash,
        ];
    }

    private static function assertPositiveInt(int $value, string $reason): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($reason);
        }
    }

    private static function assertRequestedDriver(string $driver): void
    {
        if (!\in_array(
            $driver,
            [
                self::DRIVER_REQUESTED_AUTO,
                self::DRIVER_PCNTL,
                self::DRIVER_PROC,
            ],
            true,
        )) {
            throw new \InvalidArgumentException('worker-pool-state-driver-requested-invalid');
        }
    }

    private static function assertResolvedDriver(string $driver): void
    {
        if (!\in_array(
            $driver,
            [
                self::DRIVER_PCNTL,
                self::DRIVER_PROC,
            ],
            true,
        )) {
            throw new \InvalidArgumentException('worker-pool-state-driver-invalid');
        }
    }

    private static function assertRequestedControlTransport(string $transport): void
    {
        if (!\in_array(
            $transport,
            [
                self::CONTROL_TRANSPORT_REQUESTED_AUTO,
                self::CONTROL_TRANSPORT_UNIX,
                self::CONTROL_TRANSPORT_TCP,
            ],
            true,
        )) {
            throw new \InvalidArgumentException('worker-pool-state-control-transport-requested-invalid');
        }
    }

    private static function assertResolvedControlTransport(string $transport): void
    {
        if (!\in_array(
            $transport,
            [
                self::CONTROL_TRANSPORT_UNIX,
                self::CONTROL_TRANSPORT_TCP,
            ],
            true,
        )) {
            throw new \InvalidArgumentException('worker-pool-state-control-transport-invalid');
        }
    }

    private static function assertEndpointHash(string $endpointHash): void
    {
        if (\preg_match(self::ENDPOINT_HASH_PATTERN, $endpointHash) !== 1) {
            throw new \InvalidArgumentException('worker-pool-state-endpoint-hash-invalid');
        }
    }
}
