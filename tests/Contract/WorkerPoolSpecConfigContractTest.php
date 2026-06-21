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

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\TestCase;

final class WorkerPoolSpecConfigContractTest extends TestCase
{
    public function testConfigDerivedPathsRemainRelativeAndAreStoredExactlyAsConfigured(): void
    {
        $spec = self::workerSpec([
            'socket_path' => 'var/run/coretsia-worker/control.sock',
            'state_path' => 'var/run/coretsia-worker/state.json',
            'stop_flag_path' => 'var/run/coretsia-worker/stop.flag',
        ]);

        self::assertSame('var/run/coretsia-worker/control.sock', $spec->socketPath());
        self::assertSame('var/run/coretsia-worker/state.json', $spec->statePath());
        self::assertSame('var/run/coretsia-worker/stop.flag', $spec->stopFlagPath());

        foreach (
            [
                $spec->socketPath(),
                $spec->statePath(),
                $spec->stopFlagPath(),
            ] as $path
        ) {
            self::assertFalse(\str_starts_with($path, '/'));
            self::assertFalse(\str_starts_with($path, '\\'));
            self::assertFalse(\preg_match('/\A[A-Za-z]:[\/\\\\]/', $path) === 1);
            self::assertStringNotContainsString('://', $path);
            self::assertStringNotContainsString('skeleton/', $path);
            self::assertStringNotContainsString('..', $path);
        }
    }

    public function testAbsoluteUnixPathsAreRejected(): void
    {
        foreach (self::pathKeys() as $pathKey) {
            foreach (
                [
                    '/var/tmp/worker.sock',
                    '/home/coretsia/worker.sock',
                    '/Users/coretsia/worker.sock',
                ] as $path
            ) {
                self::assertInvalidConfig(
                    overrides: [
                        $pathKey => $path,
                    ],
                );
            }
        }
    }

    public function testAbsoluteWindowsPathsAreRejected(): void
    {
        foreach (self::pathKeys() as $pathKey) {
            foreach (
                [
                    'C:\\coretsia\\worker.sock',
                    'C:/coretsia/worker.sock',
                    'c:\\coretsia\\worker.sock',
                    'c:/coretsia/worker.sock',
                    '\\\\server\\share\\worker.sock',
                ] as $path
            ) {
                self::assertInvalidConfig(
                    overrides: [
                        $pathKey => $path,
                    ],
                );
            }
        }
    }

    public function testParentDirectoryPathSegmentsAreRejected(): void
    {
        foreach (self::pathKeys() as $pathKey) {
            self::assertInvalidConfig(
                overrides: [
                    $pathKey => 'var/tmp/../worker.sock',
                ],
            );

            self::assertInvalidConfig(
                overrides: [
                    $pathKey => '../var/tmp/worker.sock',
                ],
            );
        }
    }

    public function testSkeletonPathPrefixIsRejected(): void
    {
        foreach (self::pathKeys() as $pathKey) {
            self::assertInvalidConfig(
                overrides: [
                    $pathKey => 'skeleton/var/tmp/worker.sock',
                ],
            );
        }
    }

    public function testTcpPortZeroIsRejected(): void
    {
        self::assertInvalidConfig([
            'tcp' => [
                'port' => 0,
            ],
        ]);
    }

    public function testFloatConfigValuesAreRejected(): void
    {
        foreach (
            [
                ['enabled' => 1.0],
                ['workers' => 4.0],
                ['max_requests' => 1000.0],
                ['task_type' => 1.0],
                ['socket_path' => 1.0],
                ['driver' => 1.0],
                ['control' => ['transport' => 1.0]],
                ['tcp' => ['host' => 1.0]],
                ['tcp' => ['port' => 9327.0]],
                ['state_path' => 1.0],
                ['stop_flag_path' => 1.0],
                ['stop_timeout_ms' => 3000.0],
            ] as $overrides
        ) {
            self::assertInvalidConfig($overrides);
        }
    }

