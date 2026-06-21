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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\TestCase;

final class WorkerStateStoreStateFactoryTest extends TestCase
{
    public function testCreatesWorkerPoolStateFromWorkerPoolSpec(): void
    {
        $spec = self::workerSpec([
            'workers' => 3,
            'driver' => 'auto',
            'control' => [
                'transport' => 'auto',
            ],
        ]);

        $state = self::store()->createState(
            spec: $spec,
            pid: 12345,
        );

        self::assertSame(1, $state->version());
        self::assertSame(12345, $state->pid());
        self::assertSame(3, $state->workerCount());
        self::assertSame('auto', $state->driverRequested());
        self::assertSame('proc', $state->driver());
        self::assertSame('auto', $state->controlTransportRequested());
        self::assertSame('tcp', $state->controlTransport());
        self::assertSame(
            \hash('sha256', 'tcp:127.0.0.1:9501'),
            $state->endpointHash(),
        );

        self::assertSame(
            [
                'version' => 1,
                'pid' => 12345,
                'worker_count' => 3,
                'driver_requested' => 'auto',
                'driver' => 'proc',
                'control_transport_requested' => 'auto',
                'control_transport' => 'tcp',
                'endpoint_hash' => \hash('sha256', 'tcp:127.0.0.1:9501'),
            ],
            $state->toArray(),
        );
    }

    public function testComputesUnixEndpointHashFromUnixPrefixAndConfiguredSocketPathExactly(): void
    {
        $spec = self::workerSpec([
            'driver' => 'pcntl',
            'control' => [
                'transport' => 'unix',
            ],
            'socket_path' => 'runtime/worker/control.sock',
        ]);

        self::assertSame('unix:runtime/worker/control.sock', $spec->endpointIdentifier());

        self::assertSame(
            \hash('sha256', 'unix:runtime/worker/control.sock'),
            WorkerStateStore::endpointHash($spec),
        );

        self::assertSame(
            \hash('sha256', 'unix:runtime/worker/control.sock'),
            self::store()->createState(
                spec: $spec,
                pid: 22222,
            )->endpointHash(),
        );
    }

    public function testComputesTcpEndpointHashFromTcpPrefixHostSeparatorAndResolvedPortExactly(): void
    {
        $spec = self::workerSpec([
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '10.0.0.15',
                'port' => 9515,
            ],
        ]);

        self::assertSame('tcp:10.0.0.15:9515', $spec->endpointIdentifier());

        self::assertSame(
            \hash('sha256', 'tcp:10.0.0.15:9515'),
            WorkerStateStore::endpointHash($spec),
        );

        self::assertSame(
            \hash('sha256', 'tcp:10.0.0.15:9515'),
            self::store()->createState(
                spec: $spec,
                pid: 33333,
            )->endpointHash(),
        );
    }

    public function testStateFactoryPathDoesNotReadFilesWriteFilesOrEmitStdoutStderr(): void
    {
        $spec = self::workerSpec();

        \ob_start();

        try {
            $store = self::store();

            $store->createState(
                spec: $spec,
                pid: 12345,
            );

            WorkerStateStore::endpointHash($spec);

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        $source = self::methodSource(WorkerStateStore::class, 'createState')
            . "\n"
            . self::methodSource(WorkerStateStore::class, 'endpointHash');

        self::assertStringNotContainsString('readBytes(', $source);
        self::assertStringNotContainsString('writeBytes(', $source);
        self::assertStringNotContainsString('statePath(', $source);

        self::assertStringNotContainsString('realpath(', $source);
        self::assertStringNotContainsString('file_exists(', $source);
        self::assertStringNotContainsString('is_file(', $source);
        self::assertStringNotContainsString('is_dir(', $source);
        self::assertStringNotContainsString('file_get_contents(', $source);
        self::assertStringNotContainsString('file_put_contents(', $source);
        self::assertStringNotContainsString('fopen(', $source);
        self::assertStringNotContainsString('mkdir(', $source);
        self::assertStringNotContainsString('rename(', $source);
        self::assertStringNotContainsString('unlink(', $source);

        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('fwrite(STDOUT', $source);
        self::assertStringNotContainsString('fwrite(STDERR', $source);
        self::assertStringNotContainsString('error_log(', $source);
    }

    private static function store(): WorkerStateStore
    {
        return new WorkerStateStore(
            encoder: new StableJsonEncoder(),
            decoder: new StableJsonDecoder(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function workerSpec(array $overrides = []): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig($overrides),
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function workerConfig(array $overrides = []): array
    {
        return \array_replace_recursive(
            [
                'enabled' => true,
                'workers' => 2,
                'max_requests' => 100,
                'task_type' => 'queue',
                'socket_path' => 'var/worker/control.sock',
                'driver' => 'auto',
                'control' => [
                    'transport' => 'auto',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9501,
                ],
                'state_path' => 'var/worker/state.json',
                'stop_flag_path' => 'var/worker/stop.flag',
                'stop_timeout_ms' => 5000,
            ],
            $overrides,
        );
    }

    /**
     * @param class-string $className
     */
    private static function methodSource(string $className, string $methodName): string
    {
        $method = new \ReflectionMethod($className, $methodName);
        $file = $method->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        $lines = \explode("\n", $source);
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertGreaterThanOrEqual($start, $end);

        return \implode(
            "\n",
            \array_slice(
                $lines,
                $start - 1,
                $end - $start + 1,
            ),
        );
    }
}
