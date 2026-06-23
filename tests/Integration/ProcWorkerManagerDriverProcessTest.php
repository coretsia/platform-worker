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
use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Manager\Driver\ProcWorkerManagerDriver;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\TestCase;

final class ProcWorkerManagerDriverProcessTest extends TestCase
{
    private string $skeletonRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skeletonRoot = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/coretsia-proc-worker-manager-driver-'
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

    public function testStartUsesProcOpenPathWithoutRequiringPcntlAndLaunchesConfiguredWorkers(): void
    {
        $argvLog = $this->skeletonRoot . '/var/tmp/worker-argv.log';
        $workerScript = $this->writeWorkerRecorderScript();
        $spec = self::workerSpec([
            'workers' => 3,
            'max_requests' => 17,
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => self::unusedTcpPort(),
            ],
            'stop_timeout_ms' => 0,
        ]);

        $driver = $this->driver([
            \PHP_BINARY,
            $workerScript,
            '--fixture-base=1',
        ]);

        $oldArgvLog = \getenv('CORETSIA_PROC_WORKER_TEST_ARGV_LOG');

        \putenv('CORETSIA_PROC_WORKER_TEST_ARGV_LOG=' . $argvLog);

        try {
            $state = self::captureStdout(
                static fn (): WorkerPoolState => $driver->start($spec),
            );

            self::assertInstanceOf(WorkerPoolState::class, $state);
            self::assertSame('proc', $state->driver());
            self::assertSame('proc', $state->driverRequested());
            self::assertSame(3, $state->workerCount());

            $argvLines = self::waitForArgvLogLines($argvLog, 3);

            self::assertCount(3, $argvLines);

            $argvByWorkerIndex = [];

            foreach ($argvLines as $line) {
                $argv = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

                self::assertIsArray($argv);

                /** @var list<string> $argv */
                $workerIndex = self::workerIndexFromArgv($argv);
                $argvByWorkerIndex[$workerIndex] = $argv;
            }

            \ksort($argvByWorkerIndex, \SORT_NUMERIC);

            self::assertSame([0, 1, 2], \array_keys($argvByWorkerIndex));

            foreach ($argvByWorkerIndex as $workerIndex => $argv) {
                self::assertSame(
                    [
                        $workerScript,
                        '--fixture-base=1',
                        '--coretsia-worker-index=' . $workerIndex,
                        '--coretsia-worker-count=3',
                        '--coretsia-worker-max-requests=17',
                        '--coretsia-worker-task-type=queue',
                        '--coretsia-worker-driver=proc',
                        '--coretsia-worker-config=var/cache/worker/config.php',
                        '--coretsia-worker-container=var/cache/worker/container.php',
                    ],
                    $argv,
                );
            }

            self::assertSame(
                [
                    '--coretsia-worker-index=0',
                    '--coretsia-worker-count=3',
                    '--coretsia-worker-max-requests=17',
                    '--coretsia-worker-task-type=queue',
                    '--coretsia-worker-driver=proc',
                    '--coretsia-worker-config=var/cache/worker/config.php',
                    '--coretsia-worker-container=var/cache/worker/container.php',
                ],
                \array_slice($argvByWorkerIndex[0], 2),
            );

            self::assertPersistedStateMatchesReturnedState(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
                state: $state,
            );
        } finally {
            self::restoreEnv(
                name: 'CORETSIA_PROC_WORKER_TEST_ARGV_LOG',
                oldValue: $oldArgvLog,
            );

            self::stopStartedDriver($driver, $spec, $this->skeletonRoot);
        }
    }

    public function testStartPersistsStateThroughWorkerStateStore(): void
    {
        $argvLog = $this->skeletonRoot . '/var/tmp/state-worker-argv.log';
        $workerScript = $this->writeWorkerRecorderScript();

        $spec = self::workerSpec([
            'workers' => 1,
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => self::unusedTcpPort(),
            ],
            'state_path' => 'var/tmp/persisted.worker.state.json',
            'stop_timeout_ms' => 0,
        ]);

        $driver = $this->driver([
            \PHP_BINARY,
            $workerScript,
        ]);

        $oldArgvLog = \getenv('CORETSIA_PROC_WORKER_TEST_ARGV_LOG');

        \putenv('CORETSIA_PROC_WORKER_TEST_ARGV_LOG=' . $argvLog);

        try {
            $state = $driver->start($spec);

            self::waitForArgvLogLines($argvLog, 1);

            $statePath = self::statePath($this->skeletonRoot, $spec);

            self::assertFileExists($statePath);

            $read = self::stateStore()->read(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );

            self::assertSame($state->toArray(), $read->toArray());

            $bytes = \file_get_contents($statePath);

            self::assertIsString($bytes);
            self::assertSame(
                self::encoder()->encode($state->toArray()),
                $bytes,
            );
        } finally {
            self::restoreEnv(
                name: 'CORETSIA_PROC_WORKER_TEST_ARGV_LOG',
                oldValue: $oldArgvLog,
            );

            self::stopStartedDriver($driver, $spec, $this->skeletonRoot);
        }
    }

    public function testStopWritesStopFlagAndSendsPayloadFreeStopRequestThroughWorkerSocketServer(): void
    {
        $argvLog = $this->skeletonRoot . '/var/tmp/stop-worker-argv.log';
        $workerScript = $this->writeWorkerRecorderScript();
        $socket = new WorkerSocketServer();

        $spec = self::workerSpec([
            'workers' => 1,
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => self::unusedTcpPort(),
            ],
            'stop_flag_path' => 'var/tmp/worker.stop',
            'stop_timeout_ms' => 0,
        ]);

        $driver = $this->driver([
            \PHP_BINARY,
            $workerScript,
        ]);

        $oldArgvLog = \getenv('CORETSIA_PROC_WORKER_TEST_ARGV_LOG');

        \putenv('CORETSIA_PROC_WORKER_TEST_ARGV_LOG=' . $argvLog);

        $server = null;
        $accepted = null;

        try {
            $driver->start($spec);

            self::waitForArgvLogLines($argvLog, 1);

            $server = $socket->listen($this->skeletonRoot, $spec);

            $state = self::captureStdout(
                static fn (): WorkerPoolState => $driver->stop($spec),
            );

            self::assertSame('proc', $state->driver());

            $accepted = $socket->accept($server, 1);

            self::assertSame(
                WorkerSocketServer::OPERATION_STOP,
                $socket->readRequest($accepted),
            );

            self::assertFileExists(self::stopFlagPath($this->skeletonRoot, $spec));
            self::assertSame("stop\n", self::readFile(self::stopFlagPath($this->skeletonRoot, $spec)));
        } finally {
            self::restoreEnv(
                name: 'CORETSIA_PROC_WORKER_TEST_ARGV_LOG',
                oldValue: $oldArgvLog,
            );

            self::closeQuietly($socket, $accepted);
            self::closeQuietly($socket, $server);
        }
    }

    public function testProcessStartFailureMapsToStartFailed(): void
    {
        $spec = self::workerSpec([
            'workers' => 1,
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => self::unusedTcpPort(),
            ],
            'stop_timeout_ms' => 0,
        ]);

        $missingExecutable = $this->skeletonRoot . '/missing-worker-executable';

        $driver = $this->driver([
            $missingExecutable,
        ]);

        try {
            $driver->start($spec);

            self::fail('Expected WorkerStartFailedException was not thrown.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
            self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());
            self::assertSame(
                WorkerStartFailedException::ERROR_CODE . ': ' . WorkerStartFailedException::REASON_START_FAILED,
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($missingExecutable, $exception->getMessage());
            self::assertStringNotContainsString(\basename($missingExecutable), $exception->getMessage());
        }
    }

    public function testCommunicationFailureMapsToCommunicationFailed(): void
    {
        $port = self::unusedTcpPort();
        $spec = self::workerSpec([
            'workers' => 1,
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => $port,
            ],
            'stop_timeout_ms' => 0,
        ]);

        $state = self::stateStore()->createState($spec, 12345);

        self::stateStore()->write(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        $driver = $this->driver([
            \PHP_BINARY,
            $this->writeWorkerRecorderScript(),
        ]);

        try {
            $driver->stop($spec);

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
            self::assertStringNotContainsString('127.0.0.1', $exception->getMessage());
            self::assertStringNotContainsString((string)$port, $exception->getMessage());
            self::assertStringNotContainsString('tcp://', $exception->getMessage());
        }
    }

    public function testProcDriverProcessBoundaryHasNoPcntlTaskPlatformOrOutputSideEffects(): void
    {
        \ob_start();

        try {
            $source = self::classSource(ProcWorkerManagerDriver::class);

            self::assertStringNotContainsString('pcntl_', $source);
            self::assertStringNotContainsString('ApplicationWorker', $source);
            self::assertStringNotContainsString('KernelRuntimeInterface', $source);
            self::assertStringNotContainsString('TaskFactoryInternalInterface', $source);

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

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);
    }

    private function writeWorkerRecorderScript(): string
    {
        $script = $this->skeletonRoot . '/var/tmp/proc-worker-recorder.php';
        $dir = \dirname($script);

        if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            self::fail('Failed to create temporary worker script directory.');
        }

        $bytes = <<<'PHP'
