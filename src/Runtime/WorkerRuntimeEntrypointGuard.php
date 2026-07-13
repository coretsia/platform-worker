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
use Coretsia\Kernel\Runtime\Entrypoint\RuntimeEntrypointGuard;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverConflictException;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverInvalidConfigException;
use Coretsia\Platform\Worker\Internal\WorkerRuntimeDriverContributions;

/**
 * Worker-owned runtime entrypoint compatibility boundary.
 *
 * This boundary maps an already-normalized WorkerPoolSpec to explicit Kernel
 * runtime-driver contributions and delegates canonical matrix and module
 * compatibility validation to RuntimeEntrypointGuard.
 *
 * Worker entrypoints, including the shipped child launcher, must use this
 * boundary instead of importing Worker package-internal mapping helpers.
 *
 * This class does not read the worker config root, resolve WorkerPoolSpec,
 * start workers, resolve task handlers, or duplicate Kernel driver policy.
 */
final readonly class WorkerRuntimeEntrypointGuard
{
    private const string MODULE_PLATFORM_WORKER = 'platform.worker';

    public function __construct(
        private RuntimeEntrypointGuard $kernelEntrypointGuard,
    ) {
    }

    /**
     * @throws RuntimeDriverConflictException
     * @throws RuntimeDriverInvalidConfigException
     */
    public function assertEntrypointAllowed(
        ConfigRepositoryInterface $config,
        ModulePlan $modulePlan,
        WorkerPoolSpec $spec,
    ): void {
        if (!$modulePlan->hasEnabledModule(self::MODULE_PLATFORM_WORKER)) {
            throw RuntimeDriverInvalidConfigException::requiresPlatformWorkerModule();
        }

        $this->kernelEntrypointGuard->assertEntrypointAllowed(
            config: $config,
            modulePlan: $modulePlan,
            runtimeDriverContributions: WorkerRuntimeDriverContributions::fromSpec(
                $spec,
            ),
        );
    }
}
