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

use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerStateStoreStateFactoryTest extends PackageTestCase
{
    public function testCreatesRedactedSchemaVersionOneState(): void
    {
        $root = $this->temporaryDirectory('worker-state-factory');
        $store = new WorkerStateStore($root);
        $spec = WorkerSpecFactory::create();

        $state = $store->createState(
            spec: $spec,
            pid: 321,
            status: WorkerPoolStatus::STARTING,
            readyWorkerCount: 0,
        );

        self::assertSame(1, $state->version());
        self::assertSame(
            \hash('sha256', $spec->endpointIdentifier()),
            $state->endpointHash(),
        );
        self::assertArrayNotHasKey('socket_path', $state->toArray());
        self::assertArrayNotHasKey('tcp_host', $state->toArray());
        self::assertArrayNotHasKey('tcp_port', $state->toArray());
    }

    public function testMissingSnapshotReturnsNullAndDoesNotAssertLiveness(): void
    {
        $root = $this->temporaryDirectory('worker-state-missing');
        $store = new WorkerStateStore($root);

        self::assertNull(
            $store->readSnapshot(WorkerSpecFactory::create()),
        );
    }
}
