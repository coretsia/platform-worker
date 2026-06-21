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

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use PHPUnit\Framework\TestCase;

final class WorkerServiceProviderTaskFactorySelectionTest extends TestCase
{
    public function testQueueModeResolvesQueueTaskFactoryAndDoesNotResolveHttpTaskFactory(): void
    {
        $queueResolutions = 0;
        $httpResolutions = 0;

        $taskFactory = self::serviceFactory()->taskFactory(
            spec: self::workerSpec([
                'task_type' => 'queue',
            ]),
            queueTaskFactory: static function () use (&$queueResolutions): QueueTaskFactory {
                $queueResolutions++;

                return new QueueTaskFactory();
            },
            httpTaskFactory: static function () use (&$httpResolutions): HttpTaskFactory {
                $httpResolutions++;

                self::fail('HttpTaskFactory must not be resolved in queue mode.');
            },
        );

        self::assertInstanceOf(QueueTaskFactory::class, $taskFactory);
        self::assertSame(1, $queueResolutions);
        self::assertSame(0, $httpResolutions);
    }

    public function testHttpModeResolvesHttpTaskFactoryAndDoesNotResolveQueueTaskFactory(): void
    {
        $queueResolutions = 0;
        $httpResolutions = 0;

        $taskFactory = self::serviceFactory()->taskFactory(
            spec: self::workerSpec([
                'task_type' => 'http',
            ]),
            queueTaskFactory: static function () use (&$queueResolutions): QueueTaskFactory {
                $queueResolutions++;

                self::fail('QueueTaskFactory must not be resolved in http mode.');
            },
            httpTaskFactory: static function () use (&$httpResolutions): HttpTaskFactory {
                $httpResolutions++;

                return self::uninitializedHttpTaskFactory();
            },
        );

        self::assertInstanceOf(HttpTaskFactory::class, $taskFactory);
        self::assertSame(0, $queueResolutions);
        self::assertSame(1, $httpResolutions);
    }

