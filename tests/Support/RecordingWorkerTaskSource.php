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
use Coretsia\Contracts\Worker\WorkerTaskSourceContextInterface;
use Coretsia\Contracts\Worker\WorkerTaskSourceInterface;
use Coretsia\Contracts\Worker\WorkerTaskType;

final class RecordingWorkerTaskSource implements WorkerTaskSourceInterface
{
    public int $assertReadyCalls = 0;
    public int $receiveCalls = 0;
    public ?\Throwable $readyFailure = null;
    public ?\Throwable $receiveFailure = null;

    /** @var list<WorkerTaskInterface|null> */
    public array $tasks = [];

    /** @var list<array{worker_index:int,worker_count:int,max_blocking_wait_ms:int}> */
    public array $contexts = [];

    public function __construct(
        private readonly WorkerTaskType $type = WorkerTaskType::Queue,
    ) {
    }

    public function taskType(): WorkerTaskType
    {
        return $this->type;
    }

    public function assertReady(WorkerTaskSourceContextInterface $context): void
    {
        ++$this->assertReadyCalls;
        $this->recordContext($context);

        if ($this->readyFailure !== null) {
            throw $this->readyFailure;
        }
    }

    public function receive(WorkerTaskSourceContextInterface $context): ?WorkerTaskInterface
    {
        ++$this->receiveCalls;
        $this->recordContext($context);

        if ($this->receiveFailure !== null) {
            throw $this->receiveFailure;
        }

        return \array_shift($this->tasks);
    }

    private function recordContext(WorkerTaskSourceContextInterface $context): void
    {
        $this->contexts[] = [
            'worker_index' => $context->workerIndex(),
            'worker_count' => $context->workerCount(),
            'max_blocking_wait_ms' => $context->maxBlockingWaitMs(),
        ];
    }
}
