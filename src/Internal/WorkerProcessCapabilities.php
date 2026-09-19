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
 * Package-internal process capability policy.
 *
 * This class owns exact runtime capability checks shared by WorkerPoolSpec,
 * concrete process drivers, process bootstrap, and ProcHost handoff code.
 *
 * @internal
 */
final class WorkerProcessCapabilities
{
    private function __construct()
    {
    }

    /** Returns whether the common authenticated child-bootstrap primitive is available. */
    public static function processBootstrapAvailable(
        ?string $platformFamily = null,
    ): bool {
        $platformFamily ??= \PHP_OS_FAMILY;

        if (
            !\function_exists('proc_open')
            || !\function_exists('proc_get_status')
            || !\function_exists('proc_terminate')
            || !\function_exists('proc_close')
            || !\function_exists('stream_socket_get_name')
            || !\function_exists('stream_socket_client')
            || !\function_exists('stream_socket_accept')
            || !\function_exists('stream_set_blocking')
            || !\function_exists('stream_select')
        ) {
            return false;
        }

        return WorkerLoopbackListener::available($platformFamily);
    }

    /** Returns whether the guardian-owned PCNTL backend is safe to select. */
    public static function pcntlDriverAvailable(
        ?string $platformFamily = null,
    ): bool {
        $platformFamily ??= \PHP_OS_FAMILY;

        return \strcasecmp($platformFamily, 'Windows') !== 0
            && self::processBootstrapAvailable($platformFamily)
            && \function_exists('pcntl_fork')
            && \function_exists('pcntl_exec')
            && \function_exists('pcntl_waitpid')
            && \function_exists('pcntl_wifexited')
            && \function_exists('pcntl_wexitstatus')
            && \function_exists('pcntl_wifsignaled')
            && \function_exists('pcntl_wtermsig')
            && \function_exists('pcntl_signal')
            && \function_exists('pcntl_async_signals')
            && \function_exists('posix_kill');
    }

    /** Returns whether the per-worker ProcHost handoff transport is available. */
    public static function procProcessHostTransportAvailable(
        ?string $platformFamily = null,
    ): bool {
        $platformFamily ??= \PHP_OS_FAMILY;

        return WorkerLoopbackListener::available($platformFamily)
            && \function_exists('stream_socket_get_name')
            && \function_exists('stream_socket_client')
            && \function_exists('stream_socket_accept')
            && \function_exists('stream_set_blocking')
            && \function_exists('stream_select');
    }

    /** Returns whether the proc process driver is safe to select. */
    public static function procDriverAvailable(
        ?string $platformFamily = null,
    ): bool {
        $platformFamily ??= \PHP_OS_FAMILY;

        if (
            !self::processBootstrapAvailable($platformFamily)
            || !self::procProcessHostTransportAvailable($platformFamily)
        ) {
            return false;
        }

        if (\strcasecmp($platformFamily, 'Windows') === 0) {
            return \function_exists('sapi_windows_set_ctrl_handler');
        }

        return \function_exists('pcntl_async_signals')
            && \function_exists('pcntl_signal')
            && \defined('SIGTERM')
            && \defined('SIGINT');
    }
}
