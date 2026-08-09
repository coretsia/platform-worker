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

namespace Coretsia\Platform\Worker\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Shared filesystem and process helpers for platform/worker tests.
 */
abstract class PackageTestCase extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function temporaryDirectory(string $prefix): string
    {
        $path = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/'
            . $prefix
            . '-'
            . \bin2hex(\random_bytes(8));

        if (!@\mkdir($path, 0777, true) && !\is_dir($path)) {
            self::fail('Failed to create temporary test directory.');
        }

        $this->temporaryPaths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->temporaryPaths) as $path) {
            self::removePath($path);
        }

        $this->temporaryPaths = [];

        parent::tearDown();
    }

    protected static function packageRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    protected static function frameworkRoot(): string
    {
        return \dirname(self::packageRoot(), 3);
    }

    protected static function source(string $relativePath): string
    {
        $bytes = \file_get_contents(
            self::packageRoot() . '/' . \ltrim($relativePath, '/'),
        );

        self::assertIsString($bytes);

        return $bytes;
    }

    protected static function unusedTcpPort(): int
    {
        $server = @\stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
        );

        if (!\is_resource($server)) {
            self::fail('Failed to reserve a temporary TCP port.');
        }

        $name = \stream_socket_get_name($server, false);
        \fclose($server);

        self::assertIsString($name);

        $separator = \strrpos($name, ':');
        self::assertNotFalse($separator);

        $port = (int)\substr($name, $separator + 1);
        self::assertGreaterThan(0, $port);

        return $port;
    }

    protected static function waitUntil(
        callable $condition,
        int $timeoutMs = 5000,
        string $failureMessage = 'Timed out waiting for condition.',
    ): void {
        $deadline = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            if ($condition() === true) {
                return;
            }

            \usleep(10_000);
        } while (\hrtime(true) < $deadline);

        self::fail($failureMessage);
    }

    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     *
     * @return array{process: resource, pipes: array<int, resource>}
     */
    protected static function startProcess(
        array $command,
        ?string $workingDirectory = null,
        ?array $environment = null,
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @\proc_open(
            command: $command,
            descriptor_spec: $descriptors,
            pipes: $pipes,
            cwd: $workingDirectory,
            env_vars: $environment,
            options: ['bypass_shell' => true],
        );

        if (!\is_resource($process)) {
            self::fail('Failed to start test process.');
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'pipes' => $pipes,
        ];
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    protected static function finishProcess(
        mixed $process,
        array $pipes,
        int $timeoutMs = 10000,
    ): array {
        $stdout = '';
        $stderr = '';
        $deadline = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $stdout .= (string)\stream_get_contents($pipes[1]);
            $stderr .= (string)\stream_get_contents($pipes[2]);

            $status = \proc_get_status($process);

            if (!\is_array($status)) {
                self::fail('Failed to inspect test process.');
            }

            if (($status['running'] ?? false) !== true) {
                break;
            }

            \usleep(10_000);
        } while (\hrtime(true) < $deadline);

        $status = \proc_get_status($process);
        $reportedExitCode = \is_array($status)
        && \is_int($status['exitcode'] ?? null)
        && $status['exitcode'] >= 0
            ? $status['exitcode']
            : null;

        if (\is_array($status) && ($status['running'] ?? false) === true) {
            @\proc_terminate($process, 9);
        }

        $stdout .= (string)\stream_get_contents($pipes[1]);
        $stderr .= (string)\stream_get_contents($pipes[2]);

        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $closedExitCode = \proc_close($process);
        $exitCode = $reportedExitCode ?? $closedExitCode;

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    protected static function processExists(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }

        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }

        if (\PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        $started = self::startProcess(
            [
                'tasklist.exe',
                '/FI',
                'PID eq ' . $pid,
                '/NH',
                '/FO',
                'CSV',
            ],
        );

        $result = self::finishProcess(
            $started['process'],
            $started['pipes'],
            3000,
        );

        if ($result['exit_code'] !== 0) {
            return false;
        }

        foreach (
            \preg_split(
                '/\r?\n/',
                \trim($result['stdout']),
            ) ?: [] as $line
        ) {
            if (
                $line === ''
                || \str_starts_with(
                    $line,
                    'INFO:',
                )
            ) {
                continue;
            }

            $fields = \str_getcsv(
                string: $line,
                separator: ',',
                enclosure: '"',
                escape: '',
            );

            if (
                isset($fields[1])
                && \ctype_digit($fields[1])
                && (int)$fields[1] === $pid
            ) {
                return true;
            }
        }

        return false;
    }

    protected static function removePath(string $path): void
    {
        if (!\file_exists($path) && !\is_link($path)) {
            return;
        }

        if (\is_link($path) || !\is_dir($path)) {
            @\unlink($path);

            return;
        }

        $items = @\scandir($path);

        if (!\is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            self::removePath($path . '/' . $item);
        }

        @\rmdir($path);
    }
}
