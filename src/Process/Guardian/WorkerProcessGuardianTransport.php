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

namespace Coretsia\Platform\Worker\Process\Guardian;

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;

/** Creates bounded private loopback connections for supervisor ↔ guardian IPC. */
final class WorkerProcessGuardianTransport
{
    private const int CONNECT_RETRY_US = 1_000;

    /** @return resource */
    public function accept(int $port, int $timeoutMs): mixed
    {
        self::assertPortAndTimeout($port, $timeoutMs);

        $listener = @\stream_socket_server(
            'tcp://127.0.0.1:' . $port,
            $errorCode,
            $errorMessage,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );

        if (!\is_resource($listener)) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        try {
            $seconds = \max(1, (int)\ceil($timeoutMs / 1000));
            $connection = @\stream_socket_accept($listener, $seconds);
        } finally {
            @\fclose($listener);
        }

        return self::normalize($connection);
    }

    /** @return resource */
    public function connect(int $port, int $timeoutMs): mixed
    {
        self::assertPortAndTimeout($port, $timeoutMs);
        $deadlineNs = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $remainingNs = $deadlineNs - \hrtime(true);
            if ($remainingNs <= 0) {
                break;
            }

            $timeoutSeconds = \max(0.001, \min(0.05, $remainingNs / 1_000_000_000));
            $connection = @\stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errorCode,
                $errorMessage,
                $timeoutSeconds,
                \STREAM_CLIENT_CONNECT,
            );

            if (\is_resource($connection)) {
                return self::normalize($connection);
            }

            \usleep(self::CONNECT_RETRY_US);
        } while (\hrtime(true) < $deadlineNs);

        throw WorkerLifecycleFailedException::processGuardianFailed();
    }

    public static function reserveLoopbackPort(): int
    {
        if (!WorkerProcessCapabilities::guardianTransportAvailable()) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        $listener = @\stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!\is_resource($listener)) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        try {
            $name = @\stream_socket_get_name($listener, false);
        } finally {
            @\fclose($listener);
        }

        if (!\is_string($name) || \preg_match('/:(\d+)\z/', $name, $match) !== 1) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        $port = (int)$match[1];
        if ($port < 1 || $port > 65_535) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        return $port;
    }

    private static function assertPortAndTimeout(int $port, int $timeoutMs): void
    {
        if (
            $port < 1
            || $port > 65_535
            || $timeoutMs < 1
            || $timeoutMs > 86_400_000
            || !WorkerProcessCapabilities::guardianTransportAvailable()
        ) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    /** @return resource */
    private static function normalize(mixed $connection): mixed
    {
        if (!\is_resource($connection) || !@\stream_set_blocking($connection, false)) {
            if (\is_resource($connection)) {
                @\fclose($connection);
            }
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        return $connection;
    }
}
