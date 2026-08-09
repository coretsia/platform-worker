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

use Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class CoretsiaWorkerChildReadinessTest extends PackageTestCase
{
    public function testProcFixturePublishesOnlyTokenizedInternalReadinessFrame(): void
    {
        $channel = new WorkerChildReadinessChannel();
        $endpoint = $channel->createProcessEndpoint();
        $fixture = self::packageRoot() . '/tests/Fixtures/proc-worker-fixture.php';

        $token = $endpoint->token();

        $result = self::startProcess([
            \PHP_BINARY,
            $fixture,
            '--coretsia-worker-readiness-port=' . $endpoint->port(),
            '--coretsia-worker-readiness-token=' . $token,
            '--fixture-run-ms=25',
        ]);

        $accepted = null;
        self::waitUntil(function () use ($endpoint, &$accepted): bool {
            $accepted = @\stream_socket_accept($endpoint->streamResource(), 0);
            return \is_resource($accepted);
        });

        self::assertIsResource($accepted);

        self::assertTrue(
            \stream_set_blocking($accepted, true),
        );

        self::assertTrue(
            \stream_set_timeout($accepted, 5),
        );

        $frame = \stream_get_contents($accepted);
        self::assertIsString($frame);

        \fclose($accepted);
        $endpoint->close();

        $finished = self::finishProcess($result['process'], $result['pipes']);
        self::assertSame(0, $finished['exit_code']);
        self::assertSame('', $finished['stdout']);
        self::assertSame('', $finished['stderr']);
        self::assertSame('ready:' . $token . "\n", $frame);
    }
}
