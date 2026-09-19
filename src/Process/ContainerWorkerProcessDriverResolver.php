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

namespace Coretsia\Platform\Worker\Process;

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverResolverInterface;
use Coretsia\Platform\Worker\Process\Driver\PcntlWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\Driver\ProcWorkerProcessDriver;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Psr\Container\ContainerInterface;

/**
 * Resolves only the process driver selected by WorkerPoolSpec.
 *
 * The exact package-owned mapping prevents eager construction of the
 * unselected driver and avoids tag enumeration or cross-driver fallback.
 */
final readonly class ContainerWorkerProcessDriverResolver implements WorkerProcessDriverResolverInterface
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    /**
     * Resolves and validates one selected process driver.
     */
    public function resolve(
        WorkerPoolSpec $spec,
    ): WorkerProcessDriverInterface {
        $serviceId = match ($spec->driver()) {
            WorkerProcessDriverInterface::DRIVER_PCNTL => PcntlWorkerProcessDriver::class,
            WorkerProcessDriverInterface::DRIVER_PROC => ProcWorkerProcessDriver::class,
            default => throw WorkerStartFailedException::childStartFailed(),
        };

        try {
            $driver = $this->container->get($serviceId);
        } catch (\Throwable) {
            throw WorkerStartFailedException::childStartFailed();
        }

        if (
            !$driver instanceof WorkerProcessDriverInterface
            || $driver->name() !== $spec->driver()
            || !$driver->supports($spec)
        ) {
            throw WorkerStartFailedException::childStartFailed();
        }

        return $driver;
    }
}
