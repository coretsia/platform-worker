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

use Coretsia\Platform\Worker\Runtime\WorkerLifecyclePaths;
use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Tests\Support\SupervisorIntegrationTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerCommandHarness;

final class WorkerLifecycleLockFilesystemTest extends SupervisorIntegrationTestCase
{
    public function testSecondStartFailsDeterministically(): void
    {
        ['root' => $root, 'harness' => $first] = $this->newHarness();
        $first->startAndWaitForSummary();

        $second = new WorkerCommandHarness(
            skeletonRoot: $root,
            workerOverride: $first->workerConfig(),
        );
        $second->start();
        $message = $second->waitForStartMessage();
        self::assertSame('error', $message['type'] ?? null);
        self::assertSame('CORETSIA_WORKER_ALREADY_RUNNING', $message['code'] ?? null);
        self::assertNotSame(0, $second->finishStart()['exit_code']);

        self::onlyPayload($first->invoke('stop'));
        self::assertSame(0, $first->finishStart()['exit_code']);
    }

    public function testCurrentConfigurationCannotChangeTheCanonicalLockAnchor(): void
    {
        ['root' => $root, 'harness' => $harness] = $this->newHarness();
        $lockPath = $harness->lockPath();

        $harness->replaceWorkerConfig([
            'workers' => 0,
            'driver' => 'invalid',
            'lock_path' => 'var/tmp/current-config.lock',
        ]);

        self::assertSame(
            WorkerLifecyclePaths::resolve(
                $root,
                WorkerLifecyclePaths::LOCK,
            ),
            $lockPath,
        );
        self::assertSame($lockPath, $harness->lockPath());
    }

    public function testStaleStateWithFreeLockIsNotRunning(): void
    {
        ['root' => $root, 'harness' => $harness] = $this->newHarness();
        \mkdir($root . '/var/tmp', 0777, true);
        \file_put_contents($harness->statePath(), '{"version":1}');

        $error = self::onlyError($harness->invoke('status'));
        self::assertSame('CORETSIA_WORKER_NOT_RUNNING', $error['code'] ?? null);
    }

    public function testHeldLockWithUnavailableControlEndpointIsCommunicationFailure(): void
    {
        ['harness' => $harness] = $this->newHarness();
        \mkdir(\dirname($harness->lockPath()), 0777, true);
        $handle = \fopen($harness->lockPath(), 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(\flock($handle, \LOCK_EX | \LOCK_NB));

        try {
            $error = self::onlyError($harness->invoke('status'));
            self::assertSame('CORETSIA_WORKER_COMMUNICATION_FAILED', $error['code'] ?? null);
        } finally {
            \flock($handle, \LOCK_UN);
            \fclose($handle);
        }
    }

    public function testSecondStartFailsWhileFirstSupervisorIsStillStarting(): void
    {
        ['root' => $root, 'harness' => $first] = $this->newHarness(
            workerOverride: [
                'start_timeout_ms' => 10_000,
            ],
            behavior: [
                /*
                 * Slot 1 cannot emit readiness until the test explicitly releases
                 * the gate. The first supervisor's guardian therefore holds the generation fence
                 * while its public state remains STARTING.
                 */
                'ready_gate_slots' => [1],
            ],
        );

        $first->start();

        self::waitForStateStatus(
            $first,
            WorkerPoolStatus::STARTING,
        );

        $second = new WorkerCommandHarness(
            skeletonRoot: $root,
            workerOverride: $first->workerConfig(),
            behavior: [
                /*
                 * Preserve the same fixture configuration when the second harness
                 * rewrites the shared test behavior file. The second supervisor
                 * must fail on the lifecycle lock before spawning any child.
                 */
                'ready_gate_slots' => [1],
            ],
        );

        try {
            $second->start();

            $message = $second->waitForStartMessage();

            self::assertSame(
                'error',
                $message['type'] ?? null,
            );

            self::assertSame(
                'CORETSIA_WORKER_ALREADY_RUNNING',
                $message['code'] ?? null,
            );

            $secondFinished = $second->finishStart();

            self::assertNotSame(
                0,
                $secondFinished['exit_code'],
                $secondFinished['stderr'],
            );

            /*
             * The rejected second start must not alter or terminate the first
             * supervisor. It must still expose its live STARTING state.
             */
            $status = self::onlyPayload(
                $first->invoke('status'),
            );

            self::assertSame(
                'starting',
                $status['status'],
            );

            self::assertSame(
                $first->startPid(),
                $status['pid'],
                'The original STARTING generation must remain fenced by its guardian.',
            );

            /*
             * Release readiness only after duplicate-start rejection has been
             * observed. The original supervisor must then complete startup.
             */
            $first->releaseReadiness();

            $startMessage = $first->waitForStartMessage();

            self::assertSame(
                'json',
                $startMessage['type'] ?? null,
            );

            self::assertSame(
                'running',
                $startMessage['payload']['status'] ?? null,
            );

            self::assertSame(
                $first->startPid(),
                $startMessage['payload']['pid'] ?? null,
            );

            $stop = self::onlyPayload(
                $first->invoke('stop'),
            );

            self::assertSame(
                'stopped',
                $stop['status'],
            );

            $firstFinished = $first->finishStart();

            self::assertSame(
                0,
                $firstFinished['exit_code'],
                $firstFinished['stderr'],
            );

            self::assertLoggedChildrenExited($first);
            self::assertRuntimeArtifactsCleaned($first);
        } finally {
            $second->close();
        }
    }
}
