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

use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use Coretsia\Platform\Worker\Communication\WorkerControlOperation;
use Coretsia\Platform\Worker\Communication\WorkerControlProtocol;
use Coretsia\Platform\Worker\Communication\WorkerControlRequest;
use Coretsia\Platform\Worker\Communication\WorkerControlResponse;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use PHPUnit\Framework\TestCase;

final class WorkerControlProtocolSchemaContractTest extends TestCase
{
    public function testRequestAndResponseSchemasRoundTripExactly(): void
    {
        $protocol = self::protocol();
        $request = new WorkerControlRequest(
            operation: WorkerControlOperation::STATUS,
            requestId: 'request-1',
            credential: WorkerControlCredential::fromEncoded(
                \str_repeat('a', 64),
            ),
        );

        self::assertSame(
            [
                'version' => 1,
                'operation' => 'status',
                'request_id' => 'request-1',
                'credential' => \str_repeat('a', 64),
            ],
            $request->toArray(),
        );
        self::assertEquals(
            $request,
            $protocol->decodeRequest(
                $protocol->encodeRequest($request),
            ),
        );

        $response = WorkerControlResponse::ok(
            'request-1',
            ['status' => 'running'],
        );
        $responseFrame = $protocol->encodeResponse($response);

        self::assertStringNotContainsString('credential', $responseFrame);
        self::assertEquals(
            $response,
            $protocol->decodeResponse($responseFrame),
        );
    }

    public function testMissingMalformedUnknownAndUnsupportedRequestsAreRejected(): void
    {
        $protocol = self::protocol();
        $credential = \str_repeat('a', 64);
        $rejected = 0;

        foreach (
            [
                '{"credential":"' . $credential . '","operation":"start","request_id":"x","version":1}' . "\n",
                '{"operation":"status","request_id":"x","version":1}' . "\n",
                '{"credential":"abcd","operation":"status","request_id":"x","version":1}' . "\n",
                '{"credential":"' . $credential . '","operation":"status","payload":{},' . '"request_id":"x","version":1}' . "\n",
                '{"credential":"' . $credential . '","operation":"status","request_id":"x","version":2}' . "\n",
            ] as $frame
        ) {
            try {
                $protocol->decodeRequest($frame);
                self::fail('Expected invalid control request.');
            } catch (WorkerCommunicationFailedException) {
                $rejected++;
            }
        }

        self::assertSame(5, $rejected);
    }

    private static function protocol(): WorkerControlProtocol
    {
        return new WorkerControlProtocol();
    }
}
