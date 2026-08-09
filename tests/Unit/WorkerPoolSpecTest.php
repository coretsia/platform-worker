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

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerPoolSpecTest extends TestCase
{
    public function testNormalizesAutoCapabilitiesDeterministically(): void
    {
        $config = require \dirname(__DIR__, 2) . '/config/worker.php';

        self::assertIsArray($config);

        $unix = WorkerPoolSpec::fromConfig(
            $config,
            pcntlDriverAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
            procDriverAvailable: true,
        );

        self::assertSame('auto', $unix->driverRequested());
        self::assertSame('pcntl', $unix->driver());
        self::assertSame('unix', $unix->controlTransport());

        $windows = WorkerPoolSpec::fromConfig(
            $config,
            pcntlDriverAvailable: true,
            platformFamily: 'Windows',
            unixDomainSocketsSupported: false,
            procDriverAvailable: true,
        );

        self::assertSame('proc', $windows->driver());
        self::assertSame('tcp', $windows->controlTransport());
    }


    public function testAutoDriverFailsWhenNoSecureProcessAdapterIsAvailable(): void
    {
        $config = require \dirname(__DIR__, 2) . '/config/worker.php';

        self::assertIsArray($config);

        $this->expectException(WorkerLifecycleFailedException::class);

        WorkerPoolSpec::fromConfig(
            $config,
            pcntlDriverAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
            procDriverAvailable: false,
        );
    }

    public function testExposesAllLifecycleFieldsAndRedactedEndpointIdentity(): void
    {
        $spec = WorkerSpecFactory::create([
            'workers' => 3,
            'max_requests' => 17,
            'driver' => 'proc',
            'control' => ['transport' => 'tcp'],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 9444,
            ],
            'start_timeout_ms' => 1200,
            'stop_timeout_ms' => 2300,
            'force_kill_timeout_ms' => 400,
        ]);

        self::assertSame(3, $spec->workers());
        self::assertSame(17, $spec->maxRequests());
        self::assertSame('proc', $spec->driver());
        self::assertSame('tcp', $spec->controlTransport());
        self::assertSame(1200, $spec->startTimeoutMs());
        self::assertSame(2300, $spec->stopTimeoutMs());
        self::assertSame(400, $spec->forceKillTimeoutMs());
        self::assertSame('tcp:127.0.0.1:9444', $spec->endpointIdentifier());
    }

    #[DataProvider('invalidOverrides')]
    public function testInvalidConfigurationFailsDeterministically(array $override): void
    {
        $this->expectException(WorkerLifecycleFailedException::class);

        WorkerSpecFactory::create($override);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidOverrides(): iterable
    {
        yield 'numeric string timeout' => [
            [
                'start_timeout_ms' => '1000',
            ]
        ];

        yield 'timeout too large' => [
            [
                'stop_timeout_ms' => 86_400_001,
            ]
        ];

        yield 'public TCP host' => [
            [
                'tcp' => ['host' => '0.0.0.0'],
            ]
        ];

        yield 'overlapping state and lock' => [
            [
                'state_path' => 'var/tmp/worker.lock',
            ]
        ];

        yield 'socket overlaps lifecycle locator' => [
            [
                'socket_path' => 'var/tmp/worker.lifecycle.json',
            ]
        ];

        yield 'state and state temp overlap lifecycle locator artifacts' => [
            [
                'state_path' => 'var/tmp/worker.lifecycle.json',
            ]
        ];

        yield 'stop flag overlaps canonical lock' => [
            [
                'stop_flag_path' => 'var/tmp/worker.lock',
            ]
        ];

        yield 'absolute path' => [
            [
                'socket_path' => '/tmp/worker.sock',
            ]
        ];

        foreach (
            [
                'socket_path',
                'state_path',
                'stop_flag_path',
            ] as $pathKey
        ) {
            yield $pathKey . ' rejects skeleton prefix' => [
                [
                    $pathKey => 'skeleton/var/tmp/worker.runtime',
                ]
            ];

            yield $pathKey . ' rejects at-prefixed segment' => [
                [
                    $pathKey => 'var/@private/worker.runtime',
                ]
            ];
        }
    }
}
