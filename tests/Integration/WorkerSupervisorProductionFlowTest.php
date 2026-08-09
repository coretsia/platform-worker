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

use Coretsia\Platform\Worker\Tests\Support\SupervisorIntegrationTestCase;

final class WorkerSupervisorProductionFlowTest extends SupervisorIntegrationTestCase
{
    public function testRealStartStatusHealthStopFlowCleansEveryRuntimeArtifact(): void
    {
        ['harness' => $harness] = $this->newHarness();

        $start = $harness->startAndWaitForSummary();
        self::assertSame('running', $start['status']);
        self::assertSame(2, $start['worker_count']);
        self::assertSame(2, $start['ready_worker_count']);
        self::assertFileExists($harness->locatorPath());
        self::assertFileDoesNotExist($harness->locatorTemporaryPath());

        $locatorBytes = \file_get_contents($harness->locatorPath());
        self::assertIsString($locatorBytes);
        $locator = \json_decode(
            $locatorBytes,
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($locator);
        $credential = $locator['control_credential'] ?? null;
        self::assertIsString($credential);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $credential,
        );

        $stateBytes = \file_get_contents($harness->statePath());
        self::assertIsString($stateBytes);
        self::assertStringNotContainsString($credential, $stateBytes);
        self::assertStringNotContainsString(
            $credential,
            \json_encode($start, \JSON_THROW_ON_ERROR),
        );

        self::assertSame(
            $harness->startPid(),
            $start['pid'],
            'Published worker state PID must be the live foreground supervisor PID.',
        );

        $status = self::onlyPayload($harness->invoke('status'));
        self::assertSame('running', $status['status']);
        self::assertSame($start['pid'], $status['pid']);

        $health = self::onlyPayload($harness->invoke('health'));
        self::assertStringNotContainsString(
            $credential,
            \json_encode([$status, $health], \JSON_THROW_ON_ERROR),
        );
        self::assertSame('healthy', $health['status']);
        self::assertTrue($health['healthy']);
        self::assertSame('healthy', $health['reason']);

        $stop = self::onlyPayload($harness->invoke('stop'));
        self::assertSame('stopped', $stop['status']);

        $finished = $harness->finishStart();
        self::assertSame(0, $finished['exit_code'], $finished['stderr']);
        self::assertSame('', $finished['stderr']);

        self::assertLoggedChildrenExited($harness);
        self::assertRuntimeArtifactsCleaned($harness);
    }
}
