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

use Coretsia\Platform\Worker\Exception\WorkerAlreadyRunningException;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerForkFailedException;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use PHPUnit\Framework\TestCase;

final class WorkerExceptionsAreDeterministicContractTest extends TestCase
{
    public function testStableErrorCodesAndSafeReasons(): void
    {
        $exceptions = [
            WorkerAlreadyRunningException::alreadyRunning(),
            WorkerNotRunningException::notRunning(),
            WorkerCommunicationFailedException::communicationFailed(),
            WorkerForkFailedException::forkFailed(),
            WorkerStartFailedException::startFailed(),
            WorkerStartFailedException::taskSourceMissing(),
            WorkerStartFailedException::taskSourceAmbiguous(),
            WorkerStartFailedException::taskSourceInvalid(),
            WorkerStartFailedException::taskSourceUnresolvable(),
            WorkerStartFailedException::taskSourceNotReady(),
            WorkerStartFailedException::readinessTimeout(),
            WorkerLifecycleFailedException::lifecycleFailed(),
            WorkerLifecycleFailedException::taskSourceTerminated(),
            WorkerLifecycleFailedException::taskSourceReceiveFailed(),
            WorkerLifecycleFailedException::taskSettlementFailed(),
            WorkerLifecycleFailedException::processHostFailed(),
            WorkerLifecycleFailedException::processGuardianFailed(),
            WorkerLifecycleFailedException::lifecycleLocatorFailed(),
        ];

        foreach ($exceptions as $exception) {
            self::assertMatchesRegularExpression(
                '/\ACORETSIA_WORKER_[A-Z0-9_]+\z/',
                $exception->errorCode(),
            );
            self::assertMatchesRegularExpression(
                '/\Aworker-[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                $exception->reason(),
            );
            self::assertSame(
                $exception->errorCode() . ': ' . $exception->reason(),
                $exception->getMessage(),
            );
        }
    }

    public function testTaskSourceAmbiguityContractRemainsStable(): void
    {
        $first = WorkerStartFailedException::taskSourceAmbiguous();
        $second = WorkerStartFailedException::taskSourceAmbiguous();

        self::assertSame($first->errorCode(), $second->errorCode());
        self::assertSame($first->reason(), $second->reason());
        self::assertSame($first->getMessage(), $second->getMessage());
    }
}
