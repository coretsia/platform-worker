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

namespace Coretsia\Platform\Worker\Tests\Support;

use Coretsia\Contracts\Worker\WorkerTaskInterface;

final class RecordingWorkerTask implements WorkerTaskInterface
{
    public int $executeCalls = 0;
    public int $completeCalls = 0;
    public int $failCalls = 0;
    public mixed $completedResult = null;
    public ?\Throwable $failedWith = null;
    public ?\Throwable $executeFailure = null;
    public ?\Throwable $completeFailure = null;
    public ?\Throwable $failFailure = null;

    public function __construct(public mixed $result = null)
    {
    }

    public function execute(): mixed
    {
        ++$this->executeCalls;

        if ($this->executeFailure !== null) {
            throw $this->executeFailure;
        }

        return $this->result;
    }

    public function complete(mixed $result): void
    {
        ++$this->completeCalls;
        $this->completedResult = $result;

        if ($this->completeFailure !== null) {
            throw $this->completeFailure;
        }
    }

    public function fail(\Throwable $failure): void
    {
        ++$this->failCalls;
        $this->failedWith = $failure;

        if ($this->failFailure !== null) {
            throw $this->failFailure;
        }
    }
}
