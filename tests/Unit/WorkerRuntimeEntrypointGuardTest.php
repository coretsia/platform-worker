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

use Coretsia\Contracts\Module\ModuleId;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Module\ModulePlanEntry;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverResolver;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverConflictException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Module\WorkerModule;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Tests\Support\ArrayConfigRepository;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use PHPUnit\Framework\TestCase;

final class WorkerRuntimeEntrypointGuardTest extends TestCase
{
    public function testMissingWorkerModuleFailsBeforeKernelRuntimeDriverResolution(): void
    {
        $guard = new WorkerRuntimeEntrypointGuard(
            new RuntimeDriverResolver(),
        );

        $spec = WorkerSpecFactory::create([
            'task_type' => 'queue',
        ]);

        try {
            $guard->assertEntrypointAllowed(
                config: new ArrayConfigRepository([
                    'kernel' => [
                        'runtime' => [],
                    ],
                ]),
                modulePlan: self::modulePlan(false),
                spec: $spec,
            );
        } catch (WorkerStartFailedException $exception) {
            self::assertSame(
                WorkerStartFailedException::ERROR_CODE,
                $exception->errorCode(),
            );
            self::assertSame(
                WorkerStartFailedException::REASON_MODULE_NOT_ENABLED,
                $exception->reason(),
            );

            return;
        }

        self::fail('Missing Worker module must fail before Kernel runtime-driver resolution.');
    }

    public function testQueueWorkerContributionPassesWithClassicKernelHttp(): void
    {
        new WorkerRuntimeEntrypointGuard(
            new RuntimeDriverResolver(),
        )->assertEntrypointAllowed(
            config: self::kernelConfig('http.classic'),
            modulePlan: self::modulePlan(true),
            spec: WorkerSpecFactory::create([
                'task_type' => 'queue',
            ]),
        );

        self::assertTrue(true);
    }

    public function testHttpWorkerContributionDoesNotRequirePlatformHttpModule(): void
    {
        $plan = self::modulePlan(true);

        self::assertTrue($plan->hasEnabledModule(WorkerModule::MODULE_ID));
        self::assertFalse($plan->hasEnabledModule('platform.http'));

        new WorkerRuntimeEntrypointGuard(
            new RuntimeDriverResolver(),
        )->assertEntrypointAllowed(
            config: self::kernelConfig('http.classic'),
            modulePlan: $plan,
            spec: WorkerSpecFactory::create([
                'task_type' => 'http',
            ]),
        );

        self::assertTrue(true);
    }

    public function testHttpWorkerPreservesKernelRuntimeDriverConflictTaxonomy(): void
    {
        try {
            new WorkerRuntimeEntrypointGuard(
                new RuntimeDriverResolver(),
            )->assertEntrypointAllowed(
                config: self::kernelConfig('http.roadrunner'),
                modulePlan: self::modulePlan(true),
                spec: WorkerSpecFactory::create([
                    'task_type' => 'http',
                ]),
            );
        } catch (RuntimeDriverConflictException $exception) {
            self::assertSame(
                RuntimeDriverConflictException::ERROR_CODE,
                $exception->errorCode(),
            );
            self::assertSame(
                RuntimeDriverConflictException::REASON_WORKER_HTTP_CONFLICTS_WITH_HTTP_DRIVER,
                $exception->reason(),
            );
            self::assertSame(
                [
                    'http.roadrunner',
                    'http.worker',
                ],
                $exception->activeDriverIds(),
            );

            return;
        }

        self::fail('Worker guard must preserve the Kernel runtime-driver conflict.');
    }

    private static function kernelConfig(string $httpDriver): ArrayConfigRepository
    {
        return new ArrayConfigRepository([
            'kernel' => [
                'runtime' => [
                    'http_driver' => $httpDriver,
                ],
            ],
        ]);
    }

    private static function modulePlan(bool $withWorker): ModulePlan
    {
        $ids = $withWorker
            ? [ModuleId::fromString(WorkerModule::MODULE_ID)]
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
                    composerName: $id->value() === WorkerModule::MODULE_ID
                        ? 'coretsia/platform-worker'
                        : 'coretsia/core-kernel',
                ),
                $ids,
            ),
            warnings: [],
        );
    }
}
