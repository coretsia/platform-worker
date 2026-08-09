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
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;

/**
 * Creates bounded private proc-host loopback connections.
 *
 * Descriptor isolation does not depend on platform-specific close-on-exec
 * flags. Before every worker-child launch, the host closes its authenticated
 * connection and establishes a replacement connection only after proc_open()
 * returns.
 */
final class WorkerProcProcessHostTransport
{
    private const int ACCEPT_TIMEOUT_SECONDS = 10;
    private const int CONNECT_RETRY_US = 1_000;

    /**
     * Accepts the initial private guardian connection.
     *
     * @return resource
     */
    public function accept(int $port): mixed
    {
        if (
            $port < 1
            || $port > 65_535
            || !WorkerProcessCapabilities::procProcessHostTransportAvailable()
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $listener = @\stream_socket_server(
            'tcp://127.0.0.1:' . $port,
            $errorCode,
            $errorMessage,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );

        if (!\is_resource($listener)) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        try {
            $connection = @\stream_socket_accept(
                $listener,
                self::ACCEPT_TIMEOUT_SECONDS,
            );
        } finally {
            @\fclose($listener);
        }

        return self::normalizeConnection($connection);
    }

    /**
     * Connects the host to one guardian-owned handoff endpoint.
     *
     * @return resource
     */
    public function connect(int $port, int $timeoutMs): mixed
    {
        if (
            $port < 1
            || $port > 65_535
            || $timeoutMs < 1
            || !WorkerProcessCapabilities::procProcessHostTransportAvailable()
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $deadlineNs = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $remainingNs = $deadlineNs - \hrtime(true);

            if ($remainingNs <= 0) {
                break;
            }

            $timeoutSeconds = \max(
                0.001,
                \min(0.05, $remainingNs / 1_000_000_000),
            );

            $connection = @\stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errorCode,
                $errorMessage,
                $timeoutSeconds,
                \STREAM_CLIENT_CONNECT,
            );

            if (\is_resource($connection)) {
                return self::normalizeConnection($connection);
            }

            \usleep(self::CONNECT_RETRY_US);
        } while (\hrtime(true) < $deadlineNs);

        throw WorkerLifecycleFailedException::processHostFailed();
    }

    /**
     * @return resource
     */
    private static function normalizeConnection(mixed $connection): mixed
    {
        if (
            !\is_resource($connection)
            || !@\stream_set_blocking(
                $connection,
                false,
            )
        ) {
            if (\is_resource($connection)) {
                @\fclose($connection);
            }

            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $connection;
    }
}
