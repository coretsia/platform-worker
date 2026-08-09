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

namespace Coretsia\Platform\Worker\Communication;

/**
 * Mutable supervisor-side handle for one child readiness endpoint.
 *
 * Production process children use a tokenized loopback TCP listener. Connected
 * stream mode remains available for package-owned test doubles that exercise
 * supervisor readiness without launching the artifact-only child executable.
 */
final class WorkerChildReadinessEndpoint
{
    public const string MODE_STREAM = 'stream';
    public const string MODE_TCP_LISTENER = 'tcp-listener';

    private mixed $connection = null;
    private string $buffer = '';
    private bool $closed = false;

    private function __construct(
        private mixed $stream,
        private string $mode,
        private ?int $port,
        private ?string $token,
    ) {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('worker-readiness-endpoint-invalid');
        }

        if ($mode === self::MODE_STREAM) {
            if ($port !== null || $token !== null) {
                throw new \InvalidArgumentException('worker-readiness-endpoint-invalid');
            }

            return;
        }

        if (
            $mode !== self::MODE_TCP_LISTENER
            || !\is_int($port)
            || $port < 1
            || $port > 65535
            || !\is_string($token)
            || \preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
        ) {
            throw new \InvalidArgumentException('worker-readiness-endpoint-invalid');
        }
    }

    public static function stream(mixed $stream): self
    {
        return new self(
            stream: $stream,
            mode: self::MODE_STREAM,
            port: null,
            token: null,
        );
    }

    public static function tcpListener(
        mixed $listener,
        int $port,
        string $token,
    ): self {
        return new self(
            stream: $listener,
            mode: self::MODE_TCP_LISTENER,
            port: $port,
            token: $token,
        );
    }

    public function streamResource(): mixed
    {
        return $this->stream;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function port(): int
    {
        if ($this->port === null) {
            throw new \LogicException('worker-readiness-port-unavailable');
        }

        return $this->port;
    }

    public function token(): string
    {
        if ($this->token === null) {
            throw new \LogicException('worker-readiness-token-unavailable');
        }

        return $this->token;
    }

    public function connection(): mixed
    {
        return $this->connection;
    }

    public function attachConnection(mixed $connection): void
    {
        if (
            $this->mode !== self::MODE_TCP_LISTENER
            || $this->closed
            || \is_resource($this->connection)
            || !\is_resource($connection)
        ) {
            throw new \LogicException('worker-readiness-connection-invalid');
        }

        $this->connection = $connection;
    }

    public function append(string $bytes): void
    {
        if ($this->closed || $bytes === '') {
            throw new \LogicException('worker-readiness-buffer-invalid');
        }

        $this->buffer .= $bytes;
    }

    public function buffer(): string
    {
        return $this->buffer;
    }

    public function expectedFrame(): string
    {
        return $this->mode === self::MODE_STREAM
            ? "ready\n"
            : 'ready:' . $this->token() . "\n";
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if (\is_resource($this->connection)) {
            @\fclose($this->connection);
        }

        if (\is_resource($this->stream)) {
            @\fclose($this->stream);
        }

        $this->connection = null;
        $this->stream = null;
        $this->buffer = '';
        $this->closed = true;
    }
}