    public function testUnsupportedTaskTypeFailsDeterministically(): void
    {
        $queueResolutions = 0;
        $httpResolutions = 0;

        try {
            self::serviceFactory()->taskFactory(
                spec: self::workerSpecWithUnsupportedTaskType('cron'),
                queueTaskFactory: static function () use (&$queueResolutions): QueueTaskFactory {
                    $queueResolutions++;

                    self::fail('QueueTaskFactory must not be resolved for unsupported task type.');
                },
                httpTaskFactory: static function () use (&$httpResolutions): HttpTaskFactory {
                    $httpResolutions++;

                    self::fail('HttpTaskFactory must not be resolved for unsupported task type.');
                },
            );

            self::fail('Expected WorkerStartFailedException was not thrown.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
            self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());
            self::assertSame(
                WorkerStartFailedException::ERROR_CODE . ': ' . WorkerStartFailedException::REASON_START_FAILED,
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $queueResolutions);
        self::assertSame(0, $httpResolutions);
    }

    public function testTaskFactorySelectionDoesNotStartWorkersForkCallProcOpenOpenSocketsWriteFilesDependOnPlatformPackagesOrEmitStdoutStderr(
    ): void {
        \ob_start();

        try {
            $factory = self::serviceFactory();

            self::assertInstanceOf(
                QueueTaskFactory::class,
                $factory->taskFactory(
                    spec: self::workerSpec([
                        'task_type' => 'queue',
                    ]),
                    queueTaskFactory: static fn (): QueueTaskFactory => new QueueTaskFactory(),
                    httpTaskFactory: static fn (): HttpTaskFactory => self::uninitializedHttpTaskFactory(),
                ),
            );

            self::assertInstanceOf(
                HttpTaskFactory::class,
                $factory->taskFactory(
                    spec: self::workerSpec([
                        'task_type' => 'http',
                    ]),
                    queueTaskFactory: static fn (): QueueTaskFactory => new QueueTaskFactory(),
                    httpTaskFactory: static fn (): HttpTaskFactory => self::uninitializedHttpTaskFactory(),
                ),
            );

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        $selectionSource = self::methodSource(WorkerServiceFactory::class, 'taskFactory')
            . "\n"
            . self::methodSource(WorkerServiceFactory::class, 'supportedTaskFactory');

        self::assertStringNotContainsString('start(', $selectionSource);
        self::assertStringNotContainsString('stop(', $selectionSource);
        self::assertStringNotContainsString('status(', $selectionSource);
        self::assertStringNotContainsString('run(', $selectionSource);
        self::assertStringNotContainsString('runOne(', $selectionSource);

        self::assertStringNotContainsString('pcntl_', $selectionSource);
        self::assertStringNotContainsString('proc_open(', $selectionSource);

        self::assertStringNotContainsString('stream_socket_server(', $selectionSource);
        self::assertStringNotContainsString('stream_socket_client(', $selectionSource);
        self::assertStringNotContainsString('stream_socket_accept(', $selectionSource);
        self::assertStringNotContainsString('listen(', $selectionSource);
        self::assertStringNotContainsString('connect(', $selectionSource);

        self::assertStringNotContainsString('file_put_contents(', $selectionSource);
        self::assertStringNotContainsString('fopen(', $selectionSource);
        self::assertStringNotContainsString('mkdir(', $selectionSource);
        self::assertStringNotContainsString('rename(', $selectionSource);
        self::assertStringNotContainsString('unlink(', $selectionSource);

        self::assertStringNotContainsString('Platform\\Cli', $selectionSource);
        self::assertStringNotContainsString('Coretsia\\Platform\\Cli', $selectionSource);
        self::assertStringNotContainsString('Platform\\Http', $selectionSource);
        self::assertStringNotContainsString('Coretsia\\Platform\\Http', $selectionSource);

        self::assertStringNotContainsString('echo ', $selectionSource);
        self::assertStringNotContainsString('print ', $selectionSource);
        self::assertStringNotContainsString('var_dump(', $selectionSource);
        self::assertStringNotContainsString('print_r(', $selectionSource);
        self::assertStringNotContainsString('fwrite(STDOUT', $selectionSource);
        self::assertStringNotContainsString('fwrite(STDERR', $selectionSource);
        self::assertStringNotContainsString('error_log(', $selectionSource);
    }

    private static function serviceFactory(): WorkerServiceFactory
    {
        return new WorkerServiceFactory();
    }

    private static function uninitializedHttpTaskFactory(): HttpTaskFactory
    {
        $reflection = new \ReflectionClass(HttpTaskFactory::class);
        $factory = $reflection->newInstanceWithoutConstructor();

        self::assertInstanceOf(HttpTaskFactory::class, $factory);

        return $factory;
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

    private static function workerSpecWithUnsupportedTaskType(string $taskType): WorkerPoolSpec
    {
        $reflection = new \ReflectionClass(WorkerPoolSpec::class);

        /** @var WorkerPoolSpec $spec */
        $spec = $reflection->newInstanceWithoutConstructor();

        $initialize = \Closure::bind(
            static function (WorkerPoolSpec $spec, string $taskType): void {
                $spec->taskType = $taskType;
            },
            null,
            WorkerPoolSpec::class,
        );

        self::assertInstanceOf(\Closure::class, $initialize);

        $initialize($spec, $taskType);

        return $spec;
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
                'stop_timeout_ms' => 5000,
            ],
            $overrides,
        );
    }

    /**
     * @param class-string $className
     */
    private static function methodSource(string $className, string $methodName): string
    {
        $method = new \ReflectionMethod($className, $methodName);
        $file = $method->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        $lines = \explode("\n", $source);
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertGreaterThanOrEqual($start, $end);

        return \implode(
            "\n",
            \array_slice(
                $lines,
                $start - 1,
                $end - $start + 1,
            ),
        );
    }
}
