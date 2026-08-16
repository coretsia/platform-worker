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
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerProcessGuardianProtocolTest extends TestCase
{
    public function testClaimIsCanonicalFirstRuntimeOperationAndResponsesRoundTrip(): void
    {
        $protocol = new WorkerProcessGuardianProtocol();
        $frame = $protocol->encodeRequest(1, WorkerProcessGuardianProtocol::OPERATION_CLAIM, [
            'force_kill_timeout_ms' => 1000,
            'skeleton_root' => '/tmp/coretsia-worker-test',
            'stop_timeout_ms' => 1000,
        ]);
        self::assertSame('claim', $protocol->decodeRequest($frame)['operation']);

        $response = $protocol->encodeOkResponse(1, ['acknowledged' => true]);
        self::assertSame('ok', $protocol->decodeResponse($response)['status']);
        $error = $protocol->encodeErrorResponse(1, WorkerProcessGuardianProtocol::ERROR_ALREADY_RUNNING);
        self::assertSame('already-running', $protocol->decodeResponse($error)['payload']['reason']);
    }

    #[DataProvider('invalidRequestProvider')]
    public function testRequestSchemaIsExactAndBounded(string $frame): void
    {
        $this->expectException(WorkerLifecycleFailedException::class);
        new WorkerProcessGuardianProtocol()->decodeRequest($frame);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRequestProvider(): iterable
    {
        yield 'hello is not a runtime operation' => ["{\"operation\":\"hello\",\"payload\":{},\"request_id\":1,\"version\":1}\n"];
        yield 'unknown operation' => ["{\"operation\":\"unknown\",\"payload\":{},\"request_id\":1,\"version\":1}\n"];
        yield 'extra field' => ["{\"extra\":true,\"operation\":\"release\",\"payload\":{},\"request_id\":1,\"version\":1}\n"];
        yield 'invalid child id' => ["{\"operation\":\"poll\",\"payload\":{\"child_id\":\"7\"},\"request_id\":1,\"version\":1}\n"];
        yield 'missing newline' => ['{"operation":"release","payload":{},"request_id":1,"version":1}'];
    }

    public function testUnknownErrorReasonAndOversizedFrameAreRejected(): void
    {
        $protocol = new WorkerProcessGuardianProtocol();
        try {
            $protocol->encodeErrorResponse(1, 'raw-/tmp/secret');
            self::fail('Unknown raw error reason must be rejected.');
        } catch (WorkerLifecycleFailedException $exception) {
            self::assertSame('worker-process-guardian-failed', $exception->reason());
        }

        $this->expectException(WorkerLifecycleFailedException::class);
        $protocol->decodeRequest(\str_repeat('x', WorkerProcessGuardianProtocol::MAX_FRAME_BYTES + 1));
    }
}
