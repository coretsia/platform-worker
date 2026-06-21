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

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use PHPUnit\Framework\TestCase;

final class WorkerPoolSpecTest extends TestCase
{
    public function testCreatesSpecFromCompleteDefaultLikeWorkerConfig(): void
    {
        $spec = self::specFromConfig(self::workerConfig());

        self::assertTrue($spec->enabled());
        self::assertSame(2, $spec->workers());
        self::assertSame(100, $spec->maxRequests());
        self::assertSame('queue', $spec->taskType());
        self::assertSame('var/worker/control.sock', $spec->socketPath());
        self::assertSame('auto', $spec->driverRequested());
        self::assertSame('proc', $spec->driver());
        self::assertSame('auto', $spec->controlTransportRequested());
        self::assertSame('tcp', $spec->controlTransport());
        self::assertSame('127.0.0.1', $spec->tcpHost());
        self::assertSame(9501, $spec->tcpPort());
        self::assertSame('var/worker/state.json', $spec->statePath());
        self::assertSame('var/worker/stop.flag', $spec->stopFlagPath());
        self::assertSame(5000, $spec->stopTimeoutMs());
    }

    public function testRejectsMissingRequiredTopLevelKeys(): void
    {
        foreach (
            [
                'enabled',
                'workers',
                'max_requests',
                'task_type',
                'socket_path',
                'driver',
                'control',
                'tcp',
                'state_path',
                'stop_flag_path',
                'stop_timeout_ms',
            ] as $key
        ) {
            $config = self::workerConfig();
            unset($config[$key]);

            self::assertInvalidConfig($config, 'Missing top-level key must be rejected: ' . $key);
        }
    }

    public function testRejectsMissingControlTransport(): void
    {
        $config = self::workerConfig();
        unset($config['control']['transport']);

        self::assertInvalidConfig($config);
    }

    public function testRejectsMissingTcpHost(): void
    {
        $config = self::workerConfig();
        unset($config['tcp']['host']);

        self::assertInvalidConfig($config);
    }

    public function testRejectsMissingTcpPort(): void
    {
        $config = self::workerConfig();
        unset($config['tcp']['port']);

        self::assertInvalidConfig($config);
    }

    public function testRejectsInvalidScalarTypesAndRanges(): void
    {
        self::assertInvalidConfig(self::workerConfig(['enabled' => 'true']));
        self::assertInvalidConfig(self::workerConfig(['workers' => '2']));
        self::assertInvalidConfig(self::workerConfig(['workers' => 0]));
        self::assertInvalidConfig(self::workerConfig(['workers' => -1]));
        self::assertInvalidConfig(self::workerConfig(['max_requests' => '100']));
        self::assertInvalidConfig(self::workerConfig(['max_requests' => 0]));
        self::assertInvalidConfig(self::workerConfig(['max_requests' => -1]));
        self::assertInvalidConfig(self::workerConfig(['stop_timeout_ms' => '5000']));
        self::assertInvalidConfig(self::workerConfig(['stop_timeout_ms' => -1]));
    }

    public function testRejectsUnsupportedTaskTypeDriverAndControlTransport(): void
    {
        self::assertInvalidConfig(self::workerConfig(['task_type' => 'cron']));
        self::assertInvalidConfig(self::workerConfig(['driver' => 'fork']));
        self::assertInvalidConfig(self::workerConfig([
            'control' => [
                'transport' => 'pipe',
            ],
        ]));
    }

    public function testRejectsInvalidTcpPortTypesAndRanges(): void
    {
        self::assertInvalidConfig(self::workerConfig([
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => '9501',
            ],
        ]));

