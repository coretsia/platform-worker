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

use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapEndpoint;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapFailure;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkerProcessBootstrapChannelTest extends PackageTestCase
{
    public function testProductionLauncherAuthenticatesLaunchedChildForBothRoles(): void
    {
        foreach (
            [
                WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                WorkerProcessBootstrapProtocol::ROLE_PROC_HOST
            ] as $role
        ) {
            $protocol = new WorkerProcessBootstrapProtocol();
            $launcher = new WorkerProcessBootstrapLauncher($protocol);
            $session = $launcher->launchAuthenticatedChild(
                command: [
                    \PHP_BINARY,
                    self::packageRoot() . '/tests/Fixtures/process-bootstrap-fixture.php',
                    $role,
                ],
                workingDirectory: self::frameworkRoot(),
                role: $role,
                timeoutMs: 3_000,
                driver: $role === WorkerProcessBootstrapProtocol::ROLE_GUARDIAN ? 'proc' : null,
            );

            try {
                self::assertGreaterThan(0, $session['pid']);
                self::assertSame(
                    "process-bootstrap-fixture-ready\n",
                    self::readLine($session['connection'], 2_000),
                );
            } finally {
                @\fclose($session['connection']);
                self::waitProcessExit($session['process']);
            }
        }
    }

    public function testPendingCandidateBoundDoesNotCloseRetainedListener(): void
    {
        $protocol = new WorkerProcessBootstrapProtocol();
        $endpoint = WorkerProcessBootstrapEndpoint::create(
            $protocol,
            WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
        );
        $launch = $protocol->decodeGuardianLaunch(
            $endpoint->launchFrame(3_000, 'proc'),
        );
        $candidates = [];

        try {
            for ($i = 0; $i < 12; $i++) {
                $candidates[] = self::connect($launch['port']);
            }

            $legitimate = self::connect($launch['port']);
            $candidates[] = $legitimate;

            self::write(
                $legitimate,
                $protocol->encodeAuthentication(
                    WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                    $launch['credential'],
                ),
            );

            $legitimateLocal = @\stream_socket_get_name(
                $legitimate,
                false,
            );

            self::assertIsString($legitimateLocal);

            $authenticated = $endpoint->authenticate(
                \hrtime(true) + 1_000_000_000,
            );

            try {
                self::assertSame(
                    $legitimateLocal,
                    @\stream_socket_get_name($authenticated, true),
                );
            } finally {
                @\fclose($authenticated);
            }

            $second = @\stream_socket_client(
                'tcp://127.0.0.1:' . $launch['port'],
                $code,
                $message,
                0.1,
                \STREAM_CLIENT_CONNECT,
            );

            self::assertFalse(
                \is_resource($second),
                'Successful authentication must close the one-shot listener.',
            );
        } finally {
            foreach ($candidates as $candidate) {
                if (\is_resource($candidate)) {
                    @\fclose($candidate);
                }
            }

            $endpoint->close();
        }
    }

    public function testRetainedListenerRejectsRebindAndDoesNotRevealCapability(): void
    {
        $protocol = new WorkerProcessBootstrapProtocol();
        $endpoint = WorkerProcessBootstrapEndpoint::create(
            $protocol,
            WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
        );
        $launch = $protocol->decodeGuardianLaunch($endpoint->launchFrame(2_000, 'proc'));
        $silent = self::connect($launch['port']);

        try {
            $second = @\stream_socket_server('tcp://127.0.0.1:' . $launch['port'], $code, $message);
            self::assertFalse(
                \is_resource($second),
                'The bootstrap listener must retain continuous ownership of its port.',
            );

            $start = \hrtime(true);

            try {
                $endpoint->authenticate(
                    $start + 150_000_000,
                );

                self::fail('Silent unauthenticated candidate must not authenticate.');
            } catch (WorkerProcessBootstrapFailure) {
                $elapsedMs = (\hrtime(true) - $start) / 1_000_000;

                self::assertGreaterThanOrEqual(
                    100,
                    $elapsedMs,
                );
                self::assertLessThan(
                    750,
                    $elapsedMs,
                );
            }

            @\stream_set_blocking($silent, false);

            self::assertSame(
                '',
                (string)@\stream_get_contents($silent),
                'Parent bootstrap endpoint must never send the capability to an unauthenticated peer.',
            );
        } finally {
            @\fclose($silent);
            $endpoint->close();
        }
    }

    #[DataProvider('independentlyRejectedCandidateProvider')]
    public function testInvalidOrSilentCandidateIsRejectedWithoutConsumingAuthority(
        string $scenario,
    ): void {
        $protocol = new WorkerProcessBootstrapProtocol();
        $endpoint = WorkerProcessBootstrapEndpoint::create(
            $protocol,
            WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
        );
        $launch = $protocol->decodeGuardianLaunch(
            $endpoint->launchFrame(3_000, 'proc'),
        );
        $hostile = self::connect($launch['port']);

        try {
            if ($scenario === 'wrong-credential') {
                $wrongCredential = ($launch['credential'][0] === '0' ? '1' : '0')
                    . \substr($launch['credential'], 1);

                self::write(
                    $hostile,
                    $protocol->encodeAuthentication(
                        WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                        $wrongCredential,
                    ),
                );
            } elseif ($scenario === 'wrong-role') {
                self::write(
                    $hostile,
                    $protocol->encodeAuthentication(
                        WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
                        $launch['credential'],
                    ),
                );
            } elseif ($scenario === 'oversized') {
                self::write(
                    $hostile,
                    \str_repeat(
                        'x',
                        WorkerProcessBootstrapProtocol::MAX_AUTH_FRAME_BYTES + 1,
                    ),
                );
            }

            $rejectDeadlineMs = $scenario === 'silent'
                ? 350
                : 150;

            try {
                $endpoint->authenticate(
                    \hrtime(true) + ($rejectDeadlineMs * 1_000_000),
                );

                self::fail('Hostile candidate must not authenticate.');
            } catch (WorkerProcessBootstrapFailure) {
            }

            @\stream_set_blocking($hostile, false);

            self::waitUntil(
                static function () use ($hostile): bool {
                    @\fread($hostile, 1);

                    return @\feof($hostile);
                },
                750,
                'Rejected bootstrap candidate must be evicted and closed.',
            );

            $legitimate = self::connect($launch['port']);

            try {
                self::write(
                    $legitimate,
                    $protocol->encodeAuthentication(
                        WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                        $launch['credential'],
                    ),
                );

                $legitimateLocal = @\stream_socket_get_name(
                    $legitimate,
                    false,
                );

                self::assertIsString($legitimateLocal);

                $authenticated = $endpoint->authenticate(
                    \hrtime(true) + 1_000_000_000,
                );

                try {
                    self::assertIsResource($authenticated);
                    self::assertSame(
                        $legitimateLocal,
                        @\stream_socket_get_name($authenticated, true),
                        'Only the legitimate candidate may consume bootstrap authority.',
                    );
                } finally {
                    @\fclose($authenticated);
                }
            } finally {
                @\fclose($legitimate);
            }
        } finally {
            @\fclose($hostile);
            $endpoint->close();
        }
    }

    public function testListenerWaitWithoutCandidatesHonorsTheOverallDeadline(): void
    {
        $endpoint = WorkerProcessBootstrapEndpoint::create(
            new WorkerProcessBootstrapProtocol(),
            WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
        );

        try {
            $start = \hrtime(true);

            try {
                $endpoint->authenticate(
                    $start + 150_000_000,
                );

                self::fail('Authentication without candidates must time out.');
            } catch (WorkerProcessBootstrapFailure) {
                $elapsedMs = (\hrtime(true) - $start) / 1_000_000;

                self::assertGreaterThanOrEqual(
                    100,
                    $elapsedMs,
                );
                self::assertLessThan(
                    750,
                    $elapsedMs,
                    'The retained listener must not block waiting for accept().',
                );
            }
        } finally {
            $endpoint->close();
        }
    }

    public function testBootstrapDomainsReceiveIndependentCredentials(): void
    {
        $protocol = new WorkerProcessBootstrapProtocol();

        $guardian = WorkerProcessBootstrapEndpoint::create(
            $protocol,
            WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
        );
        $procHost = WorkerProcessBootstrapEndpoint::create(
            $protocol,
            WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
        );

        try {
            $guardianLaunch = $protocol->decodeGuardianLaunch(
                $guardian->launchFrame(
                    1_000,
                    'proc',
                ),
            );

            $procHostLaunch = $protocol->decodeProcHostLaunch(
                $procHost->launchFrame(1_000),
            );

            self::assertNotSame(
                $guardianLaunch['credential'],
                $procHostLaunch['credential'],
                'Each bootstrap domain must receive an independent one-shot capability.',
            );
        } finally {
            $guardian->close();
            $procHost->close();
        }
    }

    public function testHostileCandidatesDoNotExtendOverallDeadline(): void
    {
        $protocol = new WorkerProcessBootstrapProtocol();
        $endpoint = WorkerProcessBootstrapEndpoint::create(
            $protocol,
            WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
        );
        $launch = $protocol->decodeProcHostLaunch($endpoint->launchFrame(200));
        $candidates = [];

        try {
            for ($i = 0; $i < 12; $i++) {
                $candidates[] = self::connect($launch['port']);
            }

            $start = \hrtime(true);
            try {
                $endpoint->authenticate($start + 200_000_000);
                self::fail('Authentication without a valid candidate must time out.');
            } catch (WorkerProcessBootstrapFailure) {
                $elapsedMs = (\hrtime(true) - $start) / 1_000_000;
                self::assertGreaterThanOrEqual(150, $elapsedMs);
                self::assertLessThan(
                    1_000,
                    $elapsedMs,
                    'Hostile candidates must not restart or extend the overall deadline.',
                );
            }
        } finally {
            foreach ($candidates as $candidate) {
                if (\is_resource($candidate)) {
                    @\fclose($candidate);
                }
            }
            $endpoint->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function independentlyRejectedCandidateProvider(): iterable
    {
        yield 'wrong credential' => ['wrong-credential'];
        yield 'wrong role' => ['wrong-role'];
        yield 'oversized frame' => ['oversized'];
        yield 'silent candidate' => ['silent'];
    }

    /** @return resource */
    private static function connect(int $port): mixed
    {
        $connection = @\stream_socket_client(
            'tcp://127.0.0.1:' . $port,
            $code,
            $message,
            1.0,
            \STREAM_CLIENT_CONNECT,
        );
        self::assertIsResource($connection);
        return $connection;
    }

    /** @param resource $connection */
    private static function write(mixed $connection, string $bytes): void
    {
        $remaining = $bytes;
        while ($remaining !== '') {
            $written = @\fwrite($connection, $remaining);
            self::assertIsInt($written);
            self::assertGreaterThan(0, $written);
            $remaining = \substr($remaining, $written);
        }
        @\fflush($connection);
    }

    /** @param resource $connection */
    private static function readLine(mixed $connection, int $timeoutMs): string
    {
        $deadline = \hrtime(true) + ($timeoutMs * 1_000_000);
        $buffer = '';
        do {
            $chunk = @\fread($connection, 256);
            if (\is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
                if (\str_contains($buffer, "\n")) {
                    return \substr($buffer, 0, \strpos($buffer, "\n") + 1);
                }
            }
            \usleep(5_000);
        } while (\hrtime(true) < $deadline);
        self::fail('Timed out waiting for bootstrap fixture marker.');
    }

    /** @param resource $process */
    private static function waitProcessExit(mixed $process): void
    {
        $deadline = \hrtime(true) + 2_000_000_000;
        do {
            $status = @\proc_get_status($process);
            if (!\is_array($status) || ($status['running'] ?? false) !== true) {
                @\proc_close($process);
                return;
            }
            \usleep(10_000);
        } while (\hrtime(true) < $deadline);
        @\proc_terminate($process, 9);
        @\proc_close($process);
        self::fail('Bootstrap fixture did not exit after authenticated connection EOF.');
    }
}
