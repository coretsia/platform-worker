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

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerControlProtocolSafetyContractTest extends PackageTestCase
{
    public function testProtocolIsBoundedStableJsonAndPayloadFree(): void
    {
        $source = self::source('src/Communication/WorkerControlProtocol.php');
        $request = self::source('src/Communication/WorkerControlRequest.php');
        $response = self::source('src/Communication/WorkerControlResponse.php');

        self::assertStringContainsString(
            'StableJsonEncoder',
            $source,
        );
        self::assertStringContainsString(
            'StableJsonDecoder',
            $source,
        );
        self::assertStringContainsString(
            'MAX_FRAME_BYTES = 4096',
            $source,
        );
        self::assertStringNotContainsString('serialize(', $source);
        self::assertStringNotContainsString('unserialize(', $source);
        self::assertStringNotContainsString("case START", $request);
        self::assertStringContainsString("'credential' =>", $request);
        self::assertStringNotContainsString("'credential' =>", $response);
        self::assertStringContainsString('VERSION = 1', $request);

        foreach (
            [
                'payload',
                'headers',
                'authorization',
                'socket_path',
                'tcp_host',
                'tcp_port',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                "'" . $forbidden . "' =>",
                $request . $response,
            );
        }
    }
}
