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

final class WorkerLifecycleLocatorOwnershipContractTest extends PackageTestCase
{
    public function testOnlySupervisorPublishesAndClientReadsTheLocator(): void
    {
        $supervisor = self::source('src/Supervisor/WorkerSupervisor.php');
        $client = self::source('src/Communication/WorkerControlClient.php');

        self::assertStringContainsString(
            '$this->locatorStore->write(',
            $supervisor,
        );
        self::assertStringContainsString(
            '$this->locatorStore->delete()',
            $supervisor,
        );
        self::assertStringNotContainsString(
            '$this->locatorStore->read()',
            $supervisor,
        );

        self::assertStringContainsString(
            '$this->locatorStore->read()',
            $client,
        );
        self::assertStringNotContainsString(
            '$this->locatorStore->write(',
            $client,
        );
        self::assertStringNotContainsString(
            '$this->locatorStore->delete()',
            $client,
        );

        foreach (
            [
                'src/Process/Driver/PcntlWorkerProcessDriver.php',
                'src/Process/Driver/ProcWorkerProcessDriver.php',
                'src/Process/Guardian/WorkerProcessGuardianRuntime.php',
                'src/Worker/ApplicationWorker.php',
                'src/Runtime/WorkerStateStore.php',
                'src/Console/WorkerStatusCommand.php',
                'src/Console/WorkerHealthCommand.php',
                'src/Console/WorkerStopCommand.php',
                'src/Task/WorkerTaskSourceResolver.php',
                'src/Runtime/WorkerTaskSourceContext.php',
            ] as $path
        ) {
            self::assertStringNotContainsString(
                'WorkerLifecycleLocatorStore',
                self::source($path),
                $path,
            );
        }
    }

    public function testLocatorOnlyFieldsDoNotLeakIntoStateProtocolOrCliSummaries(): void
    {
        $surfaces = '';

        foreach (
            [
                'src/Runtime/WorkerPoolState.php',
                'src/Runtime/WorkerHealthState.php',
                'src/Communication/WorkerControlRequest.php',
                'src/Communication/WorkerControlResponse.php',
                'src/Console/WorkerStatusCommand.php',
                'src/Console/WorkerHealthCommand.php',
                'src/Console/WorkerStopCommand.php',
            ] as $path
        ) {
            $surfaces .= "\n" . self::source($path);
        }

        foreach (
            [
                'socket_path',
                'tcp_host',
                'tcp_port',
                'stop_timeout_ms',
                'force_kill_timeout_ms',
            ] as $field
        ) {
            self::assertStringNotContainsString(
                "'" . $field . "' =>",
                $surfaces,
                $field,
            );
        }
    }
}
