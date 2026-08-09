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

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerExceptionTaxonomyContractTest extends PackageTestCase
{
    public function testStartupAndLifecycleReasonsAreDisjoint(): void
    {
        $startupReasons = [
            WorkerStartFailedException::startFailed()->reason(),
            WorkerStartFailedException::taskSourceMissing()->reason(),
            WorkerStartFailedException::taskSourceAmbiguous()->reason(),
            WorkerStartFailedException::taskSourceInvalid()->reason(),
            WorkerStartFailedException::taskSourceUnresolvable()->reason(),
            WorkerStartFailedException::taskSourceNotReady()->reason(),
            WorkerStartFailedException::readinessTimeout()->reason(),
            WorkerStartFailedException::readinessInvalid()->reason(),
            WorkerStartFailedException::childStartFailed()->reason(),
            WorkerStartFailedException::signalHandlingUnavailable()->reason(),
        ];

        $lifecycleReasons = [
            WorkerLifecycleFailedException::lifecycleFailed()->reason(),
            WorkerLifecycleFailedException::invalidState()->reason(),
            WorkerLifecycleFailedException::taskSourceTerminated()->reason(),
            WorkerLifecycleFailedException::taskSourceReceiveFailed()->reason(),
            WorkerLifecycleFailedException::taskSettlementFailed()->reason(),
            WorkerLifecycleFailedException::childExited()->reason(),
            WorkerLifecycleFailedException::shutdownFailed()->reason(),
            WorkerLifecycleFailedException::runtimeCleanupFailed()->reason(),
            WorkerLifecycleFailedException::lifecycleLockFailed()->reason(),
            WorkerLifecycleFailedException::lifecycleLocatorFailed()->reason(),
            WorkerLifecycleFailedException::processHostFailed()->reason(),
            WorkerLifecycleFailedException::processGuardianFailed()->reason(),
        ];

        self::assertSame(
            [],
            \array_values(\array_intersect($startupReasons, $lifecycleReasons)),
        );
        self::assertSame(
            \count($startupReasons),
            \count(\array_unique($startupReasons)),
        );
        self::assertSame(
            \count($lifecycleReasons),
            \count(\array_unique($lifecycleReasons)),
        );
    }

    public function testStartupAndLifecycleErrorCodesRemainDistinct(): void
    {
        self::assertSame(
            'CORETSIA_WORKER_START_FAILED',
            WorkerStartFailedException::ERROR_CODE,
        );
        self::assertSame(
            'CORETSIA_WORKER_LIFECYCLE_FAILED',
            WorkerLifecycleFailedException::ERROR_CODE,
        );
        self::assertNotSame(
            WorkerStartFailedException::ERROR_CODE,
            WorkerLifecycleFailedException::ERROR_CODE,
        );
    }

    public function testSupervisorCatchAllUsesStartupCompletionBoundary(): void
    {
        $source = self::source('src/Supervisor/WorkerSupervisor.php');

        self::assertStringContainsString(
            'throw $startupCompleted',
            $source,
        );
        self::assertStringContainsString(
            'WorkerLifecycleFailedException::lifecycleFailed()',
            $source,
        );
        self::assertStringContainsString(
            'WorkerStartFailedException::startFailed()',
            $source,
        );
    }

    public function testRuntimeWideFactoriesDoNotExistOnStartupException(): void
    {
        foreach (
            [
                'lifecycleFailed',
                'invalidState',
                'taskSourceTerminated',
                'taskSourceReceiveFailed',
                'taskSettlementFailed',
                'childExited',
                'shutdownFailed',
                'runtimeCleanupFailed',
                'lifecycleLockFailed',
                'lifecycleLocatorFailed',
                'processHostFailed',
                'processGuardianFailed',
            ] as $factory
        ) {
            self::assertFalse(
                \method_exists(WorkerStartFailedException::class, $factory),
                $factory,
            );
            self::assertTrue(
                \method_exists(WorkerLifecycleFailedException::class, $factory),
                $factory,
            );
        }
    }
}
