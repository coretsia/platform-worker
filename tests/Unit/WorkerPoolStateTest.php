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

use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerPoolStateTest extends TestCase
{
    public function testSchemaVersionOneRoundTripsExactly(): void
    {
        $state = self::state();

        self::assertSame(1, $state->version());
        self::assertSame(
            [
                'version' => 1,
                'pid' => 1234,
                'status' => 'running',
                'worker_count' => 2,
                'ready_worker_count' => 2,
                'driver_requested' => 'auto',
                'driver' => 'pcntl',
                'control_transport_requested' => 'auto',
                'control_transport' => 'unix',
                'endpoint_hash' => \str_repeat('a', 64),
            ],
            $state->toArray(),
        );

        self::assertEquals(
            $state,
            WorkerPoolState::fromArray($state->toArray()),
        );
    }

    public function testWithStatusPreservesIdentityAndUpdatesReadyCount(): void
    {
        $state = self::state()->withStatus(
            WorkerPoolStatus::STOPPING,
            1,
        );

        self::assertSame(WorkerPoolStatus::STOPPING, $state->status());
        self::assertSame(1, $state->readyWorkerCount());
        self::assertSame(1234, $state->pid());
        self::assertSame(\str_repeat('a', 64), $state->endpointHash());
    }

    #[DataProvider('invalidArrays')]
    public function testInvalidSchemaIsRejected(array $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorkerPoolState::fromArray($value);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidArrays(): iterable
    {
        $valid = self::state()->toArray();

        yield 'unknown key' => [
            [
                ...$valid,
                'raw_endpoint' => '/tmp/private.sock',
            ]
        ];

        yield 'wrong version' => [
            [
                ...$valid,
                'version' => 2,
            ]
        ];

        yield 'invalid ready count' => [
            [
                ...$valid,
                'ready_worker_count' => 3,
            ]
        ];

        yield 'invalid hash' => [
            [
                ...$valid,
                'endpoint_hash' => '/tmp/private.sock',
            ]
        ];
    }

    private static function state(): WorkerPoolState
    {
        return new WorkerPoolState(
            pid: 1234,
            status: WorkerPoolStatus::RUNNING,
            workerCount: 2,
            readyWorkerCount: 2,
            driverRequested: 'auto',
            driver: 'pcntl',
            controlTransportRequested: 'auto',
            controlTransport: 'unix',
            endpointHash: \str_repeat('a', 64),
        );
    }
}
