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

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;

/**
 * Immutable normalized worker pool specification.
 *
 * This value object represents the complete validated `worker.*`
 * configuration subtree after config defaults, merge, directives, and
 * validation have already completed.
 *
 * It intentionally does not load `config/worker.php` and does not invent
 * missing defaults. Callers must pass the complete merged `worker` subtree.
 *
 * Path values are stored exactly as configured and must remain
 * skeleton-root-relative strings. This class never prepends `skeleton/`,
 * never canonicalizes paths through the filesystem, and never stores absolute
 * paths.
 *
 * Endpoint identifiers are exposed only for deterministic hashing by runtime
 * collaborators such as WorkerStateStore. They must not be used in logs,
 * public diagnostics, or exception messages.
 *
 * @phpstan-type WorkerConfig array{
 *     enabled: bool,
 *     workers: int,
 *     max_requests: int,
 *     task_type: 'http'|'queue',
 *     socket_path: non-empty-string,
 *     driver: 'auto'|'pcntl'|'proc',
 *     control: array{transport: 'auto'|'unix'|'tcp'},
 *     tcp: array{host: non-empty-string, port: int},
 *     state_path: non-empty-string,
 *     stop_flag_path: non-empty-string,
 *     stop_timeout_ms: int
 * }
 */
final readonly class WorkerPoolSpec
{
    private const string TASK_TYPE_HTTP = 'http';
    private const string TASK_TYPE_QUEUE = 'queue';

    private const string DRIVER_REQUESTED_AUTO = 'auto';
    private const string DRIVER_PCNTL = 'pcntl';
    private const string DRIVER_PROC = 'proc';

    private const string CONTROL_TRANSPORT_REQUESTED_AUTO = 'auto';
    private const string CONTROL_TRANSPORT_UNIX = 'unix';
    private const string CONTROL_TRANSPORT_TCP = 'tcp';

    private function __construct(
        private bool $enabled,
        private int $workers,
        private int $maxRequests,
        private string $taskType,
        private string $socketPath,
        private string $driverRequested,
        private string $driver,
        private string $controlTransportRequested,
        private string $controlTransport,
        private string $tcpHost,
        private int $tcpPort,
        private string $statePath,
        private string $stopFlagPath,
        private int $stopTimeoutMs,
    ) {
    }

    /**
     * Builds a normalized worker pool specification from the complete merged
     * `worker` configuration subtree.
     *
     * Capability arguments are nullable on purpose:
     *
     * - production code may pass null to use deterministic runtime capability checks;
     * - tests should pass explicit values and must not depend on host pcntl or
     *   unix-domain-socket support.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        ?bool $pcntlForkAvailable = null,
        ?string $platformFamily = null,
        ?bool $unixDomainSocketsSupported = null,
    ): self {
        $enabled = self::requiredBool($config, 'enabled');
        $workers = self::requiredPositiveInt($config, 'workers');
        $maxRequests = self::requiredPositiveInt($config, 'max_requests');
        $taskType = self::requiredString($config, 'task_type');
        $socketPath = self::requiredString($config, 'socket_path');
        $driverRequested = self::requiredString($config, 'driver');
        $control = self::requiredMap($config, 'control');
        $controlTransportRequested = self::requiredString($control, 'transport');
        $tcp = self::requiredMap($config, 'tcp');
        $tcpHost = self::requiredString($tcp, 'host');
        $tcpPort = self::requiredTcpPort($tcp, 'port');
        $statePath = self::requiredString($config, 'state_path');
        $stopFlagPath = self::requiredString($config, 'stop_flag_path');
        $stopTimeoutMs = self::requiredNonNegativeInt($config, 'stop_timeout_ms');

        self::assertTaskType($taskType);
        self::assertRequestedDriver($driverRequested);
        self::assertRequestedControlTransport($controlTransportRequested);

        self::assertRelativeSafePath($socketPath);
        self::assertRelativeSafePath($statePath);
        self::assertRelativeSafePath($stopFlagPath);
        self::assertSafeTcpHost($tcpHost);

        $platformFamily = $platformFamily ?? \PHP_OS_FAMILY;
        $pcntlForkAvailable = $pcntlForkAvailable ?? self::detectPcntlForkAvailable();
        $unixDomainSocketsSupported = $unixDomainSocketsSupported
            ?? self::detectUnixDomainSocketsSupported($platformFamily);

        $driver = self::resolveDriver(
            requested: $driverRequested,
            pcntlForkAvailable: $pcntlForkAvailable,
            platformFamily: $platformFamily,
        );

        $controlTransport = self::resolveControlTransport(
            requested: $controlTransportRequested,
            resolvedDriver: $driver,
            unixDomainSocketsSupported: $unixDomainSocketsSupported,
        );

        return new self(
            enabled: $enabled,
            workers: $workers,
            maxRequests: $maxRequests,
            taskType: $taskType,
            socketPath: $socketPath,
            driverRequested: $driverRequested,
            driver: $driver,
            controlTransportRequested: $controlTransportRequested,
            controlTransport: $controlTransport,
            tcpHost: $tcpHost,
            tcpPort: $tcpPort,
            statePath: $statePath,
            stopFlagPath: $stopFlagPath,
            stopTimeoutMs: $stopTimeoutMs,
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function workers(): int
    {
        return $this->workers;
    }

    public function maxRequests(): int
    {
        return $this->maxRequests;
    }

    public function taskType(): string
    {
        return $this->taskType;
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

    public function socketPath(): string
    {
        return $this->socketPath;
    }

    public function statePath(): string
    {
        return $this->statePath;
    }

    public function stopFlagPath(): string
    {
        return $this->stopFlagPath;
    }

    public function tcpHost(): string
    {
        return $this->tcpHost;
    }

    public function tcpPort(): int
    {
        return $this->tcpPort;
    }

    public function stopTimeoutMs(): int
    {
        return $this->stopTimeoutMs;
    }

    /**
     * Returns the deterministic raw endpoint identifier used only for hashing.
     *
     * This value is intentionally not safe for logs or public diagnostics.
     */
    public function endpointIdentifier(): string
    {
        return match ($this->controlTransport) {
            self::CONTROL_TRANSPORT_UNIX => 'unix:' . $this->socketPath,
            self::CONTROL_TRANSPORT_TCP => 'tcp:' . $this->tcpHost . ':' . $this->tcpPort,
            default => throw WorkerStartFailedException::invalidState(),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredBool(array $config, string $key): bool
    {
        if (!\array_key_exists($key, $config) || !\is_bool($config[$key])) {
            throw WorkerStartFailedException::invalidState();
        }

        return $config[$key];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredPositiveInt(array $config, string $key): int
    {
        $value = self::requiredInt($config, $key);

        if ($value < 1) {
            throw WorkerStartFailedException::invalidState();
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredNonNegativeInt(array $config, string $key): int
    {
        $value = self::requiredInt($config, $key);

        if ($value < 0) {
            throw WorkerStartFailedException::invalidState();
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredTcpPort(array $config, string $key): int
    {
        $value = self::requiredInt($config, $key);

        if ($value < 1 || $value > 65535) {
            throw WorkerStartFailedException::invalidState();
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredInt(array $config, string $key): int
    {
        if (!\array_key_exists($key, $config) || !\is_int($config[$key])) {
            throw WorkerStartFailedException::invalidState();
        }

        return $config[$key];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredString(array $config, string $key): string
    {
        if (!\array_key_exists($key, $config) || !\is_string($config[$key])) {
            throw WorkerStartFailedException::invalidState();
        }

        $value = $config[$key];

        if ($value === '') {
            throw WorkerStartFailedException::invalidState();
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function requiredMap(array $config, string $key): array
    {
        if (!\array_key_exists($key, $config) || !\is_array($config[$key])) {
            throw WorkerStartFailedException::invalidState();
        }

        /** @var array<string, mixed> $value */
        $value = $config[$key];

        return $value;
    }

    private static function assertTaskType(string $taskType): void
    {
        if (!\in_array(
            $taskType,
            [
                self::TASK_TYPE_HTTP,
                self::TASK_TYPE_QUEUE,
            ],
            true,
        )) {
            throw WorkerStartFailedException::invalidState();
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
            throw WorkerStartFailedException::invalidState();
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
            throw WorkerStartFailedException::invalidState();
        }
    }

    private static function assertRelativeSafePath(string $path): void
    {
        if ($path === '') {
            throw WorkerStartFailedException::invalidState();
        }

        if (\trim($path) !== $path) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\preg_match('/\s/u', $path) === 1) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\str_contains($path, "\0")) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\str_contains($path, '\\')) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\str_starts_with($path, '/') || \str_starts_with($path, '\\')) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\preg_match('/\A[A-Za-z]:[\/\\\\]/u', $path) === 1) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\str_contains($path, '://')) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\str_starts_with($path, 'skeleton/')) {
            throw WorkerStartFailedException::invalidState();
        }

        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw WorkerStartFailedException::invalidState();
            }

            if (\str_starts_with($segment, '@')) {
                throw WorkerStartFailedException::invalidState();
            }
        }
    }

    private static function assertSafeTcpHost(string $host): void
    {
        if ($host === '') {
            throw WorkerStartFailedException::invalidState();
        }

        if (\trim($host) !== $host) {
            throw WorkerStartFailedException::invalidState();
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $host) === 1) {
            throw WorkerStartFailedException::invalidState();
        }
    }

    private static function resolveDriver(
        string $requested,
        bool $pcntlForkAvailable,
        string $platformFamily,
    ): string {
        if ($requested === self::DRIVER_PCNTL || $requested === self::DRIVER_PROC) {
            return $requested;
        }

        if ($requested !== self::DRIVER_REQUESTED_AUTO) {
            throw WorkerStartFailedException::invalidState();
        }

        if ($pcntlForkAvailable && !self::isWindowsPlatform($platformFamily)) {
            return self::DRIVER_PCNTL;
        }

        return self::DRIVER_PROC;
    }

    private static function resolveControlTransport(
        string $requested,
        string $resolvedDriver,
        bool $unixDomainSocketsSupported,
    ): string {
        if (
            $requested === self::CONTROL_TRANSPORT_UNIX
            || $requested === self::CONTROL_TRANSPORT_TCP
        ) {
            return $requested;
        }

        if ($requested !== self::CONTROL_TRANSPORT_REQUESTED_AUTO) {
            throw WorkerStartFailedException::invalidState();
        }

        if (
            $resolvedDriver === self::DRIVER_PCNTL
            && $unixDomainSocketsSupported
        ) {
            return self::CONTROL_TRANSPORT_UNIX;
        }

        return self::CONTROL_TRANSPORT_TCP;
    }

    private static function detectPcntlForkAvailable(): bool
    {
        return \function_exists('pcntl_fork');
    }

    private static function detectUnixDomainSocketsSupported(string $platformFamily): bool
    {
        if (self::isWindowsPlatform($platformFamily)) {
            return false;
        }

        $transports = \stream_get_transports();

        return \in_array('unix', $transports, true);
    }

    private static function isWindowsPlatform(string $platformFamily): bool
    {
        return \strcasecmp($platformFamily, 'Windows') === 0;
    }
}
