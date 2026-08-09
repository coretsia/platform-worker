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

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class CoretsiaWorkerChildBootsCurrentGenerationTest extends PackageTestCase
{
    public function testLauncherBootsGeneratedArtifactsBeforePublishingReadiness(): void
    {
        $source = self::source('bin/coretsia-worker');
        $main = \strstr(
            $source,
            'try {' . "\n" . '    $cwd',
        );
        self::assertIsString($main);

        $boot = \strpos($main, 'coretsia_worker_child_boot_container');
        $guard = \strpos(
            $main,
            'coretsia_worker_child_assert_runtime_entrypoint_allowed',
        );
        $resolve = \strpos($main, 'ApplicationWorker::class');
        $preflight = \strpos($main, '$worker->assertReady');
        $ready = \strpos($main, 'coretsia_worker_child_signal_ready');
        $run = \strpos($main, '$worker->run');

        foreach ([$boot, $guard, $resolve, $preflight, $ready, $run] as $position) {
            self::assertNotFalse($position);
        }
        self::assertTrue(
            $boot < $guard && $guard < $resolve && $resolve < $preflight && $preflight < $ready && $ready < $run
        );
        self::assertStringContainsString(
            "\$args['index']",
            $main,
        );
    }
}
