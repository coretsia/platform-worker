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

use Coretsia\Kernel\Runtime\Driver\BackgroundDriver;
use Coretsia\Kernel\Runtime\Driver\HttpDriver;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverContributions;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Package-local mapper from Worker-owned runtime inputs to Kernel runtime
 * driver contributions.
 *
 * This class owns the worker.task_type -> runtime-driver mapping.
 *
 * @internal
 */
final class WorkerRuntimeDriverContributions
{
    private const string TASK_TYPE_HTTP = 'http';
    private const string TASK_TYPE_QUEUE = 'queue';

    public static function fromSpec(WorkerPoolSpec $spec): RuntimeDriverContributions
    {
        return self::fromTaskType($spec->taskType());
    }

    private static function fromTaskType(string $taskType): RuntimeDriverContributions
    {
        return match ($taskType) {
            self::TASK_TYPE_QUEUE => RuntimeDriverContributions::fromDrivers(
                httpDrivers: [],
                backgroundDrivers: [BackgroundDriver::WORKER_QUEUE],
            ),

            self::TASK_TYPE_HTTP => RuntimeDriverContributions::fromDrivers(
                httpDrivers: [HttpDriver::WORKER],
                backgroundDrivers: [],
            ),

            default => throw WorkerStartFailedException::invalidState(),
        };
    }
}
