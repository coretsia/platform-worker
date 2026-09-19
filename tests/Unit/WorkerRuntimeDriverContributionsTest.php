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

namespace Coretsia\Platform\Worker\Tests\Unit;

use Coretsia\Platform\Worker\Internal\WorkerRuntimeDriverContributions;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use PHPUnit\Framework\TestCase;

final class WorkerRuntimeDriverContributionsTest extends TestCase
{
    public function testQueueContributesOnlyBackgroundWorkerDriver(): void
    {
        $contributions = WorkerRuntimeDriverContributions::fromSpec(
            WorkerSpecFactory::create([
                'task_type' => 'queue',
            ]),
        );

        self::assertSame([], $contributions->httpDrivers());
        self::assertSame(
            ['bg.worker_queue'],
            \array_map(
                static fn ($driver): string => $driver->value,
                $contributions->backgroundDrivers(),
            ),
        );
    }

    public function testHttpContributesOnlyWorkerHttpDriver(): void
    {
        $contributions = WorkerRuntimeDriverContributions::fromSpec(
            WorkerSpecFactory::create([
                'task_type' => 'http',
            ]),
        );

        self::assertSame(
            ['http.worker'],
            \array_map(
                static fn ($driver): string => $driver->value,
                $contributions->httpDrivers(),
            ),
        );
        self::assertSame([], $contributions->backgroundDrivers());
    }
}
