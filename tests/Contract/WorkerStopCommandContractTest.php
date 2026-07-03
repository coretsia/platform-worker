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
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
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

final class WorkerStopCommandContractTest extends TestCase
{
    public function testSuccessfulStopUsesDirectCommandRunPathAndEmitsSafeJsonSummary(): void
    {
        $config = new WorkerStopArrayConfigRepository(self::workerConfig());
        $driver = new WorkerStopRecordingDriver(
            state: self::stoppedState(),
        );

        $command = new WorkerStopCommand(
            config: $config,
            factory: new WorkerServiceFactory(),
            manager: self::workerManager($driver),
        );

        $input = new WorkerStopParsedInput(
            commandName: WorkerStopCommand::NAME,
        );

        $output = new WorkerStopRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);
        self::assertFalse($input->tokensCalled, 'WorkerStopCommand must not call InputInterface::tokens().');

        self::assertGreaterThan(0, $config->hasCalls);
        self::assertGreaterThan(0, $config->getCalls);

        self::assertSame(1, $driver->stopCalls);
        self::assertInstanceOf(WorkerPoolSpec::class, $driver->lastStopSpec);

        self::assertSame('proc', $driver->lastStopSpec->driver());
        self::assertSame('tcp', $driver->lastStopSpec->controlTransport());
        self::assertSame('var/tmp/private-stop.sock', $driver->lastStopSpec->socketPath());
        self::assertSame('10.77.66.55', $driver->lastStopSpec->tcpHost());
        self::assertSame(9741, $driver->lastStopSpec->tcpPort());
        self::assertSame('var/tmp/private-stop.stop', $driver->lastStopSpec->stopFlagPath());

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

        self::assertSame('stopped', $payload['status']);
        self::assertSame(5432, $payload['pid']);
        self::assertSame(2, $payload['worker_count']);
        self::assertSame('proc', $payload['driver']);
        self::assertSame('tcp', $payload['control_transport']);
        self::assertSame(\hash('sha256', 'tcp:10.77.66.55:9741'), $payload['endpoint_hash']);

