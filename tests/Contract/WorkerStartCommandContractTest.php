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

use Coretsia\Contracts\Module\ModuleId;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Module\ModulePlanEntry;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverResolver;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Tests\Support\ArrayConfigRepository;
use Coretsia\Platform\Worker\Tests\Support\RecordingOutput;
use Coretsia\Platform\Worker\Tests\Support\RecordingSupervisorResolver;
use Coretsia\Platform\Worker\Tests\Support\TestInput;
use PHPUnit\Framework\TestCase;

final class WorkerStartCommandContractTest extends TestCase
{
    public function testForegroundCommandEmitsRunningSummaryAndReturnsSupervisorExitCode(): void
    {
        $supervisor = new class() implements WorkerSupervisorInterface {
            public function run(
                WorkerPoolSpec $spec,
                \Closure $onReady,
            ): int {
                $onReady(
                    new WorkerPoolState(
                        pid: 123,
                        status: WorkerPoolStatus::RUNNING,
                        workerCount: 1,
                        readyWorkerCount: 1,
                        driverRequested: 'pcntl',
                        driver: 'pcntl',
                        controlTransportRequested: 'unix',
                        controlTransport: 'unix',
                        endpointHash: \str_repeat('a', 64),
                    )
                );

                return 7;
            }
        };
        $resolver = new RecordingSupervisorResolver($supervisor);
        $command = new WorkerStartCommand(
            config: self::config(),
            modulePlan: self::plan(true),
            runtimeEntrypointGuard: new WorkerRuntimeEntrypointGuard(
                new RuntimeDriverResolver(),
            ),
            factory: new WorkerServiceFactory(),
            supervisorResolver: $resolver,
        );
        $output = new RecordingOutput();

        self::assertSame(
            7,
            $command->run(
                new TestInput(WorkerStartCommand::NAME),
                $output,
            ),
        );
        self::assertSame(1, $resolver->calls);
        self::assertSame('running', $output->json[0]['status']);
        self::assertSame(1, $output->json[0]['ready_worker_count']);
    }

    public function testMissingWorkerModuleFailsBeforeLazySupervisorResolution(): void
    {
        $supervisor = new class() implements WorkerSupervisorInterface {
            public function run(
                WorkerPoolSpec $spec,
                \Closure $onReady,
            ): int {
                throw new \LogicException('must-not-run');
            }
        };
        $resolver = new RecordingSupervisorResolver($supervisor);
        $output = new RecordingOutput();
        $command = new WorkerStartCommand(
            config: self::config(),
            modulePlan: self::plan(false),
            runtimeEntrypointGuard: new WorkerRuntimeEntrypointGuard(
                new RuntimeDriverResolver(),
            ),
            factory: new WorkerServiceFactory(),
            supervisorResolver: $resolver,
        );

        self::assertSame(
            1,
            $command->run(
                new TestInput(WorkerStartCommand::NAME),
                $output,
            ),
        );
        self::assertSame(0, $resolver->calls);
        self::assertSame(
            'CORETSIA_WORKER_START_FAILED',
            $output->errors[0]['code'],
        );
        self::assertSame(
            'worker-module-not-enabled',
            $output->errors[0]['message'],
        );
    }

    private static function config(): ArrayConfigRepository
    {
        $worker = require \dirname(__DIR__, 2) . '/config/worker.php';
        $worker['workers'] = 1;
        $worker['driver'] = 'pcntl';
        $worker['control']['transport'] = 'unix';

        return new ArrayConfigRepository([
            'kernel' => [
                'runtime' => [
                    'http_driver' => 'http.classic',
                ],
            ],
            'worker' => $worker,
        ]);
    }

    private static function plan(bool $withWorker): ModulePlan
    {
        $ids = $withWorker
            ? [ModuleId::fromString('platform.worker')]
            : [ModuleId::fromString('core.kernel')];

        return new ModulePlan(
            app: 'worker',
            preset: 'test',
            enabled: $ids,
            disabled: [],
            optionalMissing: [],
            topologicalOrder: $ids,
            modules: \array_map(
                static fn (ModuleId $id): ModulePlanEntry => new ModulePlanEntry(
                    moduleId: $id,
                    composerName: $id->value() === 'platform.worker'
                        ? 'coretsia/platform-worker'
                        : 'coretsia/core-kernel',
                ),
                $ids,
            ),
            warnings: [],
        );
    }
}
