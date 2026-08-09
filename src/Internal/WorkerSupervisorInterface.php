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

use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

/**
 * Package-internal foreground supervisor boundary.
 *
 * @internal
 */
interface WorkerSupervisorInterface
{
    /**
     * Runs until requested or signal-driven shutdown completes.
     *
     * @param \Closure(WorkerPoolState): void $onReady
     */
    public function run(
        WorkerPoolSpec $spec,
        \Closure $onReady,
    ): int;
}
