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

namespace Coretsia\Platform\Worker\Tests\Contract;

use Coretsia\Platform\Worker\Console\WorkerHealthCommand;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use PHPUnit\Framework\TestCase;

final class WorkerCommandMetadataConstantsTest extends TestCase
{
    public function testAllWorkerCommandsExposeCanonicalMetadata(): void
    {
        $commands = [
            WorkerStartCommand::class => 'worker:start',
            WorkerStopCommand::class => 'worker:stop',
            WorkerStatusCommand::class => 'worker:status',
            WorkerHealthCommand::class => 'worker:health',
        ];

        foreach ($commands as $class => $name) {
            self::assertSame($name, $class::NAME);
            self::assertSame('worker', $class::GROUP);
            self::assertFalse($class::HIDDEN);
            self::assertSame('none', $class::MODE);
            self::assertSame([], $class::ARGUMENTS);
            self::assertSame([], $class::OPTIONS);
        }
    }
}
