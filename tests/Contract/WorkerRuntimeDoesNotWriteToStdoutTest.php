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

use PHPUnit\Framework\TestCase;

final class WorkerRuntimeDoesNotWriteToStdoutTest extends TestCase
{
    public function testWorkerRuntimeSourceDoesNotUseDirectStdoutOrStderrSinks(): void
    {
        $files = $this->runtimeSourceFiles();

        self::assertNotEmpty($files, 'platform/worker runtime source scan must cover src/**/*.php files.');

        foreach ($files as $file) {
            $code = \file_get_contents($file);

            self::assertIsString($code);

            $tokens = \token_get_all(
                $code,
                \defined('TOKEN_PARSE') ? \TOKEN_PARSE : 0,
            );

            $this->scanTokens($file, $tokens);
        }

        self::assertTrue(true, 'platform/worker runtime source does not use direct stdout/stderr sinks.');
    }

    public function testWorkerRuntimeSourceScanExcludesTestsFixturesAndChildLauncher(): void
    {
        foreach ($this->runtimeSourceFiles() as $file) {
            $relativePath = self::packageRelativePath($file);

            self::assertStringStartsWith('src/', $relativePath);
            self::assertStringNotContainsString('/tests/', $relativePath);
            self::assertStringNotContainsString('/Tests/', $relativePath);
            self::assertStringNotContainsString('/fixtures/', $relativePath);
            self::assertStringNotContainsString('/Fixtures/', $relativePath);
            self::assertNotSame('bin/coretsia-worker', $relativePath);
        }
    }

    /**
     * @param list<mixed> $tokens
     */
    private function scanTokens(string $file, array $tokens): void
    {
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                continue;
            }

            $id = $token[0];
            $text = (string)$token[1];
            $line = (int)($token[2] ?? 0);

            if ($id === \T_COMMENT || $id === \T_DOC_COMMENT) {
                continue;
            }

            if ($id === \T_ECHO) {
                $this->failToken($file, $line, 'echo is forbidden in platform/worker runtime source.');
            }

            if ($id === \T_PRINT) {
                $this->failToken($file, $line, 'print is forbidden in platform/worker runtime source.');
            }

            if ($id !== \T_STRING) {
                continue;
            }

            $name = \strtolower($text);

            if (\in_array($name, ['var_dump', 'print_r', 'printf', 'vprintf', 'fprintf', 'error_log'], true)) {
                if ($this->nextNonWhitespaceTokenIs($tokens, $i, '(')) {
                    $this->failToken($file, $line, $name . '() is forbidden in platform/worker runtime source.');
                }
            }

            if (\in_array($name, ['fwrite', 'fputs'], true)) {
                $open = $this->nextNonWhitespaceIndex($tokens, $i);

                if ($open === null || $this->tokenText($tokens[$open]) !== '(') {
                    continue;
                }

                $arg = $this->nextNonWhitespaceIndex($tokens, $open);

                if ($arg === null) {
                    continue;
                }

                $constant = $this->readPossibleFqConstant($tokens, $arg);

                if ($constant === 'STDOUT' || $constant === 'STDERR') {
                    $this->failToken(
                        $file,
                        $line,
                        $name . '(' . $constant . ', ...) is forbidden in platform/worker runtime source.',
                    );
                }
            }

            if (\in_array($name, ['file_put_contents', 'fopen', 'popen'], true)) {
                $open = $this->nextNonWhitespaceIndex($tokens, $i);

                if ($open === null || $this->tokenText($tokens[$open]) !== '(') {
                    continue;
                }

                $arg = $this->nextNonWhitespaceIndex($tokens, $open);

                if ($arg === null || !\is_array($tokens[$arg]) || $tokens[$arg][0] !== \T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $literal = \strtolower((string)$tokens[$arg][1]);

                if (
                    \str_contains($literal, 'php://stdout')
                    || \str_contains($literal, 'php://stderr')
                    || \str_contains($literal, 'php://output')
                ) {
                    $this->failToken(
                        $file,
                        $line,
                        $name . '("php://stdout|stderr|output", ...) is forbidden in platform/worker runtime source.',
                    );
                }
            }
        }
    }

    /**
     * @param list<mixed> $tokens
     */
    private function readPossibleFqConstant(array $tokens, int $index): string
    {
        $token = $tokens[$index];
        $text = $this->tokenText($token);

        if ($text === '\\') {
            $next = $this->nextNonWhitespaceIndex($tokens, $index);

            if ($next === null) {
                return '';
            }

            return $this->readPossibleFqConstant($tokens, $next);
        }

        return \strtoupper(\ltrim($text, '\\'));
    }

    private function failToken(string $file, int $line, string $message): void
    {
        self::fail(
            $message . ' File: ' . self::packageRelativePath($file) . ' Line: ' . (string)$line,
        );
    }

    /**
     * @return list<string>
     */
    private function runtimeSourceFiles(): array
    {
        $srcDir = self::packageRoot() . '/src';

        self::assertDirectoryExists($srcDir);

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $srcDir,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            if (\strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }

            $files[] = self::normalizePath($fileInfo->getPathname());
        }

        $files = \array_values(\array_unique($files));

        \usort(
            $files,
            static fn (string $left, string $right): int => \strcmp($left, $right),
        );

        /** @var list<string> $files */
        return $files;
    }

    /**
     * Find next non-whitespace/non-comment token index after $index.
     *
     * @param list<mixed> $tokens
     */
    private function nextNonWhitespaceIndex(array $tokens, int $index): ?int
    {
        $count = \count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_array($token) && $token[0] === \T_WHITESPACE) {
                continue;
            }

            if (\is_array($token) && ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function nextNonWhitespaceTokenIs(array $tokens, int $index, string $expected): bool
    {
        $next = $this->nextNonWhitespaceIndex($tokens, $index);

        if ($next === null) {
            return false;
        }

        return $this->tokenText($tokens[$next]) === $expected;
    }

    private function tokenText(mixed $token): string
    {
        return \is_array($token) ? (string)$token[1] : (string)$token;
    }

    private static function packageRelativePath(string $file): string
    {
        $packageRoot = self::packageRoot();
        $file = self::normalizePath($file);

        self::assertStringStartsWith($packageRoot . '/', $file);

        return \substr($file, \strlen($packageRoot) + 1);
    }

    private static function packageRoot(): string
    {
        return self::normalizePath(\dirname(__DIR__, 2));
    }

    private static function normalizePath(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }
}
