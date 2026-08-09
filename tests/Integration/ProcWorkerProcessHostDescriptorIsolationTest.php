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
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostHandoffEndpoint;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostProtocol;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class ProcWorkerProcessHostDescriptorIsolationTest extends PackageTestCase
{
    public function testEverySpawnRotatesTheAuthenticatedConnectionBeforeChildLaunch(): void
    {
        if (!WorkerProcessCapabilities::procDriverAvailable()) {
            self::assertFalse(
                WorkerProcessCapabilities::procDriverAvailable(),
            );

            return;
        }

        $root = $this->temporaryDirectory('proc-host-descriptor-isolation');
        $started = self::startHost();
        $protocol = self::protocol();
        $connection = null;
        $connections = [];
        $releaseFiles = [];
        $childPids = [];

        try {
            $connection = self::connect(
                port: $started['port'],
                timeoutMs: 2_000,
            );
            $connections[] = $connection;

            self::request(
                connection: $connection,
                protocol: $protocol,
                requestId: 1,
                operation: WorkerProcProcessHostProtocol::OPERATION_HELLO,
                payload: ['token' => $started['token']],
            );

            for ($index = 0; $index < 2; $index++) {
                $previousConnection = $connection;
                $readyFile = $root . '/child-' . $index . '.ready';
                $releaseFile = $root . '/child-' . $index . '.release';
                $releaseFiles[] = $releaseFile;

                $handoff = self::spawnThroughHandoff(
                    connection: $previousConnection,
                    protocol: $protocol,
                    requestId: $index + 2,
                    command: [
                        \PHP_BINARY,
                        self::packageRoot() . '/tests/Fixtures/exec-hold-fixture.php',
                        '--ready-file=' . $readyFile,
                        '--release-file=' . $releaseFile,
                        '--timeout-ms=8000',
                    ],
                    workingDirectory: $root,
                );

                $connection = $handoff['connection'];
                $connections[] = $connection;
                $spawn = self::successfulPayload($handoff['response']);

                self::assertSame(
                    ['child_id', 'pid'],
                    \array_keys($spawn),
                );
                self::assertIsInt($spawn['pid']);
                self::assertGreaterThan(0, $spawn['pid']);
                $childPids[] = $spawn['pid'];

                self::waitUntil(
                    static fn (): bool => @\feof($previousConnection),
                    1_000,
                    'The previous authenticated host connection remained open across child launch.',
                );

                self::waitUntil(
                    static fn (): bool => \is_file($readyFile),
                    2_000,
                    'The proc-host child did not become ready.',
                );

                self::assertTrue(self::processExists($spawn['pid']));
            }

            @\proc_terminate(
                $started['process'],
                9,
            );

            self::waitUntil(
                static function () use ($started): bool {
                    $status = @\proc_get_status(
                        $started['process'],
                    );

                    return !\is_array($status)
                        || ($status['running'] ?? false) !== true;
                },
                2_000,
                'The proc process host did not terminate.',
            );

            self::waitUntil(
                static fn (): bool => @\feof($connection),
                1_000,
                'The active authenticated host connection crossed child launch.',
            );

            foreach ($childPids as $childPid) {
                self::assertTrue(self::processExists($childPid));
            }

            foreach ($releaseFiles as $releaseFile) {
                self::assertNotFalse(
                    @\file_put_contents(
                        $releaseFile,
                        "release\n",
                        \LOCK_EX,
                    ),
                );
            }

            foreach ($childPids as $childPid) {
                self::waitUntil(
                    static fn (): bool => !self::processExists($childPid),
                    3_000,
                    'A released proc-host child did not exit.',
                );
            }
        } finally {
            foreach ($releaseFiles as $releaseFile) {
                if (!\is_file($releaseFile)) {
                    @\file_put_contents(
                        $releaseFile,
                        "release\n",
                        \LOCK_EX,
                    );
                }
            }

            foreach ($connections as $candidate) {
                if (\is_resource($candidate)) {
                    @\fclose($candidate);
                }
            }

            self::terminateHost($started);
        }
    }

    public function testFailedSpawnRestoresAuthenticatedConnection(): void
    {
        if (!WorkerProcessCapabilities::procDriverAvailable()) {
            self::assertFalse(
                WorkerProcessCapabilities::procDriverAvailable(),
            );

            return;
        }

        $root = $this->temporaryDirectory('proc-host-failed-spawn-handoff');
        $started = self::startHost();
        $protocol = self::protocol();
        $connections = [];

        try {
            $connection = self::connect(
                port: $started['port'],
                timeoutMs: 2_000,
            );
            $connections[] = $connection;

            self::request(
                connection: $connection,
                protocol: $protocol,
                requestId: 1,
                operation: WorkerProcProcessHostProtocol::OPERATION_HELLO,
                payload: ['token' => $started['token']],
            );

            $failed = self::spawnThroughHandoff(
                connection: $connection,
                protocol: $protocol,
                requestId: 2,
                command: [
                    \PHP_BINARY,
                    '-r',
                    'exit(0);',
                ],
                workingDirectory: $root . '/missing-directory',
            );
            $connections[] = $failed['connection'];

            self::assertSame(
                WorkerProcProcessHostProtocol::STATUS_ERROR,
                $failed['response']['status'],
            );
            self::assertSame(
                ['reason' => WorkerProcProcessHostProtocol::ERROR_CHILD_START_FAILED],
                $failed['response']['payload'],
            );
            self::waitUntil(
                static fn (): bool => @\feof($connection),
                1_000,
                'The failed spawn did not retire the previous connection.',
            );

            $recovered = self::spawnThroughHandoff(
                connection: $failed['connection'],
                protocol: $protocol,
                requestId: 3,
                command: [
                    \PHP_BINARY,
                    '-r',
                    'usleep(100000);',
                ],
                workingDirectory: $root,
            );
            $connections[] = $recovered['connection'];
            self::successfulPayload($recovered['response']);

            self::request(
                connection: $recovered['connection'],
                protocol: $protocol,
                requestId: 4,
                operation: WorkerProcProcessHostProtocol::OPERATION_SHUTDOWN,
                payload: [],
            );

            self::waitUntil(
                static function () use ($started): bool {
                    $status = @\proc_get_status($started['process']);

                    return !\is_array($status)
                        || ($status['running'] ?? false) !== true;
                },
                3_000,
                'The recovered proc process host did not shut down.',
            );
        } finally {
            foreach ($connections as $candidate) {
                if (\is_resource($candidate)) {
                    @\fclose($candidate);
                }
            }

            self::terminateHost($started);
        }
    }

    public function testFailedHandoffTerminatesSpawnedChildAndHost(): void
    {
        if (!WorkerProcessCapabilities::procDriverAvailable()) {
            self::assertFalse(
                WorkerProcessCapabilities::procDriverAvailable(),
            );

            return;
        }

        $root = $this->temporaryDirectory('proc-host-failed-handoff');
        $pidFile = $root . '/child.pid';
        $releaseFile = $root . '/child.release';
        $started = self::startHost();
        $protocol = self::protocol();
        $connection = null;
        $childPid = null;
        $finished = null;

        try {
            $connection = self::connect(
                port: $started['port'],
                timeoutMs: 2_000,
            );

            self::request(
                connection: $connection,
                protocol: $protocol,
                requestId: 1,
                operation: WorkerProcProcessHostProtocol::OPERATION_HELLO,
                payload: ['token' => $started['token']],
            );

            self::writeRequest(
                connection: $connection,
                protocol: $protocol,
                requestId: 2,
                operation: WorkerProcProcessHostProtocol::OPERATION_SPAWN,
                payload: [
                    'command' => [
                        \PHP_BINARY,
                        self::packageRoot() . '/tests/Fixtures/exec-hold-pid-fixture.php',
                        '--pid-file=' . $pidFile,
                        '--release-file=' . $releaseFile,
                        '--timeout-ms=8000',
                    ],
                    'handoff_port' => self::unusedTcpPort(),
                    'handoff_token' => \bin2hex(\random_bytes(32)),
                    'working_directory' => $root,
                ],
            );

            self::waitUntil(
                static fn (): bool => \is_file($pidFile),
                2_000,
                'The failed-handoff child did not publish its PID.',
            );

            $pidBytes = @\file_get_contents($pidFile);
            self::assertIsString($pidBytes);
            $pidValue = \trim($pidBytes);
            self::assertTrue(\ctype_digit($pidValue));
            $childPid = (int)$pidValue;
            self::assertGreaterThan(0, $childPid);

            self::waitUntil(
                static function () use ($started): bool {
                    $status = @\proc_get_status($started['process']);

                    return !\is_array($status)
                        || ($status['running'] ?? false) !== true;
                },
                4_000,
                'The proc process host did not exit after failed handoff.',
            );

            self::waitUntil(
                static fn (): bool => !self::processExists($childPid),
                3_000,
                'The process host left an unidentified child alive after failed handoff.',
            );

            $finished = self::finishProcess(
                $started['process'],
                $started['pipes'],
                2_000,
            );
            self::assertNotSame(0, $finished['exit_code']);
        } finally {
            if (!\is_file($releaseFile)) {
                @\file_put_contents(
                    $releaseFile,
                    "release\n",
                    \LOCK_EX,
                );
            }

            if (\is_resource($connection)) {
                @\fclose($connection);
            }

            if ($finished === null) {
                self::terminateHost($started);
            }

            if ($childPid !== null && self::processExists($childPid)) {
                @\file_put_contents(
                    $releaseFile,
                    "release\n",
                    \LOCK_EX,
                );
            }
        }
    }

    /**
     * @return array{
     *     process: resource,
     *     pipes: array<int, resource>,
     *     port: int<1, 65535>,
     *     token: non-empty-string
     * }
     */
    private static function startHost(): array
    {
        $port = self::unusedTcpPort();
        $token = \bin2hex(\random_bytes(32));
        $hostRoot = \is_file(
            self::frameworkRoot() . '/vendor/autoload.php',
        )
            ? self::frameworkRoot()
            : self::packageRoot();
        $started = self::startProcess(
            [
                \PHP_BINARY,
                self::packageRoot()
                . '/bin/coretsia-worker-proc-host',
                '--coretsia-proc-host-port=' . $port,
                '--coretsia-proc-host-token=' . $token,
            ],
            $hostRoot,
        );

        return [
            'process' => $started['process'],
            'pipes' => $started['pipes'],
            'port' => $port,
            'token' => $token,
        ];
    }

    private static function protocol(): WorkerProcProcessHostProtocol
    {
        return new WorkerProcProcessHostProtocol(
            new StableJsonEncoder(),
            new StableJsonDecoder(),
        );
    }

    /**
     * @param resource $connection
     * @param non-empty-list<non-empty-string> $command
     *
     * @return array{
     *     connection: resource,
     *     response: array{
     *         version: 1,
     *         request_id: positive-int,
     *         status: 'ok'|'error',
     *         payload: array<int|string, mixed>
     *     }
     * }
     */
    private static function spawnThroughHandoff(
        mixed $connection,
        WorkerProcProcessHostProtocol $protocol,
        int $requestId,
        array $command,
        string $workingDirectory,
    ): array {
        $endpoint = WorkerProcProcessHostHandoffEndpoint::create();
        $deadlineNs = \hrtime(true) + 2_000_000_000;

        try {
            self::writeRequest(
                connection: $connection,
                protocol: $protocol,
                requestId: $requestId,
                operation: WorkerProcProcessHostProtocol::OPERATION_SPAWN,
                payload: [
                    'command' => $command,
                    'handoff_port' => $endpoint->port(),
                    'handoff_token' => $endpoint->token(),
                    'working_directory' => $workingDirectory,
                ],
            );

            $replacement = $endpoint->accept($deadlineNs);
            $frame = self::readFrame($replacement);
            $response = $protocol->decodeHandoff(
                frame: $frame,
                expectedRequestId: $requestId,
                expectedToken: $endpoint->token(),
            );

            return [
                'connection' => $replacement,
                'response' => $response,
            ];
        } finally {
            $endpoint->close();
        }
    }

    /**
     * @param array{
     *     version: 1,
     *     request_id: positive-int,
     *     status: 'ok'|'error',
     *     payload: array<int|string, mixed>
     * } $response
     *
     * @return array<int|string, mixed>
     */
    private static function successfulPayload(array $response): array
    {
        self::assertSame(
            WorkerProcProcessHostProtocol::STATUS_OK,
            $response['status'],
        );

        return $response['payload'];
    }

    /**
     * @return resource
     */
    private static function connect(
        int $port,
        int $timeoutMs,
    ): mixed {
        $deadline = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $connection = @\stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errorCode,
                $errorMessage,
                0.1,
                \STREAM_CLIENT_CONNECT,
            );

            if (\is_resource($connection)) {
                if (!@\stream_set_timeout($connection, 1, 0)) {
                    @\fclose($connection);

                    self::fail('Failed to configure the host connection timeout.');
                }

                return $connection;
            }

            \usleep(10_000);
        } while (\hrtime(true) < $deadline);

        self::fail('Failed to connect to the proc process host.');
    }

    /**
     * @param resource $connection
     * @param array<int|string, mixed> $payload
     *
     * @return array<int|string, mixed>
     */
    private static function request(
        mixed $connection,
        WorkerProcProcessHostProtocol $protocol,
        int $requestId,
        string $operation,
        array $payload,
    ): array {
        self::writeRequest(
            connection: $connection,
            protocol: $protocol,
            requestId: $requestId,
            operation: $operation,
            payload: $payload,
        );

        $decoded = $protocol->decodeResponse(
            self::readFrame($connection),
        );

        self::assertSame(
            $requestId,
            $decoded['request_id'],
        );
        self::assertSame(
            WorkerProcProcessHostProtocol::STATUS_OK,
            $decoded['status'],
        );

        return $decoded['payload'];
    }

    /**
     * @param resource $connection
     * @param array<int|string, mixed> $payload
     */
    private static function writeRequest(
        mixed $connection,
        WorkerProcProcessHostProtocol $protocol,
        int $requestId,
        string $operation,
        array $payload,
    ): void {
        $frame = $protocol->encodeRequest(
            requestId: $requestId,
            operation: $operation,
            payload: $payload,
        );
        $remaining = $frame;

        while ($remaining !== '') {
            $written = @\fwrite(
                $connection,
                $remaining,
            );

            if (!\is_int($written) || $written < 1) {
                self::fail('Failed to write a proc-host request.');
            }

            $remaining = \substr(
                $remaining,
                $written,
            );
        }

        self::assertTrue(@\fflush($connection));
    }

    /** @param resource $connection */
    private static function readFrame(mixed $connection): string
    {
        $deadlineNs = \hrtime(true) + 2_000_000_000;
        $buffer = '';

        do {
            $read = [$connection];
            $write = null;
            $except = null;
            $selected = @\stream_select(
                $read,
                $write,
                $except,
                0,
                50_000,
            );

            if ($selected !== 1) {
                continue;
            }

            $remaining = WorkerProcProcessHostProtocol::MAX_FRAME_BYTES
                + 1
                - \strlen($buffer);

            if ($remaining < 1) {
                self::fail('The proc-host response exceeded the frame limit.');
            }

            $chunk = @\fread($connection, $remaining);

            if (!\is_string($chunk) || $chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $newline = \strpos($buffer, "\n");

            if ($newline !== false) {
                self::assertSame(
                    \strlen($buffer) - 1,
                    $newline,
                );

                return $buffer;
            }
        } while (\hrtime(true) < $deadlineNs);

        self::fail('Timed out reading a proc-host response.');
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>} $started
     */
    private static function terminateHost(array $started): void
    {
        $status = @\proc_get_status($started['process']);

        if (
            \is_array($status)
            && ($status['running'] ?? false) === true
        ) {
            @\proc_terminate(
                $started['process'],
                9,
            );
        }

        self::finishProcess(
            $started['process'],
            $started['pipes'],
            2_000,
        );
    }
}
