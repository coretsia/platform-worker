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

use Coretsia\Contracts\Cli\Command\CommandInterface;
use Coretsia\Contracts\Module\ModuleId;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Module\ModulePlanEntry;
use Coretsia\Kernel\Runtime\Entrypoint\RuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Console\WorkerHealthCommand;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Tests\Support\ArrayConfigRepository;
use Coretsia\Platform\Worker\Tests\Support\RecordingControlClient;
use Coretsia\Platform\Worker\Tests\Support\RecordingOutput;
use Coretsia\Platform\Worker\Tests\Support\RecordingSupervisorResolver;
use Coretsia\Platform\Worker\Tests\Support\TestInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerCommandExceptionTaxonomyContractTest extends TestCase
{
    #[DataProvider('unknownThrowableCommands')]
    public function testUnknownThrowableUsesCommandSpecificFallback(
        string $commandName,
        CommandInterface $command,
        string $expectedCode,
        string $expectedReason,
    ): void {
        $output = new RecordingOutput();

        self::assertSame(
            1,
            $command->run(
                new TestInput($commandName),
                $output,
            ),
        );
        self::assertSame(
            [
                'code' => $expectedCode,
                'message' => $expectedReason,
            ],
            $output->errors[0],
        );
    }

    /**
     * @return iterable<string, array{
     *     non-empty-string,
     *     CommandInterface,
     *     non-empty-string,
     *     non-empty-string
     * }>
     */
    public static function unknownThrowableCommands(): iterable
    {
        yield 'start' => [
            WorkerStartCommand::NAME,
            self::startCommand(new \LogicException('unsafe-start-detail')),
            'CORETSIA_WORKER_START_FAILED',
            'worker-start-failed',
        ];

        yield 'status' => [
            WorkerStatusCommand::NAME,
            self::statusCommand(new \LogicException('unsafe-status-detail')),
            'CORETSIA_WORKER_STATUS_FAILED',
            'worker-status-failed',
        ];

        yield 'health' => [
            WorkerHealthCommand::NAME,
            self::healthCommand(new \LogicException('unsafe-health-detail')),
            'CORETSIA_WORKER_HEALTH_FAILED',
            'worker-health-failed',
        ];

        yield 'stop' => [
            WorkerStopCommand::NAME,
            self::stopCommand(new \LogicException('unsafe-stop-detail')),
            'CORETSIA_WORKER_STOP_FAILED',
            'worker-stop-failed',
        ];
    }

    public function testStartCommandPreservesLifecycleExceptionTaxonomy(): void
    {
        $exception = WorkerLifecycleFailedException::shutdownFailed();
        $output = new RecordingOutput();

        self::assertSame(
            1,
            self::startCommand($exception)->run(
                new TestInput(WorkerStartCommand::NAME),
                $output,
            ),
        );
        self::assertSame(
            [
                'code' => $exception->errorCode(),
                'message' => $exception->reason(),
            ],
            $output->errors[0],
        );
    }

    #[DataProvider('controlCommandsWithWorkerException')]
    public function testControlCommandsPreserveWorkerExceptionTaxonomy(
        string $commandName,
        CommandInterface $command,
    ): void {
        $exception = WorkerCommunicationFailedException::communicationFailed();
        $output = new RecordingOutput();

        self::assertSame(
            1,
            $command->run(
                new TestInput($commandName),
                $output,
            ),
        );
        self::assertSame(
            [
                'code' => $exception->errorCode(),
                'message' => $exception->reason(),
            ],
            $output->errors[0],
        );
    }

    /** @return iterable<string, array{non-empty-string, CommandInterface}> */
    public static function controlCommandsWithWorkerException(): iterable
    {
        $failure = WorkerCommunicationFailedException::communicationFailed();

        yield 'status' => [
            WorkerStatusCommand::NAME,
            self::statusCommand($failure),
        ];

        yield 'health' => [
            WorkerHealthCommand::NAME,
            self::healthCommand($failure),
        ];

        yield 'stop' => [
            WorkerStopCommand::NAME,
            self::stopCommand($failure),
        ];
    }

    private static function startCommand(\Throwable $failure): WorkerStartCommand
    {
        $supervisor = new class($failure) implements WorkerSupervisorInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function run(
                WorkerPoolSpec $spec,
                \Closure $onReady,
            ): int {
                throw $this->failure;
            }
        };

        return new WorkerStartCommand(
            config: self::config(),
            modulePlan: self::plan(),
            runtimeEntrypointGuard: new WorkerRuntimeEntrypointGuard(
                new RuntimeEntrypointGuard(),
            ),
            factory: new WorkerServiceFactory(),
            supervisorResolver: new RecordingSupervisorResolver($supervisor),
        );
    }

    private static function statusCommand(\Throwable $failure): WorkerStatusCommand
    {
        $client = new RecordingControlClient();
        $client->failure = $failure;

        return new WorkerStatusCommand($client);
    }

    private static function healthCommand(\Throwable $failure): WorkerHealthCommand
    {
        $client = new RecordingControlClient();
        $client->failure = $failure;

        return new WorkerHealthCommand($client);
    }

    private static function stopCommand(\Throwable $failure): WorkerStopCommand
    {
        $client = new RecordingControlClient();
        $client->failure = $failure;

        return new WorkerStopCommand($client);
    }

    private static function config(): ArrayConfigRepository
    {
        $worker = require \dirname(__DIR__, 2) . '/config/worker.php';
        $worker['workers'] = 1;
        $worker['driver'] = 'proc';
        $worker['control']['transport'] = 'tcp';

        return new ArrayConfigRepository([
            'kernel' => [
                'runtime' => [
                    'http_driver' => 'http.classic',
                ],
            ],
            'worker' => $worker,
        ]);
    }

    private static function plan(): ModulePlan
    {
        $id = ModuleId::fromString('platform.worker');

        return new ModulePlan(
            app: 'worker',
            preset: 'test',
            enabled: [$id],
            disabled: [],
            optionalMissing: [],
            topologicalOrder: [$id],
            modules: [
                new ModulePlanEntry(
                    moduleId: $id,
                    composerName: 'coretsia/platform-worker',
                ),
            ],
            warnings: [],
        );
    }
}
