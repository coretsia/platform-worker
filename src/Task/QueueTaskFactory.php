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

namespace Coretsia\Platform\Worker\Task;

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Package-local queue task placeholder factory.
 *
 * This factory handles only `worker.task_type=queue`.
 *
 * It intentionally does not implement a real external queue adapter in this
 * epic. Real queue sources, transports, payload decoding, acknowledgement
 * semantics, retry semantics, and integration-specific adapters are owned by
 * later integration epics.
 *
 * The produced task work is deterministic and package-internal. It exists so
 * ApplicationWorker can exercise the long-running UoW loop through
 * KernelRuntimeInterface without introducing a real queue dependency.
 *
 * The operation id is the stable low-cardinality token `queue`. It must remain
 * safe for observability metric label `operation` and must not include raw
 * queue payloads, queue names, socket paths, TCP endpoints, headers, tokens,
 * config values, or adapter internals.
 *
 * This class must not depend on integrations, external queue packages,
 * platform/cli, or platform/http. It must not write to stdout/stderr directly.
 *
 * @phpstan-import-type WorkerTaskWork from TaskFactoryInternalInterface
 */
final class QueueTaskFactory implements TaskFactoryInternalInterface
{
    public function taskType(): string
    {
        return self::TASK_TYPE_QUEUE;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        return $spec->taskType() === self::TASK_TYPE_QUEUE;
    }

    public function operationId(WorkerPoolSpec $spec): string
    {
        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        return self::TASK_TYPE_QUEUE;
    }

    /**
     * @return WorkerTaskWork
     */
    public function create(WorkerPoolSpec $spec): array
    {
        $operationId = $this->operationId($spec);

        return [
            'operation_id' => $operationId,
            'run' => static function (): null {
                return null;
            },
        ];
    }
}
