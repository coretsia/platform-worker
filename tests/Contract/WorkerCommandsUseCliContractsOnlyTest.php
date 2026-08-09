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

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerCommandsUseCliContractsOnlyTest extends PackageTestCase
{
    public function testCommandsUseOnlyContractsInputOutputAndNoRawSinks(): void
    {
        foreach (
            [
                'src/Console/WorkerStartCommand.php',
                'src/Console/WorkerStopCommand.php',
                'src/Console/WorkerStatusCommand.php',
                'src/Console/WorkerHealthCommand.php',
            ] as $path
        ) {
            $source = self::source($path);

            self::assertStringContainsString(
                'Coretsia\\Contracts\\Cli\\Input\\InputInterface',
                $source,
            );
            self::assertStringContainsString(
                'Coretsia\\Contracts\\Cli\\Output\\OutputInterface',
                $source,
            );
            self::assertStringNotContainsString(
                'Coretsia\\Platform\\Cli\\',
                $source,
            );

            foreach (
                [
                    'echo ',
                    'print ',
                    'printf(',
                    'fwrite(',
                    'STDOUT',
                    'STDERR',
                    'error_log(',
                ] as $forbidden
            ) {
                self::assertStringNotContainsString($forbidden, $source);
            }
        }
    }
}
