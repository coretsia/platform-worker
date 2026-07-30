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

namespace Coretsia\Platform\Worker\Manager;

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerResolverInterface;
use Psr\Container\ContainerInterface;

/**
 * Resolves WorkerManager lazily from the active runtime container.
 *
 * Container failures and invalid bindings are mapped to one safe deterministic
 * worker-start failure without exposing service ids or nested exception data.
 *
 * @internal Worker runtime container wiring detail.
 */
final readonly class ContainerWorkerManagerResolver implements WorkerManagerResolverInterface
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    public function resolve(): WorkerManager
    {
        try {
            $manager = $this->container->get(WorkerManager::class);
        } catch (\Throwable) {
            throw WorkerStartFailedException::startFailed();
        }

        if (!$manager instanceof WorkerManager) {
            throw WorkerStartFailedException::startFailed();
        }

        return $manager;
    }
}
