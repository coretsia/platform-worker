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

use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use PHPUnit\Framework\TestCase;

final class WorkerSocketProtocolSafetyContractTest extends TestCase
{
    public function testOnlyAllowedControlOperationsAreAccepted(): void
    {
        $socket = new WorkerSocketServer();

        foreach (
            [
                WorkerSocketServer::OPERATION_START,
                WorkerSocketServer::OPERATION_STOP,
                WorkerSocketServer::OPERATION_STATUS,
                WorkerSocketServer::OPERATION_HEALTH,
            ] as $operation
        ) {
            $stream = self::streamWithFrame($operation);

            try {
                self::assertSame($operation, $socket->readRequest($stream));
            } finally {
                self::closeStream($stream);
            }
        }
    }

    public function testUnknownControlOperationFramesAreRejected(): void
    {
        foreach (
            [
                'restart',
                'reload',
                'pause',
                'queue',
                'http',
                'ok',
                'stop_requested',
                'health ok',
            ] as $frame
        ) {
            self::assertFrameRejectedByReadRequest($frame);
        }
    }

    public function testEmptyFramesAreRejected(): void
    {
        self::assertFrameBytesRejectedByReadRequest("\n");
    }

    public function testOversizedFramesAreRejected(): void
    {
        self::assertFrameBytesRejectedByReadRequest(\str_repeat('a', 257) . "\n");
    }

    public function testFramesContainingControlCharactersAreRejected(): void
    {
        foreach (
            [
                "start\tpayload\n",
                "start\0payload\n",
                "start\rpayload\n",
            ] as $bytes
        ) {
            self::assertFrameBytesRejectedByReadRequest($bytes);
        }
    }

    public function testFramesThatLookLikePayloadTransportAreRejected(): void
    {
        foreach (
            [
                '{"payload":"task","headers":{"authorization":"Bearer token"}}',
                'POST /worker HTTP/1.1',
                'payload=task headers=Authorization token=secret',
                'endpoint=tcp://10.20.30.40:9511 payload=charge-card',
                'socket=var/tmp/private-worker-control.sock body=raw-task-data',
            ] as $frame
        ) {
            self::assertFrameRejectedByReadResponse($frame);
        }
    }

    public function testStartSummaryContainsOnlySafeFields(): void
    {
        $socket = new WorkerSocketServer();
        $endpointHash = \hash('sha256', 'tcp:10.20.30.40:9511');

        $state = new WorkerPoolState(
            pid: 12345,
            workerCount: 4,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: $endpointHash,
        );

        $stream = self::writableMemoryStream();

        try {
            $socket->writeStartSummary($stream, $state);
            \rewind($stream);

            $frame = $socket->readResponse($stream);
        } finally {
            self::closeStream($stream);
        }

        self::assertSame(
            'start pid=12345 worker_count=4 driver=proc control_transport=tcp endpoint_hash=' . $endpointHash,
            $frame,
        );

        self::assertSafeSummaryFrame(
            frame: $frame,
            kind: 'start',
            endpointHash: $endpointHash,
            forbiddenFragments: [
                'driver_requested',
                'control_transport_requested',
                'version',
                '10.20.30.40',
                '10.20.30.40:9511',
                ':9511',
                'tcp:10.20.30.40:9511',
                'tcp://10.20.30.40:9511',
                '/srv/coretsia',
                'C:\\coretsia',
                'payload',
                'body',
                'headers',
                'Authorization',
                'cookie',
                'token',
                'secret',
            ],
        );
    }

    public function testStatusSummaryContainsOnlySafeFields(): void
    {
        $socket = new WorkerSocketServer();
        $socketPath = 'var/tmp/private-worker-control.sock';
        $endpointHash = \hash('sha256', 'unix:' . $socketPath);

        $state = new WorkerPoolState(
            pid: 67890,
            workerCount: 2,
            driverRequested: 'pcntl',
            driver: 'pcntl',
            controlTransportRequested: 'unix',
            controlTransport: 'unix',
            endpointHash: $endpointHash,
        );

        $stream = self::writableMemoryStream();

        try {
            $socket->writeStatusSummary($stream, $state);
            \rewind($stream);

            $frame = $socket->readResponse($stream);
        } finally {
            self::closeStream($stream);
        }

        self::assertSame(
            'status pid=67890 worker_count=2 driver=pcntl control_transport=unix endpoint_hash=' . $endpointHash,
            $frame,
        );

        self::assertSafeSummaryFrame(
            frame: $frame,
            kind: 'status',
            endpointHash: $endpointHash,
            forbiddenFragments: [
                'driver_requested',
                'control_transport_requested',
                'version',
                $socketPath,
                'private-worker-control.sock',
                'unix:' . $socketPath,
                'unix://',
                '/tmp/private-worker-control.sock',
                'C:\\coretsia\\private-worker-control.sock',
                'payload',
                'body',
                'headers',
                'Authorization',
                'cookie',
                'token',
                'secret',
            ],
        );
    }

