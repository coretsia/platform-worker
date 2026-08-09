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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use Coretsia\Platform\Worker\Communication\WorkerControlOperation;
use Coretsia\Platform\Worker\Communication\WorkerControlProtocol;
use Coretsia\Platform\Worker\Communication\WorkerControlRequest;
use Coretsia\Platform\Worker\Communication\WorkerControlServer;
use Coretsia\Platform\Worker\Communication\WorkerControlSession;
use Coretsia\Platform\Worker\Communication\WorkerControlTransport;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerControlAuthenticationTest extends PackageTestCase
{
    public function testMissingCredentialIsRejectedAndListenerRemainsUsable(): void
    {
        [$server, $spec, $protocol] = $this->server('missing-credential');
        $credential = self::credential('a');
        $server->listen($spec, $credential);

        try {
            $client = self::sendFrame(
                $spec,
                "{\"operation\":\"stop\",\"request_id\":\"missing\",\"version\":1}\n",
            );
            self::assertNull($server->accept(1_000));
            self::close($client);

            $session = self::sendAuthenticatedRequest(
                $server,
                $spec,
                $protocol,
                WorkerControlOperation::STATUS,
                'valid-after-missing',
                $credential,
            );

            self::assertSame(
                WorkerControlOperation::STATUS,
                $session->request()->operation(),
            );
            $server->closeSession($session);
        } finally {
            $server->close();
        }
    }

    public function testMalformedAndWrongCredentialsAreRejected(): void
    {
        [$server, $spec, $protocol] = $this->server('invalid-credentials');
        $expected = self::credential('a');
        $server->listen($spec, $expected);

        try {
            $malformed = self::sendFrame(
                $spec,
                "{\"credential\":\"abcd\",\"operation\":\"health\",\"request_id\":\"malformed\",\"version\":1}\n",
            );
            self::assertNull($server->accept(1_000));
            self::close($malformed);

            $wrong = new WorkerControlRequest(
                operation: WorkerControlOperation::STOP,
                requestId: 'wrong',
                credential: self::credential('b'),
            );
            $wrongClient = self::sendFrame(
                $spec,
                $protocol->encodeRequest($wrong),
            );
            self::assertNull($server->accept(1_000));
            self::close($wrongClient);
        } finally {
            $server->close();
        }
    }

    public function testCorrectCredentialCreatesTypedSession(): void
    {
        [$server, $spec, $protocol] = $this->server('correct-credential');
        $credential = self::credential('a');
        $server->listen($spec, $credential);

        try {
            $session = self::sendAuthenticatedRequest(
                $server,
                $spec,
                $protocol,
                WorkerControlOperation::HEALTH,
                'correct',
                $credential,
            );

            self::assertSame('correct', $session->request()->requestId());
            self::assertSame(
                WorkerControlOperation::HEALTH,
                $session->request()->operation(),
            );
            self::assertTrue(
                $credential->matches($session->request()->credential()),
            );
            $server->closeSession($session);
        } finally {
            $server->close();
        }
    }

    public function testPreviousSupervisorCredentialIsRejectedAfterRestart(): void
    {
        [$firstServer, $firstSpec] = $this->server('credential-first');
        $firstServer->listen($firstSpec, self::credential('a'));
        $firstServer->close();

        [$secondServer, $secondSpec, $protocol] = $this->server('credential-second');
        $current = self::credential('b');
        $secondServer->listen($secondSpec, $current);

        try {
            $staleRequest = new WorkerControlRequest(
                operation: WorkerControlOperation::STOP,
                requestId: 'stale',
                credential: self::credential('a'),
            );
            $staleClient = self::sendFrame(
                $secondSpec,
                $protocol->encodeRequest($staleRequest),
            );
            self::assertNull($secondServer->accept(1_000));
            self::close($staleClient);

            $session = self::sendAuthenticatedRequest(
                $secondServer,
                $secondSpec,
                $protocol,
                WorkerControlOperation::STATUS,
                'current',
                $current,
            );
            self::assertSame('current', $session->request()->requestId());
            $secondServer->closeSession($session);
        } finally {
            $secondServer->close();
        }
    }

    /** @return array{WorkerControlServer, WorkerPoolSpec, WorkerControlProtocol} */
    private function server(string $prefix): array
    {
        $root = $this->temporaryDirectory($prefix);
        $protocol = new WorkerControlProtocol(
            new StableJsonEncoder(),
            new StableJsonDecoder(),
        );
        $spec = WorkerSpecFactory::create([
            'driver' => 'proc',
            'control' => ['transport' => 'tcp'],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => self::unusedTcpPort(),
            ],
        ]);

        return [
            new WorkerControlServer(
                new WorkerControlTransport($root),
                $protocol,
            ),
            $spec,
            $protocol,
        ];
    }

    private static function sendAuthenticatedRequest(
        WorkerControlServer $server,
        WorkerPoolSpec $spec,
        WorkerControlProtocol $protocol,
        WorkerControlOperation $operation,
        string $requestId,
        WorkerControlCredential $credential,
    ): WorkerControlSession {
        $request = new WorkerControlRequest(
            operation: $operation,
            requestId: $requestId,
            credential: $credential,
        );
        $client = self::sendFrame(
            $spec,
            $protocol->encodeRequest($request),
        );
        $session = $server->accept(1_000);

        self::assertInstanceOf(WorkerControlSession::class, $session);
        self::close($client);

        return $session;
    }

    /** @return resource */
    private static function sendFrame(
        WorkerPoolSpec $spec,
        string $frame,
    ): mixed {
        $client = @\stream_socket_client(
            'tcp://127.0.0.1:' . $spec->tcpPort(),
            $errorCode,
            $errorMessage,
            1.0,
            \STREAM_CLIENT_CONNECT,
        );

        self::assertIsResource($client);
        self::assertSame(\strlen($frame), @\fwrite($client, $frame));
        self::assertTrue(@\fflush($client));

        return $client;
    }

    private static function credential(string $character): WorkerControlCredential
    {
        return WorkerControlCredential::fromEncoded(
            \str_repeat($character, 64),
        );
    }

    private static function close(mixed $stream): void
    {
        if (\is_resource($stream)) {
            @\fclose($stream);
        }
    }
}
