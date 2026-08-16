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

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostProtocol;
use PHPUnit\Framework\TestCase;

final class WorkerProcProcessHostProtocolTest extends TestCase
{
    public function testSpawnHandoffKeepsVersionOneAndExactSchema(): void
    {
        $protocol = new WorkerProcProcessHostProtocol();
        $token = \str_repeat('a', 64);
        $request = $protocol->encodeRequest(7, WorkerProcProcessHostProtocol::OPERATION_SPAWN, [
            'command' => ['php', 'child.php'],
            'handoff_port' => 12_345,
            'handoff_token' => $token,
            'working_directory' => 'runtime',
        ]);
        self::assertSame(
            '{"operation":"spawn","payload":{"command":["php","child.php"],"handoff_port":12345,"handoff_token":"' . $token . '","working_directory":"runtime"},"request_id":7,"version":1}' . "\n",
            $request,
        );

        $response = $protocol->encodeOkResponse(7, ['child_id' => 'child-1', 'pid' => 123]);
        $handoff = $protocol->encodeHandoff(7, $token, $response);
        self::assertSame('ok', $protocol->decodeHandoff($handoff, 7, $token)['status']);
    }

    public function testHelloIsRejectedAsInvalidRuntimeOperation(): void
    {
        $this->expectException(WorkerLifecycleFailedException::class);
        new WorkerProcProcessHostProtocol()->decodeRequest(
            "{\"operation\":\"hello\",\"payload\":{},\"request_id\":1,\"version\":1}\n",
        );
    }

    public function testHandoffRejectsWrongTokenWithoutVersionChange(): void
    {
        $protocol = new WorkerProcProcessHostProtocol();
        $response = $protocol->encodeErrorResponse(3, WorkerProcProcessHostProtocol::ERROR_CHILD_START_FAILED);
        $handoff = $protocol->encodeHandoff(3, \str_repeat('b', 64), $response);
        $this->expectException(WorkerLifecycleFailedException::class);
        $protocol->decodeHandoff($handoff, 3, \str_repeat('c', 64));
    }
}
