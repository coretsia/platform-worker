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

namespace Coretsia\Platform\Worker\Process\Bootstrap;

/**
 * Shared parent-side launch transaction for Guardian and ProcHost bootstrap.
 *
 * @internal
 */
final class WorkerProcessBootstrapLauncher
{
    private const int SELF_CLEANUP_GRACE_MS = 1_000;
    private const int WAIT_TICK_US = 1_000;

    public function __construct(
        private readonly WorkerProcessBootstrapProtocol $protocol,
    ) {
    }

    /**
     * @param non-empty-list<non-empty-string> $command
     * @return array{process: resource, pid: positive-int, connection: resource}
     */
    public function launchAuthenticatedChild(
        array $command,
        string $workingDirectory,
        string $role,
        int $timeoutMs,
        ?string $driver = null,
    ): array {
        self::assertInputs($command, $workingDirectory, $role, $timeoutMs, $driver);
        $deadlineNs = self::deadlineNs($timeoutMs);

        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];
        $options = ['bypass_shell' => true];
        if (\PHP_OS_FAMILY === 'Windows') {
            $options['create_process_group'] = true;
        }

        $pipes = [];
        $process = @\proc_open(
            command: $command,
            descriptor_spec: $descriptors,
            pipes: $pipes,
            cwd: $workingDirectory,
            env_vars: null,
            options: $options,
        );

        if (!\is_resource($process)) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        $stdin = $pipes[0] ?? null;
        $endpoint = null;
        $connection = null;

        try {
            if (!\is_resource($stdin)) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            $status = @\proc_get_status($process);
            $pid = $status['pid'];

            if ($status['running'] !== true || $pid < 1) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            $endpoint = WorkerProcessBootstrapEndpoint::create($this->protocol, $role);
            $frame = $endpoint->launchFrame(
                timeoutMs: self::remainingMs($deadlineNs),
                driver: $driver,
            );

            self::writeBootstrapFrame($stdin, $frame);
            @\fclose($stdin);
            $stdin = null;

            $connection = $endpoint->authenticate(
                $deadlineNs,
                static function () use ($process): bool {
                    $status = @\proc_get_status($process);

                    return $status['running'] === true;
                },
            );

            /** @var positive-int $pid */
            return [
                'process' => $process,
                'pid' => $pid,
                'connection' => $connection,
            ];
        } catch (\Throwable) {
            if (\is_resource($connection)) {
                @\fclose($connection);
            }
            if ($endpoint instanceof WorkerProcessBootstrapEndpoint) {
                $endpoint->close();
            }
            if (\is_resource($stdin)) {
                @\fclose($stdin);
            }

            self::cleanupDirectChild($process);
            throw WorkerProcessBootstrapFailure::failed();
        }
    }

    /** @param resource $stdin */
    private static function writeBootstrapFrame(mixed $stdin, string $frame): void
    {
        $remaining = $frame;

        while ($remaining !== '') {
            $written = @\fwrite($stdin, $remaining);
            if (!\is_int($written) || $written < 1) {
                throw WorkerProcessBootstrapFailure::failed();
            }
            $remaining = \substr($remaining, $written);
        }

        if (!@\fflush($stdin)) {
            throw WorkerProcessBootstrapFailure::failed();
        }
    }

    /** @param resource $process */
    private static function cleanupDirectChild(mixed $process): void
    {
        $deadlineNs = \hrtime(true) + (self::SELF_CLEANUP_GRACE_MS * 1_000_000);

        while (\hrtime(true) < $deadlineNs) {
            $status = @\proc_get_status($process);
            if ($status['running'] !== true) {
                @\proc_close($process);
                return;
            }
            \usleep(self::WAIT_TICK_US);
        }

        $status = @\proc_get_status($process);
        if ($status['running'] === true) {
            @\proc_terminate($process, 9);
        }

        @\proc_close($process);
    }

    /** @param non-empty-list<non-empty-string> $command */
    private static function assertInputs(
        array $command,
        string $workingDirectory,
        string $role,
        int $timeoutMs,
        ?string $driver,
    ): void {
        if (
            $command === []
            || !\array_is_list($command)
            || !self::isSafePath($workingDirectory)
            || $timeoutMs < 1
            || $timeoutMs > WorkerProcessBootstrapProtocol::MAX_TIMEOUT_MS
            || !\in_array(
                $role,
                [
                    WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                    WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
                ],
                true,
            )
        ) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        foreach ($command as $part) {
            if (!self::isSafeCommandPart($part)) {
                throw WorkerProcessBootstrapFailure::failed();
            }
        }

        if (
            ($role === WorkerProcessBootstrapProtocol::ROLE_GUARDIAN
                && !\in_array($driver, ['pcntl', 'proc'], true))
            || ($role === WorkerProcessBootstrapProtocol::ROLE_PROC_HOST && $driver !== null)
        ) {
            throw WorkerProcessBootstrapFailure::failed();
        }
    }

    private static function deadlineNs(int $timeoutMs): int
    {
        return \hrtime(true) + ($timeoutMs * 1_000_000);
    }

    private static function remainingMs(int $deadlineNs): int
    {
        $remainingNs = $deadlineNs - \hrtime(true);
        if ($remainingNs <= 0) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        return \max(1, (int)\ceil($remainingNs / 1_000_000));
    }

    private static function isSafeCommandPart(mixed $part): bool
    {
        return \is_string($part)
            && $part !== ''
            && \trim($part) === $part
            && \strlen($part) <= 8192
            && \preg_match('/[\x00-\x1F\x7F]/', $part) !== 1;
    }

    private static function isSafePath(string $path): bool
    {
        return $path !== ''
            && \trim($path) === $path
            && \strlen($path) <= 8192
            && !\str_contains($path, "\0")
            && \preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }
}
