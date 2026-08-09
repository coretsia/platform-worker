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

namespace Coretsia\Platform\Worker\Runtime;

use Coretsia\Contracts\Worker\WorkerTaskSourceContextInterface;

/**
 * Worker-owned safe task-source context.
 *
 * @internal
 */
final readonly class WorkerTaskSourceContext implements WorkerTaskSourceContextInterface
{
    public function __construct(
        private int $workerIndex,
        private int $workerCount,
        private int $maxBlockingWaitMs,
        private WorkerStopSignal $stopSignal,
        private WorkerPoolSpec $spec,
    ) {
        if (
            $workerIndex < 0
            || $workerCount < 1
            || $workerIndex >= $workerCount
            || $maxBlockingWaitMs < 1
        ) {
            throw new \InvalidArgumentException('worker-task-source-context-invalid');
        }
    }

    public function workerIndex(): int
    {
        return $this->workerIndex;
    }

    public function workerCount(): int
    {
        return $this->workerCount;
    }

    /**
     * Reads live cooperative-shutdown state.
     *
     * @phpstan-impure
     */
    public function cancellationRequested(): bool
    {
        return $this->stopSignal->isRequested($this->spec);
    }

    public function maxBlockingWaitMs(): int
    {
        return $this->maxBlockingWaitMs;
    }
}
