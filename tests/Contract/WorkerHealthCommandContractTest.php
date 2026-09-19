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
use Coretsia\Platform\Worker\Runtime\WorkerHealthState;
use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Tests\Support\RecordingControlClient;
use Coretsia\Platform\Worker\Tests\Support\RecordingOutput;
use Coretsia\Platform\Worker\Tests\Support\TestInput;
use PHPUnit\Framework\TestCase;

final class WorkerHealthCommandContractTest extends TestCase
{
    public function testHealthCommandUsesLiveClientAndEmitsSafeSummary(): void
    {
        $client = new RecordingControlClient();
        $client->healthState = new WorkerHealthState(
            pid: 123,
            status: WorkerPoolStatus::RUNNING,
            workerCount: 2,
            readyWorkerCount: 2,
            healthy: true,
            reason: WorkerHealthState::REASON_HEALTHY,
            driver: 'pcntl',
            controlTransport: 'unix',
            endpointHash: \str_repeat('a', 64),
        );
        $command = new WorkerHealthCommand(
            client: $client,
        );
        $output = new RecordingOutput();

        self::assertSame(
            0,
            $command->run(
                new TestInput(WorkerHealthCommand::NAME),
                $output,
            ),
        );
        self::assertSame(1, $client->healthCalls);

        $source = \file_get_contents(
            \dirname(__DIR__, 2) . '/src/Console/WorkerHealthCommand.php',
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
        self::assertSame(
            [
                'status' => 'healthy',
                'pool_status' => 'running',
                'pid' => 123,
                'worker_count' => 2,
                'ready_worker_count' => 2,
                'healthy' => true,
                'reason' => 'healthy',
                'driver' => 'pcntl',
                'control_transport' => 'unix',
                'endpoint_hash' => \str_repeat('a', 64),
            ],
            $output->json[0],
        );
    }
}
