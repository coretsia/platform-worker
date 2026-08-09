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

namespace Coretsia\Platform\Worker\Process;

/**
 * Immutable normalized child-process exit result.
 *
 * Expected exits are limited to normal exit code zero; signaled or non-zero
 * exits are classified as unexpected terminal failures.
 */
final readonly class WorkerProcessExit
{
    public function __construct(
        private int $pid,
        private int $exitCode,
        private bool $signaled,
        private int $terminatingSignal,
        private bool $expected,
    ) {
        if ($pid < 1 || $exitCode < 0 || $terminatingSignal < 0) {
            throw new \InvalidArgumentException('worker-process-exit-invalid');
        }
        if ((!$signaled && $terminatingSignal !== 0) || ($signaled && $terminatingSignal < 1)) {
            throw new \InvalidArgumentException('worker-process-exit-invalid');
        }
        if ($expected !== (!$signaled && $exitCode === 0)) {
            throw new \InvalidArgumentException('worker-process-exit-invalid');
        }
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function signaled(): bool
    {
        return $this->signaled;
    }

    public function terminatingSignal(): int
    {
        return $this->terminatingSignal;
    }

    public function expected(): bool
    {
        return $this->expected;
    }
}
