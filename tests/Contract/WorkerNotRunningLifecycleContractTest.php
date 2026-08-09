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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Communication\WorkerControlClient;
use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use Coretsia\Platform\Worker\Communication\WorkerControlProtocol;
use Coretsia\Platform\Worker\Communication\WorkerControlTransport;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocator;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocatorStore;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLock;
use Coretsia\Platform\Worker\Runtime\WorkerLifecyclePaths;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\RecordingLogger;
use Coretsia\Platform\Worker\Tests\Support\RecordingMeter;
use Coretsia\Platform\Worker\Tests\Support\RecordingTracer;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerNotRunningLifecycleContractTest extends PackageTestCase
{
    public function testFreeLifecycleLockDefinesNotRunningEvenWithStaleStateAndLocator(): void
    {
        $root = $this->temporaryDirectory('worker-not-running');
        $statePath = $root . '/var/tmp/worker.state.json';
        @\mkdir(\dirname($statePath), 0777, true);
        \file_put_contents($statePath, "{\"stale\":true}\n");

        $encoder = new StableJsonEncoder();
        $decoder = new StableJsonDecoder();
        $locatorStore = new WorkerLifecycleLocatorStore(
            skeletonRoot: $root,
            encoder: $encoder,
            decoder: $decoder,
        );
        $locatorStore->write(
            WorkerLifecycleLocator::fromPoolSpec(
                WorkerSpecFactory::create(),
                WorkerControlCredential::fromEncoded(
                    \str_repeat('a', 64),
                ),
            ),
        );

        $client = new WorkerControlClient(
            transport: new WorkerControlTransport($root),
            protocol: new WorkerControlProtocol($encoder, $decoder),
            lifecycleLock: new WorkerLifecycleLock($root),
            locatorStore: $locatorStore,
            tracer: new RecordingTracer(),
            meter: new RecordingMeter(),
            logger: new RecordingLogger(),
            stopwatch: new Stopwatch(),
        );

        foreach (['status', 'health', 'stop'] as $method) {
            try {
                $client->{$method}();
                self::fail('Expected WorkerNotRunningException.');
            } catch (WorkerNotRunningException $exception) {
                self::assertSame(
                    WorkerNotRunningException::ERROR_CODE,
                    $exception->errorCode(),
                );
            }
        }

        self::assertFileExists(
            WorkerLifecyclePaths::resolve(
                $root,
                WorkerLifecyclePaths::LOCATOR,
            ),
            'A free lock must short-circuit before stale locator inspection.',
        );
    }
}