    public function testNormalizesDefaultsExactlyFromWorkerConfigFile(): void
    {
        $config = require self::workerConfigFile();

        self::assertSame(
            [
                'enabled' => false,
                'workers' => 4,
                'max_requests' => 1000,
                'task_type' => 'queue',
                'socket_path' => 'var/tmp/worker.sock',
                'driver' => 'auto',
                'proc' => [
                    'command' => [
                        '@php',
                        'vendor/coretsia/platform-worker/bin/coretsia-worker',
                    ],
                ],
                'control' => [
                    'transport' => 'auto',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9327,
                ],
                'state_path' => 'var/tmp/worker.state.json',
                'stop_flag_path' => 'var/tmp/worker.stop',
                'stop_timeout_ms' => 3000,
            ],
            $config,
        );

        $spec = WorkerPoolSpec::fromConfig(
            config: $config,
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );

        self::assertFalse($spec->enabled());
        self::assertSame(4, $spec->workers());
        self::assertSame(1000, $spec->maxRequests());
        self::assertSame('queue', $spec->taskType());
        self::assertSame('var/tmp/worker.sock', $spec->socketPath());
        self::assertSame('auto', $spec->driverRequested());
        self::assertSame('proc', $spec->driver());
        self::assertSame('auto', $spec->controlTransportRequested());
        self::assertSame('tcp', $spec->controlTransport());
        self::assertSame('127.0.0.1', $spec->tcpHost());
        self::assertSame(9327, $spec->tcpPort());
        self::assertSame('var/tmp/worker.state.json', $spec->statePath());
        self::assertSame('var/tmp/worker.stop', $spec->stopFlagPath());
        self::assertSame(3000, $spec->stopTimeoutMs());
    }

    public function testEndpointIdentifiersAreDeterministicAndHashOnlyForPublicOutput(): void
    {
        $tcpSpec = self::workerSpec([
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '10.20.30.40',
                'port' => 9511,
            ],
        ]);

        self::assertSame('tcp:10.20.30.40:9511', $tcpSpec->endpointIdentifier());
        self::assertSame('tcp:10.20.30.40:9511', $tcpSpec->endpointIdentifier());

        $tcpHash = WorkerStateStore::endpointHash($tcpSpec);

