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
use PHPUnit\Framework\TestCase;

final class WorkerPoolStateTest extends TestCase
{
    public function testConstructsValidWorkerPoolState(): void
    {
        $state = self::workerPoolState();

        self::assertSame(12345, $state->pid());
        self::assertSame(4, $state->workerCount());
        self::assertSame('auto', $state->driverRequested());
        self::assertSame('proc', $state->driver());
        self::assertSame('auto', $state->controlTransportRequested());
        self::assertSame('tcp', $state->controlTransport());
        self::assertSame(self::validEndpointHash(), $state->endpointHash());
    }

    public function testVersionAlwaysReturnsOne(): void
    {
        self::assertSame(1, self::workerPoolState()->version());

        self::assertSame(
            1,
            self::workerPoolState(
                pid: 999,
                workerCount: 8,
                driverRequested: 'pcntl',
                driver: 'pcntl',
                controlTransportRequested: 'unix',
                controlTransport: 'unix',
                endpointHash: \str_repeat('f', 64),
            )->version(),
        );
    }

    public function testToArrayReturnsExactStableKeyOrderAndValues(): void
    {
        $endpointHash = self::validEndpointHash();

        $state = self::workerPoolState(
            pid: 12345,
            workerCount: 4,
            driverRequested: 'auto',
            driver: 'proc',
            controlTransportRequested: 'auto',
            controlTransport: 'tcp',
            endpointHash: $endpointHash,
        );

        $array = $state->toArray();

        self::assertSame(
            [
                'version',
                'pid',
                'worker_count',
                'driver_requested',
                'driver',
                'control_transport_requested',
                'control_transport',
                'endpoint_hash',
            ],
            \array_keys($array),
        );

        self::assertSame(
            [
                'version' => 1,
                'pid' => 12345,
                'worker_count' => 4,
                'driver_requested' => 'auto',
                'driver' => 'proc',
                'control_transport_requested' => 'auto',
                'control_transport' => 'tcp',
                'endpoint_hash' => $endpointHash,
            ],
            $array,
        );
    }

    public function testRejectsNonPositivePid(): void
    {
        self::assertInvalidState(
            operation: static fn (): WorkerPoolState => self::workerPoolState(pid: 0),
            expectedMessage: 'worker-pool-state-pid-invalid',
        );

        self::assertInvalidState(
            operation: static fn (): WorkerPoolState => self::workerPoolState(pid: -1),
            expectedMessage: 'worker-pool-state-pid-invalid',
        );
    }

    public function testRejectsNonPositiveWorkerCount(): void
    {
        self::assertInvalidState(
            operation: static fn (): WorkerPoolState => self::workerPoolState(workerCount: 0),
            expectedMessage: 'worker-pool-state-worker-count-invalid',
        );

        self::assertInvalidState(
            operation: static fn (): WorkerPoolState => self::workerPoolState(workerCount: -1),
            expectedMessage: 'worker-pool-state-worker-count-invalid',
        );
    }

    public function testRejectsRequestedDriverOutsideAllowedSet(): void
    {
        foreach (['', 'fork', 'worker', 'PCNTL'] as $driverRequested) {
            self::assertInvalidState(
                operation: static fn (): WorkerPoolState => self::workerPoolState(
                    driverRequested: $driverRequested,
                ),
                expectedMessage: 'worker-pool-state-driver-requested-invalid',
            );
        }
    }

    public function testRejectsResolvedDriverOutsideAllowedSet(): void
    {
        foreach (['', 'auto', 'fork', 'worker', 'PCNTL'] as $driver) {
            self::assertInvalidState(
                operation: static fn (): WorkerPoolState => self::workerPoolState(
                    driver: $driver,
                ),
                expectedMessage: 'worker-pool-state-driver-invalid',
            );
        }
    }

    public function testRejectsRequestedControlTransportOutsideAllowedSet(): void
    {
        foreach (['', 'pipe', 'udp', 'UNIX'] as $controlTransportRequested) {
            self::assertInvalidState(
                operation: static fn (): WorkerPoolState => self::workerPoolState(
                    controlTransportRequested: $controlTransportRequested,
                ),
                expectedMessage: 'worker-pool-state-control-transport-requested-invalid',
            );
        }
    }

    public function testRejectsResolvedControlTransportOutsideAllowedSet(): void
    {
        foreach (['', 'auto', 'pipe', 'udp', 'UNIX'] as $controlTransport) {
            self::assertInvalidState(
                operation: static fn (): WorkerPoolState => self::workerPoolState(
                    controlTransport: $controlTransport,
                ),
                expectedMessage: 'worker-pool-state-control-transport-invalid',
            );
        }
    }

    public function testRejectsEndpointHashWithUppercaseHexCharacters(): void
    {
        self::assertInvalidState(
            operation: static fn (): WorkerPoolState => self::workerPoolState(
                endpointHash: \str_repeat('A', 64),
            ),
            expectedMessage: 'worker-pool-state-endpoint-hash-invalid',
        );

        self::assertInvalidState(
            operation: static fn (): WorkerPoolState => self::workerPoolState(
                endpointHash: \str_repeat('a', 63) . 'F',
            ),
            expectedMessage: 'worker-pool-state-endpoint-hash-invalid',
        );
    }

    public function testRejectsEndpointHashThatIsNotExactlySixtyFourLowercaseHexCharacters(): void
    {
        foreach (
            [
                '',
                \str_repeat('a', 63),
                \str_repeat('a', 65),
                \str_repeat('g', 64),
                \str_repeat('0', 63) . '-',
                'not-a-sha256-endpoint-hash',
            ] as $endpointHash
        ) {
            self::assertInvalidState(
                operation: static fn (): WorkerPoolState => self::workerPoolState(
                    endpointHash: $endpointHash,
                ),
                expectedMessage: 'worker-pool-state-endpoint-hash-invalid',
            );
        }
    }

    public function testRuntimeImplementationDoesNotUseFilesystemStateWriteFilesOrEmitStdoutStderr(): void
    {
        \ob_start();

        try {
            self::workerPoolState()->toArray();

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        $file = new \ReflectionClass(WorkerPoolState::class)->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

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

    private static function workerPoolState(
        int $pid = 12345,
        int $workerCount = 4,
        string $driverRequested = 'auto',
        string $driver = 'proc',
        string $controlTransportRequested = 'auto',
        string $controlTransport = 'tcp',
        ?string $endpointHash = null,
    ): WorkerPoolState {
        return new WorkerPoolState(
            pid: $pid,
            workerCount: $workerCount,
            driverRequested: $driverRequested,
            driver: $driver,
            controlTransportRequested: $controlTransportRequested,
            controlTransport: $controlTransport,
            endpointHash: $endpointHash ?? self::validEndpointHash(),
        );
    }

    private static function validEndpointHash(): string
    {
        return \hash('sha256', 'coretsia-worker-endpoint');
    }

    /**
     * @param callable(): mixed $operation
     */
    private static function assertInvalidState(
        callable $operation,
        string $expectedMessage,
    ): void {
        try {
            $operation();

            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }
}
