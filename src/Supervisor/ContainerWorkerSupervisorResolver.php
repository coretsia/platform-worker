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

namespace Coretsia\Platform\Worker\Supervisor;

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorResolverInterface;
use Psr\Container\ContainerInterface;

/**
 * Lazily resolves the foreground supervisor after entrypoint validation.
 *
 * The resolver preserves command ordering by preventing process infrastructure
 * from being resolved while the command itself is constructed.
 */
final readonly class ContainerWorkerSupervisorResolver implements WorkerSupervisorResolverInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function resolve(): WorkerSupervisorInterface
    {
        try {
            $supervisor = $this->container->get(WorkerSupervisorInterface::class);
        } catch (\Throwable) {
            throw WorkerStartFailedException::startFailed();
        }
        if (!$supervisor instanceof WorkerSupervisorInterface) {
            throw WorkerStartFailedException::startFailed();
        }
        return $supervisor;
    }
}
