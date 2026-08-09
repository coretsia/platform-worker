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

namespace Coretsia\Platform\Worker\Process\Guardian;

use Coretsia\Platform\Worker\Exception\WorkerAlreadyRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostClient;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLock;

/**
 * Process-local owner of one worker generation and its canonical fence.
 *
 * The runtime intentionally has no dependency on supervisor state, locator or
 * control-server services. Its only durable authority is WorkerLifecycleLock.
 * Losing the authenticated supervisor connection triggers generation cleanup;
 * the fence is released only after every owned worker has been terminated and
 * reaped/closed.
 *
 * @internal
 */
final class WorkerProcessGuardianRuntime
{
    private const int LOOP_TICK_US = 10_000;
    private const int REQUEST_TIMEOUT_MS = 1_000;

    /**
     * @var array<string, array{
     *     pid: positive-int,
     *     backend_id: non-empty-string,
     *     exit: ?WorkerProcessExit
     * }>
     */
    private array $children = [];

    private int $nextChildId = 1;
    private ?WorkerLifecycleLock $lifecycleLock = null;
    private int $stopTimeoutMs = 1_000;
    private int $forceKillTimeoutMs = 1_000;
    private bool $claimed = false;
    private bool $released = false;
    private bool $signalInterrupted = false;

    public function __construct(
        private readonly string $driverName,
        private readonly WorkerProcessGuardianProtocol $protocol,
        private mixed $connection,
        private readonly ?WorkerProcProcessHostClient $processHost = null,
    ) {
        if (
            !\in_array($driverName, ['pcntl', 'proc'], true)
            || !\is_resource($connection)
            || ($driverName === 'proc' && !$processHost instanceof WorkerProcProcessHostClient)
            || ($driverName === 'pcntl' && $processHost !== null)
        ) {
            throw new \InvalidArgumentException('worker-process-guardian-runtime-invalid');
        }
    }

