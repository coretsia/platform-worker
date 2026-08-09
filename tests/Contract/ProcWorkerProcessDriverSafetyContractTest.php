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

final class ProcWorkerProcessDriverSafetyContractTest extends PackageTestCase
{
    public function testProcDriverDelegatesAllProcessOwnershipToGuardian(): void
    {
        $driver = self::source('src/Process/Driver/ProcWorkerProcessDriver.php');
        self::assertStringContainsString('WorkerProcessGuardianInterface', $driver);
        foreach (['spawn', 'pollExit', 'terminate', 'kill', 'close'] as $method) {
            self::assertStringContainsString('$this->guardian->' . $method, $driver);
        }
        foreach (['proc_open(', 'WorkerProcProcessHostClient', 'WorkerLifecycleLock'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $driver);
        }
    }

    public function testGuardianStartsProcHostBeforeAcceptingSupervisorAndClaimingFence(): void
    {
        $bin = self::source('bin/coretsia-worker-guardian');
        $runtime = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        $startHost = \strpos($bin, '$processHost->start(');
        $accept = \strpos($bin, '$transport->accept(');
        self::assertIsInt($startHost);
        self::assertIsInt($accept);
        self::assertLessThan($accept, $startHost);
        self::assertStringContainsString('$lock->acquire()', $runtime);
    }

    public function testProcessHostRotatesGuardianConnectionAroundEveryProcOpen(): void
    {
        $host = self::source('bin/coretsia-worker-proc-host');
        $client = self::source('src/Process/Proc/WorkerProcProcessHostClient.php');
        self::assertStringContainsString('WorkerProcProcessHostHandoffEndpoint::create', $client);
        self::assertStringContainsString('$this->closeConnection();', $host);
        self::assertStringContainsString('$this->spawn($payload)', $host);
        self::assertStringContainsString('$this->transport->connect(', $host);
    }

    public function testProcCapabilityIncludesGuardianAndProcessHostTransportRequirements(): void
    {
        $capabilities = self::source('src/Internal/WorkerProcessCapabilities.php');
        foreach (
            [
                'guardianTransportAvailable',
                'procProcessHostTransportAvailable',
                'procDriverAvailable'
            ] as $required
        ) {
            self::assertStringContainsString($required, $capabilities);
        }
        foreach (
            [
                'proc_open',
                'proc_get_status',
                'proc_terminate',
                'proc_close',
                'stream_socket_server',
                'stream_socket_client'
            ] as $fn
        ) {
            self::assertStringContainsString("\\function_exists('{$fn}')", $capabilities);
        }
    }
}
