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

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class CoretsiaWorkerChildLauncherContractTest extends PackageTestCase
{
    public function testLauncherUsesTokenizedTcpReadinessAfterPreflightAndNoDirectOutput(): void
    {
        $source = self::source('bin/coretsia-worker');
        foreach (
            [
                'readiness_port',
                'readiness_token',
                'assertReady',
                "\$args['index']",
                'coretsia_worker_child_signal_ready',
                "\$driver !== 'pcntl' && \$driver !== 'proc'",
                "\$args['driver'] !== \$spec->driver()",
            ] as $required
        ) {
            self::assertStringContainsString($required, $source);
        }
        foreach (
            [
                'readiness_fd',
                'STDOUT',
                'STDERR',
                'fwrite(STDERR',
                'echo ',
                'print ',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testLauncherUsesWorkerOwnedEntrypointGuardBeforeApplicationWorkerResolution(): void
    {
        $source = self::source('bin/coretsia-worker');

        self::assertStringContainsString(
            'WorkerRuntimeEntrypointGuard::class',
            $source,
        );
        self::assertStringNotContainsString(
            'Runtime\\Entrypoint\\RuntimeEntrypointGuard',
            $source,
        );
        self::assertStringNotContainsString(
            'platform.http',
            $source,
        );

        $guardPosition = \strpos(
            $source,
            "coretsia_worker_child_assert_runtime_entrypoint_allowed(\n        container: \$container,",
        );
        $applicationWorkerPosition = \strpos(
            $source,
            'coretsia_worker_child_service($container, ApplicationWorker::class)',
        );

        self::assertIsInt($guardPosition);
        self::assertIsInt($applicationWorkerPosition);
        self::assertLessThan(
            $applicationWorkerPosition,
            $guardPosition,
            'Worker-owned entrypoint guard must run before ApplicationWorker resolution.',
        );
    }

    public function testLauncherKeepsKernelMatrixAndWorkerEntrypointFailureReasonsDistinct(): void
    {
        $source = self::source('bin/coretsia-worker');

        foreach (
            [
                'RuntimeDriverConflictException|RuntimeDriverInvalidConfigException',
                "coretsia_worker_child_throw('runtime-driver-incompatible')",
                'catch (WorkerStartFailedException $exception)',
                'WorkerStartFailedException::REASON_MODULE_NOT_ENABLED',
                "coretsia_worker_child_throw('runtime-entrypoint-incompatible')",
            ] as $required
        ) {
            self::assertStringContainsString($required, $source);
        }

        self::assertNotSame(
            'runtime-driver-incompatible',
            'runtime-entrypoint-incompatible',
        );

        $matrixCatch = \strpos(
            $source,
            'catch (RuntimeDriverConflictException|RuntimeDriverInvalidConfigException)',
        );
        $workerCatch = \strpos(
            $source,
            'catch (WorkerStartFailedException $exception)',
        );

        self::assertIsInt($matrixCatch);
        self::assertIsInt($workerCatch);
        self::assertLessThan(
            $workerCatch,
            $matrixCatch,
            'Kernel matrix failures must be mapped before Worker-owned entrypoint failures.',
        );
    }
}
