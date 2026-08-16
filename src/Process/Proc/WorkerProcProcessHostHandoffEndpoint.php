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

namespace Coretsia\Platform\Worker\Process\Proc;

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Internal\WorkerLoopbackListener;

/**
 * Owns one guardian-side proc-host connection handoff endpoint.
 *
 * The endpoint is created only after the process host has started. Its listener
 * therefore cannot be inherited by the host or by a worker child launched from
 * the host. Each endpoint accepts exactly one replacement authenticated
 * connection and is then closed explicitly.
 */
final class WorkerProcProcessHostHandoffEndpoint
{
    private mixed $listener;

    private function __construct(
        mixed $listener,
        private readonly int $port,
        private readonly string $token,
    ) {
        if (
            !\is_resource($listener)
            || $port < 1
            || $port > 65_535
            || \preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
        ) {
            throw new \InvalidArgumentException('worker-proc-host-handoff-endpoint-invalid');
        }

        $this->listener = $listener;
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function create(): self
    {
        $listener = WorkerLoopbackListener::create();

        if (!\is_resource($listener)) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        try {
            $name = @\stream_socket_get_name($listener, false);
            $port = self::portFromName($name);

            try {
                $token = \bin2hex(\random_bytes(32));
            } catch (\Throwable) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            return new self(
                listener: $listener,
                port: $port,
                token: $token,
            );
        } catch (\Throwable $exception) {
            @\fclose($listener);

            if ($exception instanceof WorkerLifecycleFailedException) {
                throw $exception;
            }

            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    public function port(): int
    {
        return $this->port;
    }

    public function token(): string
    {
        return $this->token;
    }

    /**
     * Accepts the one replacement host connection before the supplied deadline.
     *
     * @return resource
     */
    public function accept(int $deadlineNs): mixed
    {
        $listener = $this->listener;

        if (
            !\is_resource($listener)
            || $deadlineNs <= \hrtime(true)
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        while (true) {
            $read = [$listener];
            $write = null;
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($deadlineNs);

            $selected = @\stream_select(
                $read,
                $write,
                $except,
                $seconds,
                $microseconds,
            );

            if ($selected === false) {
                if (\hrtime(true) < $deadlineNs) {
                    continue;
                }

                throw WorkerLifecycleFailedException::processHostFailed();
            }

            if ($selected !== 1) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            $connection = @\stream_socket_accept($listener, 0);

            if (!\is_resource($connection)) {
                if (\hrtime(true) < $deadlineNs) {
                    continue;
                }

                throw WorkerLifecycleFailedException::processHostFailed();
            }

            $this->close();

            if (!@\stream_set_blocking($connection, false)) {
                @\fclose($connection);

                throw WorkerLifecycleFailedException::processHostFailed();
            }

            return $connection;
        }
    }

    public function close(): void
    {
        if (\is_resource($this->listener)) {
            @\fclose($this->listener);
        }

        $this->listener = null;
    }

    private static function portFromName(mixed $name): int
    {
        if (!\is_string($name)) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $separator = \strrpos($name, ':');
        $value = $separator === false
            ? ''
            : \substr($name, $separator + 1);

        if (!\ctype_digit($value)) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $port = (int)$value;

        if ($port < 1 || $port > 65_535) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $port;
    }

    /** @return array{0: non-negative-int, 1: int<0, 999999>} */
    private static function selectTimeout(int $deadlineNs): array
    {
        $remainingNs = $deadlineNs - \hrtime(true);

        if ($remainingNs <= 0) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return [
            \intdiv($remainingNs, 1_000_000_000),
            (int)\intdiv(
                $remainingNs % 1_000_000_000,
                1_000,
            ),
        ];
    }
}
