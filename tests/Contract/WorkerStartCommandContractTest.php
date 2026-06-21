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

use Coretsia\Contracts\Cli\Input\InputInterface;
use Coretsia\Contracts\Cli\Output\OutputInterface;
use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Contracts\Config\ConfigValueSource;
use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;
use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverGuard;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverConflictException;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverInvalidConfigException;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WorkerStartCommandContractTest extends TestCase
{
    public function testSuccessfulStartUsesDirectCommandRunPathDelegatesToWorkerManagerAndEmitsSafeJsonSummary(): void
    {
        $config = new WorkerStartArrayConfigRepository(self::workerConfig());
        $driver = new WorkerStartRecordingDriver(
            state: self::startedState(),
        );

        $managerFactoryCalls = 0;

        $command = new WorkerStartCommand(
            config: $config,
            modulePlan: self::emptyModulePlan(),
            runtimeDriverGuard: new RuntimeDriverGuard(),
            factory: new WorkerServiceFactory(),
            managerFactory: static function () use (&$managerFactoryCalls, $driver): WorkerManager {
                $managerFactoryCalls++;

                return self::workerManager($driver);
            },
        );

        $input = new WorkerStartParsedInput(
            commandName: WorkerStartCommand::NAME,
        );

        $output = new WorkerStartRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);
        self::assertFalse($input->tokensCalled, 'WorkerStartCommand must not call InputInterface::tokens().');

        self::assertSame(1, $managerFactoryCalls);
        self::assertSame(1, $driver->startCalls);
        self::assertInstanceOf(WorkerPoolSpec::class, $driver->lastStartSpec);

        self::assertSame('proc', $driver->lastStartSpec->driver());
        self::assertSame('tcp', $driver->lastStartSpec->controlTransport());
        self::assertSame('queue', $driver->lastStartSpec->taskType());
        self::assertSame('var/tmp/private-start.sock', $driver->lastStartSpec->socketPath());
        self::assertSame('10.11.12.13', $driver->lastStartSpec->tcpHost());
        self::assertSame(9751, $driver->lastStartSpec->tcpPort());

        self::assertSame([], $output->texts);
        self::assertSame([], $output->errors);
        self::assertCount(1, $output->jsonPayloads);

        $payload = $output->jsonPayloads[0];

        self::assertSame(
            [
                'status',
                'pid',
                'worker_count',
                'driver',
                'control_transport',
                'endpoint_hash',
            ],
            \array_keys($payload),
        );

        self::assertSame('started', $payload['status']);
        self::assertSame(6543, $payload['pid']);
        self::assertSame(2, $payload['worker_count']);
        self::assertSame('proc', $payload['driver']);
        self::assertSame('tcp', $payload['control_transport']);
        self::assertSame(\hash('sha256', 'tcp:10.11.12.13:9751'), $payload['endpoint_hash']);

        self::assertSafeJsonSummary($payload);
    }

    public function testCommandSourceDelegatesToWorkerManagerWithoutFullBinaryOrCatalogDispatch(): void
    {
        $source = self::workerStartCommandSource();

        $guardPosition = \strpos($source, '$this->assertRuntimeDriverCompatibility()');
        $specPosition = \strpos($source, '$this->factory->workerPoolSpec($this->config)');
        $managerStartPosition = \strpos($source, '$this->manager()->start($spec)');

        self::assertIsInt($guardPosition);
        self::assertIsInt($specPosition);
        self::assertIsInt($managerStartPosition);

        self::assertLessThan(
            $specPosition,
            $guardPosition,
            'RuntimeDriverGuard compatibility check must happen before WorkerServiceFactory::workerPoolSpec(...).',
        );

        self::assertLessThan(
            $managerStartPosition,
            $specPosition,
            'WorkerServiceFactory::workerPoolSpec(...) must happen before WorkerManager::start(...).',
        );

        self::assertStringContainsString('$this->manager()->start($spec)', $source);

        self::assertStringNotContainsString('CommandCatalog', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Cli', $source);
        self::assertStringNotContainsString('Application::class', $source);
        self::assertStringNotContainsString('dispatch(', $source);
        self::assertStringNotContainsString('parse(', $source);
        self::assertStringNotContainsString('coretsia worker:start', $source);
    }

    public function testCommandSourceDoesNotResolveRequestHandlerInterfaceDirectly(): void
    {
        $source = self::workerStartCommandExecutableSource();

        self::assertStringNotContainsString('RequestHandlerInterface', $source);
        self::assertStringNotContainsString('Psr\\Http\\Server\\RequestHandlerInterface', $source);
        self::assertStringNotContainsString('RequestHandlerInterface::class', $source);
        self::assertStringNotContainsString('->get(RequestHandlerInterface::class)', $source);
    }

    public function testGuardConflictReturnsOriginalRuntimeDriverConflictCodeAndReason(): void
    {
        $config = new WorkerStartArrayConfigRepository(
            self::workerConfig([
                'kernel' => [
                    'runtime' => [
                        'frankenphp' => [
                            'enabled' => true,
                        ],
                    ],
                ],
                'worker' => [
                    'task_type' => 'http',
                ],
            ]),
        );

        $driver = new WorkerStartRecordingDriver(
            state: self::startedState(),
        );

        $managerFactoryCalls = 0;

        $command = self::command(
            config: $config,
            modulePlan: self::emptyModulePlan(),
            driver: $driver,
            managerFactoryCalls: $managerFactoryCalls,
        );

        $output = new WorkerStartRecordingOutput();

        $exitCode = $command->run(
            input: new WorkerStartParsedInput(commandName: WorkerStartCommand::NAME),
            output: $output,
        );

        self::assertSame(1, $exitCode);
        self::assertSame(0, $managerFactoryCalls);
        self::assertSame(0, $driver->startCalls);

        self::assertSame(
            [
                [
                    'code' => RuntimeDriverConflictException::ERROR_CODE,
                    'message' => RuntimeDriverConflictException::REASON_WORKER_HTTP_CONFLICTS_WITH_HTTP_DRIVER,
                ],
            ],
            $output->errors,
        );
    }

    public function testGuardInvalidConfigReturnsOriginalRuntimeDriverInvalidConfigCodeAndReason(): void
    {
        $config = new WorkerStartArrayConfigRepository(
            self::workerConfig([
                'worker' => [
                    'task_type' => 'invalid-task-type',
                ],
            ]),
        );

        $driver = new WorkerStartRecordingDriver(
            state: self::startedState(),
        );

        $managerFactoryCalls = 0;

        $command = self::command(
            config: $config,
            modulePlan: self::emptyModulePlan(),
            driver: $driver,
            managerFactoryCalls: $managerFactoryCalls,
        );

        $output = new WorkerStartRecordingOutput();

        $exitCode = $command->run(
            input: new WorkerStartParsedInput(commandName: WorkerStartCommand::NAME),
            output: $output,
        );

        self::assertSame(1, $exitCode);
        self::assertSame(0, $managerFactoryCalls);
        self::assertSame(0, $driver->startCalls);

        self::assertSame(
            [
                [
                    'code' => RuntimeDriverInvalidConfigException::ERROR_CODE,
                    'message' => RuntimeDriverInvalidConfigException::REASON_WORKER_TASK_TYPE_INVALID,
                ],
            ],
            $output->errors,
        );
    }

    public function testHttpWorkerWithoutPlatformHttpReturnsOriginalRuntimeDriverInvalidConfigCodeAndReason(): void
    {
        $config = new WorkerStartArrayConfigRepository(
            self::workerConfig([
                'worker' => [
                    'task_type' => 'http',
                ],
            ]),
        );

        $driver = new WorkerStartRecordingDriver(
            state: self::startedState(),
        );

        $managerFactoryCalls = 0;

        $command = self::command(
            config: $config,
            modulePlan: self::emptyModulePlan(),
            driver: $driver,
            managerFactoryCalls: $managerFactoryCalls,
        );

        $output = new WorkerStartRecordingOutput();

        $exitCode = $command->run(
            input: new WorkerStartParsedInput(commandName: WorkerStartCommand::NAME),
            output: $output,
        );

        self::assertSame(1, $exitCode);
        self::assertSame(0, $managerFactoryCalls);
        self::assertSame(0, $driver->startCalls);

        self::assertSame(
            [
                [
                    'code' => RuntimeDriverInvalidConfigException::ERROR_CODE,
                    'message' => RuntimeDriverInvalidConfigException::REASON_REQUIRES_PLATFORM_HTTP_MODULE,
                ],
            ],
            $output->errors,
        );
    }

    public function testInvalidParsedCommandNameFailsSafely(): void
    {
        $driver = new WorkerStartRecordingDriver(
            state: self::startedState(),
        );

        $managerFactoryCalls = 0;
        $command = self::command(
            config: new WorkerStartArrayConfigRepository(self::workerConfig()),
            modulePlan: self::emptyModulePlan(),
            driver: $driver,
            managerFactoryCalls: $managerFactoryCalls,
        );

        $input = new WorkerStartParsedInput(commandName: 'worker:status');
        $output = new WorkerStartRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $managerFactoryCalls);
        self::assertSame(0, $driver->startCalls);
        self::assertFalse($input->tokensCalled);

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_COMMAND_INVALID',
                    'message' => 'worker-command-name-invalid',
                ],
            ],
            $output->errors,
        );
    }

    public function testUnsupportedArgumentsFailSafely(): void
    {
        $driver = new WorkerStartRecordingDriver(
            state: self::startedState(),
        );

        $managerFactoryCalls = 0;
        $command = self::command(
            config: new WorkerStartArrayConfigRepository(self::workerConfig()),
            modulePlan: self::emptyModulePlan(),
            driver: $driver,
            managerFactoryCalls: $managerFactoryCalls,
        );

        $input = new WorkerStartParsedInput(
            commandName: WorkerStartCommand::NAME,
            arguments: ['unexpected'],
        );
        $output = new WorkerStartRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $managerFactoryCalls);
        self::assertSame(0, $driver->startCalls);
        self::assertFalse($input->tokensCalled);

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_COMMAND_INVALID',
                    'message' => 'worker-start-arguments-not-supported',
                ],
            ],
            $output->errors,
        );
    }

    public function testUnsupportedOptionsFailSafely(): void
    {
        $driver = new WorkerStartRecordingDriver(
            state: self::startedState(),
        );

        $managerFactoryCalls = 0;
        $command = self::command(
            config: new WorkerStartArrayConfigRepository(self::workerConfig()),
            modulePlan: self::emptyModulePlan(),
            driver: $driver,
            managerFactoryCalls: $managerFactoryCalls,
        );

        $input = new WorkerStartParsedInput(
            commandName: WorkerStartCommand::NAME,
            options: ['daemon' => true],
        );
        $output = new WorkerStartRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $managerFactoryCalls);
        self::assertSame(0, $driver->startCalls);
        self::assertFalse($input->tokensCalled);

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_COMMAND_INVALID',
                    'message' => 'worker-start-options-not-supported',
                ],
            ],
            $output->errors,
        );
    }

    public function testCommandSourceDoesNotCallInputTokens(): void
    {
        $source = self::workerStartCommandSource();

        self::assertStringNotContainsString('->tokens(', $source);
    }

    /**
     * @param int $managerFactoryCalls
     */
    private static function command(
        WorkerStartArrayConfigRepository $config,
        ModulePlan $modulePlan,
        WorkerStartRecordingDriver $driver,
        int &$managerFactoryCalls,
    ): WorkerStartCommand {
        return new WorkerStartCommand(
            config: $config,
            modulePlan: $modulePlan,
            runtimeDriverGuard: new RuntimeDriverGuard(),
            factory: new WorkerServiceFactory(),
            managerFactory: static function () use (&$managerFactoryCalls, $driver): WorkerManager {
                $managerFactoryCalls++;

                return self::workerManager($driver);
            },
        );
    }

    private static function workerManager(WorkerStartRecordingDriver $driver): WorkerManager
    {
        return new WorkerManager(
            drivers: [$driver],
            tracer: new WorkerStartSilentTracer(),
            meter: new WorkerStartSilentMeter(),
            logger: new WorkerStartSilentLogger(),
            stopwatch: new Stopwatch(),
        );
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

    private static function startedState(): WorkerPoolState
    {
        return new WorkerPoolState(
            pid: 6543,
            workerCount: 2,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: \hash('sha256', 'tcp:10.11.12.13:9751'),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function workerConfig(array $overrides = []): array
    {
        return self::mergeRecursiveDistinct(
            [
                'kernel' => [
                    'runtime' => [
                        'frankenphp' => [
                            'enabled' => false,
                        ],
                        'swoole' => [
                            'enabled' => false,
                        ],
                        'roadrunner' => [
                            'enabled' => false,
                        ],
                    ],
                ],
                'worker' => [
                    'enabled' => true,
                    'workers' => 2,
                    'max_requests' => 100,
                    'task_type' => 'queue',
                    'socket_path' => 'var/tmp/private-start.sock',
                    'driver' => 'proc',
                    'control' => [
                        'transport' => 'tcp',
                    ],
                    'tcp' => [
                        'host' => '10.11.12.13',
                        'port' => 9751,
                    ],
                    'state_path' => 'var/tmp/private-start.state.json',
                    'stop_flag_path' => 'var/tmp/private-start.stop',
                    'stop_timeout_ms' => 3000,
                ],
            ],
            $overrides,
        );
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                \is_array($value)
                && \array_key_exists($key, $base)
                && \is_array($base[$key])
            ) {
                /** @var array<string, mixed> $baseValue */
                $baseValue = $base[$key];

                /** @var array<string, mixed> $value */
                $base[$key] = self::mergeRecursiveDistinct($baseValue, $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function assertSafeJsonSummary(array $payload): void
    {
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR);

        foreach (
            [
                'var/tmp/private-start.sock',
                'var/tmp/private-start.state.json',
                'var/tmp/private-start.stop',
                '10.11.12.13',
                '9751',
                'tcp:10.11.12.13:9751',
                'tcp://10.11.12.13:9751',
                __DIR__,
                \str_replace('/', '\\', __DIR__),
                'worker.enabled',
                'worker.socket_path',
                'worker.tcp.host',
                'worker.tcp.port',
                'kernel.runtime',
                'config',
                'env',
                'PATH=',
                'HOME=',
                'payload',
                'headers',
                'Authorization',
                'authorization',
                'cookie',
                'secret',
                'token',
                'bearer',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $json,
                'Start summary must not expose unsafe fragment: ' . $forbidden,
            );
        }
    }

    private static function workerStartCommandSource(): string
    {
        $file = new \ReflectionClass(WorkerStartCommand::class)->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }

    private static function workerStartCommandExecutableSource(): string
    {
        $tokens = \token_get_all(self::workerStartCommandSource());

        $source = '';

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                $source .= $token;

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

            $source .= $token[1];
        }

        return $source;
    }
}

final class WorkerStartArrayConfigRepository implements ConfigRepositoryInterface
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

final class WorkerStartParsedInput implements InputInterface
{
    public bool $tokensCalled = false;

    /**
     * @param list<string> $arguments
     * @param array<string, string|bool|list<string>|null> $options
     */
    public function __construct(
        private readonly string $commandName,
        private readonly array $arguments = [],
        private readonly array $options = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        $this->tokensCalled = true;

        throw new \LogicException('InputInterface::tokens() must not be called by WorkerStartCommand.');
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
        return $this->arguments;
    }

    /**
     * @return array<string, string|bool|list<string>|null>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function hasOption(string $name): bool
    {
        return \array_key_exists($name, $this->options);
    }

    public function option(string $name): string|bool|array|null
    {
        return $this->options[$name] ?? null;
    }
}

final class WorkerStartRecordingOutput implements OutputInterface
{
    /**
     * @var list<string>
     */
    public array $texts = [];

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
        $this->texts[] = $text;
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

final class WorkerStartRecordingDriver implements WorkerManagerDriverInterface
{
    public int $startCalls = 0;

    public ?WorkerPoolSpec $lastStartSpec = null;

    public function __construct(
        private readonly ?WorkerPoolState $state = null,
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
        $this->lastStartSpec = $spec;

        if ($this->state === null) {
            throw WorkerStartFailedException::startFailed();
        }

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

final class WorkerStartSilentTracer implements TracerPortInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new WorkerStartSilentSpan($name);
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

final readonly class WorkerStartSilentSpan implements SpanInterface
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

final class WorkerStartSilentMeter implements MeterPortInterface
{
    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
    }

    public function observe(string $name, int $value, array $labels = []): void
    {
    }
}

final class WorkerStartSilentLogger implements LoggerInterface
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
