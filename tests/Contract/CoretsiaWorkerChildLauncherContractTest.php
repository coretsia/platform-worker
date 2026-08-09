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
}
