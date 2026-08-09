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
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;
use Coretsia\Platform\Worker\Process\Driver\PcntlWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianClient;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianTransport;
use Coretsia\Platform\Worker\Process\WorkerChildCommandBuilder;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class PcntlWorkerProcessDriverTest extends PackageTestCase
{
    public function testCapabilityAndForkExecBehaviorMatchCurrentPlatform(): void
    {
        $root = $this->temporaryDirectory('pcntl-driver');
        $readiness = new WorkerChildReadinessChannel();
        $driver = self::driver(
            root: $root,
            artifactRoot: 'var/cache/coretsia',
            readiness: $readiness,
        );
        $spec = WorkerSpecFactory::create([
            'workers' => 1,
            'driver' => 'pcntl',
        ]);

        if (!$driver->supports($spec)) {
            self::assertFalse(WorkerProcessCapabilities::pcntlDriverAvailable());
            return;
        }

        $guardian = self::guardian($root);
        $guardian->claim($spec, 'pcntl');
        $driver = self::driver($root, 'var/cache/coretsia', $readiness, $guardian);
        $child = $driver->spawn($spec, 0);
        $readiness->await($child, 2000);

        $exit = null;
        self::waitUntil(function () use ($driver, $child, &$exit): bool {
            $exit = $driver->pollExit($child, 1_000);

            return $exit !== null;
        });

        self::assertNotNull($exit);
        self::assertTrue($exit->expected());
        self::assertSame(0, $exit->exitCode());

        $markerPath = $root . '/var/cache/coretsia/pcntl-exec-marker.json';
        self::assertFileExists($markerPath);
        $marker = \json_decode(
            (string)\file_get_contents($markerPath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($marker);
        self::assertTrue($marker['fresh_process_image'] ?? false);
        self::assertSame($child->pid(), $marker['pid'] ?? null);

        $driver->close($child, 1_000);
        $guardian->release(1_000);
    }

    public function testExecFailureBeforeReadinessIsUnexpectedNonZeroExit(): void
    {
        $root = $this->temporaryDirectory('pcntl-driver-exec-failure');
        $readiness = new WorkerChildReadinessChannel();
        $driver = self::driver(
            root: $root,
            artifactRoot: 'var/fail-before-readiness',
            readiness: $readiness,
        );
        $spec = WorkerSpecFactory::create([
            'workers' => 1,
            'driver' => 'pcntl',
        ]);

        if (!$driver->supports($spec)) {
            self::assertFalse(WorkerProcessCapabilities::pcntlDriverAvailable());
            return;
        }

        $guardian = self::guardian($root);
        $guardian->claim($spec, 'pcntl');
        $driver = self::driver($root, 'var/fail-before-readiness', $readiness, $guardian);
        $child = $driver->spawn($spec, 0);

        try {
            $readiness->await($child, 1000);
            self::fail('A child that exits before writing readiness must fail.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(
                WorkerStartFailedException::REASON_READINESS_INVALID,
                $exception->reason(),
            );
        }

        $exit = null;
        self::waitUntil(
            function () use ($driver, $child, &$exit): bool {
                $exit = $driver->pollExit($child, 1_000);

                return $exit !== null;
            },
            failureMessage: 'PCNTL exec child was not reaped.',
        );

        self::assertNotNull($exit);
        self::assertSame(1, $exit->exitCode());
        self::assertFalse($exit->expected());

        $driver->close($child, 1_000);
        $guardian->release(1_000);
    }

    private static function driver(
        string $root,
        string $artifactRoot,
        WorkerChildReadinessChannel $readiness,
        ?WorkerProcessGuardianClient $guardian = null,
    ): PcntlWorkerProcessDriver {
        $guardian ??= self::guardian($root);

        return new PcntlWorkerProcessDriver(
            skeletonRoot: $root,
            workerCommand: [
                \PHP_BINARY,
                self::packageRoot() . '/tests/Fixtures/pcntl-exec-worker-fixture.php',
            ],
            commandBuilder: new WorkerChildCommandBuilder($artifactRoot),
            readinessChannel: $readiness,
            guardian: $guardian,
            driverAvailable: WorkerProcessCapabilities::pcntlDriverAvailable(),
            platformFamily: \PHP_OS_FAMILY,
        );
    }

    private static function guardian(string $root): WorkerProcessGuardianClient
    {
        $bootstrapRoot = \is_file(self::frameworkRoot() . '/vendor/autoload.php')
            ? self::frameworkRoot()
            : self::packageRoot();

        return new WorkerProcessGuardianClient(
            command: [\PHP_BINARY, self::packageRoot() . '/bin/coretsia-worker-guardian'],
            bootstrapWorkingDirectory: $bootstrapRoot,
            skeletonRoot: $root,
            protocol: new WorkerProcessGuardianProtocol(new StableJsonEncoder(), new StableJsonDecoder()),
            transport: new WorkerProcessGuardianTransport(),
        );
    }
}
