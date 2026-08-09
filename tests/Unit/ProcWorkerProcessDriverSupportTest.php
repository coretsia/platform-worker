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

use Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel;
use Coretsia\Platform\Worker\Process\Driver\ProcWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\WorkerChildCommandBuilder;
use Coretsia\Platform\Worker\Tests\Fake\FakeWorkerProcessGuardian;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class ProcWorkerProcessDriverSupportTest extends PackageTestCase
{
    public function testSupportIsNarrowedToResolvedProcSpecAndGuardianCapability(): void
    {
        $root = $this->temporaryDirectory('worker-proc-support');
        $driver = self::driver($root, true);

        self::assertSame('proc', $driver->name());
        self::assertTrue($driver->supports(WorkerSpecFactory::create(['driver' => 'proc'])));
        self::assertFalse($driver->supports(WorkerSpecFactory::create(['driver' => 'pcntl'])));
        self::assertFalse(
            self::driver($root, false)->supports(
                WorkerSpecFactory::create(['driver' => 'proc']),
            )
        );
    }

    public function testConstructorRejectsInvalidCommandParts(): void
    {
        $root = $this->temporaryDirectory('worker-proc-invalid');
        $this->expectException(\InvalidArgumentException::class);

        new ProcWorkerProcessDriver(
            skeletonRoot: $root,
            workerCommand: ["php\n"],
            commandBuilder: new WorkerChildCommandBuilder('var/cache/worker'),
            readinessChannel: new WorkerChildReadinessChannel(),
            guardian: new FakeWorkerProcessGuardian(),
            driverAvailable: true,
        );
    }

    private static function driver(string $root, bool $driverAvailable): ProcWorkerProcessDriver
    {
        return new ProcWorkerProcessDriver(
            skeletonRoot: $root,
            workerCommand: [\PHP_BINARY, self::packageRoot() . '/tests/Fixtures/proc-worker-fixture.php'],
            commandBuilder: new WorkerChildCommandBuilder('var/cache/worker'),
            readinessChannel: new WorkerChildReadinessChannel(),
            guardian: new FakeWorkerProcessGuardian(),
            driverAvailable: $driverAvailable,
        );
    }
}
