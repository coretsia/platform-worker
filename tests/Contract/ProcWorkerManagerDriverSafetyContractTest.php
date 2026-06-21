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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Manager\Driver\ProcWorkerManagerDriver;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\TestCase;

final class ProcWorkerManagerDriverSafetyContractTest extends TestCase
{
    public function testGeneratedCommandDoesNotIncludeRawSocketPathTcpEndpointPayloadHeadersOrTokens(): void
    {
        $spec = self::workerSpec([
            'workers' => 3,
            'max_requests' => 17,
            'task_type' => 'queue',
            'socket_path' => 'var/tmp/private-worker-control.sock',
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '10.20.30.40',
                'port' => 9511,
            ],
        ]);

        $command = self::generatedWorkerCommand(
            baseCommand: [
                'php',
                'worker-entrypoint',
            ],
            spec: $spec,
            workerIndex: 2,
        );

        self::assertSame(
            [
                'php',
                'worker-entrypoint',
                '--coretsia-worker-index=2',
                '--coretsia-worker-count=3',
                '--coretsia-worker-max-requests=17',
                '--coretsia-worker-task-type=queue',
                '--coretsia-worker-driver=proc',
                '--coretsia-worker-config=var/cache/worker/config.php',
                '--coretsia-worker-container=var/cache/worker/container.php',
            ],
            $command,
        );

