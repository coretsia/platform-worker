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

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Package-internal lazy resolver for the selected OS process driver.
 *
 * The resolver must construct only the driver selected by the normalized
 * WorkerPoolSpec. It must not inspect raw configuration, enumerate tagged
 * services, or fall back to another process driver.
 *
 * @internal
 */
interface WorkerProcessDriverResolverInterface
{
    /**
     * Resolves the exact process driver selected by the normalized pool spec.
     *
     * @throws WorkerStartFailedException
     *     When the selected driver cannot be resolved or is unsupported.
     */
    public function resolve(
        WorkerPoolSpec $spec,
    ): WorkerProcessDriverInterface;
}
