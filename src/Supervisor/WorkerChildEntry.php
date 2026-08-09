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

namespace Coretsia\Platform\Worker\Supervisor;

use Coretsia\Platform\Worker\Process\WorkerChildProcess;

/**
 * Tracks readiness and shutdown transitions for one child-table slot.
 *
 * Transition methods reject duplicate or invalid state changes instead of
 * silently mutating lifecycle state.
 */
final class WorkerChildEntry
{
    private WorkerChildReadinessState $readiness = WorkerChildReadinessState::PENDING;
    private WorkerChildShutdownState $shutdown = WorkerChildShutdownState::RUNNING;

    public function __construct(private readonly WorkerChildProcess $child)
    {
    }

    public function child(): WorkerChildProcess
    {
        return $this->child;
    }

    public function readiness(): WorkerChildReadinessState
    {
        return $this->readiness;
    }

    public function shutdown(): WorkerChildShutdownState
    {
        return $this->shutdown;
    }

    public function markReady(): void
    {
        if ($this->readiness !== WorkerChildReadinessState::PENDING) {
            throw new \LogicException('worker-child-readiness-transition-invalid');
        }

        $this->readiness = WorkerChildReadinessState::READY;
    }

    public function markTerminating(): void
    {
        if ($this->shutdown !== WorkerChildShutdownState::RUNNING) {
            throw new \LogicException('worker-child-shutdown-transition-invalid');
        }

        $this->shutdown = WorkerChildShutdownState::TERMINATING;
    }

    public function markKilling(): void
    {
        if ($this->shutdown === WorkerChildShutdownState::KILLING) {
            throw new \LogicException('worker-child-shutdown-transition-invalid');
        }

        $this->shutdown = WorkerChildShutdownState::KILLING;
    }
}
