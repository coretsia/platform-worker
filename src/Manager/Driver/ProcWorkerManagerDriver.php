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

namespace Coretsia\Platform\Worker\Manager\Driver;

use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;

/**
 * Cross-platform process worker manager driver.
 *
 * This driver is the deterministic fallback when the resolved worker driver is
 * `proc`. It starts child worker processes through proc_open() and does not
 * rely on pcntl.
 *
 * The process command is injected as an argument vector and is never converted
 * to a shell string by this class. Worker config values are appended as
 * deterministic arguments only. Raw socket paths, raw TCP endpoints, absolute
 * paths, environment values, and full command lines are never exposed through
 * public diagnostics.
 *
 * This class owns only process-driver lifecycle behavior. It does not contain
 * task execution logic, does not call the kernel runtime boundary, does not know
 * about CLI command dispatch, does not depend on platform/cli, and does not
 * depend on platform/http.
 */
final class ProcWorkerManagerDriver implements WorkerManagerDriverInterface
{
    /**
     * @var list<non-empty-string>
     */
    private readonly array $workerCommand;

    private readonly string $skeletonRoot;

    private readonly string $configArtifactPath;
    private readonly string $containerArtifactPath;

    /**
     * @var list<resource>
     */
    private array $processes = [];

    /**
     * @param list<non-empty-string> $workerCommand
     */
    public function __construct(
        string $skeletonRoot,
        private readonly WorkerStateStore $stateStore,
        private readonly WorkerSocketServer $controlChannel,
        array $workerCommand,
        string $configArtifactPath,
        string $containerArtifactPath,
    ) {
        $this->skeletonRoot = self::normalizeSkeletonRoot($skeletonRoot);
        $this->workerCommand = self::normalizeWorkerCommand($workerCommand);
        $this->configArtifactPath = self::normalizeRelativePath(
            relativePath: $configArtifactPath,
            reason: 'proc-worker-config-artifact-path-invalid',
        );

        $this->containerArtifactPath = self::normalizeRelativePath(
            relativePath: $containerArtifactPath,
            reason: 'proc-worker-container-artifact-path-invalid',
        );
    }

    public function name(): string
    {
        return self::DRIVER_PROC;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        return $spec->driver() === self::DRIVER_PROC
            && $this->workerCommand !== [];
    }

    public function start(WorkerPoolSpec $spec): WorkerPoolState
    {
        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        try {
            $this->clearStopFlag($spec);

            for ($workerIndex = 0; $workerIndex < $spec->workers(); $workerIndex++) {
                $this->processes[] = self::openProcess(
                    command: self::workerCommand(
                        baseCommand: $this->workerCommand,
                        spec: $spec,
                        workerIndex: $workerIndex,
                        configArtifactPath: $this->configArtifactPath,
                        containerArtifactPath: $this->containerArtifactPath,
                    ),
                    cwd: $this->skeletonRoot,
                );
            }

            $state = $this->stateStore->createState(
                spec: $spec,
                pid: self::currentProcessId(),
            );

            $this->stateStore->write(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
                state: $state,
            );

            return $state;
        } catch (WorkerStartFailedException|WorkerCommunicationFailedException $exception) {
            $this->terminateStartedProcesses($spec->stopTimeoutMs());

            throw $exception;
        } catch (\Throwable) {
            $this->terminateStartedProcesses($spec->stopTimeoutMs());

            throw WorkerStartFailedException::startFailed();
        }
    }

    public function stop(WorkerPoolSpec $spec): WorkerPoolState
    {
        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        $state = $this->stateStore->read($this->skeletonRoot, $spec);

        try {
            $this->writeStopFlag($spec);
            $this->sendStopRequest($spec);
            $this->terminateStartedProcesses($spec->stopTimeoutMs());

            return $state;
        } catch (WorkerCommunicationFailedException $exception) {
            throw $exception;
        } catch (WorkerStartFailedException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw WorkerStartFailedException::startFailed();
        }
    }

    public function status(WorkerPoolSpec $spec): WorkerPoolState
    {
        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        return $this->stateStore->read($this->skeletonRoot, $spec);
    }

