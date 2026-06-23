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

use Coretsia\Contracts\Context\ContextAccessorInterface;
use Coretsia\Contracts\Context\ContextKeys;
use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use PHPUnit\Framework\TestCase;

final class ApplicationWorkerTest extends TestCase
{
    public function testRunOneObtainsTaskWorkOnlyThroughTaskFactoryAndPassesSpecTaskTypeToKernelRuntime(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $taskFactory = new ApplicationWorkerRecordingTaskFactory(
            supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
            operationId: TaskFactoryInternalInterface::TASK_TYPE_HTTP,
            result: 'task-result',
        );

        $kernelRuntime = new ApplicationWorkerRecordingKernelRuntime();
        $meter = new ApplicationWorkerRecordingMeter();

        $worker = self::worker(
            taskFactory: $taskFactory,
            kernelRuntime: $kernelRuntime,
            meter: $meter,
        );

        self::assertSame('task-result', $worker->runOne($spec));

        self::assertSame(1, $taskFactory->supportsCalls);
        self::assertSame(1, $taskFactory->createCalls);
        self::assertSame(1, $taskFactory->runCalls);
        self::assertSame([$spec], $taskFactory->supportsSpecs);
        self::assertSame([$spec], $taskFactory->createSpecs);

        self::assertSame([TaskFactoryInternalInterface::TASK_TYPE_QUEUE], $kernelRuntime->types);
        self::assertSame([[]], $kernelRuntime->attributes);

        self::assertSame(
            [
                [
                    'name' => 'worker.task_total',
                    'delta' => 1,
                    'labels' => [
                        'operation' => TaskFactoryInternalInterface::TASK_TYPE_HTTP,
                        'outcome' => 'success',
                    ],
                ],
            ],
            $meter->increments,
        );
    }

    public function testRunOneEmitsSuccessMetricsWithOnlyOperationAndOutcomeLabels(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $meter = new ApplicationWorkerRecordingMeter();

        self::worker(
            taskFactory: new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                result: 'ok',
            ),
            meter: $meter,
        )->runOne($spec);

        self::assertCount(1, $meter->increments);
        self::assertCount(1, $meter->observations);

        self::assertSame('worker.task_total', $meter->increments[0]['name']);
        self::assertSame(1, $meter->increments[0]['delta']);
        self::assertSame(
            [
                'operation' => TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                'outcome' => 'success',
            ],
            $meter->increments[0]['labels'],
        );

        self::assertSame('worker.task_duration_ms', $meter->observations[0]['name']);
        self::assertIsInt($meter->observations[0]['value']);
        self::assertGreaterThanOrEqual(0, $meter->observations[0]['value']);
        self::assertSame(
            [
                'operation' => TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                'outcome' => 'success',
            ],
            $meter->observations[0]['labels'],
        );

