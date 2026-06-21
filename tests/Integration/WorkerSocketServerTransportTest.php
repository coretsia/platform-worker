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

use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerSocketServerTransportTest extends TestCase
{
    private string $skeletonRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skeletonRoot = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/coretsia-worker-socket-server-'
            . \bin2hex(\random_bytes(8));

        if (!\mkdir($this->skeletonRoot, 0777, true) && !\is_dir($this->skeletonRoot)) {
            self::fail('Failed to create temporary skeleton root.');
        }
    }

    protected function tearDown(): void
    {
        self::removePath($this->skeletonRoot);

        parent::tearDown();
    }

    #[DataProvider('provideControlTransportAddressHandlingCases')]
    public function testControlTransportAddressHandlingDoesNotExposeRawEndpointInPublicDiagnostics(
        string $transport
    ): void {
        if ($transport === 'unix') {
            $socket = new WorkerSocketServer();
            $spec = self::unixSpec('var/tmp/worker.sock');

            $server = null;
            $client = null;
            $accepted = null;

            try {
                $rawSocketPath = self::absoluteSocketPath($this->skeletonRoot, $spec);

                self::assertFileDoesNotExist(
                    $rawSocketPath,
                    'Unix control transport socket path MUST NOT exist before listen.',
                );

                $server = $socket->listen($this->skeletonRoot, $spec);

                self::assertFileExists(
                    $rawSocketPath,
                    'Unix control transport MUST create the configured worker socket path.',
                );

                $client = $socket->connect($this->skeletonRoot, $spec, 1);
                $accepted = $socket->accept($server, 1);

                $socket->sendStartRequest($client);

                self::assertSame(WorkerSocketServer::OPERATION_START, $socket->readRequest($accepted));
            } finally {
                self::closeQuietly($socket, $accepted);
                self::closeQuietly($socket, $client);
                self::closeQuietly($socket, $server);
            }

            $missingSpec = self::unixSpec('var/tmp/missing-control.sock');
            $rawSocketPath = self::absoluteSocketPath($this->skeletonRoot, $missingSpec);

            self::assertCommunicationFailed(
                operation: fn (): mixed => new WorkerSocketServer()->connect(
                    skeletonRoot: $this->skeletonRoot,
                    spec: $missingSpec,
                    timeoutSeconds: 0,
                ),
                forbiddenDiagnostics: [
                    $rawSocketPath,
                    \str_replace('/', '\\', $rawSocketPath),
                    $missingSpec->socketPath(),
                    \basename($missingSpec->socketPath()),
                    'unix:',
                    'unix://',
                ],
            );

            return;
        }

        self::assertSame('tcp', $transport);

        $socket = new WorkerSocketServer();
        $port = self::unusedTcpPort();
        $spec = self::tcpSpec('127.0.0.1', $port);

        $server = null;
        $client = null;
        $accepted = null;

        try {
            $rawSocketPath = self::absoluteSocketPath($this->skeletonRoot, $spec);

            self::assertFileDoesNotExist(
                $rawSocketPath,
                'TCP control transport MUST NOT create the configured worker socket path before listen.',
            );

            $server = $socket->listen($this->skeletonRoot, $spec);

            self::assertFileDoesNotExist(
                $rawSocketPath,
                'TCP control transport MUST NOT create the configured worker socket path.',
            );

            $client = $socket->connect($this->skeletonRoot, $spec, 1);
            $accepted = $socket->accept($server, 1);

            $socket->sendStatusRequest($client);

            self::assertSame(WorkerSocketServer::OPERATION_STATUS, $socket->readRequest($accepted));
        } finally {
            self::closeQuietly($socket, $accepted);
            self::closeQuietly($socket, $client);
            self::closeQuietly($socket, $server);
        }

        $closedPort = self::unusedTcpPort();
        $failedSpec = self::tcpSpec('127.0.0.1', $closedPort);

        self::assertCommunicationFailed(
            operation: fn (): mixed => new WorkerSocketServer()->connect(
                skeletonRoot: $this->skeletonRoot,
                spec: $failedSpec,
                timeoutSeconds: 1,
            ),
            forbiddenDiagnostics: [
                '127.0.0.1',
                (string)$closedPort,
                'tcp:',
                'tcp://',
                '127.0.0.1:' . $closedPort,
            ],
        );
    }

    /**
     * @return array<string, array{0:'tcp'|'unix'}>
     */
    public static function provideControlTransportAddressHandlingCases(): array
    {
        $cases = [
            'tcp' => ['tcp'],
        ];

        if (\in_array('unix', \stream_get_transports(), true)) {
            $cases['unix'] = ['unix'];
        }

        return $cases;
    }

    public function testStartRequestFrameIsPayloadFree(): void
    {
        self::assertPayloadFreeRequestFrame(
            expectedFrame: WorkerSocketServer::OPERATION_START,
            send: static fn (WorkerSocketServer $socket, mixed $stream): null => $socket->sendStartRequest($stream),
        );
    }

    public function testStopRequestFrameIsPayloadFree(): void
    {
        self::assertPayloadFreeRequestFrame(
            expectedFrame: WorkerSocketServer::OPERATION_STOP,
            send: static fn (WorkerSocketServer $socket, mixed $stream): null => $socket->sendStopRequest($stream),
        );
    }

    public function testStatusRequestFrameIsPayloadFree(): void
    {
        self::assertPayloadFreeRequestFrame(
            expectedFrame: WorkerSocketServer::OPERATION_STATUS,
            send: static fn (WorkerSocketServer $socket, mixed $stream): null => $socket->sendStatusRequest($stream),
        );
    }

    public function testHealthRequestFrameIsPayloadFree(): void
    {
        self::assertPayloadFreeRequestFrame(
            expectedFrame: WorkerSocketServer::OPERATION_HEALTH,
            send: static fn (WorkerSocketServer $socket, mixed $stream): null => $socket->sendHealthRequest($stream),
        );
    }

    public function testBindListenFailuresAreConvertedToCommunicationFailed(): void
    {
        $host = '256.256.256.256';
        $port = 9501;

        $spec = self::tcpSpec($host, $port);

        self::assertCommunicationFailed(
            operation: fn (): mixed => new WorkerSocketServer()->listen(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            ),
            forbiddenDiagnostics: [
                $host,
                (string)$port,
                'tcp:',
                'tcp://',
                $host . ':' . $port,
            ],
        );
    }

    public function testConnectFailuresAreConvertedToCommunicationFailed(): void
    {
        $port = self::unusedTcpPort();
        $spec = self::tcpSpec('127.0.0.1', $port);

        self::assertCommunicationFailed(
            operation: fn (): mixed => new WorkerSocketServer()->connect(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
                timeoutSeconds: 1,
            ),
            forbiddenDiagnostics: [
                '127.0.0.1',
                (string)$port,
                'tcp:',
                'tcp://',
                '127.0.0.1:' . $port,
            ],
        );
    }

    public function testAcceptReadAndWriteProtocolFailuresAreConvertedToCommunicationFailed(): void
    {
        $socket = new WorkerSocketServer();
        $port = self::unusedTcpPort();
        $spec = self::tcpSpec('127.0.0.1', $port);

        $server = null;

        try {
            $server = $socket->listen($this->skeletonRoot, $spec);

            self::assertCommunicationFailed(
                operation: fn (): mixed => $socket->accept($server, 0),
                forbiddenDiagnostics: [],
            );
        } finally {
            self::closeQuietly($socket, $server);
        }

        $emptyReadableStream = \fopen('php://temp', 'r+');
        self::assertIsResource($emptyReadableStream);

        self::assertCommunicationFailed(
            operation: static fn (): mixed => new WorkerSocketServer()->readRequest($emptyReadableStream),
            forbiddenDiagnostics: [],
        );

        \fclose($emptyReadableStream);

        $readOnlyStream = \fopen('php://temp', 'r');
        self::assertIsResource($readOnlyStream);

        try {
            self::assertCommunicationFailed(
                operation: static fn (): mixed => new WorkerSocketServer()->sendStartRequest($readOnlyStream),
                forbiddenDiagnostics: [],
            );
        } finally {
            if (\is_resource($readOnlyStream)) {
                \fclose($readOnlyStream);
            }
        }

        $invalidFrameStream = \fopen('php://temp', 'r+');
        self::assertIsResource($invalidFrameStream);
        self::assertNotFalse(\fwrite($invalidFrameStream, "forbidden frame\n"));
        \rewind($invalidFrameStream);

        try {
            self::assertCommunicationFailed(
                operation: static fn (): mixed => new WorkerSocketServer()->readRequest($invalidFrameStream),
                forbiddenDiagnostics: [
                    'forbidden frame',
                ],
            );
        } finally {
            if (\is_resource($invalidFrameStream)) {
                \fclose($invalidFrameStream);
            }
        }
    }

    public function testTransportBoundaryDoesNotRequireWorkerProcessesPlatformPackagesOrEmitStdoutStderr(): void
    {
        \ob_start();

        try {
            self::assertPayloadFreeRequestFrame(
                expectedFrame: WorkerSocketServer::OPERATION_START,
                send: static fn (WorkerSocketServer $socket, mixed $stream): null => $socket->sendStartRequest($stream),
            );

            self::assertCommunicationFailed(
                operation: static fn (): mixed => new WorkerSocketServer()->readRequest(false),
                forbiddenDiagnostics: [],
            );

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        $source = self::classSource(WorkerSocketServer::class);

        self::assertStringNotContainsString('ApplicationWorker', $source);
        self::assertStringNotContainsString('WorkerManager', $source);
        self::assertStringNotContainsString('TaskFactoryInternalInterface', $source);
        self::assertStringNotContainsString('proc_open(', $source);
        self::assertStringNotContainsString('pcntl_', $source);

        self::assertStringNotContainsString('Platform\\Cli', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Cli', $source);
        self::assertStringNotContainsString('Platform\\Http', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Http', $source);

        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('fwrite(STDOUT', $source);
        self::assertStringNotContainsString('fwrite(STDERR', $source);
        self::assertStringNotContainsString('error_log(', $source);
    }

    /**
     * @param \Closure(WorkerSocketServer, mixed): mixed $send
     */
    private static function assertPayloadFreeRequestFrame(
        string $expectedFrame,
        \Closure $send,
    ): void {
        $stream = \fopen('php://temp', 'r+');

        self::assertIsResource($stream);

        try {
            $socket = new WorkerSocketServer();

            $send($socket, $stream);

            \rewind($stream);
            $bytes = \stream_get_contents($stream);

            self::assertSame($expectedFrame . "\n", $bytes);
            self::assertMatchesRegularExpression('/\A(?:start|stop|status|health)\n\z/', $bytes);
            self::assertStringNotContainsString(' ', $bytes);
            self::assertStringNotContainsString('=', $bytes);
            self::assertStringNotContainsString('{', $bytes);
            self::assertStringNotContainsString('[', $bytes);
            self::assertStringNotContainsString('Authorization', $bytes);
            self::assertStringNotContainsString('Cookie', $bytes);
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    /**
     * @param list<string> $forbiddenDiagnostics
     * @param \Closure(): mixed $operation
     */
    private static function assertCommunicationFailed(
        \Closure $operation,
        array $forbiddenDiagnostics,
    ): void {
        try {
            $operation();

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

            foreach ($forbiddenDiagnostics as $forbidden) {
                if ($forbidden === '') {
                    continue;
                }

                self::assertStringNotContainsString($forbidden, $exception->getMessage());
            }
        }
    }

    private static function unixSpec(string $socketPath): WorkerPoolSpec
    {
        return self::workerSpec(
            overrides: [
                'driver' => 'pcntl',
                'control' => [
                    'transport' => 'unix',
                ],
                'socket_path' => $socketPath,
            ],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );
    }

    private static function tcpSpec(string $host, int $port): WorkerPoolSpec
    {
        return self::workerSpec([
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => $host,
                'port' => $port,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function workerSpec(
        array $overrides = [],
        bool $pcntlForkAvailable = false,
        string $platformFamily = 'Linux',
        bool $unixDomainSocketsSupported = false,
    ): WorkerPoolSpec {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig($overrides),
            pcntlForkAvailable: $pcntlForkAvailable,
            platformFamily: $platformFamily,
            unixDomainSocketsSupported: $unixDomainSocketsSupported,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function workerConfig(array $overrides = []): array
    {
        return \array_replace_recursive(
            [
                'enabled' => true,
                'workers' => 2,
                'max_requests' => 100,
                'task_type' => 'queue',
                'socket_path' => 'var/tmp/worker.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9501,
                ],
                'state_path' => 'var/tmp/worker.state.json',
                'stop_flag_path' => 'var/tmp/worker.stop',
                'stop_timeout_ms' => 3000,
            ],
            $overrides,
        );
    }

    private static function absoluteSocketPath(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/') . '/' . $spec->socketPath();
    }

    /**
     * @return array{0: resource, 1: int}
     */
    private static function reservedTcpPort(): array
    {
        $server = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );

        self::assertIsResource($server);

        $name = \stream_socket_get_name($server, false);

        self::assertIsString($name);

        $port = self::portFromSocketName($name);

        return [$server, $port];
    }

    private static function unusedTcpPort(): int
    {
        [$server, $port] = self::reservedTcpPort();

        \fclose($server);

        return $port;
    }

    private static function portFromSocketName(string $name): int
    {
        $separator = \strrpos($name, ':');

        self::assertIsInt($separator);

        $port = (int)\substr($name, $separator + 1);

        self::assertGreaterThan(0, $port);
        self::assertLessThanOrEqual(65535, $port);

        return $port;
    }

    private static function closeQuietly(WorkerSocketServer $socket, mixed $stream): void
    {
        if (!\is_resource($stream)) {
            return;
        }

        try {
            $socket->close($stream);
        } catch (WorkerCommunicationFailedException) {
        }
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

    private static function removePath(string $path): void
    {
        if ($path === '' || !\file_exists($path)) {
            return;
        }

        if (\is_file($path) || \is_link($path) || !\is_dir($path)) {
            @\unlink($path);

            return;
        }

        $items = @\scandir($path);

        if (!\is_array($items)) {
            @\rmdir($path);

            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            self::removePath($path . '/' . $item);
        }

        @\rmdir($path);
    }
}
