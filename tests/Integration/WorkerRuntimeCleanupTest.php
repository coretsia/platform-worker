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

final class WorkerRuntimeCleanupTest extends SupervisorIntegrationTestCase
{
    public function testStaleOwnedArtifactsAreCleanedUnderTheLifecycleLock(): void
    {
        ['root' => $root, 'harness' => $harness] = $this->newHarness();
        \mkdir($root . '/var/tmp', 0777, true);
        \file_put_contents($harness->statePath(), '{"stale":true}');
        \file_put_contents($harness->stopPath(), 'stale');
        \file_put_contents($harness->locatorPath(), '{"stale":true}');
        \file_put_contents($harness->locatorTemporaryPath(), '{"stale":true}');

        if (\PHP_OS_FAMILY !== 'Windows') {
            $socket = @\stream_socket_server(
                'unix://' . $harness->socketPath(),
            );

            self::assertIsResource(
                $socket,
                'Unix domain sockets are required by the Unix cleanup path.',
            );

            \fclose($socket);
        }

        $summary = $harness->startAndWaitForSummary();
        self::assertSame('running', $summary['status']);
        self::onlyPayload($harness->invoke('stop'));
        self::assertSame(0, $harness->finishStart()['exit_code']);
        self::assertRuntimeArtifactsCleaned($harness);
        self::assertFileExists($harness->lockPath(), 'The lock anchor must not be unlinked.');
    }
}
