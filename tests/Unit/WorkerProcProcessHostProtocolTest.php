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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostProtocol;
use PHPUnit\Framework\TestCase;

final class WorkerProcProcessHostProtocolTest extends TestCase
{
    public function testSpawnHandoffKeepsVersionOneAndExactSchema(): void
    {
        $protocol = self::protocol();
        $token = \str_repeat('a', 64);
        $request = $protocol->encodeRequest(
            requestId: 7,
            operation: WorkerProcProcessHostProtocol::OPERATION_SPAWN,
            payload: [
                'command' => ['php', 'child.php'],
                'handoff_port' => 12_345,
                'handoff_token' => $token,
                'working_directory' => 'runtime',
            ],
        );

        self::assertSame(
            '{"operation":"spawn","payload":{"command":["php","child.php"],"handoff_port":12345,"handoff_token":"'
            . $token
            . '","working_directory":"runtime"},"request_id":7,"version":1}'
            . "\n",
            $request,
        );

        $response = $protocol->encodeOkResponse(
            requestId: 7,
            payload: [
                'child_id' => 'child-1',
                'pid' => 123,
            ],
        );
        $handoff = $protocol->encodeHandoff(
            requestId: 7,
            handoffToken: $token,
            responseFrame: $response,
        );

        self::assertSame(
            [
                'payload' => [
                    'child_id' => 'child-1',
                    'pid' => 123,
                ],
                'request_id' => 7,
                'status' => WorkerProcProcessHostProtocol::STATUS_OK,
                'version' => WorkerProcProcessHostProtocol::VERSION,
            ],
            $protocol->decodeHandoff(
                frame: $handoff,
                expectedRequestId: 7,
                expectedToken: $token,
            ),
        );
    }

    public function testHandoffRejectsWrongTokenWithoutVersionChange(): void
    {
        $protocol = self::protocol();
        $response = $protocol->encodeErrorResponse(
            requestId: 3,
            reason: WorkerProcProcessHostProtocol::ERROR_CHILD_START_FAILED,
        );
        $handoff = $protocol->encodeHandoff(
            requestId: 3,
            handoffToken: \str_repeat('b', 64),
            responseFrame: $response,
        );

        $this->expectException(
            WorkerLifecycleFailedException::class,
        );

        $protocol->decodeHandoff(
            frame: $handoff,
            expectedRequestId: 3,
            expectedToken: \str_repeat('c', 64),
        );
    }

    private static function protocol(): WorkerProcProcessHostProtocol
    {
        return new WorkerProcProcessHostProtocol(
            new StableJsonEncoder(),
            new StableJsonDecoder(),
        );
    }
}
