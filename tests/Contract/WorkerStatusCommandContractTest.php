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
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WorkerStatusCommandContractTest extends TestCase
{
    public function testSuccessfulStatusUsesDirectCommandRunPathAndEmitsSafeJsonSummary(): void
    {
        $config = new ArrayConfigRepository(self::workerConfig());
        $driver = new RecordingStatusDriver(
            state: self::runningState(),
        );

        $command = new WorkerStatusCommand(
            config: $config,
            factory: new WorkerServiceFactory(),
            manager: self::workerManager($driver),
        );

        $input = new ParsedInput(
            commandName: WorkerStatusCommand::NAME,
        );

        $output = new RecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);

        self::assertFalse($input->tokensCalled, 'WorkerStatusCommand must not call InputInterface::tokens().');

        self::assertSame(1, $config->hasCalls);
        self::assertSame(1, $config->getCalls);

        self::assertSame(1, $driver->statusCalls);
        self::assertInstanceOf(WorkerPoolSpec::class, $driver->lastStatusSpec);

        self::assertSame('proc', $driver->lastStatusSpec->driver());
        self::assertSame('tcp', $driver->lastStatusSpec->controlTransport());
        self::assertSame('var/tmp/private-status.sock', $driver->lastStatusSpec->socketPath());
        self::assertSame('10.99.88.77', $driver->lastStatusSpec->tcpHost());
        self::assertSame(9731, $driver->lastStatusSpec->tcpPort());

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

        self::assertSame('running', $payload['status']);
        self::assertSame(4321, $payload['pid']);
        self::assertSame(2, $payload['worker_count']);
        self::assertSame('proc', $payload['driver']);
        self::assertSame('tcp', $payload['control_transport']);
        self::assertSame(\hash('sha256', 'tcp:10.99.88.77:9731'), $payload['endpoint_hash']);

        self::assertSafeJsonSummary($payload);
    }

    public function testCommandSourceDelegatesToWorkerServiceFactoryAndWorkerManagerStatus(): void
    {
        $source = self::workerStatusCommandSource();

        self::assertStringContainsString('$this->factory->workerPoolSpec($this->config)', $source);
        self::assertStringContainsString('$this->manager->status($spec)', $source);
    }

    public function testCommandSourceDoesNotUseFullBinaryOrCatalogDispatch(): void
    {
        $source = self::workerStatusCommandSource();

        self::assertStringNotContainsString('CommandCatalog', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Cli', $source);
        self::assertStringNotContainsString('Application::class', $source);
        self::assertStringNotContainsString('dispatch(', $source);
        self::assertStringNotContainsString('parse(', $source);
    }

    public function testNotRunningCaseReturnsOriginalCodeAndReason(): void
    {
        $output = $this->runWithDriver(
            new RecordingStatusDriver(
                exception: WorkerNotRunningException::notRunning(),
            ),
        );

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_NOT_RUNNING',
                    'message' => 'not_running',
                ],
            ],
            $output->errors,
        );
    }

    public function testCommunicationFailureReturnsOriginalCodeAndReason(): void
    {
        $output = $this->runWithDriver(
            new RecordingStatusDriver(
                exception: WorkerCommunicationFailedException::communicationFailed(),
            ),
        );

        self::assertSame(
            [
                [
                    'code' => WorkerCommunicationFailedException::ERROR_CODE,
                    'message' => WorkerCommunicationFailedException::REASON_COMMUNICATION_FAILED,
                ],
            ],
            $output->errors,
        );
    }

    public function testWorkerStartOrStateFailureReturnsStatusFailed(): void
    {
        $output = $this->runWithDriver(
            new RecordingStatusDriver(
                exception: WorkerStartFailedException::invalidState(),
            ),
        );

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_STATUS_FAILED',
                    'message' => 'worker-status-failed',
                ],
            ],
            $output->errors,
        );
    }

    public function testInvalidParsedCommandNameFailsSafely(): void
    {
        $driver = new RecordingStatusDriver(
            state: self::runningState(),
        );

        $command = self::command($driver);
        $input = new ParsedInput(commandName: 'worker:start');
        $output = new RecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $driver->statusCalls);
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
        $driver = new RecordingStatusDriver(
            state: self::runningState(),
        );

        $command = self::command($driver);
        $input = new ParsedInput(
            commandName: WorkerStatusCommand::NAME,
            arguments: ['unexpected'],
        );
        $output = new RecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $driver->statusCalls);
        self::assertFalse($input->tokensCalled);

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_COMMAND_INVALID',
                    'message' => 'worker-status-arguments-not-supported',
                ],
            ],
            $output->errors,
        );
    }

    public function testUnsupportedOptionsFailSafely(): void
    {
        $driver = new RecordingStatusDriver(
            state: self::runningState(),
        );

        $command = self::command($driver);
        $input = new ParsedInput(
            commandName: WorkerStatusCommand::NAME,
            options: ['verbose' => true],
        );
        $output = new RecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $driver->statusCalls);
        self::assertFalse($input->tokensCalled);

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_COMMAND_INVALID',
                    'message' => 'worker-status-options-not-supported',
                ],
            ],
            $output->errors,
        );
    }

    public function testCommandSourceDoesNotCallInputTokens(): void
    {
        $source = self::workerStatusCommandSource();

        self::assertStringNotContainsString('->tokens(', $source);
    }

    private function runWithDriver(RecordingStatusDriver $driver): RecordingOutput
    {
        $command = self::command($driver);
        $input = new ParsedInput(commandName: WorkerStatusCommand::NAME);
        $output = new RecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(1, $driver->statusCalls);
        self::assertSame([], $output->texts);
        self::assertSame([], $output->jsonPayloads);
        self::assertFalse($input->tokensCalled);

        foreach ($output->errors as $error) {
            self::assertSafeDiagnostics($error['code']);
            self::assertSafeDiagnostics($error['message']);
        }

        return $output;
    }

    private static function command(RecordingStatusDriver $driver): WorkerStatusCommand
    {
        return new WorkerStatusCommand(
            config: new ArrayConfigRepository(self::workerConfig()),
            factory: new WorkerServiceFactory(),
            manager: self::workerManager($driver),
        );
    }

    private static function workerManager(RecordingStatusDriver $driver): WorkerManager
    {
        return new WorkerManager(
            drivers: [$driver],
            tracer: new WorkerStatusSilentTracer(),
            meter: new WorkerStatusSilentMeter(),
            logger: new WorkerStatusSilentLogger(),
            stopwatch: new Stopwatch(),
        );
    }

    private static function runningState(): WorkerPoolState
    {
        return new WorkerPoolState(
            pid: 4321,
            workerCount: 2,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: \hash('sha256', 'tcp:10.99.88.77:9731'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerConfig(): array
    {
        return [
            'worker' => [
                'enabled' => true,
                'workers' => 2,
                'max_requests' => 100,
                'task_type' => 'queue',
                'socket_path' => 'var/tmp/private-status.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '10.99.88.77',
                    'port' => 9731,
                ],
                'state_path' => 'var/tmp/private-status.state.json',
                'stop_flag_path' => 'var/tmp/private-status.stop',
                'stop_timeout_ms' => 3000,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function assertSafeJsonSummary(array $payload): void
    {
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR);

        foreach (
            [
                'var/tmp/private-status.sock',
                'var/tmp/private-status.state.json',
                'var/tmp/private-status.stop',
                '10.99.88.77',
                '9731',
                'tcp:10.99.88.77:9731',
                'tcp://10.99.88.77:9731',
                __DIR__,
                \str_replace('/', '\\', __DIR__),
                'worker.enabled',
                'worker.socket_path',
                'worker.tcp.host',
                'worker.tcp.port',
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
                'Status summary must not expose unsafe fragment: ' . $forbidden,
            );
        }
    }

    private static function assertSafeDiagnostics(string $diagnostic): void
    {
        foreach (
            [
                'var/tmp/private-status.sock',
                'var/tmp/private-status.state.json',
                'var/tmp/private-status.stop',
                '10.99.88.77',
                '9731',
                'tcp:10.99.88.77:9731',
                'tcp://10.99.88.77:9731',
                __DIR__,
                \str_replace('/', '\\', __DIR__),
                'worker.enabled',
                'worker.socket_path',
                'worker.tcp.host',
                'worker.tcp.port',
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
                'trace',
                '#0 ',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $diagnostic,
                'Status diagnostics must not expose unsafe fragment: ' . $forbidden,
            );
        }
    }

    private static function workerStatusCommandSource(): string
    {
        $file = new \ReflectionClass(WorkerStatusCommand::class)->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }
}

final class ArrayConfigRepository implements ConfigRepositoryInterface
{
    public int $hasCalls = 0;
    public int $getCalls = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function has(string $keyPath): bool
    {
        $this->hasCalls++;

        $missing = new \stdClass();

        return $this->value($keyPath, $missing) !== $missing;
    }

    public function get(string $keyPath, mixed $default = null): mixed
    {
        $this->getCalls++;

        return $this->value($keyPath, $default);
    }

    public function all(): array
    {
        return $this->config;
    }

    public function sourceOf(string $keyPath): ?ConfigValueSource
    {
        return null;
    }

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

final class ParsedInput implements InputInterface
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

    public function tokens(): array
    {
        $this->tokensCalled = true;

        throw new \LogicException('InputInterface::tokens() must not be called by WorkerStatusCommand.');
    }

    public function commandName(): string
    {
        return $this->commandName;
    }

    public function arguments(): array
    {
        return $this->arguments;
    }

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

final class RecordingOutput implements OutputInterface
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

final class RecordingStatusDriver implements WorkerManagerDriverInterface
{
    public int $statusCalls = 0;

    public ?WorkerPoolSpec $lastStatusSpec = null;

    public function __construct(
        private readonly ?WorkerPoolState $state = null,
        private readonly ?\Throwable $exception = null,
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
        throw WorkerStartFailedException::startFailed();
    }

    public function status(WorkerPoolSpec $spec): WorkerPoolState
    {
        $this->statusCalls++;
        $this->lastStatusSpec = $spec;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->state === null) {
            throw WorkerStartFailedException::startFailed();
        }

        return $this->state;
    }
}

final class WorkerStatusSilentTracer implements TracerPortInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new WorkerStatusSilentSpan($name);
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

final readonly class WorkerStatusSilentSpan implements SpanInterface
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

final class WorkerStatusSilentMeter implements MeterPortInterface
{
    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
    }

    public function observe(string $name, int $value, array $labels = []): void
    {
    }
}

final class WorkerStatusSilentLogger implements LoggerInterface
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
