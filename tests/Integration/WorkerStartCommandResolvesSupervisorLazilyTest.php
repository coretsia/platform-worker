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

use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerStartCommandResolvesSupervisorLazilyTest extends PackageTestCase
{
    public function testSourceRunsEntrypointGuardBeforeResolvingSupervisor(): void
    {
        $source = self::source('src/Console/WorkerStartCommand.php');
        $guard = \strpos($source, 'runtimeEntrypointGuard->assertEntrypointAllowed');
        $resolve = \strpos($source, '$this->supervisor()->run');

        self::assertNotFalse($guard);
        self::assertNotFalse($resolve);
        self::assertLessThan($resolve, $guard);
        self::assertStringContainsString(WorkerStartCommand::NAME, $source);
    }
}
