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
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Module\ModulePlanEntry;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverGuard;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverInvalidConfigException;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class WorkerHttpTaskRequiresRequestHandlerTest extends TestCase
{
    public function testWorkerStartCommandInvokesRuntimeDriverGuardWithoutPlatformCliCatalog(): void
    {
        $config = self::configRepository(
            self::workerHttpConfig(),
        );
        $input = new ParsedWorkerStartInput(WorkerStartCommand::NAME);
        $output = new RecordingOutput();

        $managerFactoryCalled = false;

        $command = new WorkerStartCommand(
            config: $config,
            modulePlan: self::modulePlanWithoutPlatformHttp(),
            runtimeDriverGuard: new RuntimeDriverGuard(),
            factory: new WorkerServiceFactory(),
            managerFactory: static function () use (&$managerFactoryCalled): never {
                $managerFactoryCalled = true;

                throw new \LogicException('WorkerManager must not be resolved before runtime-driver guard failure.');
            },
        );

        $exitCode = $command->run($input, $output);

        self::assertSame(1, $exitCode);
        self::assertFalse(
            $input->tokensCalled,
            'WorkerStartCommand MUST NOT parse raw tokens or require platform/cli catalog dispatch.'
        );
        self::assertFalse(
            $managerFactoryCalled,
            'WorkerManager factory MUST NOT be invoked when RuntimeDriverGuard rejects module compatibility.'
        );

        self::assertSame(
            [
                [
                    'code' => RuntimeDriverInvalidConfigException::ERROR_CODE,
                    'message' => RuntimeDriverInvalidConfigException::REASON_REQUIRES_PLATFORM_HTTP_MODULE,
                ],
            ],
            $output->errors,
        );

        self::assertSame([], $output->jsonPayloads);
        self::assertSame([], $output->texts);
    }

    public function testHttpTaskModeRequiresRequestHandlerAfterRuntimeDriverAndModuleCompatibilityPass(): void
    {
        $config = self::configRepository(
            self::workerHttpConfig(),
        );
        $container = new MissingRequestHandlerContainer();

        $factory = new HttpTaskFactory(
            config: $config,
            modulePlan: self::modulePlanWithPlatformHttp(),
            runtimeDriverGuard: new RuntimeDriverGuard(),
            container: $container,
        );

        $exception = self::catchWorkerStartFailed(
            static fn (): array => $factory->create(self::httpSpec()),
        );

        self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerStartFailedException::REASON_REQUEST_HANDLER_MISSING, $exception->reason());
        self::assertSame(
            'CORETSIA_WORKER_START_FAILED: request_handler_missing',
            $exception->getMessage(),
        );

        self::assertTrue($container->hasCalled);
        self::assertFalse($container->getCalled);

        self::assertSame(
            [
                'kernel.runtime.frankenphp.enabled',
                'kernel.runtime.swoole.enabled',
                'kernel.runtime.roadrunner.enabled',
                'worker.enabled',
                'worker.task_type',
            ],
            $config->guardReadKeys(),
            'RuntimeDriverGuard must run before RequestHandlerInterface resolution.',
        );
    }

    public function testHttpTaskModeMapsUnresolvableRequestHandlerDeterministicallyAfterGuardPasses(): void
    {
        $config = self::configRepository(
            self::workerHttpConfig(),
        );
        $container = new UnresolvableRequestHandlerContainer();

        $factory = new HttpTaskFactory(
            config: $config,
            modulePlan: self::modulePlanWithPlatformHttp(),
            runtimeDriverGuard: new RuntimeDriverGuard(),
            container: $container,
        );

        $exception = self::catchWorkerStartFailed(
            static fn (): array => $factory->create(self::httpSpec()),
        );

        self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerStartFailedException::REASON_REQUEST_HANDLER_UNRESOLVABLE, $exception->reason());
        self::assertSame(
            'CORETSIA_WORKER_START_FAILED: request_handler_unresolvable',
            $exception->getMessage(),
        );

        self::assertTrue($container->hasCalled);
        self::assertTrue($container->getCalled);
    }

    public function testHttpTaskFactoryChecksRuntimeDriverCompatibilityBeforeRequestHandlerResolution(): void
    {
        $source = self::methodSource(HttpTaskFactory::class, 'create');

        $guardOffset = \strpos($source, '$this->assertRuntimeDriverCompatibilityHasPassed();');
        $handlerOffset = \strpos($source, '$this->assertRequestHandlerResolvable();');

        self::assertIsInt($guardOffset);
        self::assertIsInt($handlerOffset);
        self::assertLessThan(
            $handlerOffset,
            $guardOffset,
            'HttpTaskFactory::create() MUST run RuntimeDriverGuard/module compatibility before RequestHandlerInterface resolution.',
        );
    }

    public function testWorkerStartCommandAndHarnessDoNotUsePlatformCliCatalogDiscovery(): void
    {
        $workerStartExecutableSource = self::executableSource(WorkerStartCommand::class);
        $httpTaskFactoryExecutableSource = self::executableSource(HttpTaskFactory::class);

        foreach (
            [
                'Coretsia\\Platform\\Cli',
                'Platform\\Cli',
                'CommandCatalog',
                'CommandRegistry',
                'CliApplication',
                'tokens',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $workerStartExecutableSource);
            self::assertStringNotContainsString($forbidden, $httpTaskFactoryExecutableSource);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerHttpConfig(): array
    {
        return [
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
                'workers' => 1,
                'max_requests' => 1,
                'task_type' => 'http',
                'socket_path' => 'var/tmp/worker.sock',
                'driver' => 'proc',
                'proc' => [
                    'command' => [
                        '@php',
                        'vendor/coretsia/platform-worker/bin/coretsia-worker',
                    ],
                ],
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

    private static function httpSpec(): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: self::workerHttpConfig()['worker'],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }

    private static function modulePlanWithPlatformHttp(): ModulePlan
    {
        return self::modulePlan(includePlatformHttp: true);
    }

    private static function modulePlanWithoutPlatformHttp(): ModulePlan
    {
        return self::modulePlan(includePlatformHttp: false);
    }

    private static function modulePlan(bool $includePlatformHttp): ModulePlan
    {
        $foundation = ModuleId::fromString('core.foundation');
        $kernel = ModuleId::fromString('core.kernel');
        $worker = ModuleId::fromString('platform.worker');
        $http = ModuleId::fromString('platform.http');

        $enabled = [
            $foundation,
            $kernel,
            $worker,
        ];

        $topologicalOrder = [
            $foundation,
            $kernel,
            $worker,
        ];

        $modules = [
            new ModulePlanEntry(
                moduleId: $foundation,
                composerName: 'coretsia/core-foundation',
                requires: [],
                conflicts: [],
            ),
            new ModulePlanEntry(
                moduleId: $kernel,
                composerName: 'coretsia/core-kernel',
                requires: [
                    $foundation,
                ],
                conflicts: [],
            ),
            new ModulePlanEntry(
                moduleId: $worker,
                composerName: 'coretsia/platform-worker',
                requires: [
                    $kernel,
                ],
                conflicts: [],
            ),
        ];

        if ($includePlatformHttp) {
            $enabled[] = $http;
            $topologicalOrder[] = $http;
            $modules[] = new ModulePlanEntry(
                moduleId: $http,
                composerName: 'coretsia/platform-http',
                requires: [
                    $kernel,
                ],
                conflicts: [],
            );
        }

        return new ModulePlan(
            app: 'worker',
            preset: 'micro',
            enabled: $enabled,
            disabled: [],
            optionalMissing: [],
            topologicalOrder: $topologicalOrder,
            modules: $modules,
            warnings: [],
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function configRepository(array $config): ArrayConfigRepository
    {
        return new ArrayConfigRepository($config);
    }

    /**
     * @param callable(): array<string, mixed> $callback
     */
    private static function catchWorkerStartFailed(callable $callback): WorkerStartFailedException
    {
        try {
            $callback();
        } catch (WorkerStartFailedException $exception) {
            return $exception;
        }

        self::fail('Expected WorkerStartFailedException was not thrown.');
    }

    /**
     * @param class-string $className
     */
    private static function executableSource(string $className): string
    {
        $source = self::classSource($className);
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

    /**
     * @param class-string $className
     */
    private static function methodSource(string $className, string $methodName): string
    {
        $method = new \ReflectionMethod($className, $methodName);
        $file = $method->getFileName();

        self::assertIsString($file);

        $lines = \file($file);

        self::assertIsArray($lines);

        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        self::assertIsInt($startLine);
        self::assertIsInt($endLine);

        return \implode('', \array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
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

final class ArrayConfigRepository implements ConfigRepositoryInterface
{
    /**
     * @var list<string>
     */
    private array $guardReadKeys = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
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

    /**
     * @return list<string>
     */
    public function guardReadKeys(): array
    {
        $out = [];

        foreach ($this->guardReadKeys as $key) {
            if (!\in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    private function recordGuardRead(string $keyPath): void
    {
        if (
            \str_starts_with($keyPath, 'kernel.runtime.')
            || $keyPath === 'worker.enabled'
            || $keyPath === 'worker.task_type'
        ) {
            $this->guardReadKeys[] = $keyPath;
        }
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

final class ParsedWorkerStartInput implements InputInterface
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

final class MissingRequestHandlerContainer implements ContainerInterface
{
    public bool $hasCalled = false;

    public bool $getCalled = false;

    public function get(string $id): mixed
    {
        $this->getCalled = true;

        throw new \LogicException(
            'RequestHandlerInterface must not be fetched when ContainerInterface::has() returned false.'
        );
    }

    public function has(string $id): bool
    {
        $this->hasCalled = true;

        if ($id !== RequestHandlerInterface::class) {
            throw new \LogicException('Unexpected container id requested.');
        }

        return false;
    }
}

final class UnresolvableRequestHandlerContainer implements ContainerInterface
{
    public bool $hasCalled = false;

    public bool $getCalled = false;

    public function get(string $id): mixed
    {
        $this->getCalled = true;

        if ($id !== RequestHandlerInterface::class) {
            throw new \LogicException('Unexpected container id requested.');
        }

        throw new \RuntimeException('request handler resolution failed');
    }

    public function has(string $id): bool
    {
        $this->hasCalled = true;

        if ($id !== RequestHandlerInterface::class) {
            throw new \LogicException('Unexpected container id requested.');
        }

        return true;
    }
}
