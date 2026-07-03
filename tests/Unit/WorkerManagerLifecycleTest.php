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

use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerForkFailedException;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Tests\Fake\FakeWorkerManagerDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

final class WorkerManagerLifecycleTest extends TestCase
{
    public function testStartDelegatesToDriverSelectedByResolvedWorkerPoolSpecDriver(): void
    {
        $procDriver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
            pid: 4101,
        );
        $pcntlDriver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PCNTL,
            pid: 4102,
        );

        $manager = self::manager([$procDriver, $pcntlDriver]);

        $procState = $manager->start(self::procSpec());

        self::assertSame(4101, $procState->pid());
        self::assertSame(1, $procDriver->startCalls());
        self::assertSame(0, $pcntlDriver->startCalls());

        $pcntlState = $manager->start(self::pcntlSpec());

        self::assertSame(4102, $pcntlState->pid());
        self::assertSame(1, $procDriver->startCalls());
        self::assertSame(1, $pcntlDriver->startCalls());
    }

    public function testStopDelegatesToDriverSelectedByResolvedWorkerPoolSpecDriver(): void
    {
        $procDriver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
            pid: 4201,
        );
        $pcntlDriver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PCNTL,
            pid: 4202,
        );

        $manager = self::manager([$procDriver, $pcntlDriver]);

        $procSpec = self::procSpec();
        $pcntlSpec = self::pcntlSpec();

        $manager->start($procSpec);
        $manager->start($pcntlSpec);

        $procState = $manager->stop($procSpec);

        self::assertSame(4201, $procState->pid());
        self::assertSame(1, $procDriver->stopCalls());
        self::assertSame(0, $pcntlDriver->stopCalls());

        $pcntlState = $manager->stop($pcntlSpec);

        self::assertSame(4202, $pcntlState->pid());
        self::assertSame(1, $procDriver->stopCalls());
        self::assertSame(1, $pcntlDriver->stopCalls());
    }

    public function testStatusDelegatesToDriverSelectedByResolvedWorkerPoolSpecDriver(): void
    {
        $procDriver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
            pid: 4301,
        );
        $pcntlDriver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PCNTL,
            pid: 4302,
        );

        $manager = self::manager([$procDriver, $pcntlDriver]);

        $procSpec = self::procSpec();
        $pcntlSpec = self::pcntlSpec();

        $manager->start($procSpec);
        $manager->start($pcntlSpec);

        $procState = $manager->status($procSpec);

        self::assertSame(4301, $procState->pid());
        self::assertSame(1, $procDriver->statusCalls());
        self::assertSame(0, $pcntlDriver->statusCalls());

        $pcntlState = $manager->status($pcntlSpec);

        self::assertSame(4302, $pcntlState->pid());
        self::assertSame(1, $procDriver->statusCalls());
        self::assertSame(1, $pcntlDriver->statusCalls());
    }

    public function testUnsupportedSelectedDriverMapsToStartFailed(): void
    {
        $manager = self::manager([
            new FakeWorkerManagerDriver(name: WorkerManagerDriverInterface::DRIVER_PROC),
        ]);

        $exception = self::catchWorkerStartFailed(
            static fn (): WorkerPoolState => $manager->start(self::pcntlSpec()),
        );

        self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());
        self::assertSame(
            'CORETSIA_WORKER_START_FAILED: worker-start-failed',
            $exception->getMessage(),
        );
    }

    public function testUnsupportedDriverSupportMapsToStartFailedWithoutStartingDriver(): void
    {
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
            supported: false,
        );

        $manager = self::manager([$driver]);

        $exception = self::catchWorkerStartFailed(
            static fn (): WorkerPoolState => $manager->start(self::procSpec()),
        );

        self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());
        self::assertSame(0, $driver->startCalls());
    }

    #[DataProvider('provideDeterministicWorkerExceptions')]
    public function testKnownWorkerExceptionsPassThrough(Throwable $expected): void
    {
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
        );
        $driver->throwOnStart($expected);

        $manager = self::manager([$driver]);

        try {
            $manager->start(self::procSpec());

            self::fail('Expected worker exception was not thrown.');
        } catch (Throwable $caught) {
            self::assertSame($expected, $caught);
        }
    }

    /**
     * @param callable(WorkerManager, WorkerPoolSpec, FakeWorkerManagerDriver): WorkerPoolState $operation
     * @param callable(FakeWorkerManagerDriver, WorkerPoolSpec): void $prepare
     */
    #[DataProvider('provideLifecycleOperations')]
    public function testUnknownThrowableIsWrappedIntoStartFailed(
        callable $operation,
        callable $prepare,
    ): void {
        $spec = self::procSpec();
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
        );
        $manager = self::manager([$driver]);

        $prepare($driver, $spec);

        $exception = self::catchWorkerStartFailed(
            static fn (): WorkerPoolState => $operation($manager, $spec, $driver),
        );

        self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());
        self::assertSame('CORETSIA_WORKER_START_FAILED: worker-start-failed', $exception->getMessage());
        self::assertStringNotContainsString('unknown throwable leaked', $exception->getMessage());
    }

    public function testObservabilityFailuresDoNotAlterSuccessfulLifecycleResult(): void
    {
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
            pid: 4401,
        );

        $manager = self::manager(
            drivers: [$driver],
            tracer: new WorkerManagerLifecycleThrowingTracer(),
            meter: new WorkerManagerLifecycleThrowingMeter(),
            logger: new WorkerManagerLifecycleThrowingLogger(),
        );

        $state = $manager->start(self::procSpec());

        self::assertSame(4401, $state->pid());
        self::assertSame(1, $driver->startCalls());
    }

    public function testObservabilityFailuresDoNotAlterFailureLifecycleSemantics(): void
    {
        $expected = WorkerCommunicationFailedException::communicationFailed();
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
        );
        $driver->throwOnStart($expected);

        $manager = self::manager(
            drivers: [$driver],
            tracer: new WorkerManagerLifecycleThrowingTracer(),
            meter: new WorkerManagerLifecycleThrowingMeter(),
            logger: new WorkerManagerLifecycleThrowingLogger(),
        );

        try {
            $manager->start(self::procSpec());

            self::fail('Expected worker exception was not thrown.');
        } catch (WorkerCommunicationFailedException $caught) {
            self::assertSame($expected, $caught);
        }
    }

    public function testMetricLabelsStayLowCardinalityStatusOnly(): void
    {
        $meter = new WorkerManagerLifecycleRecordingMeter();
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
            pid: 4501,
        );

        $manager = self::manager(
            drivers: [$driver],
            meter: $meter,
        );

        $spec = self::procSpec();

        $manager->start($spec);
        $manager->status($spec);
        $manager->stop($spec);

        self::assertSame(
            [
                [
                    'name' => 'worker.process_total',
                    'delta' => 1,
                    'labels' => [
                        'status' => 'start_success',
                    ],
                ],
                [
                    'name' => 'worker.process_total',
                    'delta' => 1,
                    'labels' => [
                        'status' => 'status_success',
                    ],
                ],
                [
                    'name' => 'worker.process_total',
                    'delta' => 1,
                    'labels' => [
                        'status' => 'stop_success',
                    ],
                ],
            ],
            $meter->increments,
        );

        foreach ($meter->increments as $increment) {
            self::assertSame(['status'], \array_keys($increment['labels']));
        }
    }

    public function testMetricFailureLabelStaysLowCardinalityStatusOnly(): void
    {
        $meter = new WorkerManagerLifecycleRecordingMeter();
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
        );
        $driver->throwOnStart(WorkerCommunicationFailedException::communicationFailed());

        $manager = self::manager(
            drivers: [$driver],
            meter: $meter,
        );

        try {
            $manager->start(self::procSpec());

            self::fail('Expected worker exception was not thrown.');
        } catch (WorkerCommunicationFailedException) {
        }

        self::assertSame(
            [
                [
                    'name' => 'worker.process_total',
                    'delta' => 1,
                    'labels' => [
                        'status' => 'start_failure',
                    ],
                ],
            ],
            $meter->increments,
        );
    }

    public function testLogContextStaysSafeForSuccessfulLifecycleOperations(): void
    {
        $logger = new WorkerManagerLifecycleRecordingLogger();
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
            pid: 4601,
        );

        $manager = self::manager(
            drivers: [$driver],
            logger: $logger,
        );

        $spec = self::procSpec();

        $manager->start($spec);
        $manager->status($spec);
        $manager->stop($spec);

        self::assertSame(
            [
                'worker.process.start',
                'worker.process.status',
                'worker.process.stop',
            ],
            \array_map(
                static fn (array $record): string => $record['message'],
                $logger->records,
            ),
        );

        foreach ($logger->records as $record) {
            self::assertSame('info', $record['level']);
            self::assertSafeLogContext($record['context']);
        }
    }

    public function testLogContextStaysSafeForFailedLifecycleOperation(): void
    {
        $logger = new WorkerManagerLifecycleRecordingLogger();
        $driver = new FakeWorkerManagerDriver(
            name: WorkerManagerDriverInterface::DRIVER_PROC,
        );
        $driver->throwOnStart(new \RuntimeException('unknown throwable leaked /home/coretsia tcp://127.0.0.1:9327'));

        $manager = self::manager(
            drivers: [$driver],
            logger: $logger,
        );

        try {
            $manager->start(self::procSpec());

            self::fail('Expected worker exception was not thrown.');
        } catch (WorkerStartFailedException) {
        }

        self::assertCount(1, $logger->records);
        self::assertSame('worker.process.start', $logger->records[0]['message']);
        self::assertSafeLogContext($logger->records[0]['context']);
        self::assertSame(
            [
                'status',
                'outcome',
                'duration_ms',
            ],
            \array_keys($logger->records[0]['context']),
        );
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function provideDeterministicWorkerExceptions(): iterable
    {
        yield 'not running' => [
            WorkerNotRunningException::notRunning(),
        ];

        yield 'start failed' => [
            WorkerStartFailedException::startFailed(),
        ];

        yield 'fork failed' => [
            WorkerForkFailedException::forkFailed(),
        ];

        yield 'communication failed' => [
            WorkerCommunicationFailedException::communicationFailed(),
        ];
    }

    /**
     * @return iterable<string, array{
     *     callable(WorkerManager, WorkerPoolSpec, FakeWorkerManagerDriver): WorkerPoolState,
     *     callable(FakeWorkerManagerDriver, WorkerPoolSpec): void
     * }>
     */
    public static function provideLifecycleOperations(): iterable
    {
        yield 'start' => [
            static function (
                WorkerManager $manager,
                WorkerPoolSpec $spec,
                FakeWorkerManagerDriver $driver
            ): WorkerPoolState {
                $driver->throwOnStart(new \RuntimeException('unknown throwable leaked'));

                return $manager->start($spec);
            },
            static function (FakeWorkerManagerDriver $_driver, WorkerPoolSpec $_spec): void {
            },
        ];

        yield 'stop' => [
            static function (
                WorkerManager $manager,
                WorkerPoolSpec $spec,
                FakeWorkerManagerDriver $driver
            ): WorkerPoolState {
                $driver->throwOnStop(new \RuntimeException('unknown throwable leaked'));

                return $manager->stop($spec);
            },
            static function (FakeWorkerManagerDriver $driver, WorkerPoolSpec $spec): void {
                $driver->start($spec);
            },
        ];

        yield 'status' => [
            static function (
                WorkerManager $manager,
                WorkerPoolSpec $spec,
                FakeWorkerManagerDriver $driver
            ): WorkerPoolState {
                $driver->throwOnStatus(new \RuntimeException('unknown throwable leaked'));

                return $manager->status($spec);
            },
            static function (FakeWorkerManagerDriver $driver, WorkerPoolSpec $spec): void {
                $driver->start($spec);
            },
        ];
    }

    /**
     * @param iterable<WorkerManagerDriverInterface> $drivers
     */
    private static function manager(
        iterable $drivers,
        ?TracerPortInterface $tracer = null,
        ?MeterPortInterface $meter = null,
        ?LoggerInterface $logger = null,
    ): WorkerManager {
        return new WorkerManager(
            drivers: $drivers,
            tracer: $tracer ?? new WorkerManagerLifecycleRecordingTracer(),
            meter: $meter ?? new WorkerManagerLifecycleRecordingMeter(),
            logger: $logger ?? new WorkerManagerLifecycleRecordingLogger(),
            stopwatch: new Stopwatch(),
        );
    }

    private static function procSpec(): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig([
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
            ]),
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }

    private static function pcntlSpec(): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig([
                'driver' => 'pcntl',
                'control' => [
                    'transport' => 'unix',
                ],
            ]),
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
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
                'max_requests' => 3,
                'task_type' => 'queue',
                'socket_path' => 'var/tmp/worker.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9327,
                ],
                'state_path' => 'var/tmp/worker.state.json',
                'stop_flag_path' => 'var/tmp/worker.stop',
                'stop_timeout_ms' => 3000,
            ],
            $overrides,
        );
    }

    /**
     * @param callable(): WorkerPoolState $callback
     */
    private static function catchWorkerStartFailed(callable $callback): WorkerStartFailedException
    {
        try {
            $callback();
        } catch (WorkerStartFailedException $exception) {
            return $exception;
        }

        self::fail('Expected WorkerStartFailedException was not thrown.');
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function assertSafeLogContext(array $context): void
    {
        $allowedKeys = [
            'status',
            'outcome',
            'duration_ms',
            'pid',
            'worker_count',
            'driver',
            'control_transport',
            'endpoint_hash',
        ];

        foreach (\array_keys($context) as $key) {
            self::assertContains($key, $allowedKeys);
        }

        self::assertArrayHasKey('status', $context);
        self::assertArrayHasKey('outcome', $context);
        self::assertArrayHasKey('duration_ms', $context);

        self::assertIsString($context['status']);
        self::assertContains($context['status'], [
            'start_success',
            'start_failure',
            'stop_success',
            'stop_failure',
            'status_success',
            'status_failure',
        ]);

        self::assertIsString($context['outcome']);
        self::assertContains($context['outcome'], [
            'success',
            'failure',
        ]);

        self::assertIsInt($context['duration_ms']);
        self::assertGreaterThanOrEqual(0, $context['duration_ms']);

        if (isset($context['pid'])) {
            self::assertIsInt($context['pid']);
            self::assertGreaterThan(0, $context['pid']);
        }

        if (isset($context['worker_count'])) {
            self::assertIsInt($context['worker_count']);
            self::assertGreaterThan(0, $context['worker_count']);
        }

        if (isset($context['driver'])) {
            self::assertContains($context['driver'], ['pcntl', 'proc']);
        }

        if (isset($context['control_transport'])) {
            self::assertContains($context['control_transport'], ['unix', 'tcp']);
        }

        if (isset($context['endpoint_hash'])) {
            self::assertIsString($context['endpoint_hash']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $context['endpoint_hash']);
        }

        $encoded = \json_encode($context, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertIsString($encoded);
        self::assertStringNotContainsString('/home/', $encoded);
        self::assertStringNotContainsString('/Users/', $encoded);
        self::assertStringNotContainsString('var/tmp/worker.sock', $encoded);
        self::assertStringNotContainsString('tcp://', $encoded);
        self::assertStringNotContainsString('127.0.0.1:9327', $encoded);
        self::assertStringNotContainsString('payload', $encoded);
        self::assertStringNotContainsString('Authorization', $encoded);
        self::assertStringNotContainsString('Bearer', $encoded);
        self::assertStringNotContainsString('token', \strtolower($encoded));
        self::assertStringNotContainsString('unknown throwable leaked', $encoded);
    }
}

final class WorkerManagerLifecycleRecordingMeter implements MeterPortInterface
{
    /**
     * @var list<array{name: string, delta: int, labels: array<string, mixed>}>
     */
    public array $increments = [];

    /**
     * @var list<array{name: string, value: int, labels: array<string, mixed>}>
     */
    public array $observations = [];

    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
        $this->increments[] = [
            'name' => $name,
            'delta' => $delta,
            'labels' => $labels,
        ];
    }

    public function observe(string $name, int $value, array $labels = []): void
    {
        $this->observations[] = [
            'name' => $name,
            'value' => $value,
            'labels' => $labels,
        ];
    }
}

