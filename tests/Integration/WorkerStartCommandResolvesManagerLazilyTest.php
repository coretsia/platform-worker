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

use Coretsia\Contracts\Cli\Input\InputInterface;
use Coretsia\Contracts\Cli\Output\OutputInterface;
use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Contracts\Config\ConfigValueSource;
use Coretsia\Contracts\Module\ModuleId;
use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Module\ModulePlanEntry;
use Coretsia\Kernel\Runtime\Entrypoint\RuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Manager\ContainerWorkerManagerResolver;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class WorkerStartCommandResolvesManagerLazilyTest extends TestCase
{
    public function testManagerResolutionOccursAfterRuntimeEntrypointValidation(): void
    {
        $events = new WorkerStartLazyEventLog();
        $driver = new WorkerStartLazyRecordingDriver(
            events: $events,
            state: self::startedState(),
        );
        $manager = new WorkerManager(
            drivers: [
                $driver,
            ],
            tracer: new WorkerStartLazySilentTracer(),
            meter: new WorkerStartLazySilentMeter(),
            logger: new WorkerStartLazySilentLogger(),
            stopwatch: new Stopwatch(),
        );
        $container = new WorkerStartLazyManagerContainer(
            manager: $manager,
            events: $events,
        );

        $command = new WorkerStartCommand(
            config: new WorkerStartLazyConfigRepository(
                config: self::workerConfig(),
                events: $events,
            ),
            modulePlan: self::workerModulePlan(),
            runtimeEntrypointGuard: new WorkerRuntimeEntrypointGuard(
                kernelEntrypointGuard: new RuntimeEntrypointGuard(),
            ),
            factory: new WorkerServiceFactory(),
            managerResolver: new ContainerWorkerManagerResolver($container),
        );

        self::assertSame([], $container->requestedIds);
        self::assertSame([], $events->events);

        $output = new WorkerStartLazyRecordingOutput();

        $exitCode = $command->run(
            input: new WorkerStartLazyParsedInput(WorkerStartCommand::NAME),
            output: $output,
        );

        self::assertSame(0, $exitCode);
        self::assertSame(
            [
                WorkerManager::class,
            ],
            $container->requestedIds,
        );
        self::assertSame(1, $driver->startCalls);
        self::assertSame([], $output->errors);
        self::assertCount(1, $output->jsonPayloads);

        $guardPosition = \array_search(
            WorkerStartLazyEventLog::EVENT_GUARD_READ,
            $events->events,
            true,
        );
        $resolvePosition = \array_search(
            WorkerStartLazyEventLog::EVENT_MANAGER_RESOLVE,
            $events->events,
            true,
        );
        $startPosition = \array_search(
            WorkerStartLazyEventLog::EVENT_MANAGER_START,
            $events->events,
            true,
        );

        self::assertIsInt($guardPosition);
        self::assertIsInt($resolvePosition);
        self::assertIsInt($startPosition);
        self::assertLessThan(
            $resolvePosition,
            $guardPosition,
            'WorkerRuntimeEntrypointGuard must run before WorkerManager resolution.',
        );
        self::assertLessThan(
            $startPosition,
            $resolvePosition,
            'WorkerManager must be resolved before its start method is invoked.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerConfig(): array
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
        ];
    }

    private static function workerModulePlan(): ModulePlan
    {
        $worker = ModuleId::fromString('platform.worker');

        return new ModulePlan(
            app: 'worker',
            preset: 'micro',
            enabled: [
                $worker,
            ],
            disabled: [],
            optionalMissing: [],
            topologicalOrder: [
                $worker,
            ],
            modules: [
                new ModulePlanEntry(
                    moduleId: $worker,
                    composerName: 'coretsia/platform-worker',
                ),
            ],
            warnings: [],
        );
    }

    private static function startedState(): WorkerPoolState
    {
        return new WorkerPoolState(
            pid: 6543,
            workerCount: 1,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: \hash('sha256', 'tcp:127.0.0.1:9327'),
        );
    }
}

