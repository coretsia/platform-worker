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
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use PHPUnit\Framework\TestCase;
use Throwable;

final class MaxRequestsTriggersRecycleTest extends TestCase
{
    private ?string $skeletonRoot = null;

    protected function tearDown(): void
    {
        if ($this->skeletonRoot !== null) {
            self::removeTree($this->skeletonRoot);
            $this->skeletonRoot = null;
        }
    }

    public function testWorkerLoopExitsAfterExactlyMaxRequestsWithoutProcessingExtraTask(): void
    {
        $this->skeletonRoot = self::newSkeletonRoot('worker-max-requests');

        $recorder = new MaxRequestsEventRecorder();
        $runtime = new MaxRequestsRecordingKernelRuntime($recorder);
        $taskFactory = new MaxRequestsTaskFactory($recorder);

        $worker = new ApplicationWorker(
            skeletonRoot: $this->skeletonRoot,
            kernelRuntime: $runtime,
            taskFactory: $taskFactory,
            context: new MaxRequestsContextAccessor(),
            stopwatch: new Stopwatch(),
            tracer: new MaxRequestsNoopTracer(),
            meter: new MaxRequestsRecordingMeter(),
        );

        \ob_start();

        try {
            $processed = $worker->run(self::queueSpec(maxRequests: 4));
        } finally {
            $output = \ob_get_clean();
        }

        self::assertIsString($output);
        self::assertSame('', $output, 'ApplicationWorker::run() MUST NOT write stdout output.');

        self::assertSame(4, $processed);
        self::assertSame(4, $taskFactory->createCalls);
        self::assertSame(4, $taskFactory->bodyCalls);
        self::assertSame(4, $runtime->runUnitOfWorkCalls);

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
                'factory-create-4',
                'uow-begin-4:queue',
                'task-4',
                'uow-end-4',
            ],
            $recorder->records,
        );

        self::assertFileDoesNotExist($this->skeletonRoot . '/var/tmp/worker.stop');
        self::assertNoStdoutStderrOrPlatformCliUsageInWorkerLoopSource();
    }

    public function testMaxRequestsZeroCannotBeConstructedAsWorkerPoolSpec(): void
    {
        $this->expectException(\Coretsia\Platform\Worker\Exception\WorkerStartFailedException::class);

        self::queueSpec(maxRequests: 0);
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

    private static function assertNoStdoutStderrOrPlatformCliUsageInWorkerLoopSource(): void
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
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $source);
        }

        self::assertNoOutputSinksInExecutableSource($source);
    }

    private static function assertNoOutputSinksInExecutableSource(string $source): void
    {
        $tokens = \token_get_all($source);

        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === \T_COMMENT || $id === \T_DOC_COMMENT) {
                continue;
            }

            if ($id === \T_ECHO || $id === \T_PRINT) {
                self::fail('Forbidden output token found in ApplicationWorker on line ' . (string)$line . '.');
            }

            if (!self::isNameToken($id)) {
                continue;
            }

            $name = \strtolower(\ltrim($text, '\\'));

            if (\in_array($name, ['printf', 'vprintf', 'fprintf', 'var_dump', 'print_r', 'error_log'], true)) {
                self::fail(
                    'Forbidden output function ' . $text . ' found in ApplicationWorker on line ' . (string)$line . '.'
                );
            }

            if (\in_array($name, ['fwrite', 'fputs'], true)) {
                $next = self::nextNonWhitespaceIndex($tokens, $i);

                if ($next === null || self::tokenText($tokens[$next]) !== '(') {
                    continue;
                }

                $arg = self::nextNonWhitespaceIndex($tokens, $next);

                if ($arg === null) {
                    continue;
                }

                $constant = \strtoupper(\ltrim(self::tokenText($tokens[$arg]), '\\'));

                if ($constant === 'STDOUT' || $constant === 'STDERR') {
                    self::fail(
                        'Forbidden ' . $text . '(' . $constant . ', ...) found in ApplicationWorker on line ' . (string)$line . '.'
                    );
                }
            }
        }
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function nextNonWhitespaceIndex(array $tokens, int $index): ?int
    {
        $count = \count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_array($token) && $token[0] === \T_WHITESPACE) {
                continue;
            }

            if (\is_array($token) && ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private static function tokenText(mixed $token): string
    {
        return \is_array($token) ? (string)$token[1] : (string)$token;
    }

    private static function isNameToken(int $id): bool
    {
        return $id === \T_STRING
            || $id === \T_NAME_FULLY_QUALIFIED
            || $id === \T_NAME_QUALIFIED
            || $id === \T_NAME_RELATIVE;
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

        return $source;
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

final class MaxRequestsEventRecorder
{
    /**
     * @var list<string>
     */
    public array $records = [];

    public function record(string $event): void
    {
        $this->records[] = $event;
    }
}

final class MaxRequestsTaskFactory implements TaskFactoryInternalInterface
{
    public int $createCalls = 0;

    public int $bodyCalls = 0;

    public function __construct(
        private readonly MaxRequestsEventRecorder $events,
    ) {
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

        return [
            'operation_id' => $this->operationId($spec),
            'run' => function () use ($index): string {
                $this->bodyCalls++;
                $this->events->record('task-' . $index);

                return 'task-' . $index . '-result';
            },
        ];
    }
}

final class MaxRequestsRecordingKernelRuntime implements KernelRuntimeInterface
{
    public int $runUnitOfWorkCalls = 0;

    public function __construct(
        private readonly MaxRequestsEventRecorder $events,
    ) {
    }

    public function runUnitOfWork(string $type, callable $body, array $attributes = []): mixed
    {
        $this->runUnitOfWorkCalls++;
        $boundary = $this->runUnitOfWorkCalls;

        $this->events->record('uow-begin-' . $boundary . ':' . $type);

        $result = $body();

        $this->events->record('uow-end-' . $boundary);

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

final class MaxRequestsContextAccessor implements ContextAccessorInterface
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

final class MaxRequestsRecordingMeter implements MeterPortInterface
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

final class MaxRequestsNoopTracer implements TracerPortInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new MaxRequestsNoopSpan($name);
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

final class MaxRequestsNoopSpan implements SpanInterface
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