        self::assertSame(['operation', 'outcome'], \array_keys($meter->increments[0]['labels']));
        self::assertSame(['operation', 'outcome'], \array_keys($meter->observations[0]['labels']));
    }

    public function testRunOneEmitsFailureMetricsWithOnlyOperationAndOutcomeLabels(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $meter = new ApplicationWorkerRecordingMeter();
        $failure = new \RuntimeException('task-failed');

        $worker = self::worker(
            taskFactory: new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                throwable: $failure,
            ),
            meter: $meter,
        );

        try {
            $worker->runOne($spec);

            self::fail('Expected task failure was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertCount(1, $meter->increments);
        self::assertCount(1, $meter->observations);

        self::assertSame('worker.task_total', $meter->increments[0]['name']);
        self::assertSame(1, $meter->increments[0]['delta']);
        self::assertSame(
            [
                'operation' => TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                'outcome' => 'failure',
            ],
            $meter->increments[0]['labels'],
        );

        self::assertSame('worker.task_duration_ms', $meter->observations[0]['name']);
        self::assertIsInt($meter->observations[0]['value']);
        self::assertGreaterThanOrEqual(0, $meter->observations[0]['value']);
        self::assertSame(
            [
                'operation' => TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                'outcome' => 'failure',
            ],
            $meter->observations[0]['labels'],
        );

        self::assertSame(['operation', 'outcome'], \array_keys($meter->increments[0]['labels']));
        self::assertSame(['operation', 'outcome'], \array_keys($meter->observations[0]['labels']));
    }

    public function testRunOneMeasuresTaskDurationWithFoundationStopwatch(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $meter = new ApplicationWorkerRecordingMeter();

        self::worker(
            taskFactory: new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                result: null,
            ),
            stopwatch: new Stopwatch(),
            meter: $meter,
        )->runOne($spec);

        self::assertCount(1, $meter->observations);
        self::assertSame('worker.task_duration_ms', $meter->observations[0]['name']);
        self::assertIsInt($meter->observations[0]['value']);
        self::assertGreaterThanOrEqual(0, $meter->observations[0]['value']);
    }

    public function testTracerFailuresDoNotChangeTaskControlFlowSemantics(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $meter = new ApplicationWorkerRecordingMeter();

        $result = self::worker(
            taskFactory: new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                result: 'task-result',
            ),
            tracer: new ApplicationWorkerRecordingTracer(
                throwOnStartSpan: true,
            ),
            meter: $meter,
        )->runOne($spec);

        self::assertSame('task-result', $result);
        self::assertSame('success', $meter->increments[0]['labels']['outcome']);
    }

    public function testSpanFailuresDoNotChangeTaskControlFlowSemantics(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $meter = new ApplicationWorkerRecordingMeter();

        $result = self::worker(
            taskFactory: new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                result: 'task-result',
            ),
            tracer: new ApplicationWorkerRecordingTracer(
                span: new ApplicationWorkerRecordingSpan(
                    throwOnSetAttributes: true,
                    throwOnEnd: true,
                ),
            ),
            meter: $meter,
        )->runOne($spec);

        self::assertSame('task-result', $result);
        self::assertSame('success', $meter->increments[0]['labels']['outcome']);
    }

    public function testMeterFailuresDoNotChangeTaskControlFlowSemantics(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $result = self::worker(
            taskFactory: new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                result: 'task-result',
            ),
            meter: new ApplicationWorkerRecordingMeter(throwOnEmit: true),
        )->runOne($spec);

        self::assertSame('task-result', $result);
    }

    public function testContextReadsAreLimitedToCorrelationIdUowIdAndUowType(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $context = new ApplicationWorkerRecordingContext([
            ContextKeys::CORRELATION_ID => 'correlation-id',
            ContextKeys::UOW_ID => 'uow-id',
            ContextKeys::UOW_TYPE => 'queue',
            'unsafe.extra' => 'must-not-be-read',
        ]);

        $span = new ApplicationWorkerRecordingSpan();

        self::worker(
            taskFactory: new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                result: null,
            ),
            context: $context,
            tracer: new ApplicationWorkerRecordingTracer(span: $span),
        )->runOne($spec);

        self::assertSame(
            [
                ContextKeys::CORRELATION_ID,
                ContextKeys::UOW_ID,
                ContextKeys::UOW_TYPE,
            ],
            $context->hasKeys,
        );

        self::assertSame(
            [
                ContextKeys::CORRELATION_ID,
                ContextKeys::UOW_ID,
                ContextKeys::UOW_TYPE,
            ],
            $context->getKeys,
        );

        self::assertSame(
            [
                [
                    ContextKeys::CORRELATION_ID => 'correlation-id',
                    ContextKeys::UOW_ID => 'uow-id',
                    ContextKeys::UOW_TYPE => 'queue',
                ],
                [
                    'operation' => TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                    'outcome' => 'success',
                ],
            ],
            $span->setAttributesCalls,
        );
    }

    public function testContextReadFailuresDoNotChangeTaskControlFlowSemantics(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        $meter = new ApplicationWorkerRecordingMeter();

        $result = self::worker(
            taskFactory: new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                result: 'task-result',
            ),
            context: new ApplicationWorkerRecordingContext(
                values: [],
                throwOnHas: true,
            ),
            tracer: new ApplicationWorkerRecordingTracer(span: new ApplicationWorkerRecordingSpan()),
            meter: $meter,
        )->runOne($spec);

        self::assertSame('task-result', $result);
        self::assertSame('success', $meter->increments[0]['labels']['outcome']);
    }

    public function testWorkerUsesReadOnlyContextAccessorAndDoesNotReferenceContextWrites(): void
    {
        $constructor = new \ReflectionMethod(ApplicationWorker::class, '__construct');
        $parameters = $constructor->getParameters();

        self::assertArrayHasKey(3, $parameters);

        $contextType = $parameters[3]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $contextType);
        self::assertSame(ContextAccessorInterface::class, $contextType->getName());

        $file = new \ReflectionClass(ApplicationWorker::class)->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        self::assertStringNotContainsString('ContextStoreInterface', $source);
        self::assertStringNotContainsString('ContextWriterInterface', $source);
        self::assertStringNotContainsString('->set(', $source);
        self::assertStringNotContainsString('->put(', $source);
        self::assertStringNotContainsString('->write(', $source);
        self::assertStringNotContainsString('->remove(', $source);
        self::assertStringNotContainsString('->clear(', $source);
    }

    public function testWorkerDoesNotEmitStdoutOrStderr(): void
    {
        $spec = self::workerSpec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE);

        \ob_start();

        try {
            self::worker(
                taskFactory: new ApplicationWorkerRecordingTaskFactory(
                    supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                    operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                    result: null,
                ),
            )->runOne($spec);

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        $file = new \ReflectionClass(ApplicationWorker::class)->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('fwrite(STDOUT', $source);
        self::assertStringNotContainsString('fwrite(STDERR', $source);
        self::assertStringNotContainsString('error_log(', $source);
    }

    private static function worker(
        ?ApplicationWorkerRecordingTaskFactory $taskFactory = null,
        ?ApplicationWorkerRecordingKernelRuntime $kernelRuntime = null,
        ?ApplicationWorkerRecordingContext $context = null,
        ?Stopwatch $stopwatch = null,
        ?ApplicationWorkerRecordingTracer $tracer = null,
        ?ApplicationWorkerRecordingMeter $meter = null,
    ): ApplicationWorker {
        return new ApplicationWorker(
            skeletonRoot: __DIR__,
            kernelRuntime: $kernelRuntime ?? new ApplicationWorkerRecordingKernelRuntime(),
            taskFactory: $taskFactory ?? new ApplicationWorkerRecordingTaskFactory(
                supportedTaskType: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                operationId: TaskFactoryInternalInterface::TASK_TYPE_QUEUE,
                result: null,
            ),
            context: $context ?? new ApplicationWorkerRecordingContext(),
            stopwatch: $stopwatch ?? new Stopwatch(),
            tracer: $tracer ?? new ApplicationWorkerRecordingTracer(),
            meter: $meter ?? new ApplicationWorkerRecordingMeter(),
        );
    }

    private static function workerSpec(string $taskType): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: [
                'enabled' => true,
                'workers' => 1,
                'max_requests' => 1,
                'task_type' => $taskType,
                'socket_path' => 'var/worker/control.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9501,
                ],
                'state_path' => 'var/worker/state.json',
                'stop_flag_path' => 'var/worker/stop.flag',
                'stop_timeout_ms' => 100,
            ],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }
}

