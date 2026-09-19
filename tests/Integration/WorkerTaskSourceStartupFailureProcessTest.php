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
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkerTaskSourceStartupFailureProcessTest extends SupervisorIntegrationTestCase
{
    #[DataProvider('taskSourceStartFailureProvider')]
    public function testTaskSourceFailureBeforeReadinessRollsBackStartup(
        string $scenario,
    ): void {
        ['harness' => $harness] = $this->newHarness(
            workerOverride: [
                'workers' => 1,
            ],
            behavior: [
                'task_source_start_failure' => [
                    'slot' => 0,
                    'scenario' => $scenario,
                ],
            ],
        );

        $harness->start();

        $message = $harness->waitForStartMessage();

        self::assertSame(
            'error',
            $message['type'] ?? null,
        );

        $finished = $harness->finishStart();

        self::assertNotSame(
            0,
            $finished['exit_code'],
        );

        self::assertFileDoesNotExist(
            $harness->statePath(),
        );

        self::assertLoggedChildrenExited(
            $harness,
        );

        self::assertRuntimeArtifactsCleaned(
            $harness,
        );

        self::assertCount(
            1,
            $harness->pidLog(),
            'A pre-readiness task-source failure must not enter a recycle loop.',
        );
    }

    /**
     * @return iterable<
     *     non-empty-string,
     *     array{0: 'missing'|'ambiguous'|'not_ready'}
     * >
     */
    public static function taskSourceStartFailureProvider(): iterable
    {
        yield 'missing source' => ['missing'];
        yield 'ambiguous source' => ['ambiguous'];
        yield 'source readiness failure' => ['not_ready'];
    }
}