        self::assertInvalidConfig(self::workerConfig([
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 0,
            ],
        ]));

        self::assertInvalidConfig(self::workerConfig([
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 65536,
            ],
        ]));
    }

    public function testPreservesPathValuesAsConfiguredRelativeStrings(): void
    {
        $spec = self::specFromConfig(self::workerConfig([
            'socket_path' => 'runtime/worker/control.sock',
            'state_path' => 'runtime/worker/state-file.json',
            'stop_flag_path' => 'runtime/worker/stop-file.flag',
        ]));

        self::assertSame('runtime/worker/control.sock', $spec->socketPath());
        self::assertSame('runtime/worker/state-file.json', $spec->statePath());
        self::assertSame('runtime/worker/stop-file.flag', $spec->stopFlagPath());
    }

    public function testRejectsUnsafePathValues(): void
    {
        foreach (
            [
                '/var/worker/control.sock',
                '\\var\\worker\\control.sock',
                'C:/worker/control.sock',
                'C:\\worker\\control.sock',
                'var/../worker/control.sock',
                'skeleton/var/worker/control.sock',
            ] as $path
        ) {
            self::assertInvalidConfig(
                self::workerConfig(['socket_path' => $path]),
                'Unsafe socket_path must be rejected: ' . $path,
            );

            self::assertInvalidConfig(
                self::workerConfig(['state_path' => $path]),
                'Unsafe state_path must be rejected: ' . $path,
            );

            self::assertInvalidConfig(
                self::workerConfig(['stop_flag_path' => $path]),
                'Unsafe stop_flag_path must be rejected: ' . $path,
            );
        }
    }

    public function testResolvesAutoDriverToPcntlWhenExplicitCapabilitiesAllowPcntl(): void
    {
        $spec = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'auto',
            ]),
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );

        self::assertSame('auto', $spec->driverRequested());
        self::assertSame('pcntl', $spec->driver());
    }

    public function testResolvesAutoDriverToProcWhenPcntlIsUnavailable(): void
    {
        $spec = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'auto',
            ]),
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('auto', $spec->driverRequested());
        self::assertSame('proc', $spec->driver());
    }

    public function testResolvesAutoDriverToProcOnWindows(): void
    {
        $spec = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'auto',
            ]),
            pcntlForkAvailable: true,
            platformFamily: 'Windows',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('auto', $spec->driverRequested());
        self::assertSame('proc', $spec->driver());
    }

    public function testPreservesExplicitDriverValues(): void
    {
        $pcntl = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'pcntl',
            ]),
            pcntlForkAvailable: false,
            platformFamily: 'Windows',
            unixDomainSocketsSupported: false,
        );

        self::assertSame('pcntl', $pcntl->driverRequested());
        self::assertSame('pcntl', $pcntl->driver());

        $proc = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'proc',
            ]),
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('proc', $proc->driverRequested());
        self::assertSame('proc', $proc->driver());
    }

    public function testResolvesAutoControlTransportToUnixWhenPcntlAndUnixSocketsAreAvailable(): void
    {
        $spec = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'auto',
                'control' => [
                    'transport' => 'auto',
                ],
            ]),
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('pcntl', $spec->driver());
        self::assertSame('auto', $spec->controlTransportRequested());
        self::assertSame('unix', $spec->controlTransport());
    }

    public function testResolvesAutoControlTransportToTcpWhenUnixSocketsAreUnsupported(): void
    {
        $spec = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'auto',
                'control' => [
                    'transport' => 'auto',
                ],
            ]),
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );

        self::assertSame('pcntl', $spec->driver());
        self::assertSame('auto', $spec->controlTransportRequested());
        self::assertSame('tcp', $spec->controlTransport());
    }

    public function testResolvesAutoControlTransportToTcpWhenResolvedDriverIsProc(): void
    {
        $spec = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'proc',
                'control' => [
                    'transport' => 'auto',
                ],
            ]),
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('proc', $spec->driver());
        self::assertSame('auto', $spec->controlTransportRequested());
        self::assertSame('tcp', $spec->controlTransport());
    }

    public function testPreservesExplicitControlTransportValues(): void
    {
        $unix = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'proc',
                'control' => [
                    'transport' => 'unix',
                ],
            ]),
            pcntlForkAvailable: false,
            platformFamily: 'Windows',
            unixDomainSocketsSupported: false,
        );

        self::assertSame('unix', $unix->controlTransportRequested());
        self::assertSame('unix', $unix->controlTransport());

        $tcp = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'pcntl',
                'control' => [
                    'transport' => 'tcp',
                ],
            ]),
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('tcp', $tcp->controlTransportRequested());
        self::assertSame('tcp', $tcp->controlTransport());
    }

    public function testExposesDeterministicUnixEndpointIdentifierForHashing(): void
    {
        $spec = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'pcntl',
                'control' => [
                    'transport' => 'unix',
                ],
                'socket_path' => 'runtime/worker/control.sock',
            ]),
            pcntlForkAvailable: false,
            platformFamily: 'Windows',
            unixDomainSocketsSupported: false,
        );

        self::assertSame('unix:runtime/worker/control.sock', $spec->endpointIdentifier());
    }

    public function testExposesDeterministicTcpEndpointIdentifierForHashing(): void
    {
        $spec = self::specFromConfig(
            config: self::workerConfig([
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '10.0.0.15',
                    'port' => 9515,
                ],
            ]),
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('tcp:10.0.0.15:9515', $spec->endpointIdentifier());
    }

    public function testWorkerPoolSpecRuntimeImplementationDoesNotUseFilesystemStateWriteFilesOrEmitStdoutStderr(): void
    {
        \ob_start();

        try {
            self::specFromConfig(self::workerConfig());

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        $file = new \ReflectionClass(WorkerPoolSpec::class)->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        self::assertStringNotContainsString('realpath(', $source);
        self::assertStringNotContainsString('file_exists(', $source);
        self::assertStringNotContainsString('is_file(', $source);
        self::assertStringNotContainsString('is_dir(', $source);
        self::assertStringNotContainsString('file_get_contents(', $source);
        self::assertStringNotContainsString('file_put_contents(', $source);
        self::assertStringNotContainsString('mkdir(', $source);
        self::assertStringNotContainsString('rename(', $source);
        self::assertStringNotContainsString('unlink(', $source);

        self::assertStringNotContainsString('fopen(', $source);
        self::assertStringNotContainsString('proc_open(', $source);
        self::assertStringNotContainsString('pcntl_fork(', $source);

        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('fwrite(STDOUT', $source);
        self::assertStringNotContainsString('fwrite(STDERR', $source);
        self::assertStringNotContainsString('error_log(', $source);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function specFromConfig(
        array $config,
        bool $pcntlForkAvailable = false,
        string $platformFamily = 'Linux',
        bool $unixDomainSocketsSupported = false,
    ): WorkerPoolSpec {
        return WorkerPoolSpec::fromConfig(
            config: $config,
            pcntlForkAvailable: $pcntlForkAvailable,
            platformFamily: $platformFamily,
            unixDomainSocketsSupported: $unixDomainSocketsSupported,
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
     * @param array<string, mixed> $config
     */
    private static function assertInvalidConfig(array $config, string $message = ''): void
    {
        try {
            self::specFromConfig($config);

            self::fail($message === '' ? 'Expected WorkerStartFailedException was not thrown.' : $message);
        } catch (WorkerStartFailedException) {
            self::assertTrue(true);
        }
    }
}
