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

use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;

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
 *     workers: positive-int,
 *     max_requests: positive-int,
 *     task_type: 'http'|'queue',
 *     socket_path: non-empty-string,
 *     driver: 'auto'|'pcntl'|'proc',
 *     proc: array{command: non-empty-list<non-empty-string>},
 *     control: array{transport: 'auto'|'unix'|'tcp'},
 *     tcp: array{host: '127.0.0.1', port: int<1, 65535>},
 *     state_path: non-empty-string,
 *     stop_flag_path: non-empty-string,
 *     start_timeout_ms: int<1, 86400000>,
 *     stop_timeout_ms: int<1, 86400000>,
 *     force_kill_timeout_ms: int<1, 86400000>
 * }
 */
final readonly class WorkerPoolSpec
{
    /**
     * Maximum supported lifecycle timeout: 24 hours.
     *
     * Worker lifecycle timeouts are operational deadlines, not arbitrary
     * application durations. The upper bound also protects nanosecond deadline
     * arithmetic and composite stop-client timeout calculations.
     */
    private const int MAX_TIMEOUT_MS = 86_400_000;

    private function __construct(
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
        private int $startTimeoutMs,
        private int $stopTimeoutMs,
        private int $forceKillTimeoutMs,
    ) {
    }

    /**
     * Builds a normalized worker pool specification from the complete merged
     * `worker` configuration subtree.
     *
     * Capability arguments are nullable on purpose:
     *
     * - production code may pass null to use deterministic runtime capability checks;
     * - tests should pass explicit values and must not depend on host pcntl,
     *   secure proc-host transport, or unix-domain-socket support.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        ?bool $pcntlDriverAvailable = null,
        ?string $platformFamily = null,
        ?bool $unixDomainSocketsSupported = null,
        ?bool $procDriverAvailable = null,
    ): self {
        $workers = self::positiveInt($config, 'workers');
        $maxRequests = self::positiveInt($config, 'max_requests');
        $taskType = self::string($config, 'task_type');
        $socketPath = self::string($config, 'socket_path');
        $driverRequested = self::string($config, 'driver');
        $control = self::map($config, 'control');
        $controlTransportRequested = self::string($control, 'transport');
        $tcp = self::map($config, 'tcp');
        $tcpHost = self::string($tcp, 'host');
        $tcpPort = self::int($tcp, 'port');
        $statePath = self::string($config, 'state_path');
        $stopFlagPath = self::string($config, 'stop_flag_path');
        $startTimeoutMs = self::timeoutInt($config, 'start_timeout_ms');
        $stopTimeoutMs = self::timeoutInt($config, 'stop_timeout_ms');
        $forceKillTimeoutMs = self::timeoutInt($config, 'force_kill_timeout_ms');

        if (WorkerTaskType::tryFrom($taskType) === null) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        if (!\in_array($driverRequested, ['auto', 'pcntl', 'proc'], true)) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        if (!\in_array($controlTransportRequested, ['auto', 'unix', 'tcp'], true)) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        if ($tcpPort < 1 || $tcpPort > 65535) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        foreach ([$socketPath, $statePath, $stopFlagPath] as $path) {
            self::assertRelativeSafePath($path);
        }
        self::assertRuntimeArtifactPathsDoNotOverlap([
            $socketPath,
            $statePath,
            $statePath . '.tmp',
            $stopFlagPath,
            WorkerLifecyclePaths::LOCK,
            WorkerLifecyclePaths::LOCATOR,
            WorkerLifecyclePaths::LOCATOR_TEMP,
        ]);
        self::assertSafeTcpHost($tcpHost);

        $platformFamily ??= \PHP_OS_FAMILY;
        $pcntlDriverAvailable ??= WorkerProcessCapabilities::pcntlDriverAvailable($platformFamily);
        $procDriverAvailable ??= WorkerProcessCapabilities::procDriverAvailable($platformFamily);
        $unixDomainSocketsSupported ??= self::detectUnixDomainSocketsSupported($platformFamily);

        $driver = match ($driverRequested) {
            'pcntl', 'proc' => $driverRequested,
            'auto' => self::resolveAutomaticDriver(
                pcntlDriverAvailable: $pcntlDriverAvailable,
                procDriverAvailable: $procDriverAvailable,
                platformFamily: $platformFamily,
            ),
        };

        $controlTransport = match ($controlTransportRequested) {
            'unix', 'tcp' => $controlTransportRequested,
            'auto' => $unixDomainSocketsSupported ? 'unix' : 'tcp',
        };

        return new self(
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
            startTimeoutMs: $startTimeoutMs,
            stopTimeoutMs: $stopTimeoutMs,
            forceKillTimeoutMs: $forceKillTimeoutMs,
        );
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

    public function socketPath(): string
    {
        return $this->socketPath;
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

    public function tcpHost(): string
    {
        return $this->tcpHost;
    }

    public function tcpPort(): int
    {
        return $this->tcpPort;
    }

    public function statePath(): string
    {
        return $this->statePath;
    }

    public function stopFlagPath(): string
    {
        return $this->stopFlagPath;
    }

    public function startTimeoutMs(): int
    {
        return $this->startTimeoutMs;
    }

    public function stopTimeoutMs(): int
    {
        return $this->stopTimeoutMs;
    }

    public function forceKillTimeoutMs(): int
    {
        return $this->forceKillTimeoutMs;
    }

    public function endpointIdentifier(): string
    {
        return match ($this->controlTransport) {
            'unix' => 'unix:' . $this->socketPath,
            'tcp' => 'tcp:' . $this->tcpHost . ':' . $this->tcpPort,
            default => throw WorkerLifecycleFailedException::invalidState(),
        };
    }

    /**
     * Reads a strictly positive bounded lifecycle timeout.
     *
     * @param array<string, mixed> $config
     */
    private static function timeoutInt(
        array $config,
        string $key,
    ): int {
        $value = self::positiveInt($config, $key);

        if ($value > self::MAX_TIMEOUT_MS) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function positiveInt(array $config, string $key): int
    {
        $value = self::int($config, $key);
        if ($value < 1) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function int(array $config, string $key): int
    {
        if (!\array_key_exists($key, $config) || !\is_int($config[$key])) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        return $config[$key];
    }

    /** @param array<string, mixed> $config */
    private static function string(array $config, string $key): string
    {
        if (!\array_key_exists($key, $config) || !\is_string($config[$key]) || $config[$key] === '') {
            throw WorkerLifecycleFailedException::invalidState();
        }
        return $config[$key];
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private static function map(array $config, string $key): array
    {
        if (!\array_key_exists($key, $config) || !\is_array($config[$key]) || \array_is_list($config[$key])) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        return $config[$key];
    }

    private static function assertRelativeSafePath(string $path): void
    {
        if ($path === '' || \trim($path) !== $path || \preg_match('/[\x00-\x20\x7F]/', $path) === 1) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        if (
            \str_starts_with($path, '/')
            || \str_starts_with($path, '\\')
            || \preg_match('/\A[A-Za-z]:[\/\\\\]/', $path) === 1
            || \str_contains($path, '\\')
            || \str_contains($path, '://')
            || \str_starts_with($path, 'skeleton/')
        ) {
            throw WorkerLifecycleFailedException::invalidState();
        }
        foreach (\explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || \str_starts_with($segment, '@')
            ) {
                throw WorkerLifecycleFailedException::invalidState();
            }
        }
    }

    /**
     * Rejects exact aliases and parent/child overlap between runtime artifacts.
     *
     * Comparison is ASCII case-insensitive so one configuration remains safe
     * on both case-sensitive and case-insensitive filesystems.
     *
     * @param non-empty-list<non-empty-string> $paths
     */
    private static function assertRuntimeArtifactPathsDoNotOverlap(array $paths): void
    {
        $count = \count($paths);

        for ($leftIndex = 0; $leftIndex < $count; $leftIndex++) {
            $left = \strtolower($paths[$leftIndex]);

            for ($rightIndex = $leftIndex + 1; $rightIndex < $count; $rightIndex++) {
                $right = \strtolower($paths[$rightIndex]);

                if (
                    $left === $right
                    || \str_starts_with($left, $right . '/')
                    || \str_starts_with($right, $left . '/')
                ) {
                    throw WorkerLifecycleFailedException::invalidState();
                }
            }
        }
    }

    private static function resolveAutomaticDriver(
        bool $pcntlDriverAvailable,
        bool $procDriverAvailable,
        string $platformFamily,
    ): string {
        if (
            $pcntlDriverAvailable && \strcasecmp($platformFamily, 'Windows') !== 0
        ) {
            return 'pcntl';
        }

        if ($procDriverAvailable) {
            return 'proc';
        }

        throw WorkerLifecycleFailedException::invalidState();
    }

    /**
     * Restricts the authenticated TCP control channel to the canonical IPv4
     * loopback host.
     */
    private static function assertSafeTcpHost(string $host): void
    {
        if ($host !== '127.0.0.1') {
            throw WorkerLifecycleFailedException::invalidState();
        }
    }


    private static function detectUnixDomainSocketsSupported(string $platformFamily): bool
    {
        return \strcasecmp($platformFamily, 'Windows') !== 0
            && \in_array('unix', \stream_get_transports(), true);
    }
}