        self::assertSafeJsonSummary($payload);
    }

    public function testCommandSourceDelegatesToWorkerServiceFactoryAndWorkerManagerStop(): void
    {
        $source = self::workerStopCommandSource();

        self::assertStringContainsString('$this->factory->workerPoolSpec($this->config)', $source);
        self::assertStringContainsString('$this->manager->stop($spec)', $source);
    }

    public function testCommandSourceDoesNotUseFullBinaryOrCatalogDispatch(): void
    {
        $source = self::workerStopCommandSource();

        self::assertStringNotContainsString('CommandCatalog', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Cli', $source);
        self::assertStringNotContainsString('Application::class', $source);
        self::assertStringNotContainsString('dispatch(', $source);
        self::assertStringNotContainsString('parse(', $source);
    }

    public function testCommunicationFailureReturnsOriginalCodeAndReason(): void
    {
        $output = $this->runWithDriver(
            new WorkerStopRecordingDriver(
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

    public function testNotRunningCaseFailsDeterministicallyWithOriginalCodeAndReason(): void
    {
        $output = $this->runWithDriver(
            new WorkerStopRecordingDriver(
                exception: WorkerNotRunningException::notRunning(),
            ),
        );

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_NOT_RUNNING',
                    'message' => 'worker-not-running',
                ],
            ],
            $output->errors,
        );
    }

    public function testInvalidWorkerStateDoesNotGetTranslatedToNotRunning(): void
    {
        $output = $this->runWithDriver(
            new WorkerStopRecordingDriver(
                exception: WorkerStartFailedException::invalidState(),
            ),
        );

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_STOP_FAILED',
                    'message' => 'worker-stop-failed',
                ],
            ],
            $output->errors,
        );

        self::assertNotSame('CORETSIA_WORKER_NOT_RUNNING', $output->errors[0]['code']);
        self::assertNotSame('worker-not-running', $output->errors[0]['message']);
    }

    public function testInvalidCommandNameFailsSafely(): void
    {
        $driver = new WorkerStopRecordingDriver(
            state: self::stoppedState(),
        );

        $command = self::command($driver);
        $input = new WorkerStopParsedInput(commandName: 'worker:status');
        $output = new WorkerStopRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $driver->stopCalls);
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
        $driver = new WorkerStopRecordingDriver(
            state: self::stoppedState(),
        );

        $command = self::command($driver);
        $input = new WorkerStopParsedInput(
            commandName: WorkerStopCommand::NAME,
            arguments: ['unexpected'],
        );
        $output = new WorkerStopRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $driver->stopCalls);
        self::assertFalse($input->tokensCalled);

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_COMMAND_INVALID',
                    'message' => 'worker-stop-arguments-not-supported',
                ],
            ],
            $output->errors,
        );
    }

    public function testUnsupportedOptionsFailSafely(): void
    {
        $driver = new WorkerStopRecordingDriver(
            state: self::stoppedState(),
        );

        $command = self::command($driver);
        $input = new WorkerStopParsedInput(
            commandName: WorkerStopCommand::NAME,
            options: ['force' => true],
        );
        $output = new WorkerStopRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(0, $driver->stopCalls);
        self::assertFalse($input->tokensCalled);

        self::assertSame(
            [
                [
                    'code' => 'CORETSIA_WORKER_COMMAND_INVALID',
                    'message' => 'worker-stop-options-not-supported',
                ],
            ],
            $output->errors,
        );
    }

    public function testCommandSourceDoesNotCallInputTokens(): void
    {
        $source = self::workerStopCommandSource();

        self::assertStringNotContainsString('->tokens(', $source);
    }

    private function runWithDriver(WorkerStopRecordingDriver $driver): WorkerStopRecordingOutput
    {
        $command = self::command($driver);
        $input = new WorkerStopParsedInput(commandName: WorkerStopCommand::NAME);
        $output = new WorkerStopRecordingOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertSame(1, $driver->stopCalls);
        self::assertSame([], $output->texts);
        self::assertSame([], $output->jsonPayloads);
        self::assertFalse($input->tokensCalled);

        foreach ($output->errors as $error) {
            self::assertSafeDiagnostics($error['code']);
            self::assertSafeDiagnostics($error['message']);
        }

        return $output;
    }

    private static function command(WorkerStopRecordingDriver $driver): WorkerStopCommand
    {
        return new WorkerStopCommand(
            config: new WorkerStopArrayConfigRepository(self::workerConfig()),
            factory: new WorkerServiceFactory(),
            manager: self::workerManager($driver),
        );
    }

    private static function workerManager(WorkerStopRecordingDriver $driver): WorkerManager
    {
        return new WorkerManager(
            drivers: [$driver],
            tracer: new WorkerStopSilentTracer(),
            meter: new WorkerStopSilentMeter(),
            logger: new WorkerStopSilentLogger(),
            stopwatch: new Stopwatch(),
        );
    }

    private static function stoppedState(): WorkerPoolState
    {
        return new WorkerPoolState(
            pid: 5432,
            workerCount: 2,
            driverRequested: 'proc',
            driver: 'proc',
            controlTransportRequested: 'tcp',
            controlTransport: 'tcp',
            endpointHash: \hash('sha256', 'tcp:10.77.66.55:9741'),
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
                'socket_path' => 'var/tmp/private-stop.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '10.77.66.55',
                    'port' => 9741,
                ],
                'state_path' => 'var/tmp/private-stop.state.json',
                'stop_flag_path' => 'var/tmp/private-stop.stop',
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
                'var/tmp/private-stop.sock',
                'var/tmp/private-stop.state.json',
                'var/tmp/private-stop.stop',
                '10.77.66.55',
                '9741',
                'tcp:10.77.66.55:9741',
                'tcp://10.77.66.55:9741',
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
                'Stop summary must not expose unsafe fragment: ' . $forbidden,
            );
        }
    }

    private static function assertSafeDiagnostics(string $diagnostic): void
    {
        foreach (
            [
                'var/tmp/private-stop.sock',
                'var/tmp/private-stop.state.json',
                'var/tmp/private-stop.stop',
                '10.77.66.55',
                '9741',
                'tcp:10.77.66.55:9741',
                'tcp://10.77.66.55:9741',
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
                'Stop diagnostics must not expose unsafe fragment: ' . $forbidden,
            );
        }
    }

    private static function workerStopCommandSource(): string
    {
        $file = new \ReflectionClass(WorkerStopCommand::class)->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }
}

final class WorkerStopArrayConfigRepository implements ConfigRepositoryInterface
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
     * @return array<string, mixed>
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

final class WorkerStopParsedInput implements InputInterface
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

        throw new \LogicException('InputInterface::tokens() must not be called by WorkerStopCommand.');
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

final class WorkerStopRecordingOutput implements OutputInterface
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

final class WorkerStopRecordingDriver implements WorkerManagerDriverInterface
{
    public int $stopCalls = 0;

    public ?WorkerPoolSpec $lastStopSpec = null;

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
        $this->stopCalls++;
        $this->lastStopSpec = $spec;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->state === null) {
            throw WorkerStartFailedException::startFailed();
        }

        return $this->state;
    }

    public function status(WorkerPoolSpec $spec): WorkerPoolState
    {
        throw WorkerStartFailedException::startFailed();
    }
}

final class WorkerStopSilentTracer implements TracerPortInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new WorkerStopSilentSpan($name);
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

final readonly class WorkerStopSilentSpan implements SpanInterface
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

final class WorkerStopSilentMeter implements MeterPortInterface
{
    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
    }

    public function observe(string $name, int $value, array $labels = []): void
    {
    }
}

final class WorkerStopSilentLogger implements LoggerInterface
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
