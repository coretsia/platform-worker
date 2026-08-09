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
use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerStateJsonSchemaContractTest extends PackageTestCase
{
    public function testWrittenJsonIsStableExactAndPayloadFree(): void
    {
        $root = $this->temporaryDirectory('worker-state-json');
        $encoder = new StableJsonEncoder();
        $store = new WorkerStateStore(
            $root,
            $encoder,
            new StableJsonDecoder(),
        );
        $spec = WorkerSpecFactory::create();

        $state = $store->createState(
            $spec,
            123,
            WorkerPoolStatus::RUNNING,
            4,
        );

        $store->write($spec, $state);

        $bytes = \file_get_contents(
            $root . '/' . $spec->statePath(),
        );

        self::assertSame(
            $encoder->encodeMap($state->toArray()),
            $bytes,
        );
        self::assertStringNotContainsString(
            $spec->socketPath(),
            (string)$bytes,
        );
        self::assertStringNotContainsString(
            $spec->tcpHost(),
            (string)$bytes,
        );
    }
}
