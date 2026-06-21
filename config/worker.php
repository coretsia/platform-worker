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

/**
 * Worker runtime defaults.
 *
 * This file returns the `worker` configuration subtree only.
 * It MUST NOT wrap values in a repeated root key such as:
 *
 *     return ['worker' => [...]];
 *
 * Runtime code reads these values from the merged global configuration under
 * `worker.*`.
 *
 * Baseline invariants:
 * - the `worker` root is owned by `platform/worker`;
 * - worker runtime is opt-in and disabled by default;
 * - queue task mode is the safe default task type;
 * - process/control paths are skeleton-root-relative runtime paths;
 * - path defaults MUST remain relative and MUST NOT contain a `skeleton/`
 *   prefix, absolute path syntax, host-specific path fragments, or monorepo-only
 *   paths;
 * - `worker.driver=auto` is resolved later by WorkerPoolSpec capability policy;
 * - `worker.control.transport=auto` is resolved later by WorkerPoolSpec
 *   capability policy;
 * - TCP port `0` MUST NOT be used because it makes endpoint identity and
 *   worker state non-deterministic across runs;
 * - worker state output MUST be redacted and MUST NOT persist raw endpoints,
 *   absolute paths, timestamps, environment values, payloads, headers, or
 *   tokens;
 * - keys beginning with `@` are reserved and rejected by config rules.
 */
return [
    /*
     * Worker runtime enablement.
     *
     * Disabled by default so installing the package does not implicitly activate
     * a long-running runtime mode.
     */
    'enabled' => false,

    /*
     * Worker pool size.
     *
     * The value is validated by config rules as an integer greater than zero.
     */
    'workers' => 4,

    /*
     * Maximum number of tasks handled by one worker process before recycle.
     *
     * The value is validated by config rules as an integer greater than zero.
     */
    'max_requests' => 1000,

    /*
     * Default task type.
     *
     * Allowed values:
     *
     * - `queue`
     * - `http`
     *
     * Queue mode is the safe default because it does not require an HTTP
     * handling stack.
     */
    'task_type' => 'queue',

    /*
     * Worker control socket path.
     *
     * This path is skeleton-root-relative and is used only when the resolved
     * control transport is `unix`.
     */
    'socket_path' => 'var/tmp/worker.sock',

    /*
     * Process driver selection.
     *
     * Allowed values:
     *
     * - `auto`
     * - `pcntl`
     * - `proc`
     *
     * `auto` resolves deterministically later:
     *
     * - `pcntl` when `pcntl_fork` is available and the platform is not Windows;
     * - otherwise `proc`.
     */
    'driver' => 'auto',

    /*
     * Proc child-process command vector.
     *
     * This base argv vector is used by ProcWorkerManagerDriver to start child
     * worker processes through proc_open().
     *
     * This is an argv list, not a shell string.
     *
     * The package default points to the worker-owned child launcher shipped by this
     * package. The special `@php` token is expanded by WorkerServiceFactory to the
     * current PHP binary before ProcWorkerManagerDriver receives the command.
     *
     * The default path is skeleton-root-relative and targets the normal Composer
     * installation layout.
     *
     * The provider must not invent or mutate this vector.
     */
    'proc' => [
        'command' => [
            '@php',
            'vendor/coretsia/platform-worker/bin/coretsia-worker',
        ],
    ],

    /*
     * Worker control channel.
     */
    'control' => [
        /*
         * Control transport selection.
         *
         * Allowed values:
         *
         * - `auto`
         * - `unix`
         * - `tcp`
         *
         * `auto` resolves deterministically later:
         *
         * - `unix` when the resolved driver is `pcntl` and unix domain sockets
         *   are supported;
         * - otherwise `tcp`.
         */
        'transport' => 'auto',
    ],

    /*
     * TCP control endpoint defaults.
     *
     * These values are used only when the resolved control transport is `tcp`.
     *
     * The port must be explicit and deterministic. Port `0` is forbidden by
     * config rules.
     */
    'tcp' => [
        'host' => '127.0.0.1',
        'port' => 9327,
    ],

    /*
     * Worker state file path.
     *
     * This path is skeleton-root-relative.
     *
     * The state file contents are owned by WorkerStateStore and MUST be written
     * with StableJsonEncoder using the cemented redacted schema.
     */
    'state_path' => 'var/tmp/worker.state.json',

    /*
     * Graceful stop flag path.
     *
     * This path is skeleton-root-relative.
     */
    'stop_flag_path' => 'var/tmp/worker.stop',

    /*
     * Graceful stop timeout in milliseconds.
     *
     * The value is validated by config rules as an integer greater than or equal
     * to zero.
     */
    'stop_timeout_ms' => 3000,
];
