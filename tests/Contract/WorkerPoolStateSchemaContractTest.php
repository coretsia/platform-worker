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

use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use PHPUnit\Framework\TestCase;

final class WorkerPoolStateSchemaContractTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const array STATE_SCHEMA_KEYS = [
        'version',
        'pid',
        'worker_count',
        'driver_requested',
        'driver',
        'control_transport_requested',
        'control_transport',
        'endpoint_hash',
    ];

    public function testToArrayReturnsExactStableKeyOrder(): void
    {
        $endpointHash = \hash('sha256', 'tcp:10.20.30.40:65535');

        $state = new WorkerPoolState(
            pid: 12345,
            workerCount: 4,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: $endpointHash,
        );

        $array = $state->toArray();

        self::assertSame(self::STATE_SCHEMA_KEYS, \array_keys($array));

        self::assertSame(
            [
                'version' => 1,
                'pid' => 12345,
                'worker_count' => 4,
                'driver_requested' => 'proc',
                'driver' => 'proc',
                'control_transport_requested' => 'tcp',
                'control_transport' => 'tcp',
                'endpoint_hash' => $endpointHash,
            ],
            $array,
        );
    }

    public function testToArrayDoesNotIncludeUnsafeTcpStateFields(): void
    {
        $endpointHash = \hash('sha256', 'tcp:10.20.30.40:65535');

        $state = new WorkerPoolState(
            pid: 12345,
            workerCount: 4,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: $endpointHash,
        );

        $array = $state->toArray();

        self::assertSafeStateShape($array);

        $json = \json_encode($array, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertIsString($json);

        self::assertStringNotContainsString('10.20.30.40', $json);
        self::assertStringNotContainsString('tcp:10.20.30.40:65535', $json);
        self::assertStringNotContainsString('tcp://10.20.30.40:65535', $json);
        self::assertStringNotContainsString(':65535', $json);
        self::assertStringNotContainsString('"65535"', $json);

        self::assertEndpointHash($endpointHash, $array['endpoint_hash']);
    }

    public function testToArrayDoesNotIncludeUnsafeUnixStateFields(): void
    {
        $socketPath = 'var/tmp/private-worker-control.sock';
        $endpointHash = \hash('sha256', 'unix:' . $socketPath);

        $state = new WorkerPoolState(
            pid: 67890,
            workerCount: 2,
            driverRequested: 'pcntl',
            driver: 'pcntl',
            controlTransportRequested: 'unix',
            controlTransport: 'unix',
            endpointHash: $endpointHash,
        );

        $array = $state->toArray();

        self::assertSafeStateShape($array);

        $json = \json_encode($array, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertIsString($json);

        self::assertStringNotContainsString($socketPath, $json);
        self::assertStringNotContainsString('private-worker-control.sock', $json);
        self::assertStringNotContainsString('unix:' . $socketPath, $json);
        self::assertStringNotContainsString('unix://', $json);
        self::assertStringNotContainsString('/tmp/private-worker-control.sock', $json);
        self::assertStringNotContainsString('\\tmp\\private-worker-control.sock', $json);

        self::assertEndpointHash($endpointHash, $array['endpoint_hash']);
    }

    public function testToArrayDoesNotRelyOnFilesystemStateWriteFilesOrEmitStdoutStderr(): void
    {
        $state = new WorkerPoolState(
            pid: 12345,
            workerCount: 1,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: \hash('sha256', 'tcp:127.0.0.1:9501'),
        );

        $array = self::captureStdout(
            static fn (): array => $state->toArray(),
        );

        $source = self::classSource(WorkerPoolState::class);

        self::assertStringNotContainsString('file_put_contents(', $source);
        self::assertStringNotContainsString('file_get_contents(', $source);
        self::assertStringNotContainsString('fopen(', $source);
        self::assertStringNotContainsString('fwrite(', $source);
        self::assertStringNotContainsString('fwrite(STDOUT', $source);
        self::assertStringNotContainsString('fwrite(STDERR', $source);
        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('error_log(', $source);

        self::assertSame(self::STATE_SCHEMA_KEYS, \array_keys($array));

        /*
         * Contract guard: this test intentionally uses only WorkerPoolState
         * in-memory DTO behavior. It does not create skeleton roots, does not
         * call WorkerStateStore, does not read files, and does not write files.
         */
        self::assertFalse(\array_key_exists('state_path', $array));
        self::assertFalse(\array_key_exists('socket_path', $array));
        self::assertFalse(\array_key_exists('stop_flag_path', $array));
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function assertSafeStateShape(array $array): void
    {
        self::assertSame(self::STATE_SCHEMA_KEYS, \array_keys($array));

        foreach (
            [
                'started_at',
                'timestamp',
                'timestamps',
                'created_at',
                'updated_at',
                'env',
                'environment',
                'socket_path',
                'tcp_host',
                'tcp_port',
                'endpoint',
                'endpoint_identifier',
                'endpoint_url',
                'absolute_path',
                'skeleton_root',
                'state_path',
                'stop_flag_path',
                'payload',
                'task_payload',
                'body',
                'headers',
                'authorization',
                'cookie',
                'token',
                'tokens',
                'secret',
                'secrets',
            ] as $unsafeKey
        ) {
            self::assertFalse(
                \array_key_exists($unsafeKey, $array),
                'Unsafe key leaked into WorkerPoolState::toArray(): ' . $unsafeKey,
            );
        }

        self::assertSame(1, $array['version']);
        self::assertIsInt($array['pid']);
        self::assertGreaterThan(0, $array['pid']);
        self::assertIsInt($array['worker_count']);
        self::assertGreaterThan(0, $array['worker_count']);

        self::assertContains($array['driver_requested'], ['auto', 'pcntl', 'proc']);
        self::assertContains($array['driver'], ['pcntl', 'proc']);
        self::assertContains($array['control_transport_requested'], ['auto', 'unix', 'tcp']);
        self::assertContains($array['control_transport'], ['unix', 'tcp']);

        self::assertEndpointHashValue($array['endpoint_hash']);
    }

    private static function assertEndpointHash(string $expected, mixed $actual): void
    {
        self::assertEndpointHashValue($actual);
        self::assertSame($expected, $actual);
    }

    private static function assertEndpointHashValue(mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $actual);
    }

    private static function captureStdout(\Closure $callback): mixed
    {
        \ob_start();

        try {
            $result = $callback();
            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        return $result;
    }

    /**
     * @param class-string $className
     */
    private static function classSource(string $className): string
    {
        $reflection = new \ReflectionClass($className);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }
}
