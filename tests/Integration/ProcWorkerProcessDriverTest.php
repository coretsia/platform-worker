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

namespace Coretsia\Platform\Worker\Tests\Integration;

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel;
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;
use Coretsia\Platform\Worker\Process\Driver\ProcWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianClient;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianTransport;
use Coretsia\Platform\Worker\Process\WorkerChildCommandBuilder;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class ProcWorkerProcessDriverTest extends PackageTestCase
{
    public function testProcessHostAdapterSpawnsReadyChildWithoutSupervisorResourceInheritance(): void
    {
        if (!WorkerProcessCapabilities::procDriverAvailable()) {
            self::assertFalse(
                WorkerProcessCapabilities::procDriverAvailable(),
            );

            return;
        }

        $root = $this->temporaryDirectory('proc-driver');
        $hostRoot = \is_file(self::frameworkRoot() . '/vendor/autoload.php')
            ? self::frameworkRoot()
            : self::packageRoot();
        $guardian = new WorkerProcessGuardianClient(
            command: [\PHP_BINARY, self::packageRoot() . '/bin/coretsia-worker-guardian'],
            bootstrapWorkingDirectory: $hostRoot,
            skeletonRoot: $root,
            protocol: new WorkerProcessGuardianProtocol(new StableJsonEncoder(), new StableJsonDecoder()),
            transport: new WorkerProcessGuardianTransport(),
        );
        $readiness = new WorkerChildReadinessChannel();
        $driver = new ProcWorkerProcessDriver(
            skeletonRoot: $root,
            workerCommand: [\PHP_BINARY, self::packageRoot() . '/tests/Fixtures/proc-worker-fixture.php'],
            commandBuilder: new WorkerChildCommandBuilder('var/cache/coretsia'),
            readinessChannel: $readiness,
            guardian: $guardian,
            driverAvailable: true,
        );
        $spec = WorkerSpecFactory::create([
            'workers' => 1,
            'driver' => 'proc',
            'start_timeout_ms' => 2000,
        ]);

        $guardian->claim($spec, 'proc');
        $child = $driver->spawn($spec, 0);
        $readiness->await($child, 1000);

        $exit = null;
        self::waitUntil(function () use ($driver, $child, &$exit): bool {
            $exit = $driver->pollExit($child, 1_000);
            return $exit !== null;
        });

        self::assertNotNull($exit);
        self::assertTrue($exit->expected());
        $driver->close($child, 1_000);
        $guardian->release(1_000);
    }
}
