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
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerServiceProviderCliCommandTaggingTest extends PackageTestCase
{
    public function testAllFourCommandsAreDefinedAndTagged(): void
    {
        $source = self::source(
            'src/Provider/WorkerServiceProvider.php',
        );

        foreach (
            [
                WorkerStartCommand::class,
                WorkerStopCommand::class,
                WorkerStatusCommand::class,
                WorkerHealthCommand::class,
            ] as $command
        ) {
            self::assertStringContainsString(
                new \ReflectionClass($command)->getShortName() . '::class',
                $source,
            );
        }

        self::assertGreaterThanOrEqual(
            4,
            \substr_count($source, 'ReservedTags::CLI_COMMAND'),
        );
    }
}
