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

namespace Coretsia\Platform\Worker\Tests\Integration;

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerTaskSourceResolverSelectsServiceLazilyTest extends PackageTestCase
{
    public function testFactoryAndProviderResolveOnlyThroughSelectedTaskSourceBoundary(): void
    {
        $factory = self::source('src/Provider/WorkerServiceFactory.php');
        $provider = self::source('src/Provider/WorkerServiceProvider.php');

        self::assertStringContainsString('WorkerTaskSourceResolver $resolver', $factory);
        self::assertStringContainsString('$resolver->resolve(', $factory);
        self::assertStringContainsString('WorkerTaskType::from($spec->taskType())', $factory);
        self::assertStringContainsString('WorkerTaskSourceInterface::class', $provider);
        self::assertStringContainsString('WorkerTaskSourceResolver::class', $provider);
        self::assertStringContainsString('TagRegistry::class', $provider);
    }
}