final class WorkerStartLazyEventLog
{
    public const string EVENT_GUARD_READ = 'guard.read';
    public const string EVENT_MANAGER_RESOLVE = 'manager.resolve';
    public const string EVENT_MANAGER_START = 'manager.start';

    /**
     * @var list<string>
     */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}

final class WorkerStartLazyConfigRepository implements ConfigRepositoryInterface
{
    private bool $guardReadRecorded = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly WorkerStartLazyEventLog $events,
    ) {
    }

    public function has(string $keyPath): bool
    {
        $this->recordGuardRead($keyPath);

        $missing = new \stdClass();

        return $this->value($keyPath, $missing) !== $missing;
    }

    public function get(string $keyPath, mixed $default = null): mixed
    {
        $this->recordGuardRead($keyPath);

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

    private function recordGuardRead(string $keyPath): void
    {
        if (
            $this->guardReadRecorded
            || $keyPath !== 'kernel.runtime.http_driver'
        ) {
            return;
        }

        $this->guardReadRecorded = true;
        $this->events->record(WorkerStartLazyEventLog::EVENT_GUARD_READ);
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

final class WorkerStartLazyManagerContainer implements ContainerInterface
{
    /**
     * @var list<string>
     */
    public array $requestedIds = [];

    public function __construct(
        private readonly WorkerManager $manager,
        private readonly WorkerStartLazyEventLog $events,
    ) {
    }

    public function get(string $id): mixed
    {
        $this->requestedIds[] = $id;
        $this->events->record(WorkerStartLazyEventLog::EVENT_MANAGER_RESOLVE);

        if ($id !== WorkerManager::class) {
            throw new \LogicException('Unexpected manager service id requested.');
        }

        return $this->manager;
    }

    public function has(string $id): bool
    {
        return $id === WorkerManager::class;
    }
}

final class WorkerStartLazyParsedInput implements InputInterface
{
    public function __construct(
        private readonly string $commandName,
    ) {
    }

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        throw new \LogicException('InputInterface::tokens() must not be called.');
    }

    public function commandName(): string
    {
        return $this->commandName;
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        return [];
    }

    /**
     * @return array<string, string|bool|list<string>|null>
     */
    public function options(): array
    {
        return [];
    }

    public function hasOption(string $name): bool
    {
        return false;
    }

    public function option(string $name): string|bool|array|null
    {
        return null;
    }
}

final class WorkerStartLazyRecordingOutput implements OutputInterface
{
    /**
     * @var list<array<string, mixed>|list<mixed>>
     */
    public array $jsonPayloads = [];

    /**
     * @var list<array{code: string, message: string}>
     */
    public array $errors = [];

    public function text(string $text): void
    {
    }

    public function json(array $payload): void
    {
        $this->jsonPayloads[] = $payload;
    }

    public function error(string $code, string $message): void
    {
        $this->errors[] = [
            'code' => $code,
            'message' => $message,
        ];
    }
}

final class WorkerStartLazyRecordingDriver implements WorkerManagerDriverInterface
{
    public int $startCalls = 0;

    public function __construct(
        private readonly WorkerStartLazyEventLog $events,
        private readonly WorkerPoolState $state,
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
        $this->startCalls++;
        $this->events->record(WorkerStartLazyEventLog::EVENT_MANAGER_START);

        return $this->state;
    }

    public function stop(WorkerPoolSpec $spec): WorkerPoolState
    {
        throw WorkerStartFailedException::startFailed();
    }

    public function status(WorkerPoolSpec $spec): WorkerPoolState
    {
        throw WorkerStartFailedException::startFailed();
    }
}

final class WorkerStartLazySilentTracer implements TracerPortInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new WorkerStartLazySilentSpan($name);
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

final readonly class WorkerStartLazySilentSpan implements SpanInterface
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

final class WorkerStartLazySilentMeter implements MeterPortInterface
{
    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
    }

    public function observe(string $name, int $value, array $labels = []): void
    {
    }
}

final class WorkerStartLazySilentLogger implements LoggerInterface
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
