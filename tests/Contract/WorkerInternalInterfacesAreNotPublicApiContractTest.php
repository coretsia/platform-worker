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

final class WorkerInternalInterfacesAreNotPublicApiContractTest extends PackageTestCase
{
    public function testInternalContractsAreMarkedInternalAndOldManagerInterfacesAreAbsent(): void
    {
        foreach (
            [
                'WorkerProcessDriverInterface.php',
                'WorkerProcessGuardianInterface.php',
                'WorkerProcessDriverResolverInterface.php',
                'WorkerSupervisorInterface.php',
                'WorkerSupervisorResolverInterface.php',
            ] as $file
        ) {
            self::assertStringContainsString('@internal', self::source('src/Internal/' . $file));
        }
        self::assertFileDoesNotExist(self::packageRoot() . '/src/Internal/WorkerManagerDriverInterface.php');
        self::assertFileDoesNotExist(self::packageRoot() . '/src/Internal/WorkerManagerResolverInterface.php');
    }
}
