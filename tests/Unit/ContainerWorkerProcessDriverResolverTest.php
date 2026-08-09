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
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface;
use Coretsia\Platform\Worker\Process\ContainerWorkerProcessDriverResolver;
use Coretsia\Platform\Worker\Process\Driver\PcntlWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\Driver\ProcWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\WorkerChildProcess;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ContainerWorkerProcessDriverResolverTest extends TestCase
{
    public function testPcntlSpecResolvesOnlyPcntlService(): void
    {
        $driver = new ResolverRecordingDriver('pcntl', true);
        $container = new ResolverRecordingContainer([
            PcntlWorkerProcessDriver::class => $driver,
        ]);

        $resolved = new ContainerWorkerProcessDriverResolver($container)->resolve(
            WorkerSpecFactory::create(['driver' => 'pcntl']),
        );

        self::assertSame($driver, $resolved);
        self::assertSame([PcntlWorkerProcessDriver::class], $container->requested);
    }

    public function testProcSpecResolvesOnlyProcService(): void
    {
        $driver = new ResolverRecordingDriver('proc', true);
        $container = new ResolverRecordingContainer([
            ProcWorkerProcessDriver::class => $driver,
        ]);

        $resolved = new ContainerWorkerProcessDriverResolver($container)->resolve(
            WorkerSpecFactory::create(['driver' => 'proc']),
        );

        self::assertSame($driver, $resolved);
        self::assertSame([ProcWorkerProcessDriver::class], $container->requested);
    }

    public function testWrongServiceTypeFailsDeterministically(): void
    {
        $container = new ResolverRecordingContainer([
            ProcWorkerProcessDriver::class => new \stdClass(),
        ]);

        $this->expectException(WorkerStartFailedException::class);

        new ContainerWorkerProcessDriverResolver($container)->resolve(
            WorkerSpecFactory::create(['driver' => 'proc']),
        );
    }

    public function testDriverNameMismatchFailsDeterministically(): void
    {
        $container = new ResolverRecordingContainer([
            ProcWorkerProcessDriver::class => new ResolverRecordingDriver('pcntl', true),
        ]);

        $this->expectException(WorkerStartFailedException::class);

        new ContainerWorkerProcessDriverResolver($container)->resolve(
            WorkerSpecFactory::create(['driver' => 'proc']),
        );
    }

    public function testUnsupportedSelectedDriverFailsDeterministically(): void
    {
        $container = new ResolverRecordingContainer([
            ProcWorkerProcessDriver::class => new ResolverRecordingDriver('proc', false),
        ]);

        try {
            new ContainerWorkerProcessDriverResolver($container)->resolve(
                WorkerSpecFactory::create(['driver' => 'proc']),
            );

            self::fail('Unsupported selected driver must fail.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(
                WorkerStartFailedException::REASON_CHILD_START_FAILED,
                $exception->reason(),
            );
        }
    }
}

final class ResolverRecordingContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string, mixed> $services */
    public function __construct(private readonly array $services)
    {
    }

    public function get(string $id): mixed
    {
        $this->requested[] = $id;

        if (!\array_key_exists($id, $this->services)) {
            throw new \RuntimeException('service-missing');
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->services);
    }
}

final readonly class ResolverRecordingDriver implements WorkerProcessDriverInterface
{
    public function __construct(
        private string $driverName,
        private bool $supported,
    ) {
    }

    public function name(): string
    {
        return $this->driverName;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        return $this->supported && $spec->driver() === $this->driverName;
    }

    public function spawn(WorkerPoolSpec $spec, int $workerIndex): WorkerChildProcess
    {
        throw new \LogicException('not-used');
    }

    public function pollExit(WorkerChildProcess $child, int $timeoutMs): ?WorkerProcessExit
    {
        throw new \LogicException('not-used');
    }

    public function terminate(WorkerChildProcess $child, int $timeoutMs): void
    {
        throw new \LogicException('not-used');
    }

    public function kill(WorkerChildProcess $child, int $timeoutMs): void
    {
        throw new \LogicException('not-used');
    }

    public function close(WorkerChildProcess $child, int $timeoutMs): void
    {
        throw new \LogicException('not-used');
    }
}