        self::assertCommandDoesNotContainUnsafeFragments(
            command: $command,
            forbiddenFragments: [
                'var/tmp/private-worker-control.sock',
                'private-worker-control.sock',
                'unix:var/tmp/private-worker-control.sock',
                'unix://',
                '10.20.30.40',
                '10.20.30.40:9511',
                ':9511',
                'tcp:10.20.30.40:9511',
                'tcp://10.20.30.40:9511',
                'payload',
                'task_payload',
                'body',
                'headers',
                'Authorization',
                'cookie',
                'token',
                'secret',
            ],
        );
    }

    public function testGeneratedUnixCommandDoesNotIncludeRawSocketPath(): void
    {
        $socketPath = 'var/tmp/private-worker-control.sock';

        $spec = self::workerSpec(
            overrides: [
                'workers' => 1,
                'max_requests' => 5,
                'task_type' => 'queue',
                'socket_path' => $socketPath,
                'driver' => 'proc',
                'control' => [
                    'transport' => 'unix',
                ],
            ],
            unixDomainSocketsSupported: true,
        );

        $command = self::generatedWorkerCommand(
            baseCommand: [
                'php',
                'worker-entrypoint',
            ],
            spec: $spec,
            workerIndex: 0,
        );

        self::assertCommandDoesNotContainUnsafeFragments(
            command: $command,
            forbiddenFragments: [
                $socketPath,
                'private-worker-control.sock',
                'unix:' . $socketPath,
                'unix://',
            ],
        );
    }

    public function testDriverDoesNotWriteWorkerStateJsonDirectly(): void
    {
        $source = self::classSource(ProcWorkerManagerDriver::class);

        self::assertStringContainsString('$this->stateStore->write(', $source);
        self::assertStringContainsString('$this->stateStore->read(', $source);

        self::assertStringNotContainsString('worker.state.json', $source);
        self::assertStringNotContainsString('StableJsonEncoder', $source);
        self::assertStringNotContainsString('->statePath()', $source);
        self::assertStringNotContainsString('$spec->statePath()', $source);
    }

    public function testExceptionMessagesDoNotExposeCommandLinesAbsolutePathsEnvValuesOrRawEndpoints(): void
    {
        $driver = new ProcWorkerManagerDriver(
            skeletonRoot: '/srv/coretsia/runtime',
            stateStore: self::stateStore(),
            controlChannel: new WorkerSocketServer(),
            workerCommand: [
                '/usr/local/bin/php',
                'worker-entrypoint',
                '--env=SUPER_SECRET_ENV_VALUE',
                '--endpoint=tcp://10.20.30.40:9511',
                '--token=secret-token',
            ],
            configArtifactPath: 'var/cache/worker/config.php',
            containerArtifactPath: 'var/cache/worker/container.php',
        );

        $unsupportedSpec = self::workerSpec([
            'driver' => 'pcntl',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '10.20.30.40',
                'port' => 9511,
            ],
            'socket_path' => 'var/tmp/private-worker-control.sock',
        ]);

        try {
            $driver->start($unsupportedSpec);

            self::fail('Expected WorkerStartFailedException was not thrown.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
            self::assertSame(WorkerStartFailedException::REASON_START_FAILED, $exception->reason());

            self::assertSafeExceptionMessage($exception->getMessage());
        }
    }

    public function testConstructorFailureMessagesDoNotExposeUnsafeCommandOrEnvironmentData(): void
    {
        try {
            new ProcWorkerManagerDriver(
                skeletonRoot: '/srv/coretsia/runtime',
                stateStore: self::stateStore(),
                controlChannel: new WorkerSocketServer(),
                workerCommand: [
                    '/usr/local/bin/php',
                    'worker-entrypoint',
                    '--env=SUPER_SECRET_ENV_VALUE',
                    "--token=secret-token\n",
                    '--endpoint=tcp://10.20.30.40:9511',
                ],
                configArtifactPath: 'var/cache/worker/config.php',
                containerArtifactPath: 'var/cache/worker/container.php',
            );

            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('proc-worker-command-invalid', $exception->getMessage());

            self::assertSafeExceptionMessage($exception->getMessage());
        }
    }

    public function testProcDriverSafetyContract(): void
    {
        self::captureStdout(
            static function (): void {
                $spec = self::workerSpec([
                    'driver' => 'proc',
                    'control' => [
                        'transport' => 'tcp',
                    ],
                    'tcp' => [
                        'host' => '127.0.0.1',
                        'port' => 9501,
                    ],
                ]);

                $command = self::generatedWorkerCommand(
                    baseCommand: [
                        'php',
                        'worker-entrypoint',
                    ],
                    spec: $spec,
                    workerIndex: 0,
                );

                self::assertSame(
                    [
                        'php',
                        'worker-entrypoint',
                        '--coretsia-worker-index=0',
                        '--coretsia-worker-count=4',
                        '--coretsia-worker-max-requests=1000',
                        '--coretsia-worker-task-type=queue',
                        '--coretsia-worker-driver=proc',
                        '--coretsia-worker-config=var/cache/worker/config.php',
                        '--coretsia-worker-container=var/cache/worker/container.php',
                    ],
                    $command,
                );
            },
        );

        $source = self::classSource(ProcWorkerManagerDriver::class);

        self::assertStringNotContainsString('pcntl_', $source);
        self::assertStringNotContainsString('ApplicationWorker', $source);
        self::assertStringNotContainsString('KernelRuntimeInterface', $source);
        self::assertStringNotContainsString('TaskFactoryInternalInterface', $source);

        self::assertStringNotContainsString('Platform\\Cli', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Cli', $source);
        self::assertStringNotContainsString('Platform\\Http', $source);
        self::assertStringNotContainsString('Coretsia\\Platform\\Http', $source);

        self::assertStringNotContainsString('STDOUT', $source);
        self::assertStringNotContainsString('STDERR', $source);
        self::assertStringNotContainsString('fwrite(', $source);
        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('error_log(', $source);
    }

    /**
     * @param list<non-empty-string> $baseCommand
     *
     * @return list<non-empty-string>
     */
    private static function generatedWorkerCommand(
        array $baseCommand,
        WorkerPoolSpec $spec,
        int $workerIndex,
    ): array {
        $reflection = new \ReflectionMethod(ProcWorkerManagerDriver::class, 'workerCommand');

        /** @var list<non-empty-string> $command */
        $command = $reflection->invoke(
            null,
            $baseCommand,
            $spec,
            $workerIndex,
            'var/cache/worker/config.php',
            'var/cache/worker/container.php',
        );

        return $command;
    }

    /**
     * @param list<non-empty-string> $command
     * @param list<string> $forbiddenFragments
     */
    private static function assertCommandDoesNotContainUnsafeFragments(
        array $command,
        array $forbiddenFragments,
    ): void {
        $flatCommand = \implode(' ', $command);

        foreach ($forbiddenFragments as $fragment) {
            if ($fragment === '') {
                continue;
            }

            self::assertStringNotContainsString($fragment, $flatCommand);
        }
    }

    private static function assertSafeExceptionMessage(string $message): void
    {
        self::assertStringNotContainsString('/usr/local/bin/php worker-entrypoint', $message);
        self::assertStringNotContainsString('/usr/local/bin/php', $message);
        self::assertStringNotContainsString('/srv/coretsia/runtime', $message);
        self::assertStringNotContainsString('SUPER_SECRET_ENV_VALUE', $message);
        self::assertStringNotContainsString('--env=SUPER_SECRET_ENV_VALUE', $message);
        self::assertStringNotContainsString('10.20.30.40', $message);
        self::assertStringNotContainsString('10.20.30.40:9511', $message);
        self::assertStringNotContainsString(':9511', $message);
        self::assertStringNotContainsString('tcp:10.20.30.40:9511', $message);
        self::assertStringNotContainsString('tcp://10.20.30.40:9511', $message);
        self::assertStringNotContainsString('var/tmp/private-worker-control.sock', $message);
        self::assertStringNotContainsString('private-worker-control.sock', $message);
        self::assertStringNotContainsString('payload', $message);
        self::assertStringNotContainsString('task_payload', $message);
        self::assertStringNotContainsString('headers', $message);
        self::assertStringNotContainsString('Authorization', $message);
        self::assertStringNotContainsString('token', $message);
        self::assertStringNotContainsString('secret-token', $message);
    }

    private static function stateStore(): WorkerStateStore
    {
        return new WorkerStateStore(
            encoder: new StableJsonEncoder(),
            decoder: new StableJsonDecoder(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function workerSpec(
        array $overrides = [],
        bool $pcntlForkAvailable = false,
        string $platformFamily = 'Linux',
        bool $unixDomainSocketsSupported = false,
    ): WorkerPoolSpec {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig($overrides),
            pcntlForkAvailable: $pcntlForkAvailable,
            platformFamily: $platformFamily,
            unixDomainSocketsSupported: $unixDomainSocketsSupported,
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
                'workers' => 4,
                'max_requests' => 1000,
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
                'stop_timeout_ms' => 0,
            ],
            $overrides,
        );
    }

    private static function captureStdout(\Closure $callback): mixed
    {
        \ob_start();

        try {
            $result = $callback();
            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        return $result;
    }

    /**
     * @param class-string $className
     */
    private static function classSource(string $className): string
    {
        $reflection = new \ReflectionClass($className);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }
}