    /**
     * @param list<non-empty-string> $baseCommand
     * @return list<non-empty-string>
     */
    private static function workerCommand(
        array $baseCommand,
        WorkerPoolSpec $spec,
        int $workerIndex,
        string $configArtifactPath,
        string $containerArtifactPath,
    ): array {
        if ($workerIndex < 0) {
            throw WorkerStartFailedException::startFailed();
        }

        return [
            ...$baseCommand,
            '--coretsia-worker-index=' . $workerIndex,
            '--coretsia-worker-count=' . $spec->workers(),
            '--coretsia-worker-max-requests=' . $spec->maxRequests(),
            '--coretsia-worker-task-type=' . $spec->taskType(),
            '--coretsia-worker-driver=' . self::DRIVER_PROC,
            '--coretsia-worker-config=' . $configArtifactPath,
            '--coretsia-worker-container=' . $containerArtifactPath,
        ];
    }

    /**
     * @param list<non-empty-string> $command
     * @return resource
     */
    private static function openProcess(array $command, string $cwd): mixed
    {
        $descriptors = [
            0 => ['file', self::nullDevice(), 'r'],
            1 => ['file', self::nullDevice(), 'w'],
            2 => ['file', self::nullDevice(), 'w'],
        ];

        \set_error_handler(static fn (): bool => true);

        try {
            $process = \proc_open(
                command: $command,
                descriptor_spec: $descriptors,
                pipes: $pipes,
                cwd: $cwd,
                env_vars: null,
                options: [],
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($process)) {
            throw WorkerStartFailedException::startFailed();
        }

        if (!self::processIsRunning($process)) {
            self::closeProcess($process);

            throw WorkerStartFailedException::startFailed();
        }

        return $process;
    }

    private function sendStopRequest(WorkerPoolSpec $spec): void
    {
        $connection = null;

        try {
            $connection = $this->controlChannel->connect(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
                timeoutSeconds: 1,
            );

            $this->controlChannel->sendStopRequest($connection);
        } catch (WorkerCommunicationFailedException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw WorkerCommunicationFailedException::communicationFailed();
        } finally {
            if (\is_resource($connection)) {
                try {
                    $this->controlChannel->close($connection);
                } catch (WorkerCommunicationFailedException) {
                    // Closing a best-effort control connection must not expose
                    // endpoint details or alter already-selected failure flow.
                }
            }
        }
    }

    private function writeStopFlag(WorkerPoolSpec $spec): void
    {
        $path = $this->resolveRelativePath($spec->stopFlagPath());
        $dir = \dirname($path);

        \set_error_handler(static fn (): bool => true);

        try {
            if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
                throw WorkerStartFailedException::startFailed();
            }

            if (\file_put_contents($path, "stop\n", \LOCK_EX) === false) {
                throw WorkerStartFailedException::startFailed();
            }
        } finally {
            \restore_error_handler();
        }
    }

    private function clearStopFlag(WorkerPoolSpec $spec): void
    {
        $path = $this->resolveRelativePath($spec->stopFlagPath());

        \set_error_handler(static fn (): bool => true);

        try {
            if (\file_exists($path) && !\unlink($path)) {
                throw WorkerStartFailedException::startFailed();
            }
        } finally {
            \restore_error_handler();
        }
    }

    private function resolveRelativePath(string $relativePath): string
    {
        if ($relativePath === '' || \str_contains($relativePath, "\0")) {
            throw WorkerStartFailedException::startFailed();
        }

        return $this->skeletonRoot . '/' . $relativePath;
    }

    private function terminateStartedProcesses(int $stopTimeoutMs): void
    {
        if ($this->processes === []) {
            return;
        }

        self::waitForProcesses($this->processes, $stopTimeoutMs);

        foreach ($this->processes as $process) {
            if (self::processIsRunning($process)) {
                self::terminateProcess($process);
            }

            self::closeProcess($process);
        }

        $this->processes = [];
    }

    /**
     * @param list<resource> $processes
     */
    private static function waitForProcesses(array $processes, int $stopTimeoutMs): void
    {
        if ($stopTimeoutMs <= 0) {
            return;
        }

        $deadline = \microtime(true) + ($stopTimeoutMs / 1000);

        do {
            $running = false;

            foreach ($processes as $process) {
                if (self::processIsRunning($process)) {
                    $running = true;

                    break;
                }
            }

            if (!$running) {
                return;
            }

            \usleep(50_000);
        } while (\microtime(true) < $deadline);
    }

