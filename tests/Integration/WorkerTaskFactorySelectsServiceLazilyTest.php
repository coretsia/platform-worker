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

use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Contracts\Config\ConfigValueSource;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Entrypoint\RuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class WorkerTaskFactorySelectsServiceLazilyTest extends TestCase
{
    public function testQueueModeResolvesOnlyQueueTaskFactory(): void
    {
        $queueTaskFactory = new QueueTaskFactory();
        $container = new WorkerTaskFactoryRecordingContainer(
            services: [
                QueueTaskFactory::class => $queueTaskFactory,
                HttpTaskFactory::class => self::httpTaskFactory(),
            ],
        );

        $resolved = new WorkerServiceFactory()->taskFactory(
            spec: self::spec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE),
            container: $container,
        );

        self::assertSame($queueTaskFactory, $resolved);
        self::assertSame(
            [
                QueueTaskFactory::class,
            ],
            $container->requestedIds,
        );
    }

    public function testHttpModeResolvesOnlyHttpTaskFactory(): void
    {
        $httpTaskFactory = self::httpTaskFactory();
        $container = new WorkerTaskFactoryRecordingContainer(
            services: [
                QueueTaskFactory::class => new QueueTaskFactory(),
                HttpTaskFactory::class => $httpTaskFactory,
            ],
        );

        $resolved = new WorkerServiceFactory()->taskFactory(
            spec: self::spec(TaskFactoryInternalInterface::TASK_TYPE_HTTP),
            container: $container,
        );

        self::assertSame($httpTaskFactory, $resolved);
        self::assertSame(
            [
                HttpTaskFactory::class,
            ],
            $container->requestedIds,
        );
    }

    public function testInvalidSelectedBindingMapsToDeterministicStartFailure(): void
    {
        $container = new WorkerTaskFactoryRecordingContainer(
            services: [
                QueueTaskFactory::class => new \stdClass(),
            ],
        );

        $exception = self::catchWorkerStartFailed(
            static fn (): TaskFactoryInternalInterface => new WorkerServiceFactory()->taskFactory(
                spec: self::spec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE),
                container: $container,
            ),
        );

        self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());
        self::assertSame(
            WorkerStartFailedException::ERROR_CODE
            . ': '
            . WorkerStartFailedException::REASON_START_FAILED,
            $exception->getMessage(),
        );
        self::assertSame(
            [
                QueueTaskFactory::class,
            ],
            $container->requestedIds,
        );
    }

    public function testUnsupportedSelectedFactoryMapsToDeterministicStartFailure(): void
    {
        $container = new WorkerTaskFactoryRecordingContainer(
            services: [
                QueueTaskFactory::class => self::httpTaskFactory(),
            ],
        );

        $exception = self::catchWorkerStartFailed(
            static fn (): TaskFactoryInternalInterface => new WorkerServiceFactory()->taskFactory(
                spec: self::spec(TaskFactoryInternalInterface::TASK_TYPE_QUEUE),
                container: $container,
            ),
        );

        self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());
        self::assertSame(
            [
                QueueTaskFactory::class,
            ],
            $container->requestedIds,
        );
    }

    public function testSelectedServiceResolutionFailureMapsDeterministically(): void
    {
        $container = new WorkerTaskFactoryRecordingContainer(
            services: [],
        );

        $exception = self::catchWorkerStartFailed(
            static fn (): TaskFactoryInternalInterface => new WorkerServiceFactory()->taskFactory(
                spec: self::spec(TaskFactoryInternalInterface::TASK_TYPE_HTTP),
                container: $container,
            ),
        );

        self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());
        self::assertSame(
            [
                HttpTaskFactory::class,
            ],
            $container->requestedIds,
        );
    }

    private static function httpTaskFactory(): HttpTaskFactory
    {
        return new HttpTaskFactory(
            config: new WorkerTaskFactoryArrayConfigRepository(
                self::globalConfig(TaskFactoryInternalInterface::TASK_TYPE_HTTP),
            ),
            modulePlan: self::emptyModulePlan(),
            runtimeEntrypointGuard: new WorkerRuntimeEntrypointGuard(
                kernelEntrypointGuard: new RuntimeEntrypointGuard(),
            ),
            container: new WorkerTaskFactoryUnusedContainer(),
        );
    }

    private static function spec(string $taskType): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: self::globalConfig($taskType)['worker'],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function globalConfig(string $taskType): array
    {
        return [
            'kernel' => [
                'runtime' => [
                    'http_driver' => 'http.classic',
                ],
            ],
            'worker' => [
                'workers' => 1,
                'max_requests' => 10,
                'task_type' => $taskType,
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
        ];
    }

    private static function emptyModulePlan(): ModulePlan
    {
        return new ModulePlan(
            app: 'worker',
            preset: 'micro',
            enabled: [],
            disabled: [],
            optionalMissing: [],
            topologicalOrder: [],
            modules: [],
            warnings: [],
        );
    }

    /**
     * @param callable(): TaskFactoryInternalInterface $operation
     */
    private static function catchWorkerStartFailed(
        callable $operation,
    ): WorkerStartFailedException {
        try {
            $operation();
        } catch (WorkerStartFailedException $exception) {
            return $exception;
        }

        self::fail('Expected WorkerStartFailedException was not thrown.');
    }
}

final class WorkerTaskFactoryRecordingContainer implements ContainerInterface
{
    /**
     * @var list<string>
     */
    public array $requestedIds = [];

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(
        private readonly array $services,
    ) {
    }

    public function get(string $id): mixed
    {
        $this->requestedIds[] = $id;

        if (!\array_key_exists($id, $this->services)) {
            throw new \LogicException('Selected task-factory service is unavailable.');
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->services);
    }
}

final class WorkerTaskFactoryUnusedContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new \LogicException('Request-handler resolution is not expected during task-factory selection.');
    }

    public function has(string $id): bool
    {
        return false;
    }
}

final class WorkerTaskFactoryArrayConfigRepository implements ConfigRepositoryInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function has(string $keyPath): bool
    {
        $missing = new \stdClass();

        return $this->value($keyPath, $missing) !== $missing;
    }

    public function get(string $keyPath, mixed $default = null): mixed
    {
        return $this->value($keyPath, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->config;
    }

    public function sourceOf(string $keyPath): ?ConfigValueSource
    {
        return null;
    }

    /**
     * @return list<ConfigValueSource>
     */
    public function explain(): array
    {
        return [];
    }

    private function value(string $keyPath, mixed $default): mixed
    {
        if ($keyPath === '') {
            return $this->config;
        }

        $current = $this->config;

        foreach (\explode('.', $keyPath) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
