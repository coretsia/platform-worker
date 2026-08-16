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

use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverResolver;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverConflictException;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverInvalidConfigException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerRuntimeDriverContributions;
use Coretsia\Platform\Worker\Module\WorkerModule;

/**
 * Worker-owned runtime entrypoint compatibility boundary.
 *
 * WorkerRuntimeEntrypointGuard validates the Worker-owned
 * WorkerModule::MODULE_ID participation precondition, maps an
 * already-normalized WorkerPoolSpec to explicit runtime-driver contributions,
 * and delegates only Kernel-owned runtime-driver matrix resolution to
 * RuntimeDriverResolver.
 *
 * Worker entrypoints, including the shipped child launcher, must use this
 * boundary instead of importing Worker package-internal mapping helpers.
 *
 * This class does not read the worker config root, resolve WorkerPoolSpec,
 * start workers, resolve task handlers, or duplicate Kernel driver policy.
 */
final readonly class WorkerRuntimeEntrypointGuard
{
    public function __construct(
        private RuntimeDriverResolver $runtimeDriverResolver,
    ) {
    }

    /**
     * @throws WorkerStartFailedException
     * @throws RuntimeDriverConflictException
     * @throws RuntimeDriverInvalidConfigException
     */
    public function assertEntrypointAllowed(
        ConfigRepositoryInterface $config,
        ModulePlan $modulePlan,
        WorkerPoolSpec $spec,
    ): void {
        if (!$modulePlan->hasEnabledModule(WorkerModule::MODULE_ID)) {
            throw WorkerStartFailedException::moduleNotEnabled();
        }

        $this->runtimeDriverResolver->resolve(
            config: $config,
            contributions: WorkerRuntimeDriverContributions::fromSpec($spec),
        );
    }
}
