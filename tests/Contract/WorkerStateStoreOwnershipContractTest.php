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

use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\TestCase;

final class WorkerStateStoreOwnershipContractTest extends TestCase
{
    public function testOnlyWorkerStateStoreWritesWorkerStateJson(): void
    {
        $srcRoot = self::workerSrcRoot();

        foreach (self::phpFiles($srcRoot) as $file) {
            $relativePath = self::relativePath($srcRoot, $file);

            if ($relativePath === 'Runtime/WorkerStateStore.php') {
                continue;
            }

            $source = self::fileContents($file);

            self::assertStringNotContainsString(
                '->writeBytes(',
                $source,
                $relativePath . ' must not call worker state write internals directly.',
            );

            self::assertStringNotContainsString(
                '::writeBytes(',
                $source,
                $relativePath . ' must not call worker state write internals directly.',
            );

            self::assertStringNotContainsString(
                'file_put_contents($spec->statePath()',
                $source,
                $relativePath . ' must not write worker.state.json directly.',
            );

            self::assertStringNotContainsString(
                'file_put_contents($statePath',
                $source,
                $relativePath . ' must not write worker.state.json directly.',
            );

            self::assertStringNotContainsString(
                'rename($tmpPath, $path)',
                $source,
                $relativePath . ' must not perform worker state atomic replacement directly.',
            );

            self::assertStringNotContainsString(
                '$spec->statePath()',
                $source,
                $relativePath . ' must not resolve worker.state.json paths directly.',
            );

            self::assertStringNotContainsString(
                '->statePath()',
                $source,
                $relativePath . ' must not resolve worker.state.json paths directly.',
            );
        }
    }

    public function testWorkerStateStoreIsTheOnlyClassWithWorkerStateWritePrimitive(): void
    {
        $source = self::classSource(WorkerStateStore::class);

        self::assertStringContainsString('public function write(', $source);
        self::assertStringContainsString('private static function writeBytes(', $source);
        self::assertStringContainsString('private static function statePath(', $source);
        self::assertStringContainsString('file_put_contents($tmpPath, $bytes, \\LOCK_EX)', $source);
        self::assertStringContainsString('rename($tmpPath, $path)', $source);
        self::assertStringContainsString('$spec->statePath()', $source);
    }

    private static function workerSrcRoot(): string
    {
        $reflection = new \ReflectionClass(WorkerStateStore::class);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $srcRoot = \dirname($file, 2);

        self::assertDirectoryExists($srcRoot);

        return \str_replace('\\', '/', $srcRoot);
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            if ($fileInfo->getExtension() !== 'php') {
                continue;
            }

            $files[] = \str_replace('\\', '/', $fileInfo->getPathname());
        }

        \sort($files, \SORT_STRING);

        return $files;
    }

    private static function relativePath(string $root, string $file): string
    {
        $root = \rtrim(\str_replace('\\', '/', $root), '/');
        $file = \str_replace('\\', '/', $file);

        if (!\str_starts_with($file, $root . '/')) {
            return $file;
        }

        return \substr($file, \strlen($root) + 1);
    }

    /**
     * @param class-string $className
     */
    private static function classSource(string $className): string
    {
        $reflection = new \ReflectionClass($className);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        return self::fileContents($file);
    }

    private static function fileContents(string $file): string
    {
        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }
}
