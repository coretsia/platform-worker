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

final class WorkerProcessGuardianPcntlDescriptorIsolationTest extends PackageTestCase
{
    public function testForkExecBoundaryExplicitlyDropsOwnerChannelAndGenerationFence(): void
    {
        $runtime = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        $child = \strstr($runtime, 'if ($pid === 0)');
        self::assertIsString($child);

        $closeConnection = \strpos($child, '@\\fclose($this->connection)');
        $detachFence = \strpos($child, '$this->lifecycleLock?->detachInForkedChild()');
        $resetSignals = \strpos($child, 'self::resetForkedChildSignals()');
        $exec = \strpos($child, '@\\pcntl_exec(');

        foreach ([$closeConnection, $detachFence, $resetSignals, $exec] as $offset) {
            self::assertNotFalse($offset);
        }
        self::assertTrue($closeConnection < $detachFence && $detachFence < $resetSignals && $resetSignals < $exec);

        $lock = self::source('src/Runtime/WorkerLifecycleLock.php');
        self::assertStringContainsString("'c+be'", $lock);
        self::assertStringContainsString('detachInForkedChild', $lock);
    }
}
