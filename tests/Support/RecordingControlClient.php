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

use Coretsia\Platform\Worker\Internal\WorkerControlClientInterface;
use Coretsia\Platform\Worker\Runtime\WorkerHealthState;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

final class RecordingControlClient implements WorkerControlClientInterface
{
    public ?WorkerPoolState $statusState = null;
    public ?WorkerHealthState $healthState = null;
    public ?WorkerPoolState $stopState = null;
    public ?\Throwable $failure = null;
    public int $statusCalls = 0;
    public int $healthCalls = 0;
    public int $stopCalls = 0;

    public function status(): WorkerPoolState
    {
        $this->statusCalls++;
        if ($this->failure) {
            throw $this->failure;
        }
        return $this->statusState ?? throw new \LogicException('status-state-missing');
    }

    public function health(): WorkerHealthState
    {
        $this->healthCalls++;
        if ($this->failure) {
            throw $this->failure;
        }
        return $this->healthState ?? throw new \LogicException('health-state-missing');
    }

    public function stop(): WorkerPoolState
    {
        $this->stopCalls++;
        if ($this->failure) {
            throw $this->failure;
        }
        return $this->stopState ?? throw new \LogicException('stop-state-missing');
    }
}