    private static function processIsRunning(mixed $process): bool
    {
        if (!\is_resource($process)) {
            return false;
        }

        \set_error_handler(static fn (): bool => true);

        try {
            $status = \proc_get_status($process);
        } finally {
            \restore_error_handler();
        }

        return $status['running'] === true;
    }

    private static function terminateProcess(mixed $process): void
    {
        if (!\is_resource($process)) {
            return;
        }

        \set_error_handler(static fn (): bool => true);

        try {
            \proc_terminate($process);
        } finally {
            \restore_error_handler();
        }
    }

    private static function closeProcess(mixed $process): void
    {
        if (!\is_resource($process)) {
            return;
        }

        \set_error_handler(static fn (): bool => true);

        try {
            \proc_close($process);
        } finally {
            \restore_error_handler();
        }
    }

    private static function currentProcessId(): int
    {
        $pid = \getmypid();

        if (!\is_int($pid) || $pid < 1) {
            throw WorkerStartFailedException::startFailed();
        }

        return $pid;
    }

    private static function nullDevice(): string
    {
        if (\strcasecmp(\PHP_OS_FAMILY, 'Windows') === 0) {
            return 'NUL';
        }

        return '/dev/null';
    }

    private static function normalizeSkeletonRoot(string $skeletonRoot): string
    {
        $root = \trim($skeletonRoot);

        if ($root === '' || \str_contains($root, "\0")) {
            throw new \InvalidArgumentException('proc-worker-skeleton-root-invalid');
        }

        $root = \rtrim(\str_replace('\\', '/', $root), '/');

        if ($root === '') {
            throw new \InvalidArgumentException('proc-worker-skeleton-root-invalid');
        }

        return $root;
    }

    /**
     * @param list<non-empty-string> $workerCommand
     * @return list<non-empty-string>
     */
    private static function normalizeWorkerCommand(array $workerCommand): array
    {
        if ($workerCommand === [] || !\array_is_list($workerCommand)) {
            throw new \InvalidArgumentException('proc-worker-command-invalid');
        }

        foreach ($workerCommand as $part) {
            if (!\is_string($part) || $part === '') {
                throw new \InvalidArgumentException('proc-worker-command-invalid');
            }

            if (\trim($part) !== $part) {
                throw new \InvalidArgumentException('proc-worker-command-invalid');
            }

            if (\preg_match('/[\x00-\x1F\x7F]/', $part) === 1) {
                throw new \InvalidArgumentException('proc-worker-command-invalid');
            }
        }

        /** @var list<non-empty-string> $workerCommand */
        return $workerCommand;
    }

    private static function normalizeRelativePath(string $relativePath, string $reason): string
    {
        if ($relativePath === '') {
            throw new \InvalidArgumentException($reason);
        }

        if (\trim($relativePath) !== $relativePath) {
            throw new \InvalidArgumentException($reason);
        }

        if (\preg_match('/\s/u', $relativePath) === 1) {
            throw new \InvalidArgumentException($reason);
        }

        if (\str_contains($relativePath, "\0") || \str_contains($relativePath, "\r") || \str_contains(
            $relativePath,
            "\n"
        )) {
            throw new \InvalidArgumentException($reason);
        }

        if (\str_starts_with($relativePath, '/') || \str_starts_with($relativePath, '\\')) {
            throw new \InvalidArgumentException($reason);
        }

        if (\preg_match('/\A[A-Za-z]:[\/\\\\]/', $relativePath) === 1) {
            throw new \InvalidArgumentException($reason);
        }

        if (\str_contains($relativePath, '\\')) {
            throw new \InvalidArgumentException($reason);
        }

        if (\str_contains($relativePath, '://')) {
            throw new \InvalidArgumentException($reason);
        }

        if (\str_contains($relativePath, '//')) {
            throw new \InvalidArgumentException($reason);
        }

        foreach (\explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException($reason);
            }

            if (\str_starts_with($segment, '@')) {
                throw new \InvalidArgumentException($reason);
            }
        }

        return $relativePath;
    }
}
