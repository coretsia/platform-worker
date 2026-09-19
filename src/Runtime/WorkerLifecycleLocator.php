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

use Coretsia\Platform\Worker\Communication\WorkerControlCredential;

/**
 * Validated private capability locator of the active worker supervisor.
 *
 * The locator contains the active transport address, stop deadlines, and one
 * supervisor-instance control credential. It is not a diagnostic state snapshot
 * and not a control-protocol frame.
 *
 * Raw locator fields and the credential must remain private: they must not be
 * logged, rendered by CLI commands, copied into WorkerPoolState, or included in
 * exceptions. Endpoint identity deliberately excludes the credential.
 */
final readonly class WorkerLifecycleLocator
{
    public const int VERSION = 1;

    private const int MAX_TIMEOUT_MS = 86_400_000;

    /** @var list<string> */
    private const array SCHEMA_KEYS = [
        'control_credential',
        'control_transport',
        'force_kill_timeout_ms',
        'socket_path',
        'stop_timeout_ms',
        'tcp_host',
        'tcp_port',
        'version',
    ];

    private function __construct(
        #[\SensitiveParameter]
        private WorkerControlCredential $controlCredential,
        private string $controlTransport,
        private ?string $socketPath,
        private ?string $tcpHost,
        private ?int $tcpPort,
        private int $stopTimeoutMs,
        private int $forceKillTimeoutMs,
    ) {
    }

    public static function fromPoolSpec(
        WorkerPoolSpec $spec,
        #[\SensitiveParameter]
        WorkerControlCredential $credential,
    ): self {
        return match ($spec->controlTransport()) {
            'unix' => self::create(
                controlCredential: $credential,
                controlTransport: 'unix',
                socketPath: $spec->socketPath(),
                tcpHost: null,
                tcpPort: null,
                stopTimeoutMs: $spec->stopTimeoutMs(),
                forceKillTimeoutMs: $spec->forceKillTimeoutMs(),
            ),
            'tcp' => self::create(
                controlCredential: $credential,
                controlTransport: 'tcp',
                socketPath: null,
                tcpHost: $spec->tcpHost(),
                tcpPort: $spec->tcpPort(),
                stopTimeoutMs: $spec->stopTimeoutMs(),
                forceKillTimeoutMs: $spec->forceKillTimeoutMs(),
            ),
            default => throw new \InvalidArgumentException('worker-lifecycle-locator-invalid'),
        };
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(
        #[\SensitiveParameter]
        array $value,
    ): self {
        $keys = \array_keys($value);
        \sort($keys, \SORT_STRING);

        if (
            $keys !== self::SCHEMA_KEYS
            || ($value['version'] ?? null) !== self::VERSION
            || !\is_string($value['control_credential'] ?? null)
        ) {
            throw new \InvalidArgumentException('worker-lifecycle-locator-invalid');
        }

        try {
            $credential = WorkerControlCredential::fromEncoded($value['control_credential']);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('worker-lifecycle-locator-invalid');
        }

        return self::create(
            controlCredential: $credential,
            controlTransport: $value['control_transport'],
            socketPath: $value['socket_path'],
            tcpHost: $value['tcp_host'],
            tcpPort: $value['tcp_port'],
            stopTimeoutMs: $value['stop_timeout_ms'],
            forceKillTimeoutMs: $value['force_kill_timeout_ms'],
        );
    }

    /**
     * @return array{
     *     version: 1,
     *     control_credential: string,
     *     control_transport: 'unix'|'tcp',
     *     socket_path: non-empty-string|null,
     *     tcp_host: '127.0.0.1'|null,
     *     tcp_port: int<1, 65535>|null,
     *     stop_timeout_ms: int<1, 86400000>,
     *     force_kill_timeout_ms: int<1, 86400000>
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'control_credential' => $this->controlCredential->encoded(),
            'control_transport' => $this->controlTransport,
            'socket_path' => $this->socketPath,
            'tcp_host' => $this->tcpHost,
            'tcp_port' => $this->tcpPort,
            'stop_timeout_ms' => $this->stopTimeoutMs,
            'force_kill_timeout_ms' => $this->forceKillTimeoutMs,
        ];
    }

    public function controlCredential(): WorkerControlCredential
    {
        return $this->controlCredential;
    }

    public function controlTransport(): string
    {
        return $this->controlTransport;
    }

    public function socketPath(): ?string
    {
        return $this->socketPath;
    }

    public function tcpHost(): ?string
    {
        return $this->tcpHost;
    }

    public function tcpPort(): ?int
    {
        return $this->tcpPort;
    }

    public function stopTimeoutMs(): int
    {
        return $this->stopTimeoutMs;
    }

    public function forceKillTimeoutMs(): int
    {
        return $this->forceKillTimeoutMs;
    }

    public function stopRequestTimeoutMs(): int
    {
        return WorkerShutdownBudget::stopRequestTimeoutMs(
            $this->stopTimeoutMs,
            $this->forceKillTimeoutMs,
        );
    }

    /**
     * Returns raw endpoint identity for deterministic hashing only.
     */
    public function endpointIdentifier(): string
    {
        return match ($this->controlTransport) {
            'unix' => 'unix:' . $this->socketPath,
            'tcp' => 'tcp:' . $this->tcpHost . ':' . $this->tcpPort,
            default => throw new \LogicException('worker-lifecycle-locator-invalid'),
        };
    }

    public function endpointHash(): string
    {
        return \hash('sha256', $this->endpointIdentifier());
    }

    private static function create(
        #[\SensitiveParameter]
        WorkerControlCredential $controlCredential,
        mixed $controlTransport,
        mixed $socketPath,
        mixed $tcpHost,
        mixed $tcpPort,
        mixed $stopTimeoutMs,
        mixed $forceKillTimeoutMs,
    ): self {
        if (
            !\is_string($controlTransport)
            || !\in_array($controlTransport, ['unix', 'tcp'], true)
            || !\is_int($stopTimeoutMs)
            || $stopTimeoutMs < 1
            || $stopTimeoutMs > self::MAX_TIMEOUT_MS
            || !\is_int($forceKillTimeoutMs)
            || $forceKillTimeoutMs < 1
            || $forceKillTimeoutMs > self::MAX_TIMEOUT_MS
        ) {
            throw new \InvalidArgumentException('worker-lifecycle-locator-invalid');
        }

        if ($controlTransport === 'unix') {
            if (!\is_string($socketPath) || $tcpHost !== null || $tcpPort !== null) {
                throw new \InvalidArgumentException('worker-lifecycle-locator-invalid');
            }

            try {
                WorkerLifecyclePaths::resolve('/', $socketPath);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('worker-lifecycle-locator-invalid');
            }

            return new self(
                controlCredential: $controlCredential,
                controlTransport: 'unix',
                socketPath: $socketPath,
                tcpHost: null,
                tcpPort: null,
                stopTimeoutMs: $stopTimeoutMs,
                forceKillTimeoutMs: $forceKillTimeoutMs,
            );
        }

        if (
            $socketPath !== null
            || $tcpHost !== '127.0.0.1'
            || !\is_int($tcpPort)
            || $tcpPort < 1
            || $tcpPort > 65_535
        ) {
            throw new \InvalidArgumentException('worker-lifecycle-locator-invalid');
        }

        return new self(
            controlCredential: $controlCredential,
            controlTransport: 'tcp',
            socketPath: null,
            tcpHost: '127.0.0.1',
            tcpPort: $tcpPort,
            stopTimeoutMs: $stopTimeoutMs,
            forceKillTimeoutMs: $forceKillTimeoutMs,
        );
    }
}
