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

use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianChild;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Package-internal owner boundary for one worker generation.
 *
 * @internal
 */
interface WorkerProcessGuardianInterface
{
    public function claim(
        WorkerPoolSpec $spec,
        string $driverName,
    ): void;

    /** @param non-empty-list<non-empty-string> $command */
    public function spawn(
        array $command,
        string $workingDirectory,
        int $timeoutMs,
    ): WorkerProcessGuardianChild;

    public function pollExit(
        string $childId,
        int $timeoutMs,
    ): ?WorkerProcessExit;

    public function terminate(
        string $childId,
        int $timeoutMs,
    ): void;

    public function kill(
        string $childId,
        int $timeoutMs,
    ): void;

    public function close(
        string $childId,
        int $timeoutMs,
    ): void;

    /**
     * Releases the generation fence after all owned children are closed.
     * This operation is terminal for the guardian client instance.
     */
    public function release(
        int $timeoutMs,
    ): void;
}