final class WorkerManagerLifecycleThrowingMeter implements MeterPortInterface
{
    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
        throw new \RuntimeException('meter failure must be swallowed');
    }

    public function observe(string $name, int $value, array $labels = []): void
    {
        throw new \RuntimeException('meter failure must be swallowed');
    }
}

final class WorkerManagerLifecycleRecordingTracer implements TracerPortInterface
{
    /**
     * @var list<WorkerManagerLifecycleRecordingSpan>
     */
    public array $spans = [];

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        $span = new WorkerManagerLifecycleRecordingSpan($name);
        $span->setAttributes($attributes);

        $this->spans[] = $span;

        return $span;
    }

    public function inSpan(string $name, callable $callback, array $attributes = []): mixed
    {
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

final class WorkerManagerLifecycleThrowingTracer implements TracerPortInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        throw new \RuntimeException('tracer failure must be swallowed');
    }

    public function inSpan(string $name, callable $callback, array $attributes = []): mixed
    {
        throw new \RuntimeException('tracer failure must be swallowed');
    }

    public function currentSpan(): ?SpanInterface
    {
        throw new \RuntimeException('tracer failure must be swallowed');
    }
}

final class WorkerManagerLifecycleRecordingSpan implements SpanInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $attributes = [];

    public bool $ended = false;

    public function __construct(
        private readonly string $name,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if (\is_string($key)) {
                $this->attributes[$key] = $value;
            }
        }
    }

    public function addEvent(string $name, array $attributes = []): void
    {
    }

    public function recordException(Throwable $throwable, array $attributes = []): void
    {
    }

    public function end(): void
    {
        $this->ended = true;
    }
}

final class WorkerManagerLifecycleRecordingLogger implements LoggerInterface
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}

final class WorkerManagerLifecycleThrowingLogger implements LoggerInterface
{
    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->throw();
    }

    private function throw(): never
    {
        throw new \RuntimeException('logger failure must be swallowed');
    }
}