final class ApplicationWorkerRecordingKernelRuntime implements KernelRuntimeInterface
{
    /**
     * @var list<string>
     */
    public array $types = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $attributes = [];

    public int $runUnitOfWorkCalls = 0;

    public function runUnitOfWork(string $type, callable $body, array $attributes = []): mixed
    {
        $this->runUnitOfWorkCalls++;
        $this->types[] = $type;
        $this->attributes[] = $attributes;

        return $body();
    }

    /**
     * @return array<string, mixed>
     */
    public function beginUnitOfWork(string $type, array $attributes = []): array
    {
        $this->types[] = $type;
        $this->attributes[] = $attributes;

        return [];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extensions
     *
     * @return array<string, mixed>
     */
    public function afterUnitOfWork(
        array $context,
        string $outcome,
        ?\Throwable $error = null,
        array $extensions = [],
    ): array {
        return [
            'context' => $context,
            'outcome' => $outcome,
            'error' => $error,
            'extensions' => $extensions,
        ];
    }
}

final class ApplicationWorkerRecordingTaskFactory implements TaskFactoryInternalInterface
{
    public int $supportsCalls = 0;

    public int $createCalls = 0;

    public int $operationIdCalls = 0;

    public int $runCalls = 0;

    /**
     * @var list<WorkerPoolSpec>
     */
    public array $supportsSpecs = [];

    /**
     * @var list<WorkerPoolSpec>
     */
    public array $createSpecs = [];

    public function __construct(
        private readonly string $supportedTaskType,
        private readonly string $operationId,
        private readonly mixed $result = null,
        private readonly ?\Throwable $throwable = null,
    ) {
    }

    public function taskType(): string
    {
        return $this->supportedTaskType;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        $this->supportsCalls++;
        $this->supportsSpecs[] = $spec;

        return $spec->taskType() === $this->supportedTaskType;
    }

    public function operationId(WorkerPoolSpec $spec): string
    {
        $this->operationIdCalls++;

        if (!$this->supports($spec)) {
            throw new \RuntimeException('task-factory-unsupported');
        }

        return $this->operationId;
    }

