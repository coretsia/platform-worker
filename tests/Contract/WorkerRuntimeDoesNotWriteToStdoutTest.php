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

final class WorkerRuntimeDoesNotWriteToStdoutTest extends PackageTestCase
{
    public function testProductionRuntimeContainsNoRawOutputSinks(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                self::packageRoot() . '/src',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        $paths = [
            self::packageRoot() . '/bin/coretsia-worker',
            self::packageRoot() . '/bin/coretsia-worker-proc-host',
            self::packageRoot() . '/bin/coretsia-worker-guardian',
        ];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        foreach ($paths as $path) {
            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach (
                [
                    'echo ',
                    'print ',
                    'printf(',
                    'var_dump(',
                    'print_r(',
                    'error_log(',
                    'STDOUT',
                    'STDERR',
                    'php://stdout',
                    'php://stderr',
                    'php://output',
                ] as $forbidden
            ) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    $path,
                );
            }
        }
    }
}
