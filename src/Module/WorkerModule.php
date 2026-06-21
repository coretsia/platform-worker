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

namespace Coretsia\Platform\Worker\Module;

use Coretsia\Foundation\Container\ServiceProviderInterface;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;

/**
 * Worker runtime module metadata.
 *
 * This class is intentionally small until the canonical module descriptor /
 * module manifest integration requires a richer contracts-level descriptor.
 *
 * It exposes stable Worker package metadata and the provider list without
 * performing filesystem scanning, container construction, config loading,
 * process spawning, socket/control-channel setup, state-file writes, task
 * execution, KernelRuntime lifecycle execution, reset execution, hook
 * dispatching, or transport adapter behavior.
 *
 * `platform/worker` owns long-running worker runtime orchestration. It does
 * not own CLI dispatch, HTTP platform adapters, queue integrations, Kernel
 * lifecycle semantics, hook discovery, or reset orchestration.
 */
final class WorkerModule
{
    public const string MODULE_ID = 'platform.worker';
    public const string PACKAGE_ID = 'platform/worker';
    public const string COMPOSER_PACKAGE = 'coretsia/platform-worker';
    public const string KIND = 'runtime';
    public const string CONFIG_ROOT = 'worker';

    public function id(): string
    {
        return self::MODULE_ID;
    }

    public function packageId(): string
    {
        return self::PACKAGE_ID;
    }

    public function composerPackage(): string
    {
        return self::COMPOSER_PACKAGE;
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function configRoot(): string
    {
        return self::CONFIG_ROOT;
    }

    /**
     * Returns Worker service providers in module-declared order.
     *
     * `ContainerBuilder` must preserve this caller-supplied order exactly and
     * must not re-sort providers.
     *
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            WorkerServiceProvider::class,
        ];
    }
}
