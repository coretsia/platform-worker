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

final class PcntlWorkerArtifactBootTest extends PackageTestCase
{
    public function testPcntlFactoryTargetsTheSharedArtifactOnlyChildLauncher(): void
    {
        $factory = self::source('src/Provider/WorkerServiceFactory.php');
        $driver = self::source('src/Process/Driver/PcntlWorkerProcessDriver.php');
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        $launcher = self::source('bin/coretsia-worker');

        self::assertStringContainsString("'/bin/coretsia-worker'", $factory);
        self::assertStringNotContainsString('pcntl_exec', $driver);
        self::assertStringContainsString('pcntl_exec', $guardian);
        self::assertStringContainsString('ArtifactRuntimeBooter', $launcher);
        self::assertStringContainsString('ApplicationWorker::class', $launcher);
        self::assertStringContainsString("\$driver !== 'pcntl' && \$driver !== 'proc'", $launcher);
    }
}