    public function run(string $token): int
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return 1;
        }

        $this->installSignalHandlers();
        $buffer = '';
        $authenticated = false;

        try {
            while (true) {
                $frame = $this->readFrame($buffer);

                if ($frame === null) {
                    if (!\is_resource($this->connection) || @\feof($this->connection)) {
                        break;
                    }
                    continue;
                }

                try {
                    $request = $this->protocol->decodeRequest($frame);
                } catch (\Throwable) {
                    break;
                }

                $requestId = $request['request_id'];
                $operation = $request['operation'];

                if (!$authenticated) {
                    if (
                        $operation !== WorkerProcessGuardianProtocol::OPERATION_HELLO
                        || ($request['payload']['token'] ?? null) !== $token
                    ) {
                        $this->writeResponse(
                            $this->protocol->encodeErrorResponse(
                                $requestId,
                                WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED,
                            )
                        );
                        return 1;
                    }

                    $authenticated = true;
                    $this->writeResponse(
                        $this->protocol->encodeOkResponse(
                            $requestId,
                            ['ready' => true],
                        )
                    );
                    continue;
                }

                try {
                    $payload = $this->handle($operation, $request['payload']);
                    $this->writeResponse($this->protocol->encodeOkResponse($requestId, $payload));

                    if ($operation === WorkerProcessGuardianProtocol::OPERATION_RELEASE) {
                        return 0;
                    }
                } catch (WorkerAlreadyRunningException) {
                    $this->writeResponse(
                        $this->protocol->encodeErrorResponse(
                            $requestId,
                            WorkerProcessGuardianProtocol::ERROR_ALREADY_RUNNING,
                        )
                    );
                    return 1;
                } catch (WorkerProcessGuardianFailure $failure) {
                    $this->writeResponse(
                        $this->protocol->encodeErrorResponse(
                            $requestId,
                            $failure->safeReason(),
                        )
                    );
                } catch (\Throwable) {
                    $this->writeResponse(
                        $this->protocol->encodeErrorResponse(
                            $requestId,
                            WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED,
                        )
                    );
                }
            }

            return 0;
        } finally {
            if ($this->claimed && !$this->released) {
                $this->cleanupOwnedGeneration();
            }

            if (!$this->claimed && $this->processHost instanceof WorkerProcProcessHostClient) {
                try {
                    $this->processHost->shutdown(self::REQUEST_TIMEOUT_MS);
                } catch (\Throwable) {
                }
            }

            if (\is_resource($this->connection)) {
                @\fclose($this->connection);
            }
            $this->connection = null;
            $this->uninstallSignalHandlers();
        }
    }

    /** @param array<int|string, mixed> $payload @return array<int|string, mixed> */
    private function handle(string $operation, array $payload): array
    {
        if ($operation === WorkerProcessGuardianProtocol::OPERATION_CLAIM) {
            return $this->claim($payload);
        }

        if (!$this->claimed || $this->released) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
        }

        return match ($operation) {
            WorkerProcessGuardianProtocol::OPERATION_SPAWN => $this->spawn($payload),
            WorkerProcessGuardianProtocol::OPERATION_POLL => $this->poll($payload),
            WorkerProcessGuardianProtocol::OPERATION_TERMINATE => $this->signal($payload, false),
            WorkerProcessGuardianProtocol::OPERATION_KILL => $this->signal($payload, true),
            WorkerProcessGuardianProtocol::OPERATION_CLOSE => $this->close($payload),
            WorkerProcessGuardianProtocol::OPERATION_RELEASE => $this->release(),
            default => throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED),
        };
    }

    /** @param array<int|string, mixed> $payload @return array{acknowledged: true} */
    private function claim(array $payload): array
    {
        if ($this->claimed || $this->released) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
        }

        $root = $payload['skeleton_root'];
        $this->stopTimeoutMs = $payload['stop_timeout_ms'];
        $this->forceKillTimeoutMs = $payload['force_kill_timeout_ms'];

        $lock = new WorkerLifecycleLock($root);
        $lock->acquire();
        $this->lifecycleLock = $lock;
        $this->claimed = true;

        return ['acknowledged' => true];
    }

    /** @param array<int|string, mixed> $payload @return array{child_id: non-empty-string, pid: positive-int} */
    private function spawn(array $payload): array
    {
        if ($this->nextChildId > 2_147_483_647) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
        }

        /** @var non-empty-list<non-empty-string> $command */
        $command = $payload['command'];
        $workingDirectory = $payload['working_directory'];

        if ($this->driverName === 'pcntl') {
            [$backendId, $pid] = $this->spawnPcntl($command, $workingDirectory);
        } else {
            try {
                $hostChild = $this->processHost?->spawn(
                    command: $command,
                    workingDirectory: $workingDirectory,
                    timeoutMs: self::REQUEST_TIMEOUT_MS,
                );
            } catch (WorkerStartFailedException) {
                throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_CHILD_START_FAILED);
            } catch (\Throwable) {
                throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_PROCESS_HOST_FAILED);
            }

            if ($hostChild === null) {
                throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_PROCESS_HOST_FAILED);
            }

            $backendId = $hostChild->id();
            $pid = $hostChild->pid();
        }

        $childId = 'child-' . $this->nextChildId++;
        $this->children[$childId] = [
            'pid' => $pid,
            'backend_id' => $backendId,
            'exit' => null,
        ];

        return ['child_id' => $childId, 'pid' => $pid];
    }

    /**
     * @param non-empty-list<non-empty-string> $command
     * @return array{0: non-empty-string, 1: positive-int}
     */
    private function spawnPcntl(array $command, string $workingDirectory): array
    {
        if (
            !\function_exists('pcntl_fork')
            || !\function_exists('pcntl_exec')
            || !\function_exists('pcntl_waitpid')
            || !\function_exists('posix_kill')
        ) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_CHILD_START_FAILED);
        }

        $pid = @\pcntl_fork();
        if ($pid === -1) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_FORK_FAILED);
        }

        if ($pid === 0) {
            if (\is_resource($this->connection)) {
                @\fclose($this->connection);
            }
            $this->lifecycleLock?->detachInForkedChild();
            self::resetForkedChildSignals();

            if (!@\chdir($workingDirectory)) {
                exit(1);
            }

            $binary = \array_shift($command);
            if (!\is_string($binary) || $binary === '') {
                exit(1);
            }

            @\pcntl_exec($binary, $command);
            exit(1);
        }

        /** @var positive-int $pid */
        return ['pid-' . $pid, $pid];
    }

    /** @param array<int|string, mixed> $payload @return array<int|string, mixed> */
    private function poll(array $payload): array
    {
        $childId = $this->childId($payload);
        $exit = $this->refreshExit($childId);

        if ($exit === null) {
            return ['state' => 'running'];
        }

        return [
            'exit_code' => $exit->exitCode(),
            'expected' => $exit->expected(),
            'pid' => $exit->pid(),
            'signaled' => $exit->signaled(),
            'state' => 'exited',
            'terminating_signal' => $exit->terminatingSignal(),
        ];
    }

    /** @param array<int|string, mixed> $payload @return array{acknowledged: true} */
    private function signal(array $payload, bool $force): array
    {
        $childId = $this->childId($payload);
        if ($this->refreshExit($childId) !== null) {
            return ['acknowledged' => true];
        }

        $entry = $this->children[$childId];

        try {
            if ($this->driverName === 'pcntl') {
                $signal = $force ? \SIGKILL : \SIGTERM;
                if (!@\posix_kill($entry['pid'], $signal) && self::processExists($entry['pid'])) {
                    throw new \RuntimeException();
                }
            } else {
                if ($force) {
                    $this->processHost?->kill($entry['backend_id'], self::REQUEST_TIMEOUT_MS);
                } else {
                    $this->processHost?->terminate($entry['backend_id'], self::REQUEST_TIMEOUT_MS);
                }
            }
        } catch (\Throwable) {
            throw new WorkerProcessGuardianFailure(
                $this->driverName === 'proc'
                    ? WorkerProcessGuardianProtocol::ERROR_PROCESS_HOST_FAILED
                    : WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED,
            );
        }

        return ['acknowledged' => true];
    }

    /** @param array<int|string, mixed> $payload @return array{acknowledged: true} */
    private function close(array $payload): array
    {
        $childId = $this->childId($payload);
        if ($this->refreshExit($childId) === null) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_CHILD_RUNNING);
        }

        $entry = $this->children[$childId];
        if ($this->driverName === 'proc') {
            try {
                $this->processHost?->close($entry['backend_id'], self::REQUEST_TIMEOUT_MS);
            } catch (\Throwable) {
                throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_PROCESS_HOST_FAILED);
            }
        }

        unset($this->children[$childId]);
        return ['acknowledged' => true];
    }

    /** @return array{acknowledged: true} */
    private function release(): array
    {
        if ($this->children !== []) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_CHILD_RUNNING);
        }

        if ($this->processHost instanceof WorkerProcProcessHostClient) {
            try {
                $this->processHost->shutdown(self::REQUEST_TIMEOUT_MS);
            } catch (\Throwable) {
                throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_PROCESS_HOST_FAILED);
            }
        }

        $this->lifecycleLock?->release();
        $this->released = true;
        return ['acknowledged' => true];
    }

    private function refreshExit(string $childId): ?WorkerProcessExit
    {
        $entry = $this->children[$childId];
        if ($entry['exit'] instanceof WorkerProcessExit) {
            return $entry['exit'];
        }

        try {
            if ($this->driverName === 'pcntl') {
                $status = 0;
                $result = @\pcntl_waitpid($entry['pid'], $status, \WNOHANG);
                if ($result === 0) {
                    return null;
                }
                if ($result !== $entry['pid']) {
                    throw new \RuntimeException();
                }

                $signaled = \pcntl_wifsignaled($status);
                $signal = $signaled ? \pcntl_wtermsig($status) : 0;
                $exitCode = \pcntl_wifexited($status) ? \pcntl_wexitstatus($status) : 128 + $signal;
                $exit = new WorkerProcessExit(
                    pid: $entry['pid'],
                    exitCode: $exitCode,
                    signaled: $signaled,
                    terminatingSignal: $signal,
                    expected: !$signaled && $exitCode === 0,
                );
            } else {
                $exit = $this->processHost?->pollExit($entry['backend_id'], self::REQUEST_TIMEOUT_MS);
                if ($exit === null) {
                    return null;
                }
            }
        } catch (\Throwable) {
            throw new WorkerProcessGuardianFailure(
                $this->driverName === 'proc'
                    ? WorkerProcessGuardianProtocol::ERROR_PROCESS_HOST_FAILED
                    : WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED,
            );
        }

        $this->children[$childId]['exit'] = $exit;
        return $exit;
    }

    private function cleanupOwnedGeneration(): void
    {
        try {
            $this->signalAll(false);
            $this->waitAndCloseUntil(self::deadlineNs($this->stopTimeoutMs));

            if ($this->children !== []) {
                $this->signalAll(true);
                $this->waitAndCloseUntil(self::deadlineNs($this->forceKillTimeoutMs));
            }

            /*
             * Safety wins over availability: after forced termination was sent,
             * never release the generation fence while an owned child remains.
             * This loop is intentionally unbounded. An outer service manager may
             * still terminate the complete unit if the OS cannot reap a process.
             */
            while ($this->children !== []) {
                $this->waitAndCloseUntil(self::deadlineNs(1_000));
                if ($this->children !== []) {
                    $this->signalAll(true);
                }
            }

            if ($this->processHost instanceof WorkerProcProcessHostClient) {
                $this->processHost->shutdown(5_000);
            }
        } catch (\Throwable) {
            /* Keep the fence held while retrying cleanup rather than overlapping generations. */
            while ($this->children !== []) {
                try {
                    $this->signalAll(true);
                    $this->waitAndCloseUntil(self::deadlineNs(1_000));
                } catch (\Throwable) {
                    \usleep(self::LOOP_TICK_US);
                }
            }

            if ($this->processHost instanceof WorkerProcProcessHostClient) {
                try {
                    $this->processHost->shutdown(5_000);
                } catch (\Throwable) {
                }
            }
        } finally {
            $this->lifecycleLock?->release();
            $this->released = true;
        }
    }

    private function signalAll(bool $force): void
    {
        foreach (\array_keys($this->children) as $childId) {
            try {
                if ($this->refreshExit($childId) === null) {
                    $this->signal(['child_id' => $childId], $force);
                }
            } catch (\Throwable) {
            }
        }
    }

    private function waitAndCloseUntil(int $deadlineNs): void
    {
        do {
            foreach (\array_keys($this->children) as $childId) {
                try {
                    if ($this->refreshExit($childId) === null) {
                        continue;
                    }

                    $entry = $this->children[$childId];
                    if ($this->driverName === 'proc') {
                        $this->processHost?->close($entry['backend_id'], self::REQUEST_TIMEOUT_MS);
                    }
                    unset($this->children[$childId]);
                } catch (\Throwable) {
                }
            }

            if ($this->children === []) {
                return;
            }
            \usleep(self::LOOP_TICK_US);
        } while (\hrtime(true) < $deadlineNs);
    }

    /** @param array<int|string, mixed> $payload */
    private function childId(array $payload): string
    {
        $childId = $payload['child_id'] ?? null;
        if (!\is_string($childId) || !isset($this->children[$childId])) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_CHILD_INVALID);
        }
        return $childId;
    }

    private function readFrame(string &$buffer): ?string
    {
        $connection = $this->connection;
        if (!\is_resource($connection)) {
            return null;
        }

        $newline = \strpos($buffer, "\n");
        if ($newline !== false) {
            $frame = \substr($buffer, 0, $newline + 1);
            $buffer = \substr($buffer, $newline + 1);
            return $frame;
        }

        $read = [$connection];
        $write = null;
        $except = null;
        $selected = @\stream_select($read, $write, $except, 0, 100_000);

        if ($selected === false) {
            if ($this->signalInterrupted) {
                $this->signalInterrupted = false;
                return null;
            }
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
        }

        if ($selected === 0) {
            return null;
        }

        $remaining = WorkerProcessGuardianProtocol::MAX_FRAME_BYTES + 1 - \strlen($buffer);
        if ($remaining < 1) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
        }

        $chunk = @\fread($connection, $remaining);
        if ($chunk === false) {
            throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
        }
        if ($chunk === '') {
            return null;
        }

        $buffer .= $chunk;
        $newline = \strpos($buffer, "\n");
        if ($newline === false) {
            return null;
        }

        $frame = \substr($buffer, 0, $newline + 1);
        $buffer = \substr($buffer, $newline + 1);
        return $frame;
    }

    private function writeResponse(string $frame): void
    {
        $connection = $this->connection;
        if (!\is_resource($connection)) {
            return;
        }

        $deadlineNs = self::deadlineNs(self::REQUEST_TIMEOUT_MS);
        $remaining = $frame;

        while ($remaining !== '') {
            if (\hrtime(true) >= $deadlineNs) {
                throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
            }

            $read = null;
            $write = [$connection];
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($deadlineNs);
            $selected = @\stream_select($read, $write, $except, $seconds, $microseconds);

            if ($selected === false) {
                if ($this->signalInterrupted) {
                    $this->signalInterrupted = false;
                    continue;
                }
                throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
            }
            if ($selected !== 1) {
                continue;
            }

            $written = @\fwrite($connection, $remaining);
            if (!\is_int($written) || $written < 1) {
                throw new WorkerProcessGuardianFailure(WorkerProcessGuardianProtocol::ERROR_OPERATION_FAILED);
            }
            $remaining = \substr($remaining, $written);
        }
    }

    private function installSignalHandlers(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            if (\function_exists('sapi_windows_set_ctrl_handler')) {
                @\sapi_windows_set_ctrl_handler(function (int $_event): void {
                    $this->signalInterrupted = true;
                }, true);
            }
            return;
        }

        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }

        \pcntl_async_signals(true);
        $handler = function (): void {
            /* Supervisor owns graceful shutdown; guardian survives group signals until release/EOF. */
            $this->signalInterrupted = true;
        };
        @\pcntl_signal(\SIGTERM, $handler, true);
        @\pcntl_signal(\SIGINT, $handler, true);
    }

    private function uninstallSignalHandlers(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }
        if (\function_exists('pcntl_signal') && \defined('SIG_DFL')) {
            @\pcntl_signal(\SIGTERM, \SIG_DFL, true);
            @\pcntl_signal(\SIGINT, \SIG_DFL, true);
        }
        if (\function_exists('pcntl_async_signals')) {
            @\pcntl_async_signals(false);
        }
    }

    private static function resetForkedChildSignals(): void
    {
        if (\function_exists('pcntl_signal') && \defined('SIG_DFL')) {
            @\pcntl_signal(\SIGTERM, \SIG_DFL, true);
            @\pcntl_signal(\SIGINT, \SIG_DFL, true);
            if (\defined('SIGCHLD')) {
                @\pcntl_signal(\SIGCHLD, \SIG_DFL, true);
            }
        }
        if (\function_exists('pcntl_async_signals')) {
            @\pcntl_async_signals(false);
        }
    }

    private static function processExists(int $pid): bool
    {
        return $pid > 0 && \function_exists('posix_kill') && @\posix_kill($pid, 0);
    }

    private static function deadlineNs(int $timeoutMs): int
    {
        return \hrtime(true) + (\max(1, $timeoutMs) * 1_000_000);
    }

    /** @return array{0: non-negative-int, 1: int<0, 999999>} */
    private static function selectTimeout(int $deadlineNs): array
    {
        $remainingNs = \max(1, $deadlineNs - \hrtime(true));
        return [
            \intdiv($remainingNs, 1_000_000_000),
            (int)\intdiv($remainingNs % 1_000_000_000, 1_000),
        ];
    }
}
