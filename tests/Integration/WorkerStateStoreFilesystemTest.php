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

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerStateStoreFilesystemTest extends PackageTestCase
{
    public function testAtomicSnapshotRoundTripsAndDeleteRemovesStateAndTemp(): void
    {
        $root = $this->temporaryDirectory('worker-state-store');
        $spec = WorkerSpecFactory::create(['workers' => 2]);
        $store = new WorkerStateStore($root);
        $state = $store->createState($spec, 1234, WorkerPoolStatus::RUNNING, 2);

        $store->write($spec, $state);
        self::assertSame($state->toArray(), $store->readSnapshot($spec)?->toArray());
        self::assertStringEndsWith("\n", (string)\file_get_contents($root . '/' . $spec->statePath()));

        $store->delete($spec);
        self::assertNull($store->readSnapshot($spec));
        self::assertFileDoesNotExist($root . '/' . $spec->statePath() . '.tmp');
    }

    public function testMissingSnapshotIsNullAndInvalidSnapshotIsDeterministicFailure(): void
    {
        $root = $this->temporaryDirectory('worker-state-invalid');
        $spec = WorkerSpecFactory::create();
        $store = new WorkerStateStore($root);
        self::assertNull($store->readSnapshot($spec));

        \mkdir(\dirname($root . '/' . $spec->statePath()), 0777, true);
        \file_put_contents($root . '/' . $spec->statePath(), "{}\n");
        $this->expectException(WorkerLifecycleFailedException::class);
        $store->readSnapshot($spec);
    }
}