    /**
     * @return array{operation_id: string, run: \Closure(): mixed}
     */
    public function create(WorkerPoolSpec $spec): array
    {
        $this->createCalls++;
        $this->createSpecs[] = $spec;

        return [
            'operation_id' => $this->operationId,
            'run' => function (): mixed {
                $this->runCalls++;

                if ($this->throwable !== null) {
                    throw $this->throwable;
                }

                return $this->result;
            },
        ];
    }
}

final class ApplicationWorkerRecordingContext implements ContextAccessorInterface
{
    /**
     * @var list<string>
     */
    public array $hasKeys = [];

    /**
     * @var list<string>
     */
    public array $getKeys = [];

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly array $values = [],
        private readonly bool $throwOnHas = false,
        private readonly bool $throwOnGet = false,
    ) {
    }

    public function has(string $key): bool
    {
        $this->hasKeys[] = $key;

        if ($this->throwOnHas) {
            throw new \RuntimeException('context-has-failed');
        }

        return \array_key_exists($key, $this->values);
    }

    public function get(string $key): mixed
    {
        $this->getKeys[] = $key;

        if ($this->throwOnGet) {
            throw new \RuntimeException('context-get-failed');
        }

        return $this->values[$key] ?? null;
    }
}

final class ApplicationWorkerRecordingTracer implements TracerPortInterface
{
    /**
     * @var list<array{name: string, attributes: array<string, mixed>}>
     */
    public array $startedSpans = [];

    public function __construct(
        private readonly ?ApplicationWorkerRecordingSpan $span = null,
        private readonly bool $throwOnStartSpan = false,
    ) {
    }

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        if ($this->throwOnStartSpan) {
            throw new \RuntimeException('tracer-start-span-failed');
        }

        $this->startedSpans[] = [
            'name' => $name,
            'attributes' => $attributes,
        ];

        return $this->span ?? new ApplicationWorkerRecordingSpan($name);
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
        return $this->span;
    }
}

final class ApplicationWorkerRecordingSpan implements SpanInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $setAttributesCalls = [];

    /**
     * @var list<array{key: string, value: mixed}>
     */
    public array $setAttributeCalls = [];

    /**
     * @var list<array{name: string, attributes: array<string, mixed>}>
     */
    public array $events = [];

    /**
     * @var list<array{throwable: \Throwable, attributes: array<string, mixed>}>
     */
    public array $exceptions = [];

    public int $endCalls = 0;

    public function __construct(
        private readonly string $name = 'worker.task',
        private readonly bool $throwOnSetAttributes = false,
        private readonly bool $throwOnEnd = false,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->setAttributeCalls[] = [
            'key' => $key,
            'value' => $value,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        if ($this->throwOnSetAttributes) {
            throw new \RuntimeException('span-set-attributes-failed');
        }

        $this->setAttributesCalls[] = $attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function addEvent(string $name, array $attributes = []): void
    {
        $this->events[] = [
            'name' => $name,
            'attributes' => $attributes,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function recordException(\Throwable $throwable, array $attributes = []): void
    {
        $this->exceptions[] = [
            'throwable' => $throwable,
            'attributes' => $attributes,
        ];
    }

    public function end(): void
    {
        if ($this->throwOnEnd) {
            throw new \RuntimeException('span-end-failed');
        }

        $this->endCalls++;
    }
}

final class ApplicationWorkerRecordingMeter implements MeterPortInterface
{
    /**
     * @var list<array{name: string, delta: int, labels: array<string, string|int|bool>}>
     */
    public array $increments = [];

    /**
     * @var list<array{name: string, value: int, labels: array<string, string|int|bool>}>
     */
    public array $observations = [];

    public function __construct(
        private readonly bool $throwOnEmit = false,
    ) {
    }

    /**
     * @param array<string, string|int|bool> $labels
     */
    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
        if ($this->throwOnEmit) {
            throw new \RuntimeException('meter-increment-failed');
        }

        $this->increments[] = [
            'name' => $name,
            'delta' => $delta,
            'labels' => $labels,
        ];
    }

    /**
     * @param array<string, string|int|bool> $labels
     */
    public function observe(string $name, int $value, array $labels = []): void
    {
        if ($this->throwOnEmit) {
            throw new \RuntimeException('meter-observe-failed');
        }

        $this->observations[] = [
            'name' => $name,
            'value' => $value,
            'labels' => $labels,
        ];
    }
}
