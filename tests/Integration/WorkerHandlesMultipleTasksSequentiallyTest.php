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

use Coretsia\Contracts\Context\ContextAccessorInterface;
use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use PHPUnit\Framework\TestCase;
use Throwable;

final class WorkerHandlesMultipleTasksSequentiallyTest extends TestCase
{
    private ?string $skeletonRoot = null;

    protected function tearDown(): void
    {
        if ($this->skeletonRoot !== null) {
            self::removeTree($this->skeletonRoot);
            $this->skeletonRoot = null;
        }
    }

    public function testApplicationWorkerProcessesMultipleTasksSequentiallyThroughSeparateUnitOfWorkBoundaries(): void
    {
        $this->skeletonRoot = self::newSkeletonRoot('worker-sequential');
        $events = new SequentialWorkerEventRecorder();
        $runtime = new SequentialWorkerRecordingKernelRuntime($events);

        $factory = new SequentialWorkerTaskFactory(
            events: $events,
            taskBodies: [
                static function () use ($events): string {
                    $events->record('task-1');

                    return 'task-1-result';
                },
                static function () use ($events): string {
                    $events->record('task-2');

                    return 'task-2-result';
                },
                function () use ($events): string {
                    $events->record('task-3');

                    self::writeStopFlag($this->skeletonRoot);

                    return 'task-3-result';
                },
                static function () use ($events): never {
                    $events->record('task-4-unexpected');

                    throw new \LogicException('ApplicationWorker must stop before task 4.');
                },
            ],
        );

        $worker = self::applicationWorker(
            skeletonRoot: $this->skeletonRoot,
            kernelRuntime: $runtime,
            taskFactory: $factory,
        );

        $processed = $worker->run(self::queueSpec(maxRequests: 10));

        self::assertSame(3, $processed);
        self::assertSame(3, $factory->createCalls);
        self::assertSame(3, $runtime->runUnitOfWorkCalls);

        self::assertSame(
            [
                'factory-create-1',
                'uow-begin-1:queue',
                'task-1',
                'uow-end-1',
                'factory-create-2',
                'uow-begin-2:queue',
                'task-2',
                'uow-end-2',
                'factory-create-3',
                'uow-begin-3:queue',
                'task-3',
                'uow-end-3',
            ],
            $events->events,
        );

        self::assertSame(
            [
                [
                    'boundary' => 1,
                    'type' => 'queue',
                    'attributes' => [],
                    'result' => 'task-1-result',
                ],
                [
                    'boundary' => 2,
                    'type' => 'queue',
                    'attributes' => [],
                    'result' => 'task-2-result',
                ],
                [
                    'boundary' => 3,
                    'type' => 'queue',
                    'attributes' => [],
                    'result' => 'task-3-result',
                ],
            ],
            $runtime->unitOfWorks,
        );

        self::assertSame(
            [
                [
                    'operation_id' => 'queue',
                    'task_index' => 1,
                ],
                [
                    'operation_id' => 'queue',
                    'task_index' => 2,
                ],
                [
                    'operation_id' => 'queue',
                    'task_index' => 3,
                ],
            ],
            $factory->createdTaskRecords,
        );

        self::assertFileExists($this->skeletonRoot . '/var/tmp/worker.stop');
        self::assertApplicationWorkerDoesNotUsePlatformCliCatalog();
    }

    public function testQueueTaskFactoryDefaultTaskRunsThroughOneUnitOfWorkBoundary(): void
    {
        $this->skeletonRoot = self::newSkeletonRoot('worker-queue-default');
        $events = new SequentialWorkerEventRecorder();
        $runtime = new SequentialWorkerRecordingKernelRuntime($events);

        $worker = self::applicationWorker(
            skeletonRoot: $this->skeletonRoot,
            kernelRuntime: $runtime,
            taskFactory: new QueueTaskFactory(),
        );

        $processed = $worker->run(self::queueSpec(maxRequests: 2));

        self::assertSame(2, $processed);
        self::assertSame(2, $runtime->runUnitOfWorkCalls);
        self::assertSame(
            [
                'uow-begin-1:queue',
                'uow-end-1',
                'uow-begin-2:queue',
                'uow-end-2',
            ],
            $events->events,
        );
    }

    private static function applicationWorker(
        string $skeletonRoot,
        KernelRuntimeInterface $kernelRuntime,
        TaskFactoryInternalInterface $taskFactory,
    ): ApplicationWorker {
        return new ApplicationWorker(
            skeletonRoot: $skeletonRoot,
            kernelRuntime: $kernelRuntime,
            taskFactory: $taskFactory,
            context: new SequentialWorkerContextAccessor(),
            stopwatch: new \Coretsia\Foundation\Time\Stopwatch(),
            tracer: new SequentialWorkerNoopTracer(),
            meter: new SequentialWorkerRecordingMeter(),
        );
    }

    private static function queueSpec(int $maxRequests): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: [
                'enabled' => true,
                'workers' => 1,
                'max_requests' => $maxRequests,
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
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }

    private static function writeStopFlag(?string $skeletonRoot): void
    {
        self::assertIsString($skeletonRoot);

        $path = $skeletonRoot . '/var/tmp/worker.stop';
        $dir = \dirname($path);

        if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            self::fail('Unable to create worker stop flag directory.');
        }

