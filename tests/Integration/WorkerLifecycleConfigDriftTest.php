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

use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocator;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocatorStore;
use Coretsia\Platform\Worker\Tests\Support\SupervisorIntegrationTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerLifecycleConfigDriftTest extends SupervisorIntegrationTestCase
{
    public function testTcpPortDriftStillUsesTheActiveSupervisorEndpoint(): void
    {
        $activePort = self::unusedTcpPort();
        $currentPort = self::differentUnusedTcpPort($activePort);
        ['harness' => $harness] = $this->newHarness(
            workerOverride: [
                'control' => ['transport' => 'tcp'],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => $activePort,
                ],
            ],
        );

        $start = $harness->startAndWaitForSummary();
        $harness->replaceWorkerConfig(
            WorkerSpecFactory::merge(
                $harness->workerConfig(),
                [
                    'tcp' => [
                        'host' => '127.0.0.1',
                        'port' => $currentPort,
                    ],
                ],
            ),
        );

        $status = self::onlyPayload($harness->invoke('status'));
        self::assertSame($start['pid'], $status['pid']);
        self::assertSame($start['endpoint_hash'], $status['endpoint_hash']);

        $health = self::onlyPayload($harness->invoke('health'));
        self::assertSame($start['pid'], $health['pid']);
        self::assertSame($start['endpoint_hash'], $health['endpoint_hash']);

        $stop = self::onlyPayload($harness->invoke('stop'));
        self::assertSame($start['pid'], $stop['pid']);
        self::assertSame($start['endpoint_hash'], $stop['endpoint_hash']);

        $finished = $harness->finishStart();
        self::assertSame(0, $finished['exit_code'], $finished['stderr']);
        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }

    public function testOppositeCurrentTransportStillUsesTheActiveSupervisorTransport(): void
    {
        ['harness' => $harness] = $this->newHarness();

        $start = $harness->startAndWaitForSummary();
        $activeTransport = $start['control_transport'] ?? null;

        if ($activeTransport !== 'unix' && $activeTransport !== 'tcp') {
            self::fail('Active supervisor transport is invalid.');
        }

        $currentTransportOverride = $activeTransport === 'unix'
            ? [
                'socket_path' => 'var/tmp/current-config-worker.sock',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => self::unusedTcpPort(),
                ],
            ]
            : [
                'socket_path' => 'var/tmp/current-config-worker.sock',
                'control' => [
                    'transport' => 'unix',
                ],
            ];

        $harness->replaceWorkerConfig(
            WorkerSpecFactory::merge(
                $harness->workerConfig(),
                $currentTransportOverride,
            ),
        );

        $status = self::onlyPayload(
            $harness->invoke('status'),
        );

        self::assertSame(
            $activeTransport,
            $status['control_transport'],
        );

        self::assertSame(
            $start['endpoint_hash'],
            $status['endpoint_hash'],
        );

        $health = self::onlyPayload(
            $harness->invoke('health'),
        );

        self::assertSame(
            $activeTransport,
            $health['control_transport'],
        );

        self::assertSame(
            $start['endpoint_hash'],
            $health['endpoint_hash'],
        );

        $stop = self::onlyPayload(
            $harness->invoke('stop'),
        );

        self::assertSame(
            $activeTransport,
            $stop['control_transport'],
        );

        self::assertSame(
            $start['endpoint_hash'],
            $stop['endpoint_hash'],
        );

        $finished = $harness->finishStart();

        self::assertSame(
            0,
            $finished['exit_code'],
            $finished['stderr'],
        );

        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }

    public function testStructurallyInvalidCurrentWorkerConfigCannotBlockStop(): void
    {
        ['harness' => $harness] = $this->newHarness();
        $start = $harness->startAndWaitForSummary();

        $harness->replaceWorkerConfig([
            'workers' => 0,
            'driver' => 'invalid',
        ]);

        $stop = self::onlyPayload($harness->invoke('stop'));
        self::assertSame($start['pid'], $stop['pid']);
        self::assertSame($start['endpoint_hash'], $stop['endpoint_hash']);

        $finished = $harness->finishStart();
        self::assertSame(0, $finished['exit_code'], $finished['stderr']);
        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }

    public function testStopTimeoutDriftUsesTheActiveLocatorDeadlines(): void
    {
        ['harness' => $harness] = $this->newHarness(
            workerOverride: [
                'stop_timeout_ms' => 2_500,
                'force_kill_timeout_ms' => 500,
            ],
            behavior: [
                'ignore_stop_slots' => [0, 1],
            ],
        );
        $start = $harness->startAndWaitForSummary();

        $harness->replaceWorkerConfig(
            WorkerSpecFactory::merge(
                $harness->workerConfig(),
                [
                    'stop_timeout_ms' => 50,
                    'force_kill_timeout_ms' => 50,
                ],
            ),
        );

        $startedAt = \hrtime(true);
        $stop = self::onlyPayload($harness->invoke('stop'));
        $elapsedMs = (int)\floor((\hrtime(true) - $startedAt) / 1_000_000);

        self::assertSame($start['pid'], $stop['pid']);
        self::assertSame($start['endpoint_hash'], $stop['endpoint_hash']);
        self::assertGreaterThanOrEqual(
            2_300,
            $elapsedMs,
            'Stop must survive beyond the current-config request deadline and reach the active terminate phase.',
        );

        $finished = $harness->finishStart();
        self::assertSame(0, $finished['exit_code'], $finished['stderr']);
        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }

    public function testStaleLocatorWithFreeLockIsIgnoredAndReplacedOnStart(): void
    {
        ['root' => $root, 'harness' => $harness] = $this->newHarness();
        $store = new WorkerLifecycleLocatorStore(
            skeletonRoot: $root,
        );
        $activeConfig = $harness->workerConfig();
        $activeTcp = $activeConfig['tcp'] ?? null;
        $activePort = \is_array($activeTcp)
            ? ($activeTcp['port'] ?? null)
            : null;
        $stalePort = \is_int($activePort)
            ? self::differentUnusedTcpPort($activePort)
            : self::unusedTcpPort();
        $stale = WorkerLifecycleLocator::fromPoolSpec(
            WorkerSpecFactory::create([
                'driver' => 'proc',
                'control' => ['transport' => 'tcp'],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => $stalePort,
                ],
            ]),
            WorkerControlCredential::fromEncoded(
                \str_repeat('a', 64),
            ),
        );
        $store->write($stale);

        $error = self::onlyError($harness->invoke('status'));
        self::assertSame('CORETSIA_WORKER_NOT_RUNNING', $error['code'] ?? null);
        self::assertFileExists($harness->locatorPath());

        $start = $harness->startAndWaitForSummary();
        $fresh = $store->read();

        self::assertNotNull($fresh);
        self::assertNotSame($stale->endpointHash(), $fresh->endpointHash());
        self::assertSame($start['endpoint_hash'], $fresh->endpointHash());

        self::onlyPayload($harness->invoke('stop'));
        $finished = $harness->finishStart();
        self::assertSame(0, $finished['exit_code'], $finished['stderr']);
        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }

    private static function differentUnusedTcpPort(int $port): int
    {
        do {
            $candidate = self::unusedTcpPort();
        } while ($candidate === $port);

        return $candidate;
    }
}
