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

use Coretsia\Platform\Worker\Process\WorkerChildProcess;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Package-internal strict single-child process adapter.
 *
 * Pool/generation ownership belongs to WorkerProcessGuardianInterface. Drivers
 * only adapt one worker command to the selected guardian backend.
 *
 * @internal
 */
interface WorkerProcessDriverInterface
{
    public const string DRIVER_PCNTL = 'pcntl';
    public const string DRIVER_PROC = 'proc';

    public function name(): string;

    public function supports(
        WorkerPoolSpec $spec,
    ): bool;

    public function spawn(
        WorkerPoolSpec $spec,
        int $workerIndex,
    ): WorkerChildProcess;

    public function pollExit(
        WorkerChildProcess $child,
        int $timeoutMs,
    ): ?WorkerProcessExit;

    public function terminate(
        WorkerChildProcess $child,
        int $timeoutMs,
    ): void;

    public function kill(
        WorkerChildProcess $child,
        int $timeoutMs,
    ): void;

    public function close(
        WorkerChildProcess $child,
        int $timeoutMs,
    ): void;
}
