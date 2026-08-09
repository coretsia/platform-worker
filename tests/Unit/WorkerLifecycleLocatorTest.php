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

use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocator;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerLifecycleLocatorTest extends TestCase
{
    public function testBuildsExactUnixLocatorFromPoolSpecAndRoundTrips(): void
    {
        $credential = self::credential('a');
        $locator = WorkerLifecycleLocator::fromPoolSpec(
            WorkerSpecFactory::create([
                'socket_path' => 'var/tmp/private-worker.sock',
                'stop_timeout_ms' => 2_300,
                'force_kill_timeout_ms' => 400,
            ]),
            $credential,
        );

        $expected = [
            'version' => 1,
            'control_credential' => \str_repeat('a', 64),
            'control_transport' => 'unix',
            'socket_path' => 'var/tmp/private-worker.sock',
            'tcp_host' => null,
            'tcp_port' => null,
            'stop_timeout_ms' => 2_300,
            'force_kill_timeout_ms' => 400,
        ];

        self::assertSame($expected, $locator->toArray());
        self::assertSame($credential, $locator->controlCredential());
        self::assertSame('unix', $locator->controlTransport());
        self::assertSame('var/tmp/private-worker.sock', $locator->socketPath());
        self::assertNull($locator->tcpHost());
        self::assertNull($locator->tcpPort());
        self::assertSame(2_300, $locator->stopTimeoutMs());
        self::assertSame(400, $locator->forceKillTimeoutMs());
        self::assertSame(5_100, $locator->stopRequestTimeoutMs());
        self::assertSame(
            'unix:var/tmp/private-worker.sock',
            $locator->endpointIdentifier(),
        );
        self::assertSame(
            \hash('sha256', 'unix:var/tmp/private-worker.sock'),
            $locator->endpointHash(),
        );
        self::assertSame(
            $expected,
            WorkerLifecycleLocator::fromArray($locator->toArray())->toArray(),
        );
    }

    public function testBuildsExactTcpLocatorFromPoolSpecAndRoundTrips(): void
    {
        $credential = self::credential('b');
        $locator = WorkerLifecycleLocator::fromPoolSpec(
            WorkerSpecFactory::create([
                'driver' => 'proc',
                'control' => ['transport' => 'tcp'],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9_444,
                ],
                'stop_timeout_ms' => 4_000,
                'force_kill_timeout_ms' => 750,
            ]),
            $credential,
        );

        $expected = [
            'version' => 1,
            'control_credential' => \str_repeat('b', 64),
            'control_transport' => 'tcp',
            'socket_path' => null,
            'tcp_host' => '127.0.0.1',
            'tcp_port' => 9_444,
            'stop_timeout_ms' => 4_000,
            'force_kill_timeout_ms' => 750,
        ];

        self::assertSame($expected, $locator->toArray());
        self::assertSame($credential, $locator->controlCredential());
        self::assertSame('tcp', $locator->controlTransport());
        self::assertNull($locator->socketPath());
        self::assertSame('127.0.0.1', $locator->tcpHost());
        self::assertSame(9_444, $locator->tcpPort());
        self::assertSame(4_000, $locator->stopTimeoutMs());
        self::assertSame(750, $locator->forceKillTimeoutMs());
        self::assertSame(7_500, $locator->stopRequestTimeoutMs());
        self::assertSame('tcp:127.0.0.1:9444', $locator->endpointIdentifier());
        self::assertSame(
            \hash('sha256', 'tcp:127.0.0.1:9444'),
            $locator->endpointHash(),
        );
        self::assertSame(
            $expected,
            WorkerLifecycleLocator::fromArray($locator->toArray())->toArray(),
        );
    }

    public function testCredentialDoesNotAffectEndpointIdentity(): void
    {
        $spec = WorkerSpecFactory::create();
        $first = WorkerLifecycleLocator::fromPoolSpec(
            $spec,
            self::credential('a'),
        );
        $second = WorkerLifecycleLocator::fromPoolSpec(
            $spec,
            self::credential('b'),
        );

        self::assertNotSame(
            $first->controlCredential()->encoded(),
            $second->controlCredential()->encoded(),
        );
        self::assertSame(
            $first->endpointIdentifier(),
            $second->endpointIdentifier(),
        );
        self::assertSame(
            $first->endpointHash(),
            $second->endpointHash(),
        );
    }

    #[DataProvider('invalidLocatorMaps')]
    public function testRejectsNonExactOrUnsafeLocatorMaps(array $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('worker-lifecycle-locator-invalid');

        WorkerLifecycleLocator::fromArray($value);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidLocatorMaps(): iterable
    {
        $unix = [
            'version' => 1,
            'control_credential' => \str_repeat('a', 64),
            'control_transport' => 'unix',
            'socket_path' => 'var/tmp/worker.sock',
            'tcp_host' => null,
            'tcp_port' => null,
            'stop_timeout_ms' => 10_000,
            'force_kill_timeout_ms' => 1_000,
        ];

        yield 'missing key' => [
            \array_diff_key($unix, ['tcp_port' => true]),
        ];
        yield 'unknown key' => [[...$unix, 'instance_id' => 'unused']];
        yield 'unsupported version' => [[...$unix, 'version' => 2]];
        yield 'missing credential' => [
            \array_diff_key($unix, ['control_credential' => true]),
        ];
        yield 'short credential' => [[...$unix, 'control_credential' => 'abcd']];
        yield 'uppercase credential' => [
            [
                ...$unix,
                'control_credential' => \str_repeat('A', 64),
            ]
        ];
        yield 'unsupported transport' => [[...$unix, 'control_transport' => 'pipe']];
        yield 'numeric-string timeout' => [[...$unix, 'stop_timeout_ms' => '10000']];
        yield 'zero stop timeout' => [[...$unix, 'stop_timeout_ms' => 0]];
        yield 'oversized stop timeout' => [[...$unix, 'stop_timeout_ms' => 86_400_001]];
        yield 'zero force-kill timeout' => [[...$unix, 'force_kill_timeout_ms' => 0]];
        yield 'oversized force-kill timeout' => [[...$unix, 'force_kill_timeout_ms' => 86_400_001]];
        yield 'absolute Unix path' => [[...$unix, 'socket_path' => '/var/tmp/worker.sock']];
        yield 'skeleton-prefixed Unix path' => [
            [
                ...$unix,
                'socket_path' => 'skeleton/var/tmp/worker.sock',
            ]
        ];
        yield 'backslash Unix path' => [[...$unix, 'socket_path' => 'var\\tmp\\worker.sock']];
        yield 'control character Unix path' => [
            [
                ...$unix,
                'socket_path' => "var/tmp/worker\nsock",
            ]
        ];
        yield 'Unix locator with active TCP host' => [[...$unix, 'tcp_host' => '127.0.0.1']];
        yield 'Unix locator with active TCP port' => [[...$unix, 'tcp_port' => 9_327]];
        yield 'TCP locator with active Unix path' => [
            [
                ...$unix,
                'control_transport' => 'tcp',
                'tcp_host' => '127.0.0.1',
                'tcp_port' => 9_327,
            ]
        ];
        yield 'TCP locator with non-loopback host' => [
            [
                ...$unix,
                'control_transport' => 'tcp',
                'socket_path' => null,
                'tcp_host' => '0.0.0.0',
                'tcp_port' => 9_327,
            ]
        ];
        yield 'TCP locator with missing port' => [
            [
                ...$unix,
                'control_transport' => 'tcp',
                'socket_path' => null,
                'tcp_host' => '127.0.0.1',
                'tcp_port' => null,
            ]
        ];
        yield 'TCP locator with numeric-string port' => [
            [
                ...$unix,
                'control_transport' => 'tcp',
                'socket_path' => null,
                'tcp_host' => '127.0.0.1',
                'tcp_port' => '9327',
            ]
        ];
    }

    private static function credential(string $character): WorkerControlCredential
    {
        return WorkerControlCredential::fromEncoded(
            \str_repeat($character, 64),
        );
    }
}
