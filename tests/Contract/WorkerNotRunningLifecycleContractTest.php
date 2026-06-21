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

use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WorkerNotRunningLifecycleContractTest extends TestCase
{
    private string $skeletonRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skeletonRoot = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/coretsia-worker-not-running-lifecycle-'
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

    public function testWorkerStateStoreReadThrowsNotRunningWhenStateMarkerIsMissing(): void
    {
        $spec = self::workerSpec();

        try {
            self::stateStore()->read($this->skeletonRoot, $spec);

            self::fail('Expected missing worker state marker to throw WorkerNotRunningException.');
        } catch (WorkerNotRunningException $exception) {
            self::assertNotRunningException($exception);
            self::assertSafeDiagnostics($exception, $this->skeletonRoot, $spec);
        }
    }

    public function testMissingWorkerStateMarkerReturnsExpectedErrorCodeAndReason(): void
    {
        $exception = WorkerNotRunningException::notRunning();

        self::assertSame('CORETSIA_WORKER_NOT_RUNNING', $exception->errorCode());
        self::assertSame('not_running', $exception->reason());
        self::assertSame('CORETSIA_WORKER_NOT_RUNNING: not_running', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[DataProvider('provideExistingInvalidStateCases')]
    public function testExistingInvalidWorkerStateDoesNotMapToNotRunningException(string $case): void
    {
        if ($case === 'unreadable-file') {
            $spec = self::workerSpec(statePath: 'var/tmp/unreadable.worker.state.json');
            $path = self::absoluteStatePath($this->skeletonRoot, $spec);

            self::writeStateBytes($path, self::validStateJson());

            \chmod($path, 0000);

            try {
                self::assertFalse(
                    \is_readable($path),
                    'Unreadable worker state case requires chmod(0000) to make the file unreadable.',
                );

                self::stateStore()->read($this->skeletonRoot, $spec);

                self::fail('Expected unreadable worker state marker to fail as invalid state.');
            } catch (WorkerNotRunningException) {
                self::fail('Existing but unreadable worker state must not map to WorkerNotRunningException.');
            } catch (WorkerStartFailedException $exception) {
                self::assertInvalidStateException($exception);
                self::assertSafeDiagnostics($exception, $this->skeletonRoot, $spec);
            } finally {
                @\chmod($path, 0600);
            }

            return;
        }

        if ($case === 'non-file-state-path') {
            $spec = self::workerSpec(statePath: 'var/tmp/non-file.worker.state.json');
            $path = self::absoluteStatePath($this->skeletonRoot, $spec);

            if (!\mkdir($path, 0777, true) && !\is_dir($path)) {
                self::fail('Failed to create non-file worker state path.');
            }

            self::assertInvalidStateNotNotRunning(
                callback: fn (): WorkerPoolState => self::stateStore()->read($this->skeletonRoot, $spec),
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );

            return;
        }

        if ($case === 'invalid-json') {
            $spec = self::workerSpec(statePath: 'var/tmp/invalid-json.worker.state.json');

            self::writeStateBytes(
                path: self::absoluteStatePath($this->skeletonRoot, $spec),
                bytes: "{\"version\":1,\n",
            );

            self::assertInvalidStateNotNotRunning(
                callback: fn (): WorkerPoolState => self::stateStore()->read($this->skeletonRoot, $spec),
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );

            return;
        }

        if ($case === 'schema-drift-extra-key') {
            $spec = self::workerSpec(statePath: 'var/tmp/schema-drift.worker.state.json');

            $state = self::validStateArray();
            $state['extra_key'] = 'forbidden';

            self::writeStateBytes(
                path: self::absoluteStatePath($this->skeletonRoot, $spec),
                bytes: self::encodeState($state),
            );

            self::assertInvalidStateNotNotRunning(
                callback: fn (): WorkerPoolState => self::stateStore()->read($this->skeletonRoot, $spec),
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );

            return;
        }

        if ($case === 'invalid-values') {
            $spec = self::workerSpec(statePath: 'var/tmp/invalid-values.worker.state.json');

            $state = self::validStateArray();
            $state['pid'] = 0;
            $state['endpoint_hash'] = 'not-a-sha256-hash';

            self::writeStateBytes(
                path: self::absoluteStatePath($this->skeletonRoot, $spec),
                bytes: self::encodeState($state),
            );

            self::assertInvalidStateNotNotRunning(
                callback: fn (): WorkerPoolState => self::stateStore()->read($this->skeletonRoot, $spec),
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );

            return;
        }

        self::fail('Unknown existing invalid worker state case: ' . $case);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function provideExistingInvalidStateCases(): array
    {
        $cases = [
            'non-file-state-path' => ['non-file-state-path'],
            'invalid-json' => ['invalid-json'],
            'schema-drift-extra-key' => ['schema-drift-extra-key'],
            'invalid-values' => ['invalid-values'],
        ];

        if (self::chmod0000CanMakeFileUnreadable()) {
            $cases['unreadable-file'] = ['unreadable-file'];
        }

        return $cases;
    }

    public function testWorkerManagerStopPassesThroughWorkerNotRunningException(): void
    {
        $spec = self::workerSpec();
        $expected = WorkerNotRunningException::notRunning();

        $manager = self::workerManager(
            new WorkerNotRunningLifecycleDriver(
                stopException: $expected,
            ),
        );

        try {
            $manager->stop($spec);

            self::fail('Expected WorkerManager::stop() to pass through WorkerNotRunningException.');
        } catch (WorkerNotRunningException $exception) {
            self::assertSame($expected, $exception);
            self::assertNotRunningException($exception);
            self::assertSafeDiagnostics($exception, $this->skeletonRoot, $spec);
        }
    }

    public function testWorkerManagerStatusPassesThroughWorkerNotRunningException(): void
    {
        $spec = self::workerSpec();
        $expected = WorkerNotRunningException::notRunning();

        $manager = self::workerManager(
            new WorkerNotRunningLifecycleDriver(
                statusException: $expected,
            ),
        );

        try {
            $manager->status($spec);

            self::fail('Expected WorkerManager::status() to pass through WorkerNotRunningException.');
        } catch (WorkerNotRunningException $exception) {
            self::assertSame($expected, $exception);
            self::assertNotRunningException($exception);
            self::assertSafeDiagnostics($exception, $this->skeletonRoot, $spec);
        }
    }

    private static function stateStore(): WorkerStateStore
    {
        return new WorkerStateStore(
            encoder: new StableJsonEncoder(),
            decoder: new StableJsonDecoder(),
        );
    }

    private static function workerSpec(string $statePath = 'var/tmp/worker.state.json'): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: [
                'enabled' => true,
                'workers' => 2,
                'max_requests' => 100,
                'task_type' => 'queue',
                'socket_path' => 'var/tmp/private-worker.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '10.20.30.40',
                    'port' => 9511,
                ],
                'state_path' => $statePath,
                'stop_flag_path' => 'var/tmp/private-worker.stop',
                'stop_timeout_ms' => 3000,
            ],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }

    /**
     * @return array{
     *     version: int,
     *     pid: int,
     *     worker_count: int,
     *     driver_requested: string,
     *     driver: string,
     *     control_transport_requested: string,
     *     control_transport: string,
     *     endpoint_hash: string
     * }
     */
    private static function validStateArray(): array
    {
        return [
            'version' => 1,
            'pid' => 12345,
            'worker_count' => 2,
            'driver_requested' => 'proc',
            'driver' => 'proc',
            'control_transport_requested' => 'tcp',
            'control_transport' => 'tcp',
            'endpoint_hash' => \hash('sha256', 'tcp:10.20.30.40:9511'),
        ];
    }

    private static function validStateJson(): string
    {
        return self::encodeState(self::validStateArray());
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function encodeState(array $state): string
    {
        return new StableJsonEncoder()->encode($state);
    }

    private static function absoluteStatePath(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/') . '/' . $spec->statePath();
    }

    private static function writeStateBytes(string $path, string $bytes): void
    {
        $dir = \dirname($path);

        if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            self::fail('Failed to create worker state directory.');
        }

        if (\file_put_contents($path, $bytes, \LOCK_EX) === false) {
            self::fail('Failed to write worker state fixture.');
        }
    }

    private static function chmod0000CanMakeFileUnreadable(): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $dir = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/coretsia-worker-chmod-probe-'
            . \bin2hex(\random_bytes(8));

        if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            return false;
        }

        $file = $dir . '/state.json';

        try {
            if (\file_put_contents($file, '{}', \LOCK_EX) === false) {
                return false;
            }

            @\chmod($file, 0000);

            return !\is_readable($file);
        } finally {
            @\chmod($file, 0600);
            @\unlink($file);
            @\rmdir($dir);
        }
    }

    /**
     * @param callable(): mixed $callback
     */
    private static function assertInvalidStateNotNotRunning(
        callable $callback,
        string $skeletonRoot,
        WorkerPoolSpec $spec,
    ): void {
        try {
            $callback();

            self::fail('Expected existing invalid worker state marker to fail.');
        } catch (WorkerNotRunningException) {
            self::fail('Existing invalid worker state marker must not map to WorkerNotRunningException.');
        } catch (WorkerStartFailedException $exception) {
            self::assertInvalidStateException($exception);
            self::assertSafeDiagnostics($exception, $skeletonRoot, $spec);
        }
    }

    private static function assertNotRunningException(WorkerNotRunningException $exception): void
    {
        self::assertSame('CORETSIA_WORKER_NOT_RUNNING', $exception->errorCode());
        self::assertSame('not_running', $exception->reason());
        self::assertSame('CORETSIA_WORKER_NOT_RUNNING: not_running', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    private static function assertInvalidStateException(WorkerStartFailedException $exception): void
    {
        self::assertSame('CORETSIA_WORKER_START_FAILED', $exception->errorCode());
        self::assertSame('invalid_state', $exception->reason());
        self::assertSame('CORETSIA_WORKER_START_FAILED: invalid_state', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    private static function assertSafeDiagnostics(
        \Throwable $exception,
        string $skeletonRoot,
        WorkerPoolSpec $spec,
    ): void {
        $diagnostics = $exception->getMessage();

        foreach (
            [
                $skeletonRoot,
                \str_replace('/', '\\', $skeletonRoot),
                $spec->statePath(),
                $spec->socketPath(),
                $spec->tcpHost(),
                (string)$spec->tcpPort(),
                'tcp:' . $spec->tcpHost() . ':' . $spec->tcpPort(),
                'tcp://' . $spec->tcpHost() . ':' . $spec->tcpPort(),
                self::absoluteStatePath($skeletonRoot, $spec),
                \str_replace('/', '\\', self::absoluteStatePath($skeletonRoot, $spec)),
                'Permission denied',
                'No such file',
                'failed to open stream',
                'file_get_contents',
                'var/tmp',
                'PATH=',
                'HOME=',
                'APP_ENV',
                'payload',
                'headers',
                'Authorization',
                'authorization',
                'cookie',
                'secret',
                'token',
                'bearer',
                'trace',
                '#0 ',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $diagnostics,
                'Worker lifecycle diagnostics must not expose unsafe fragment: ' . $forbidden,
            );
        }
    }

    private static function workerManager(WorkerManagerDriverInterface $driver): WorkerManager
    {
        return new WorkerManager(
            drivers: [$driver],
            tracer: new WorkerNotRunningLifecycleSilentTracer(),
            meter: new WorkerNotRunningLifecycleSilentMeter(),
            logger: new WorkerNotRunningLifecycleSilentLogger(),
            stopwatch: new Stopwatch(),
        );
    }

    private static function removePath(string $path): void
    {
        if ($path === '' || !\file_exists($path)) {
            return;
        }

        if (\is_file($path) || \is_link($path)) {
            @\chmod($path, 0600);
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

final readonly class WorkerNotRunningLifecycleDriver implements WorkerManagerDriverInterface
{
    public function __construct(
        private ?WorkerNotRunningException $stopException = null,
        private ?WorkerNotRunningException $statusException = null,
    ) {
    }

    public function name(): string
    {
        return self::DRIVER_PROC;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        return $spec->driver() === self::DRIVER_PROC;
    }

    public function start(WorkerPoolSpec $spec): WorkerPoolState
    {
        throw WorkerStartFailedException::startFailed();
    }

    public function stop(WorkerPoolSpec $spec): WorkerPoolState
    {
        throw $this->stopException ?? WorkerNotRunningException::notRunning();
    }

    public function status(WorkerPoolSpec $spec): WorkerPoolState
    {
        throw $this->statusException ?? WorkerNotRunningException::notRunning();
    }
}

final class WorkerNotRunningLifecycleSilentTracer implements TracerPortInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new WorkerNotRunningLifecycleSilentSpan($name);
    }

    public function inSpan(
        string $name,
        callable $callback,
        array $attributes = [],
    ): mixed {
        $span = $this->startSpan($name, $attributes);

        try {
            return $callback($span);
        } finally {
            $span->end();
        }
    }

    public function currentSpan(): ?SpanInterface
    {
        return null;
    }
}

final readonly class WorkerNotRunningLifecycleSilentSpan implements SpanInterface
{
    public function __construct(
        private string $name,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function setAttribute(string $key, mixed $value): void
    {
    }

    public function setAttributes(array $attributes): void
    {
    }

    public function addEvent(string $name, array $attributes = []): void
    {
    }

    public function recordException(\Throwable $throwable, array $attributes = []): void
    {
    }

    public function end(): void
    {
    }
}

final class WorkerNotRunningLifecycleSilentMeter implements MeterPortInterface
{
    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
    }

    public function observe(string $name, int $value, array $labels = []): void
    {
    }
}

final class WorkerNotRunningLifecycleSilentLogger implements LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void
    {
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
    }
}
