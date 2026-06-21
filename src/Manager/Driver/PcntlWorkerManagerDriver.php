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
use Coretsia\Platform\Worker\Exception\WorkerForkFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;

/**
 * Optional Unix pcntl worker manager driver.
 *
 * This driver is selected only when the normalized worker pool specification
 * has resolved to `pcntl`, pcntl_fork is available, and the platform is not
 * Windows.
 *
 * It owns only fork/process-driver lifecycle behavior. It does not contain task
 * execution logic, does not call KernelRuntimeInterface, does not know about
 * CLI command dispatch, does not depend on platform/cli, and does not depend on
 * platform/http.
 *
 * Child task execution is provided as an injected Closure so the fork strategy
 * remains independent from ApplicationWorker wiring.
 *
 * This driver must not log payloads, raw socket paths, raw TCP endpoints,
 * absolute paths, headers, tokens, config dumps, or raw process internals.
 */
final class PcntlWorkerManagerDriver implements WorkerManagerDriverInterface
{
    private readonly string $skeletonRoot;

    private readonly bool $pcntlForkAvailable;

    private readonly string $platformFamily;

    /**
     * @var array<int, true>
     */
    private array $childPids = [];

    /**
     * @param \Closure(WorkerPoolSpec, int): int $childRunner
     */
    public function __construct(
        string $skeletonRoot,
        private readonly WorkerStateStore $stateStore,
        private readonly WorkerSocketServer $controlChannel,
        private readonly \Closure $childRunner,
        ?bool $pcntlForkAvailable = null,
        ?string $platformFamily = null,
    ) {
        $this->skeletonRoot = self::normalizeSkeletonRoot($skeletonRoot);
        $this->pcntlForkAvailable = $pcntlForkAvailable ?? \function_exists('pcntl_fork');
        $this->platformFamily = $platformFamily ?? \PHP_OS_FAMILY;
    }

    public function name(): string
    {
        return self::DRIVER_PCNTL;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        if ($spec->driver() !== self::DRIVER_PCNTL) {
            return false;
        }

        if (!$this->pcntlForkAvailable) {
            return false;
        }

        if (self::isWindowsPlatform($this->platformFamily)) {
            return false;
        }

        return true;
    }

    public function start(WorkerPoolSpec $spec): WorkerPoolState
    {
        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        try {
            $this->clearStopFlag($spec);

            for ($workerIndex = 0; $workerIndex < $spec->workers(); $workerIndex++) {
                $this->forkWorker($spec, $workerIndex);
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
        } catch (WorkerForkFailedException $exception) {
            $this->terminateStartedChildren($spec->stopTimeoutMs());

            throw $exception;
        } catch (WorkerCommunicationFailedException|WorkerStartFailedException $exception) {
            $this->terminateStartedChildren($spec->stopTimeoutMs());

            throw $exception;
        } catch (\Throwable) {
            $this->terminateStartedChildren($spec->stopTimeoutMs());

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
            $this->terminateStartedChildren($spec->stopTimeoutMs());

            return $state;
        } catch (WorkerCommunicationFailedException|WorkerStartFailedException $exception) {
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

    private function forkWorker(WorkerPoolSpec $spec, int $workerIndex): void
    {
        if ($workerIndex < 0) {
            throw WorkerStartFailedException::startFailed();
        }

        \set_error_handler(static fn (): bool => true);

        try {
            /** @var int $pid */
            $pid = \pcntl_fork();
        } finally {
            \restore_error_handler();
        }

        if ($pid === -1) {
            throw WorkerForkFailedException::forkFailed();
        }

        if ($pid === 0) {
            $this->runChildAndExit($spec, $workerIndex);
        }

        if ($pid < 1) {
            throw WorkerForkFailedException::forkFailed();
        }

        $this->childPids[$pid] = true;
    }

    private function runChildAndExit(WorkerPoolSpec $spec, int $workerIndex): never
    {
        try {
            $exitCode = ($this->childRunner)($spec, $workerIndex);
        } catch (\Throwable) {
            $exitCode = 1;
        }

        exit(self::normalizeExitCode($exitCode));
    }

    private static function normalizeExitCode(int $exitCode): int
    {
        if ($exitCode < 0 || $exitCode > 255) {
            return 1;
        }

        return $exitCode;
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

    private function terminateStartedChildren(int $stopTimeoutMs): void
    {
        if ($this->childPids === []) {
            return;
        }

        self::waitForChildren($this->childPids, $stopTimeoutMs);

        foreach (\array_keys($this->childPids) as $pid) {
            self::terminateChild($pid);
            self::waitForChild($pid);
        }

        $this->childPids = [];
    }

    /**
     * @param array<int, true> $childPids
     */
    private static function waitForChildren(array &$childPids, int $stopTimeoutMs): void
    {
        if ($stopTimeoutMs <= 0 || $childPids === []) {
            return;
        }

        $deadline = \microtime(true) + ($stopTimeoutMs / 1000);

        do {
            foreach (\array_keys($childPids) as $pid) {
                if (self::childExited($pid)) {
                    unset($childPids[$pid]);
                }
            }

            if ($childPids === []) {
                return;
            }

            \usleep(50_000);
        } while (\microtime(true) < $deadline);
    }

    private static function childExited(int $pid): bool
    {
        if ($pid < 1) {
            return true;
        }

        \set_error_handler(static fn (): bool => true);

        try {
            $result = \pcntl_waitpid($pid, $status, \WNOHANG);
        } finally {
            \restore_error_handler();
        }

        return $result === $pid || $result === -1;
    }

    private static function waitForChild(int $pid): void
    {
        if ($pid < 1) {
            return;
        }

        \set_error_handler(static fn (): bool => true);

        try {
            \pcntl_waitpid($pid, $status, \WNOHANG);
        } finally {
            \restore_error_handler();
        }
    }

    private static function terminateChild(int $pid): void
    {
        if ($pid < 1 || !\function_exists('posix_kill')) {
            return;
        }

        \set_error_handler(static fn (): bool => true);

        try {
            \posix_kill($pid, \SIGTERM);
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

    private static function normalizeSkeletonRoot(string $skeletonRoot): string
    {
        $root = \trim($skeletonRoot);

        if ($root === '' || \str_contains($root, "\0")) {
            throw new \InvalidArgumentException('pcntl-worker-skeleton-root-invalid');
        }

        $root = \rtrim(\str_replace('\\', '/', $root), '/');

        if ($root === '') {
            throw new \InvalidArgumentException('pcntl-worker-skeleton-root-invalid');
        }

        return $root;
    }

    private static function isWindowsPlatform(string $platformFamily): bool
    {
        return \strcasecmp($platformFamily, 'Windows') === 0;
    }
}
