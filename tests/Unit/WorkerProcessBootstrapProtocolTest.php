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

use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapFailure;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerProcessBootstrapProtocolTest extends TestCase
{
    public function testCanonicalGuardianProcHostAndAuthenticationFramesRoundTrip(): void
    {
        $protocol = new WorkerProcessBootstrapProtocol();

        foreach (['pcntl', 'proc'] as $driver) {
            $frame = $protocol->encodeGuardianLaunch(12345, self::credential(), 1, $driver);
            self::assertSame([
                'credential' => self::credential(),
                'driver' => $driver,
                'port' => 12345,
                'role' => WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                'timeout_ms' => 1,
                'version' => WorkerProcessBootstrapProtocol::VERSION,
            ], $protocol->decodeGuardianLaunch($frame));
        }

        $frame = $protocol->encodeProcHostLaunch(
            65535,
            self::credential(),
            WorkerProcessBootstrapProtocol::MAX_TIMEOUT_MS,
        );
        self::assertSame(65535, $protocol->decodeProcHostLaunch($frame)['port']);
        self::assertSame(
            WorkerProcessBootstrapProtocol::MAX_TIMEOUT_MS,
            $protocol->decodeProcHostLaunch($frame)['timeout_ms'],
        );

        foreach (
            [
                WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                WorkerProcessBootstrapProtocol::ROLE_PROC_HOST
            ] as $role
        ) {
            $auth = $protocol->encodeAuthentication($role, self::credential());
            self::assertSame(self::credential(), $protocol->decodeAuthentication($auth, $role));
        }

        self::assertSame(
            StableJsonEncoder::encodeStableMap([
                'credential' => self::credential(),
                'driver' => 'proc',
                'port' => 12_345,
                'role' => WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                'timeout_ms' => 1,
                'version' => WorkerProcessBootstrapProtocol::VERSION,
            ]),
            $protocol->encodeGuardianLaunch(
                12_345,
                self::credential(),
                1,
                'proc',
            ),
        );

        self::assertSame(
            StableJsonEncoder::encodeStableMap([
                'credential' => self::credential(),
                'port' => 65_535,
                'role' => WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
                'timeout_ms' => WorkerProcessBootstrapProtocol::MAX_TIMEOUT_MS,
                'version' => WorkerProcessBootstrapProtocol::VERSION,
            ]),
            $protocol->encodeProcHostLaunch(
                65_535,
                self::credential(),
                WorkerProcessBootstrapProtocol::MAX_TIMEOUT_MS,
            ),
        );

        self::assertSame(
            StableJsonEncoder::encodeStableMap([
                'credential' => self::credential(),
                'role' => WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                'version' => WorkerProcessBootstrapProtocol::VERSION,
            ]),
            $protocol->encodeAuthentication(
                WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                self::credential(),
            ),
        );
    }

    #[DataProvider('invalidGuardianLaunchProvider')]
    public function testGuardianLaunchRejectsInvalidOrNoncanonicalFrames(string $frame): void
    {
        $this->expectException(WorkerProcessBootstrapFailure::class);
        new WorkerProcessBootstrapProtocol()->decodeGuardianLaunch($frame);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidGuardianLaunchProvider(): iterable
    {
        $c = self::credential();
        yield 'wrong version' => ['{"credential":"' . $c . '","driver":"proc","port":1,"role":"guardian","timeout_ms":1,"version":2}' . "\n"];
        yield 'wrong role' => ['{"credential":"' . $c . '","driver":"proc","port":1,"role":"proc-host","timeout_ms":1,"version":1}' . "\n"];
        yield 'wrong driver' => ['{"credential":"' . $c . '","driver":"fork","port":1,"role":"guardian","timeout_ms":1,"version":1}' . "\n"];
        yield 'missing key' => ['{"credential":"' . $c . '","driver":"proc","port":1,"role":"guardian","version":1}' . "\n"];
        yield 'extra key' => ['{"credential":"' . $c . '","driver":"proc","extra":true,"port":1,"role":"guardian","timeout_ms":1,"version":1}' . "\n"];
        yield 'port zero' => ['{"credential":"' . $c . '","driver":"proc","port":0,"role":"guardian","timeout_ms":1,"version":1}' . "\n"];
        yield 'port too high' => ['{"credential":"' . $c . '","driver":"proc","port":65536,"role":"guardian","timeout_ms":1,"version":1}' . "\n"];
        yield 'malformed credential' => ['{"credential":"secret","driver":"proc","port":1,"role":"guardian","timeout_ms":1,"version":1}' . "\n"];
        yield 'uppercase credential' => [
            '{"credential":"' . \strtoupper(
                $c
            ) . '","driver":"proc","port":1,"role":"guardian","timeout_ms":1,"version":1}' . "\n"
        ];
        yield 'timeout zero' => ['{"credential":"' . $c . '","driver":"proc","port":1,"role":"guardian","timeout_ms":0,"version":1}' . "\n"];
        yield 'timeout too high' => ['{"credential":"' . $c . '","driver":"proc","port":1,"role":"guardian","timeout_ms":86400001,"version":1}' . "\n"];
        yield 'malformed json' => ["{\n"];
        yield 'noncanonical' => ['{"version":1,"timeout_ms":1,"role":"guardian","port":1,"driver":"proc","credential":"' . $c . '"}' . "\n"];
        yield 'missing final lf' => ['{"credential":"' . $c . '","driver":"proc","port":1,"role":"guardian","timeout_ms":1,"version":1}'];
        yield 'trailing data' => ['{"credential":"' . $c . '","driver":"proc","port":1,"role":"guardian","timeout_ms":1,"version":1}' . "\nX"];
        yield 'oversized' => [\str_repeat('x', WorkerProcessBootstrapProtocol::MAX_LAUNCH_FRAME_BYTES + 1)];
    }

    #[DataProvider('invalidProcHostLaunchProvider')]
    public function testProcHostLaunchKeepsAnExactRoleSpecificSchema(
        string $frame,
    ): void {
        $this->expectException(
            WorkerProcessBootstrapFailure::class,
        );

        new WorkerProcessBootstrapProtocol()
            ->decodeProcHostLaunch($frame);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProcHostLaunchProvider(): iterable
    {
        $c = self::credential();

        yield 'guardian-only driver is forbidden' => [
            '{"credential":"' . $c . '","driver":"proc","port":1,"role":"proc-host","timeout_ms":1,"version":1}' . "\n",
        ];

        yield 'wrong role' => [
            '{"credential":"' . $c . '","port":1,"role":"guardian","timeout_ms":1,"version":1}' . "\n",
        ];

        yield 'missing timeout' => [
            '{"credential":"' . $c . '","port":1,"role":"proc-host","version":1}' . "\n",
        ];

        yield 'extra key' => [
            '{"credential":"' . $c . '","extra":true,"port":1,"role":"proc-host","timeout_ms":1,"version":1}' . "\n",
        ];

        yield 'uppercase credential' => [
            '{"credential":"' . \strtoupper($c) . '","port":1,"role":"proc-host","timeout_ms":1,"version":1}' . "\n",
        ];
    }

    #[DataProvider('invalidAuthenticationProvider')]
    public function testAuthenticationFrameKeepsAnExactBoundedSchema(
        string $frame,
    ): void {
        $this->expectException(
            WorkerProcessBootstrapFailure::class,
        );

        new WorkerProcessBootstrapProtocol()
            ->decodeAuthentication(
                $frame,
                WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
            );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAuthenticationProvider(): iterable
    {
        $c = self::credential();

        yield 'wrong version' => ['{"credential":"' . $c . '","role":"guardian","version":2}' . "\n"];
        yield 'missing credential' => ['{"role":"guardian","version":1}' . "\n"];
        yield 'extra key' => ['{"credential":"' . $c . '","extra":true,"role":"guardian","version":1}' . "\n"];
        yield 'uppercase credential' => [
            '{"credential":"' . \strtoupper(
                $c
            ) . '","role":"guardian","version":1}' . "\n"
        ];
        yield 'noncanonical' => ['{"version":1,"role":"guardian","credential":"' . $c . '"}' . "\n"];
        yield 'missing final lf' => ['{"credential":"' . $c . '","role":"guardian","version":1}'];
        yield 'trailing data' => ['{"credential":"' . $c . '","role":"guardian","version":1}' . "\nX"];

        yield 'oversized' => [
            \str_repeat(
                'x',
                WorkerProcessBootstrapProtocol::MAX_AUTH_FRAME_BYTES + 1,
            ),
        ];
    }

    public function testAuthenticationRejectsWrongRoleAndDiagnosticsAreRedacted(): void
    {
        $protocol = new WorkerProcessBootstrapProtocol();
        $frame = $protocol->encodeAuthentication(
            WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
            self::credential(),
        );

        try {
            $protocol->decodeAuthentication(
                $frame,
                WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
            );
            self::fail('Wrong-role authentication must fail.');
        } catch (WorkerProcessBootstrapFailure $failure) {
            self::assertSame('worker-process-bootstrap-failed', $failure->getMessage());
            self::assertStringNotContainsString(self::credential(), $failure->getMessage());
            self::assertStringNotContainsString('127.0.0.1', $failure->getMessage());
            self::assertStringNotContainsString($frame, $failure->getMessage());
        }
    }

    private static function credential(): string
    {
        return \str_repeat('a', 64);
    }
}
