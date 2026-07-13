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

namespace Coretsia\Platform\Worker\Tests\Unit;

use Coretsia\Kernel\Runtime\Driver\BackgroundDriver;
use Coretsia\Kernel\Runtime\Driver\HttpDriver;
use Coretsia\Platform\Worker\Internal\WorkerRuntimeDriverContributions;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use PHPUnit\Framework\TestCase;

final class WorkerRuntimeDriverContributionsTest extends TestCase
{
    public function testMapsQueueTaskTypeToWorkerQueueBackgroundDriver(): void
    {
        $spec = self::workerPoolSpec('queue');

        $contributions = WorkerRuntimeDriverContributions::fromSpec($spec);

        self::assertSame([], $contributions->httpDrivers());
        self::assertSame([BackgroundDriver::WORKER_QUEUE], $contributions->backgroundDrivers());
        self::assertSame(['bg.worker_queue'], $contributions->driverIds());
    }

    public function testMapsHttpTaskTypeToWorkerHttpDriver(): void
    {
        $spec = self::workerPoolSpec('http');

        $contributions = WorkerRuntimeDriverContributions::fromSpec($spec);

        self::assertSame([HttpDriver::WORKER], $contributions->httpDrivers());
        self::assertSame([], $contributions->backgroundDrivers());
        self::assertSame(['http.worker'], $contributions->driverIds());
    }

    private static function workerPoolSpec(string $taskType): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: [
                'workers' => 1,
                'max_requests' => 100,
                'task_type' => $taskType,
                'socket_path' => 'var/run/coretsia-worker.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9501,
                ],
                'state_path' => 'var/run/coretsia-worker.state',
                'stop_flag_path' => 'var/run/coretsia-worker.stop',
                'stop_timeout_ms' => 1000,
            ],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }
}
