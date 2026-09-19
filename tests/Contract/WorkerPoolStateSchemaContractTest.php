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

use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use PHPUnit\Framework\TestCase;

final class WorkerPoolStateSchemaContractTest extends TestCase
{
    public function testStateSchemaIsExactRedactedAndRemainsVersionOne(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 2) . '/src/Runtime/WorkerPoolState.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'private const int VERSION = 1;',
            $source,
        );

        $reflection = new \ReflectionClass(WorkerPoolState::class);

        foreach (
            [
                'version',
                'pid',
                'status',
                'workerCount',
                'readyWorkerCount',
                'driverRequested',
                'driver',
                'controlTransportRequested',
                'controlTransport',
                'endpointHash',
                'withStatus',
                'toArray',
                'fromArray',
            ] as $method
        ) {
            self::assertTrue($reflection->hasMethod($method));
        }

        foreach (
            [
                'socket_path',
                'tcp_host',
                'tcp_port',
                'payload',
                'headers',
                'token',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                "'" . $forbidden . "' =>",
                $source,
            );
        }
    }
}