        self::assertNotFalse(\file_put_contents($path, 'stop'));
    }

    private static function assertApplicationWorkerDoesNotUsePlatformCliCatalog(): void
    {
        $source = self::executableSource(ApplicationWorker::class);

        foreach (
            [
                'Coretsia\\Platform\\Cli',
                'Platform\\Cli',
                'CommandCatalog',
                'CommandRegistry',
                'CliApplication',
                'InputInterface',
                'OutputInterface',
                'tokens',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    /**
     * @param class-string $className
     */
    private static function executableSource(string $className): string
    {
        $reflection = new \ReflectionClass($className);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        $tokens = \token_get_all($source);
        $executable = '';

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                $executable .= $token;

                continue;
            }

            if (
                $token[0] === \T_COMMENT
                || $token[0] === \T_DOC_COMMENT
                || $token[0] === \T_CONSTANT_ENCAPSED_STRING
                || $token[0] === \T_ENCAPSED_AND_WHITESPACE
            ) {
                continue;
            }

            $executable .= $token[1];
        }

        return $executable;
    }

    private static function newSkeletonRoot(string $name): string
    {
        $root = \sys_get_temp_dir() . '/coretsia-' . $name . '-' . \bin2hex(\random_bytes(8));

        if (!\mkdir($root, 0777, true) && !\is_dir($root)) {
            self::fail('Unable to create temporary worker skeleton root.');
        }

        return \str_replace('\\', '/', $root);
    }

    private static function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @\rmdir($item->getPathname());

                continue;
            }

            @\unlink($item->getPathname());
        }

        @\rmdir($path);
    }
}

final class SequentialWorkerEventRecorder
{
    /**
     * @var list<string>
     */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}

final class SequentialWorkerTaskFactory implements TaskFactoryInternalInterface
{
    /**
     * @var list<\Closure(): mixed>
     */
    private array $taskBodies;

    public int $createCalls = 0;

    /**
     * @var list<array{operation_id: string, task_index: int}>
     */
    public array $createdTaskRecords = [];

    /**
     * @param list<callable(): mixed> $taskBodies
     */
    public function __construct(
        private readonly SequentialWorkerEventRecorder $events,
        array $taskBodies,
    ) {
        $this->taskBodies = \array_map(
            static fn (callable $taskBody): \Closure => \Closure::fromCallable($taskBody),
            $taskBodies,
        );
    }

    public function taskType(): string
    {
        return self::TASK_TYPE_QUEUE;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        return $spec->taskType() === self::TASK_TYPE_QUEUE;
    }

    public function operationId(WorkerPoolSpec $spec): string
    {
        if (!$this->supports($spec)) {
            throw \Coretsia\Platform\Worker\Exception\WorkerStartFailedException::startFailed();
        }

        return self::TASK_TYPE_QUEUE;
    }

    public function create(WorkerPoolSpec $spec): array
    {
        $this->createCalls++;
        $index = $this->createCalls;
        $this->events->record('factory-create-' . $index);

        $this->createdTaskRecords[] = [
            'operation_id' => $this->operationId($spec),
            'task_index' => $index,
        ];

        $body = $this->taskBodies[$index - 1] ?? static function (): never {
            throw new \LogicException('Unexpected task creation after available test task bodies.');
        };

        return [
            'operation_id' => self::TASK_TYPE_QUEUE,
            'run' => $body,
        ];
    }
}

final class SequentialWorkerRecordingKernelRuntime implements KernelRuntimeInterface
{
    public int $runUnitOfWorkCalls = 0;

    /**
     * @var list<array{boundary: int, type: string, attributes: array<string, mixed>, result: mixed}>
     */
    public array $unitOfWorks = [];

    public function __construct(
        private readonly SequentialWorkerEventRecorder $events,
    ) {
    }

    public function runUnitOfWork(string $type, callable $body, array $attributes = []): mixed
    {
        $this->runUnitOfWorkCalls++;
        $boundary = $this->runUnitOfWorkCalls;

        $this->events->record('uow-begin-' . $boundary . ':' . $type);

        $result = $body();

        $this->events->record('uow-end-' . $boundary);

        $this->unitOfWorks[] = [
            'boundary' => $boundary,
            'type' => $type,
            'attributes' => $attributes,
            'result' => $result,
        ];

        return $result;
    }

    public function beginUnitOfWork(string $type, array $attributes = []): array
    {
        throw new \LogicException('ApplicationWorker must use runUnitOfWork().');
    }

    public function afterUnitOfWork(
        array $context,
        string $outcome,
        ?Throwable $error = null,
        array $extensions = [],
    ): array {
        throw new \LogicException('ApplicationWorker must use runUnitOfWork().');
    }
}

final class SequentialWorkerContextAccessor implements ContextAccessorInterface
{
    public function has(string $key): bool
    {
        return false;
    }

    public function get(string $key): mixed
    {
        return null;
    }
}

final class SequentialWorkerRecordingMeter implements MeterPortInterface
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

final class SequentialWorkerNoopTracer implements TracerPortInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new SequentialWorkerNoopSpan($name);
    }

    public function inSpan(string $name, callable $callback, array $attributes = []): mixed
    {
        return $callback($this->startSpan($name, $attributes));
    }

    public function currentSpan(): ?SpanInterface
    {
        return null;
    }
}

final class SequentialWorkerNoopSpan implements SpanInterface
{
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
    }

    public function setAttributes(array $attributes): void
    {
    }

    public function addEvent(string $name, array $attributes = []): void
    {
    }

    public function recordException(Throwable $throwable, array $attributes = []): void
    {
    }

    public function end(): void
    {
    }
}
