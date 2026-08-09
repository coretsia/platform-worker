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

final class WorkerShutdownDeadlinePropagationContractTest extends PackageTestCase
{
    public function testSupervisorUsesBoundedBudgetsAndReleasesGuardianFenceAfterChildrenClose(): void
    {
        $source = self::source('src/Supervisor/WorkerSupervisor.php');
        foreach (
            [
                '$cooperativeDeadlineNs',
                '$terminateDeadlineNs',
                '$killDeadlineNs',
                'remainingMsOrNull',
                'WorkerShutdownBudget::CLEANUP_TIMEOUT_MS'
            ] as $required
        ) {
            self::assertStringContainsString($required, $source);
        }
        foreach (['pollExit', 'terminate', 'kill', 'close'] as $method) {
            self::assertStringContainsString('$driver->' . $method . '(', $source);
        }
        $shutdown = \strpos($source, '$this->shutdownChildren(');

        self::assertIsInt($shutdown);

        $cleanup = \strpos(
            $source,
            '$this->locatorStore->delete()',
            $shutdown,
        );
        $release = \strpos(
            $source,
            '$this->guardian->release(',
            $shutdown,
        );
        $respond = \strpos(
            $source,
            '$this->bestEffortRespondStopped(',
            $shutdown,
        );

        self::assertIsInt($cleanup);
        self::assertIsInt($release);
        self::assertIsInt($respond);
        self::assertTrue(
            $cleanup < $release && $release < $respond,
        );
    }

    public function testGuardianOwnerLossUsesSpecDerivedTerminationBudgets(): void
    {
        $runtime = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        self::assertStringContainsString('$this->stopTimeoutMs', $runtime);
        self::assertStringContainsString('$this->forceKillTimeoutMs', $runtime);
        self::assertStringContainsString('cleanupOwnedGeneration()', $runtime);
        self::assertStringContainsString('signalAll(false)', $runtime);
        self::assertStringContainsString('signalAll(true)', $runtime);
    }

    public function testStopClientUsesOneLocatorDerivedRequestDeadline(): void
    {
        $client = self::source('src/Communication/WorkerControlClient.php');
        self::assertStringContainsString('$deadlineNs = self::deadlineNs($timeoutMs)', $client);
        self::assertStringContainsString('self::remainingMs($deadlineNs)', $client);
    }
}