<?php

declare(strict_types=1);

$argvLog = getenv('CORETSIA_PROC_WORKER_TEST_ARGV_LOG');

if (is_string($argvLog) && $argvLog !== '') {
    file_put_contents(
        $argvLog,
        json_encode($_SERVER['argv'] ?? [], JSON_THROW_ON_ERROR) . "\n",
        FILE_APPEND | LOCK_EX,
    );
}

usleep(5_000_000);
PHP;

        if (\file_put_contents($script, $bytes) === false) {
            self::fail('Failed to write temporary worker script.');
        }

        return $script;
    }

    /**
     * @param list<non-empty-string> $workerCommand
     */
    private function driver(array $workerCommand): ProcWorkerManagerDriver
    {
        return new ProcWorkerManagerDriver(
            skeletonRoot: $this->skeletonRoot,
            stateStore: self::stateStore(),
            controlChannel: new WorkerSocketServer(),
            workerCommand: $workerCommand,
            configArtifactPath: 'var/cache/worker/config.php',
            containerArtifactPath: 'var/cache/worker/container.php',
        );
    }

    private static function stateStore(): WorkerStateStore
    {
        return new WorkerStateStore(
            encoder: self::encoder(),
            decoder: self::decoder(),
        );
    }

    private static function encoder(): StableJsonEncoder
    {
        return new StableJsonEncoder();
    }

    private static function decoder(): StableJsonDecoder
    {
        return new StableJsonDecoder();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function workerSpec(array $overrides = []): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig($overrides),
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
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
                'workers' => 1,
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
                'stop_timeout_ms' => 0,
            ],
            $overrides,
        );
    }

    private static function assertPersistedStateMatchesReturnedState(
        string $skeletonRoot,
        WorkerPoolSpec $spec,
        WorkerPoolState $state,
    ): void {
        $statePath = self::statePath($skeletonRoot, $spec);

        self::assertFileExists($statePath);

        $read = self::stateStore()->read(
            skeletonRoot: $skeletonRoot,
            spec: $spec,
        );

        self::assertSame($state->toArray(), $read->toArray());

        $bytes = \file_get_contents($statePath);

        self::assertIsString($bytes);
        self::assertSame(
            self::encoder()->encode($state->toArray()),
            $bytes,
        );
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
     * @return list<string>
     */
    private static function waitForArgvLogLines(string $argvLog, int $expectedCount): array
    {
        $deadline = \microtime(true) + 3.0;

        do {
            if (\is_file($argvLog)) {
                $bytes = self::tryReadFile($argvLog);

                if ($bytes !== null) {
                    $lines = \array_values(
                        \array_filter(
                            \explode("\n", \trim($bytes)),
                            static fn (string $line): bool => $line !== '',
                        ),
                    );

                    if (\count($lines) >= $expectedCount) {
                        return $lines;
                    }
                }
            }

            \usleep(10_000);
        } while (\microtime(true) < $deadline);

        self::fail('Timed out waiting for proc worker argv log.');
    }

    /**
     * @param list<string> $argv
     */
    private static function workerIndexFromArgv(array $argv): int
    {
        foreach ($argv as $part) {
            if (\str_starts_with($part, '--coretsia-worker-index=')) {
                $index = (int)\substr($part, \strlen('--coretsia-worker-index='));

                self::assertGreaterThanOrEqual(0, $index);

                return $index;
            }
        }

        self::fail('Worker index argument was not found.');
    }

    private static function stopStartedDriver(
        ProcWorkerManagerDriver $driver,
        WorkerPoolSpec $spec,
        string $skeletonRoot,
    ): void {
        $socket = new WorkerSocketServer();
        $server = null;
        $accepted = null;

        try {
            $server = $socket->listen($skeletonRoot, $spec);

            $driver->stop($spec);

            $accepted = $socket->accept($server, 1);

            self::assertSame(
                WorkerSocketServer::OPERATION_STOP,
                $socket->readRequest($accepted),
            );
        } catch (\Throwable) {
            // Cleanup helper must not hide the original assertion path when it
            // is called from a finally block after the main assertions already
            // ran.
        } finally {
            self::closeQuietly($socket, $accepted);
            self::closeQuietly($socket, $server);
        }
    }

    private static function statePath(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/') . '/' . $spec->statePath();
    }

    private static function stopFlagPath(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/') . '/' . $spec->stopFlagPath();
    }

    private static function readFile(string $path): string
    {
        $bytes = \file_get_contents($path);

        self::assertIsString($bytes);

        return $bytes;
    }

    private static function tryReadFile(string $path): ?string
    {
        /*
         * On Windows, a child worker may hold an exclusive file lock while appending
         * the argv log. file_get_contents() can then transiently fail with EACCES.
         *
         * This helper is used only from polling test code, where a temporary read
         * failure means "retry until deadline", not "test failed".
         */
        $bytes = @\file_get_contents($path);

        if (!\is_string($bytes)) {
            return null;
        }

        return $bytes;
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

    private static function restoreEnv(string $name, string|false $oldValue): void
    {
        if ($oldValue === false) {
            \putenv($name);

            return;
        }

        \putenv($name . '=' . $oldValue);
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

        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }

        $items = \scandir($path);

        if (!\is_array($items)) {
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