        self::assertSame(\hash('sha256', 'tcp:10.20.30.40:9511'), $tcpHash);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $tcpHash);

        $tcpPublicOutput = [
            'endpoint_hash' => $tcpHash,
        ];

        $tcpPublicJson = \json_encode($tcpPublicOutput, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertIsString($tcpPublicJson);
        self::assertStringContainsString($tcpHash, $tcpPublicJson);
        self::assertStringNotContainsString('10.20.30.40', $tcpPublicJson);
        self::assertStringNotContainsString('9511', $tcpPublicJson);
        self::assertStringNotContainsString('tcp:10.20.30.40:9511', $tcpPublicJson);
        self::assertStringNotContainsString('tcp://10.20.30.40:9511', $tcpPublicJson);

        $unixSpec = self::workerSpec(
            overrides: [
                'driver' => 'pcntl',
                'control' => [
                    'transport' => 'unix',
                ],
                'socket_path' => 'var/tmp/private-worker-control.sock',
            ],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('unix:var/tmp/private-worker-control.sock', $unixSpec->endpointIdentifier());
        self::assertSame('unix:var/tmp/private-worker-control.sock', $unixSpec->endpointIdentifier());

        $unixHash = WorkerStateStore::endpointHash($unixSpec);

        self::assertSame(\hash('sha256', 'unix:var/tmp/private-worker-control.sock'), $unixHash);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $unixHash);

        $unixPublicOutput = [
            'endpoint_hash' => $unixHash,
        ];

        $unixPublicJson = \json_encode($unixPublicOutput, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertIsString($unixPublicJson);
        self::assertStringContainsString($unixHash, $unixPublicJson);
        self::assertStringNotContainsString('var/tmp/private-worker-control.sock', $unixPublicJson);
        self::assertStringNotContainsString('private-worker-control.sock', $unixPublicJson);
        self::assertStringNotContainsString('unix:var/tmp/private-worker-control.sock', $unixPublicJson);
        self::assertStringNotContainsString('unix://', $unixPublicJson);
    }

    public function testCapabilityResolutionIsExplicitAndDoesNotDependOnHostPcntlOrUnixSocketSupport(): void
    {
        $procSpec = self::workerSpec(
            overrides: [
                'driver' => 'auto',
                'control' => [
                    'transport' => 'auto',
                ],
            ],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('auto', $procSpec->driverRequested());
        self::assertSame('proc', $procSpec->driver());
        self::assertSame('auto', $procSpec->controlTransportRequested());
        self::assertSame('tcp', $procSpec->controlTransport());

        $pcntlUnixSpec = self::workerSpec(
            overrides: [
                'driver' => 'auto',
                'control' => [
                    'transport' => 'auto',
                ],
            ],
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('auto', $pcntlUnixSpec->driverRequested());
        self::assertSame('pcntl', $pcntlUnixSpec->driver());
        self::assertSame('auto', $pcntlUnixSpec->controlTransportRequested());
        self::assertSame('unix', $pcntlUnixSpec->controlTransport());

        $windowsSpec = self::workerSpec(
            overrides: [
                'driver' => 'auto',
                'control' => [
                    'transport' => 'auto',
                ],
            ],
            pcntlForkAvailable: true,
            platformFamily: 'Windows',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('auto', $windowsSpec->driverRequested());
        self::assertSame('proc', $windowsSpec->driver());
        self::assertSame('auto', $windowsSpec->controlTransportRequested());
        self::assertSame('tcp', $windowsSpec->controlTransport());
    }

    public function testConfigContractDoesNotRelyOnFilesystemStateWriteFilesOrEmitStdoutStderr(): void
    {
        $spec = self::captureStdout(
            static fn (): WorkerPoolSpec => self::workerSpec([
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9501,
                ],
            ]),
        );

        self::assertSame('proc', $spec->driver());
        self::assertSame('tcp', $spec->controlTransport());

        $source = self::classSource(WorkerPoolSpec::class);

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

        /*
         * Contract guard: this test intentionally exercises only config
         * normalization and value-object accessors. It does not create skeleton
         * roots, does not call WorkerStateStore::write(), does not read runtime
         * state files, and does not write files.
         */
        self::assertSame('var/tmp/worker.sock', $spec->socketPath());
        self::assertSame('var/tmp/worker.state.json', $spec->statePath());
        self::assertSame('var/tmp/worker.stop', $spec->stopFlagPath());
    }

    /**
     * @return list<'socket_path'|'state_path'|'stop_flag_path'>
     */
    private static function pathKeys(): array
    {
        return [
            'socket_path',
            'state_path',
            'stop_flag_path',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function assertInvalidConfig(array $overrides): void
    {
        try {
            self::workerSpec($overrides);

            self::fail('Expected WorkerStartFailedException was not thrown.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
            self::assertSame(WorkerStartFailedException::REASON_INVALID_STATE, $exception->reason());
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function workerSpec(
        array $overrides = [],
        bool $pcntlForkAvailable = false,
        string $platformFamily = 'Linux',
        bool $unixDomainSocketsSupported = false,
    ): WorkerPoolSpec {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig($overrides),
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
                'workers' => 4,
                'max_requests' => 1000,
                'task_type' => 'queue',
                'socket_path' => 'var/tmp/worker.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9327,
                ],
                'state_path' => 'var/tmp/worker.state.json',
                'stop_flag_path' => 'var/tmp/worker.stop',
                'stop_timeout_ms' => 3000,
            ],
            $overrides,
        );
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

    private static function workerConfigFile(): string
    {
        $reflection = new \ReflectionClass(WorkerPoolSpec::class);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $configFile = \dirname($file, 3) . '/config/worker.php';

        self::assertFileExists($configFile);

        return $configFile;
    }
}
