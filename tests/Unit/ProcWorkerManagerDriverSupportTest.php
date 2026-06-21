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
use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Manager\Driver\ProcWorkerManagerDriver;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\TestCase;

final class ProcWorkerManagerDriverSupportTest extends TestCase
{
    public function testNameReturnsProc(): void
    {
        self::assertSame('proc', self::driver()->name());
    }

    public function testSupportsSpecsResolvedToProc(): void
    {
        $spec = self::workerSpec([
            'driver' => 'proc',
        ]);

        self::assertSame('proc', $spec->driver());
        self::assertTrue(self::driver()->supports($spec));
    }

    public function testDoesNotSupportSpecsResolvedToPcntl(): void
    {
        $spec = self::workerSpec(
            overrides: [
                'driver' => 'pcntl',
            ],
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('pcntl', $spec->driver());
        self::assertFalse(self::driver()->supports($spec));
    }

    public function testSupportsAutoDriverAfterFallbackResolutionToProc(): void
    {
        $spec = self::workerSpec(
            overrides: [
                'driver' => 'auto',
            ],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        self::assertSame('auto', $spec->driverRequested());
        self::assertSame('proc', $spec->driver());
        self::assertTrue(self::driver()->supports($spec));
    }

    public function testConstructorRejectsInvalidWorkerCommandVectorsDeterministically(): void
    {
        foreach (
            [
                [],
                ['php' => 'bin/console'],
                [''],
                ["bin/console\nworker"],
                ["bin/console\0worker"],
                [123],
                ['bin/console', ''],
            ] as $workerCommand
        ) {
            self::assertInvalidWorkerCommand($workerCommand);
        }
    }

    public function testSupportPathHasNoProcessPayloadPackageOrOutputSideEffects(): void
    {
        $driver = self::driver();

        $procSpec = self::workerSpec([
            'driver' => 'proc',
        ]);

        $pcntlSpec = self::workerSpec(
            overrides: [
                'driver' => 'pcntl',
            ],
            pcntlForkAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );

        \ob_start();

        try {
            self::assertSame('proc', $driver->name());
            self::assertTrue($driver->supports($procSpec));
            self::assertFalse($driver->supports($pcntlSpec));

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        $supportPathSource = self::methodSource(ProcWorkerManagerDriver::class, '__construct')
            . "\n"
            . self::methodSource(ProcWorkerManagerDriver::class, 'name')
            . "\n"
            . self::methodSource(ProcWorkerManagerDriver::class, 'supports')
            . "\n"
            . self::methodSource(ProcWorkerManagerDriver::class, 'normalizeSkeletonRoot')
            . "\n"
            . self::methodSource(ProcWorkerManagerDriver::class, 'normalizeWorkerCommand')
            . "\n"
            . self::methodSource(ProcWorkerManagerDriver::class, 'normalizeRelativePath');

        self::assertStringNotContainsString('proc_open(', $supportPathSource);
        self::assertStringNotContainsString('pcntl_', $supportPathSource);
        self::assertStringNotContainsString('ApplicationWorker', $supportPathSource);
        self::assertStringNotContainsString('KernelRuntimeInterface', $supportPathSource);
        self::assertStringNotContainsString('TaskFactoryInternalInterface', $supportPathSource);
        self::assertStringNotContainsString('Platform\\Cli', $supportPathSource);
        self::assertStringNotContainsString('Platform\\Http', $supportPathSource);
        self::assertStringNotContainsString('Psr\\Http', $supportPathSource);

        self::assertStringNotContainsString('echo ', $supportPathSource);
        self::assertStringNotContainsString('print ', $supportPathSource);
        self::assertStringNotContainsString('var_dump(', $supportPathSource);
        self::assertStringNotContainsString('print_r(', $supportPathSource);
        self::assertStringNotContainsString('fwrite(STDOUT', $supportPathSource);
        self::assertStringNotContainsString('fwrite(STDERR', $supportPathSource);
        self::assertStringNotContainsString('error_log(', $supportPathSource);
    }

    private static function driver(): ProcWorkerManagerDriver
    {
        return new ProcWorkerManagerDriver(
            skeletonRoot: __DIR__,
            stateStore: new WorkerStateStore(
                encoder: new StableJsonEncoder(),
                decoder: new StableJsonDecoder(),
            ),
            controlChannel: new WorkerSocketServer(),
            workerCommand: [
                'php',
                'bin/coretsia-worker',
            ],
            configArtifactPath: 'var/cache/worker/config.php',
            containerArtifactPath: 'var/cache/worker/container.php',
        );
    }

    /**
     * @param array<mixed> $workerCommand
     */
    private static function assertInvalidWorkerCommand(array $workerCommand): void
    {
        try {
            new ProcWorkerManagerDriver(
                skeletonRoot: __DIR__,
                stateStore: new WorkerStateStore(
                    encoder: new StableJsonEncoder(),
                    decoder: new StableJsonDecoder(),
                ),
                controlChannel: new WorkerSocketServer(),
                workerCommand: $workerCommand,
                configArtifactPath: 'var/cache/worker/config.php',
                containerArtifactPath: 'var/cache/worker/container.php',
            );

            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('proc-worker-command-invalid', $exception->getMessage());
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
                'workers' => 2,
                'max_requests' => 100,
                'task_type' => 'queue',
                'socket_path' => 'var/worker/control.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
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
