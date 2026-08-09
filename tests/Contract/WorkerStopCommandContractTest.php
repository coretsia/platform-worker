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

use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Tests\Support\RecordingControlClient;
use Coretsia\Platform\Worker\Tests\Support\RecordingOutput;
use Coretsia\Platform\Worker\Tests\Support\TestInput;
use PHPUnit\Framework\TestCase;

final class WorkerStopCommandContractTest extends TestCase
{
    public function testStopDelegatesToClientAndReturnsOnlyAfterTerminalState(): void
    {
        $client = new RecordingControlClient();
        $client->stopState = self::state(
            WorkerPoolStatus::STOPPING,
            0,
        );
        $output = new RecordingOutput();
        $command = new WorkerStopCommand(
            client: $client,
        );

        self::assertSame(
            0,
            $command->run(
                new TestInput(WorkerStopCommand::NAME),
                $output,
            ),
        );
        self::assertSame(1, $client->stopCalls);
        self::assertSame('stopped', $output->json[0]['status']);
        self::assertSame(1234, $output->json[0]['pid']);

        $source = \file_get_contents(
            \dirname(__DIR__, 2) . '/src/Console/WorkerStopCommand.php',
        );
        self::assertIsString($source);
        $codeOnly = \preg_replace(
            '/\/\*.*?\*\/|\/\/[^\n]*/s',
            '',
            $source,
        ) ?? $source;

        foreach (
            [
                'ConfigRepositoryInterface',
                'WorkerServiceFactory',
                'WorkerPoolSpec',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $codeOnly);
        }
    }

    private static function state(
        WorkerPoolStatus $status = WorkerPoolStatus::RUNNING,
        int $ready = 2,
    ): WorkerPoolState {
        return new WorkerPoolState(
            pid: 1234,
            status: $status,
            workerCount: 2,
            readyWorkerCount: $ready,
            driverRequested: 'auto',
            driver: 'pcntl',
            controlTransportRequested: 'auto',
            controlTransport: 'unix',
            endpointHash: \str_repeat('a', 64),
        );
    }
}