    public function testEndpointIdentifiersAreExposedOnlyAsLowercaseHexSha256HashesInSafeSummaries(): void
    {
        $socket = new WorkerSocketServer();
        $rawEndpoint = 'tcp:10.20.30.40:9511';
        $endpointHash = \hash('sha256', $rawEndpoint);

        $state = new WorkerPoolState(
            pid: 12345,
            workerCount: 1,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: $endpointHash,
        );

        $stream = self::writableMemoryStream();

        try {
            $socket->writeStatusSummary($stream, $state);
            \rewind($stream);

            $frame = $socket->readResponse($stream);
        } finally {
            self::closeStream($stream);
        }

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $endpointHash);
        self::assertStringContainsString('endpoint_hash=' . $endpointHash, $frame);
        self::assertStringNotContainsString($rawEndpoint, $frame);
        self::assertStringNotContainsString('10.20.30.40', $frame);
        self::assertStringNotContainsString(':9511', $frame);
        self::assertStringNotContainsString('tcp://10.20.30.40:9511', $frame);
    }

    public function testCommunicationFailureMessagesDoNotExposeSensitiveFrameData(): void
    {
        $socket = new WorkerSocketServer();

        $sensitiveFrame = 'socket=var/tmp/private-worker-control.sock'
            . ' endpoint=tcp://10.20.30.40:9511'
            . ' absolute=/srv/coretsia/runtime'
            . ' payload=charge-card'
            . ' headers=Authorization'
            . ' token=secret-token';

        $stream = self::streamWithFrame($sensitiveFrame);

        try {
            $socket->readResponse($stream);

            self::fail('Expected WorkerCommunicationFailedException was not thrown.');
        } catch (WorkerCommunicationFailedException $exception) {
            self::assertSame(WorkerCommunicationFailedException::ERROR_CODE, $exception->errorCode());
            self::assertSame(
                WorkerCommunicationFailedException::REASON_COMMUNICATION_FAILED,
                $exception->reason(),
            );
            self::assertSame(
                WorkerCommunicationFailedException::ERROR_CODE
                . ': '
                . WorkerCommunicationFailedException::REASON_COMMUNICATION_FAILED,
                $exception->getMessage(),
            );

            foreach (
                [
                    'var/tmp/private-worker-control.sock',
                    'private-worker-control.sock',
                    'tcp://10.20.30.40:9511',
                    '10.20.30.40',
                    '10.20.30.40:9511',
                    ':9511',
                    '/srv/coretsia/runtime',
                    'payload',
                    'charge-card',
                    'headers',
                    'Authorization',
                    'token',
                    'secret-token',
                ] as $fragment
            ) {
                self::assertStringNotContainsString($fragment, $exception->getMessage());
            }
        } finally {
            self::closeStream($stream);
        }
    }

    public function testProtocolDoesNotRequireTaskPayloadFixturesWorkerExecutionCliHttpOrEmitStdoutStderr(): void
    {
        $socket = new WorkerSocketServer();
        $endpointHash = \hash('sha256', 'tcp:127.0.0.1:9501');

        $state = new WorkerPoolState(
            pid: 12345,
            workerCount: 1,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: $endpointHash,
        );

        self::captureStdout(
            static function () use ($socket, $state): void {
                $request = self::streamWithFrame(WorkerSocketServer::OPERATION_STATUS);
                $response = self::writableMemoryStream();

                try {
                    self::assertSame(WorkerSocketServer::OPERATION_STATUS, $socket->readRequest($request));

                    $socket->writeStatusSummary($response, $state);
                    \rewind($response);

                    self::assertStringContainsString(
                        'endpoint_hash=' . $state->endpointHash(),
                        $socket->readResponse($response),
                    );
                } finally {
                    self::closeStream($request);
                    self::closeStream($response);
                }
            },
        );

        $source = self::classSource(WorkerSocketServer::class);

        self::assertStringNotContainsString('ApplicationWorker', $source);
        self::assertStringNotContainsString('KernelRuntimeInterface', $source);
        self::assertStringNotContainsString('TaskFactoryInternalInterface', $source);
        self::assertStringNotContainsString('proc_open(', $source);
        self::assertStringNotContainsString('pcntl_', $source);

        self::assertStringNotContainsString('Platform\\Cli', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Cli', $source);
        self::assertStringNotContainsString('Platform\\Http', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Http', $source);

        self::assertStringNotContainsString('STDOUT', $source);
        self::assertStringNotContainsString('STDERR', $source);
        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('error_log(', $source);
    }

    /**
     * @param list<string> $forbiddenFragments
     */
    private static function assertSafeSummaryFrame(
        string $frame,
        string $kind,
        string $endpointHash,
        array $forbiddenFragments,
    ): void {
        self::assertMatchesRegularExpression(
            '/\A'
            . \preg_quote($kind, '/')
            . ' pid=[1-9][0-9]* worker_count=[1-9][0-9]* driver=(?:pcntl|proc) control_transport=(?:unix|tcp) endpoint_hash=[a-f0-9]{64}\z/',
            $frame,
        );

        foreach (
            [
                'pid=',
                'worker_count=',
                'driver=',
                'control_transport=',
                'endpoint_hash=',
            ] as $safeField
        ) {
            self::assertStringContainsString($safeField, $frame);
        }

        self::assertStringContainsString('endpoint_hash=' . $endpointHash, $frame);
        self::assertMatchesRegularExpression('/endpoint_hash=[a-f0-9]{64}\z/', $frame);

        foreach ($forbiddenFragments as $fragment) {
            if ($fragment === '') {
                continue;
            }

            self::assertStringNotContainsString($fragment, $frame);
        }
    }

    private static function assertFrameRejectedByReadRequest(string $frame): void
    {
        self::assertFrameBytesRejectedByReadRequest($frame . "\n");
    }

    private static function assertFrameRejectedByReadResponse(string $frame): void
    {
        $socket = new WorkerSocketServer();
        $stream = self::streamWithFrame($frame);

        try {
            $socket->readResponse($stream);

            self::fail('Expected WorkerCommunicationFailedException was not thrown.');
        } catch (WorkerCommunicationFailedException $exception) {
            self::assertSame(WorkerCommunicationFailedException::ERROR_CODE, $exception->errorCode());
            self::assertSame(
                WorkerCommunicationFailedException::REASON_COMMUNICATION_FAILED,
                $exception->reason(),
            );
        } finally {
            self::closeStream($stream);
        }
    }

    private static function assertFrameBytesRejectedByReadRequest(string $bytes): void
    {
        $socket = new WorkerSocketServer();
        $stream = self::streamWithBytes($bytes);

        try {
            $socket->readRequest($stream);

            self::fail('Expected WorkerCommunicationFailedException was not thrown.');
        } catch (WorkerCommunicationFailedException $exception) {
            self::assertSame(WorkerCommunicationFailedException::ERROR_CODE, $exception->errorCode());
            self::assertSame(
                WorkerCommunicationFailedException::REASON_COMMUNICATION_FAILED,
                $exception->reason(),
            );
        } finally {
            self::closeStream($stream);
        }
    }

    /**
     * @return resource
     */
    private static function streamWithFrame(string $frame): mixed
    {
        return self::streamWithBytes($frame . "\n");
    }

    /**
     * @return resource
     */
    private static function streamWithBytes(string $bytes): mixed
    {
        $stream = self::writableMemoryStream();

        $written = \fwrite($stream, $bytes);

        self::assertIsInt($written);
        self::assertSame(\strlen($bytes), $written);

        \rewind($stream);

        return $stream;
    }

    /**
     * @return resource
     */
    private static function writableMemoryStream(): mixed
    {
        $stream = \fopen('php://temp', 'r+');

        self::assertIsResource($stream);

        return $stream;
    }

    private static function closeStream(mixed $stream): void
    {
        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }

    private static function captureStdout(\Closure $callback): mixed
    {
        \ob_start();

        try {
            $result = $callback();
            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        return $result;
    }

    /**
     * @param class-string $className
     */
    private static function classSource(string $className): string
    {
        $reflection = new \ReflectionClass($className);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }
}
