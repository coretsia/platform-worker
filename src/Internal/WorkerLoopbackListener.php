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

namespace Coretsia\Platform\Worker\Internal;

/**
 * Creates retained package-owned loopback listeners with platform-safe address ownership.
 *
 * @internal
 */
final class WorkerLoopbackListener
{
    private function __construct()
    {
    }

    public static function available(?string $platformFamily = null): bool
    {
        $platformFamily ??= \PHP_OS_FAMILY;

        if (\strcasecmp($platformFamily, 'Windows') !== 0) {
            return \function_exists('stream_socket_server');
        }

        return \function_exists('socket_create')
            && \function_exists('socket_set_option')
            && \function_exists('socket_bind')
            && \function_exists('socket_listen')
            && \function_exists('socket_export_stream')
            && \function_exists('socket_close')
            && \defined('AF_INET')
            && \defined('SOCK_STREAM')
            && \defined('SOL_TCP')
            && \defined('SOL_SOCKET')
            && \defined('SOMAXCONN')
            && \defined('SO_EXCLUSIVEADDRUSE');
    }

    /** @return resource|null */
    public static function create(): mixed
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            $listener = @\stream_socket_server(
                'tcp://127.0.0.1:0',
                $errorCode,
                $errorMessage,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            );

            return \is_resource($listener)
                ? $listener
                : null;
        }

        if (!self::available('Windows')) {
            return null;
        }

        $exclusiveAddressUse = \constant('SO_EXCLUSIVEADDRUSE');

        if (!\is_int($exclusiveAddressUse)) {
            return null;
        }

        $socket = @\socket_create(
            \AF_INET,
            \SOCK_STREAM,
            \SOL_TCP,
        );

        if ($socket === false) {
            return null;
        }

        try {
            if (
                !@\socket_set_option(
                    $socket,
                    \SOL_SOCKET,
                    $exclusiveAddressUse,
                    1,
                )
                || !@\socket_bind(
                    $socket,
                    '127.0.0.1',
                    0,
                )
                || !@\socket_listen(
                    $socket,
                    \SOMAXCONN,
                )
            ) {
                @\socket_close($socket);

                return null;
            }

            $listener = @\socket_export_stream($socket);

            if (!\is_resource($listener)) {
                @\socket_close($socket);

                return null;
            }

            return $listener;
        } catch (\Throwable) {
            @\socket_close($socket);

            return null;
        }
    }
}
