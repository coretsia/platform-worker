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

namespace Coretsia\Platform\Worker\Process\Bootstrap;

/**
 * Child-side receiver and authenticator for one private bootstrap frame.
 *
 * @internal
 */
final class WorkerProcessBootstrapClient
{
    private const int CONNECT_RETRY_US = 1_000;

    /** @var null|array{role: string, port: int, credential: string} */
    private ?array $target = null;
    private ?int $deadlineNs = null;

    public function __construct(
        private readonly WorkerProcessBootstrapProtocol $protocol,
    ) {
    }

    /** @return array{driver: 'pcntl'|'proc', timeout_ms: int<1, 86400000>} */
    public function receiveGuardian(): array
    {
        $frame = $this->readAndCloseBootstrapInput();
        $launch = $this->protocol->decodeGuardianLaunch($frame);
        $this->initializeTarget(
            $launch['role'],
            $launch['port'],
            $launch['credential'],
            $launch['timeout_ms'],
        );

        return [
            'driver' => $launch['driver'],
            'timeout_ms' => $launch['timeout_ms'],
        ];
    }

    /** @return array{timeout_ms: int<1, 86400000>} */
    public function receiveProcHost(): array
    {
        $frame = $this->readAndCloseBootstrapInput();
        $launch = $this->protocol->decodeProcHostLaunch($frame);
        $this->initializeTarget(
            $launch['role'],
            $launch['port'],
            $launch['credential'],
            $launch['timeout_ms'],
        );

        return [
            'timeout_ms' => $launch['timeout_ms'],
        ];
    }

    public function remainingMs(): int
    {
        $deadlineNs = $this->deadlineNs;
        if (!\is_int($deadlineNs)) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        $remainingNs = $deadlineNs - \hrtime(true);
        if ($remainingNs <= 0) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        return \max(1, (int)\ceil($remainingNs / 1_000_000));
    }

    /** @return resource */
    public function connect(): mixed
    {
        $target = $this->target;
        if (!\is_array($target)) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        $authFrame = $this->protocol->encodeAuthentication(
            $target['role'],
            $target['credential'],
        );

        do {
            $remainingMs = $this->remainingMs();
            $connection = @\stream_socket_client(
                'tcp://127.0.0.1:' . $target['port'],
                $errorCode,
                $errorMessage,
                \max(0.001, \min(0.05, $remainingMs / 1_000)),
                \STREAM_CLIENT_CONNECT,
            );

            if (\is_resource($connection)) {
                try {
                    if (!@\stream_set_blocking($connection, false)) {
                        throw WorkerProcessBootstrapFailure::failed();
                    }

                    $this->writeFrame($connection, $authFrame);
                    $this->target = null;
                    return $connection;
                } catch (\Throwable) {
                    @\fclose($connection);
                    throw WorkerProcessBootstrapFailure::failed();
                }
            }

            \usleep(self::CONNECT_RETRY_US);
        } while (true);
    }

    private function readAndCloseBootstrapInput(): string
    {
        $stream = \defined('STDIN') ? \constant('STDIN') : null;
        if (!\is_resource($stream)) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        $buffer = '';

        try {
            while (!@\feof($stream)) {
                $remaining = WorkerProcessBootstrapProtocol::MAX_LAUNCH_FRAME_BYTES
                    + 1
                    - \strlen($buffer);

                if ($remaining < 1) {
                    throw WorkerProcessBootstrapFailure::failed();
                }

                $chunk = @\fread($stream, \min(512, $remaining));
                if ($chunk === false) {
                    throw WorkerProcessBootstrapFailure::failed();
                }

                if ($chunk === '') {
                    if (@\feof($stream)) {
                        break;
                    }
                    continue;
                }

                $buffer .= $chunk;
                if (\strlen($buffer) > WorkerProcessBootstrapProtocol::MAX_LAUNCH_FRAME_BYTES) {
                    throw WorkerProcessBootstrapFailure::failed();
                }
            }
        } finally {
            @\fclose($stream);
        }

        if (
            $buffer === ''
            || !\str_ends_with($buffer, "\n")
            || \substr_count($buffer, "\n") !== 1
        ) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        return $buffer;
    }

    private function initializeTarget(
        string $role,
        int $port,
        string $credential,
        int $timeoutMs,
    ): void {
        if ($this->target !== null || $this->deadlineNs !== null) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        $this->target = [
            'role' => $role,
            'port' => $port,
            'credential' => $credential,
        ];
        $this->deadlineNs = \hrtime(true) + ($timeoutMs * 1_000_000);
    }

    /** @param resource $connection */
    private function writeFrame(mixed $connection, string $frame): void
    {
        $remaining = $frame;

        while ($remaining !== '') {
            $deadlineNs = $this->deadlineNs;
            if (!\is_int($deadlineNs)) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            $remainingNs = $deadlineNs - \hrtime(true);
            if ($remainingNs <= 0) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            $read = null;
            $write = [$connection];
            $except = null;
            $selected = @\stream_select(
                $read,
                $write,
                $except,
                \intdiv($remainingNs, 1_000_000_000),
                (int)\intdiv($remainingNs % 1_000_000_000, 1_000),
            );

            if ($selected !== 1) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            $written = @\fwrite($connection, $remaining);
            if (!\is_int($written) || $written < 1) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            $remaining = \substr($remaining, $written);
        }

        if (!@\fflush($connection)) {
            throw WorkerProcessBootstrapFailure::failed();
        }
    }
}
