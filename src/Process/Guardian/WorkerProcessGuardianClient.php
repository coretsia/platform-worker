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
use Coretsia\Platform\Worker\Exception\WorkerForkFailedException;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessGuardianInterface;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Foreground-supervisor client for one package-owned process guardian.
 *
 * Guardian launch always uses the shared proc_open process-bootstrap launcher.
 * The selected driver controls only the worker-child backend owned by Guardian.
 */
final class WorkerProcessGuardianClient implements WorkerProcessGuardianInterface
{
    private const int MAX_REQUEST_ID = 2_147_483_647;
    private const int MAX_TIMEOUT_MS = 86_400_000;
    private const int WAIT_TICK_US = 1_000;

    /** @var non-empty-list<non-empty-string> */
    private array $command;
    private mixed $connection = null;
    private mixed $process = null;
    private ?int $guardianPid = null;
    private int $nextRequestId = 1;
    private bool $claimed = false;
    private bool $released = false;

    /** @var array<string, positive-int> */
    private array $children = [];

    /** @param non-empty-list<non-empty-string> $command */
    public function __construct(
        array $command,
        private readonly string $bootstrapWorkingDirectory,
        private readonly string $skeletonRoot,
        private readonly WorkerProcessGuardianProtocol $protocol,
        private readonly WorkerProcessBootstrapLauncher $bootstrapLauncher,
    ) {
        if (
            $command === []
            || !\array_is_list($command)
            || !self::isSafePath($bootstrapWorkingDirectory)
            || !self::isSafePath($skeletonRoot)
        ) {
            throw new \InvalidArgumentException('worker-process-guardian-client-invalid');
        }
        foreach ($command as $part) {
            if (!self::isSafeCommandPart($part)) {
                throw new \InvalidArgumentException('worker-process-guardian-client-invalid');
            }
        }
        $this->command = $command;
    }

    public function claim(WorkerPoolSpec $spec, string $driverName): void
    {
        if (
            $this->claimed
            || $this->released
            || \is_resource($this->process)
            || \is_resource($this->connection)
            || $this->guardianPid !== null
            || !\in_array($driverName, ['pcntl', 'proc'], true)
        ) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        self::assertTimeout($spec->startTimeoutMs());
        $deadlineNs = self::deadlineNs($spec->startTimeoutMs());

        try {
            $session = $this->bootstrapLauncher->launchAuthenticatedChild(
                command: $this->command,
                workingDirectory: $this->bootstrapWorkingDirectory,
                role: WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                timeoutMs: self::remainingMs($deadlineNs),
                driver: $driverName,
            );
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        $this->process = $session['process'];
        $this->guardianPid = $session['pid'];
        $this->connection = $session['connection'];

        try {
            $claimed = $this->request(
                WorkerProcessGuardianProtocol::OPERATION_CLAIM,
                [
                    'force_kill_timeout_ms' => $spec->forceKillTimeoutMs(),
                    'skeleton_root' => $this->skeletonRoot,
                    'stop_timeout_ms' => $spec->stopTimeoutMs(),
                ],
                self::remainingMs($deadlineNs),
            );

            if (\array_keys($claimed) !== ['acknowledged'] || $claimed['acknowledged'] !== true) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }

            $this->claimed = true;
        } catch (WorkerAlreadyRunningException $exception) {
            /* Explicit rejection proves this candidate Guardian did not acquire the fence. */
            $this->closeConnection();
            $this->waitForGuardianExit(\min($spec->startTimeoutMs(), 5_000));
            if ($this->guardianRunning()) {
                $this->forceTerminateGuardian();
            } else {
                $this->closeProcessResource();
            }
            $this->resetLaunch();
            throw $exception;
        } catch (\Throwable $exception) {
            /*
             * Once CLAIM may have reached Guardian, a missing ACK is an ambiguous
             * commit result. EOF transfers cleanup to Guardian; do not force-kill
             * a process that may already own WorkerLifecycleLock.
             */
            $this->closeConnection();
            $this->waitForGuardianExit(\min($spec->startTimeoutMs(), 5_000));

            if (!$this->guardianRunning()) {
                $this->closeProcessResource();
                $this->resetLaunch();
            }

            if ($exception instanceof WorkerStartFailedException
                || $exception instanceof WorkerForkFailedException
                || $exception instanceof WorkerLifecycleFailedException) {
                throw $exception;
            }

            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    public function spawn(array $command, string $workingDirectory, int $timeoutMs): WorkerProcessGuardianChild
    {
        self::assertTimeout($timeoutMs);
        $this->assertClaimed();

        try {
            $payload = $this->request(
                WorkerProcessGuardianProtocol::OPERATION_SPAWN,
                ['command' => $command, 'working_directory' => $workingDirectory],
                $timeoutMs,
            );
        } catch (WorkerStartFailedException|WorkerForkFailedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($exception instanceof WorkerLifecycleFailedException) {
                throw $exception;
            }
            throw WorkerStartFailedException::childStartFailed();
        }

        if (
            \array_keys($payload) !== ['child_id', 'pid']
            || !\is_string($payload['child_id'])
            || \preg_match('/\Achild-[1-9][0-9]*\z/', $payload['child_id']) !== 1
            || !\is_int($payload['pid'])
            || $payload['pid'] < 1
            || isset($this->children[$payload['child_id']])
        ) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        $this->children[$payload['child_id']] = $payload['pid'];
        return new WorkerProcessGuardianChild($payload['child_id'], $payload['pid']);
    }

    public function pollExit(string $childId, int $timeoutMs): ?WorkerProcessExit
    {
        $pid = $this->knownChildPid($childId);
        $payload = $this->request(
            WorkerProcessGuardianProtocol::OPERATION_POLL,
            ['child_id' => $childId],
            $timeoutMs,
        );

        if (\array_keys($payload) === ['state']) {
            if ($payload['state'] !== 'running') {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            return null;
        }

        if (
            \array_keys($payload) !== ['exit_code', 'expected', 'pid', 'signaled', 'state', 'terminating_signal']
            || $payload['state'] !== 'exited'
            || $payload['pid'] !== $pid
            || !\is_int($payload['exit_code'])
            || $payload['exit_code'] < 0
            || !\is_bool($payload['expected'])
            || !\is_bool($payload['signaled'])
            || !\is_int($payload['terminating_signal'])
            || $payload['terminating_signal'] < 0
        ) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        return new WorkerProcessExit(
            pid: $pid,
            exitCode: $payload['exit_code'],
            signaled: $payload['signaled'],
            terminatingSignal: $payload['terminating_signal'],
            expected: $payload['expected'],
        );
    }

    public function terminate(string $childId, int $timeoutMs): void
    {
        $this->ackChildOperation(WorkerProcessGuardianProtocol::OPERATION_TERMINATE, $childId, $timeoutMs);
    }

    public function kill(string $childId, int $timeoutMs): void
    {
        $this->ackChildOperation(WorkerProcessGuardianProtocol::OPERATION_KILL, $childId, $timeoutMs);
    }

    public function close(string $childId, int $timeoutMs): void
    {
        $this->ackChildOperation(WorkerProcessGuardianProtocol::OPERATION_CLOSE, $childId, $timeoutMs);
        unset($this->children[$childId]);
    }

    public function release(int $timeoutMs): void
    {
        self::assertTimeout($timeoutMs);
        if ($this->released) {
            return;
        }
        $this->assertClaimed();

        try {
            $payload = $this->request(
                WorkerProcessGuardianProtocol::OPERATION_RELEASE,
                [],
                $timeoutMs,
            );
            if (\array_keys($payload) !== ['acknowledged'] || $payload['acknowledged'] !== true) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
        } catch (\Throwable $exception) {
            /* EOF transfers terminal cleanup authority to the still-live guardian. */
            $this->closeConnection();

            if ($exception instanceof WorkerLifecycleFailedException) {
                throw $exception;
            }
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        $this->released = true;
        $this->claimed = false;
        $this->children = [];
        $this->closeConnection();
        $this->waitForGuardianExit($timeoutMs);
        $this->closeProcessResource();
    }

    private function ackChildOperation(string $operation, string $childId, int $timeoutMs): void
    {
        $this->knownChildPid($childId);
        $payload = $this->request($operation, ['child_id' => $childId], $timeoutMs);
        if (\array_keys($payload) !== ['acknowledged'] || $payload['acknowledged'] !== true) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    /** @param array<int|string, mixed> $payload @return array<int|string, mixed> */
    private function request(string $operation, array $payload, int $timeoutMs): array
    {
        self::assertTimeout($timeoutMs);
        if (!\is_resource($this->connection)) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        $requestId = $this->nextRequestId();
        $deadlineNs = self::deadlineNs($timeoutMs);
        $this->writeFrame($this->protocol->encodeRequest($requestId, $operation, $payload), $deadlineNs);
        $response = $this->protocol->decodeResponse($this->readFrame($deadlineNs));

        if ($response['request_id'] !== $requestId) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        if ($response['status'] === WorkerProcessGuardianProtocol::STATUS_ERROR) {
            $reason = $response['payload']['reason'] ?? null;
            throw match ($reason) {
                WorkerProcessGuardianProtocol::ERROR_ALREADY_RUNNING => WorkerAlreadyRunningException::alreadyRunning(),
                WorkerProcessGuardianProtocol::ERROR_FORK_FAILED => WorkerForkFailedException::forkFailed(),
                WorkerProcessGuardianProtocol::ERROR_CHILD_START_FAILED => WorkerStartFailedException::childStartFailed(),
                WorkerProcessGuardianProtocol::ERROR_PROCESS_HOST_FAILED => WorkerLifecycleFailedException::processHostFailed(),
                WorkerProcessGuardianProtocol::ERROR_CHILD_INVALID => WorkerLifecycleFailedException::childExited(),
                default => WorkerLifecycleFailedException::processGuardianFailed(),
            };
        }

        return $response['payload'];
    }

    private function writeFrame(string $frame, int $deadlineNs): void
    {
        $connection = $this->connection;
        if (!\is_resource($connection)) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
        $remaining = $frame;
        while ($remaining !== '') {
            [$seconds, $microseconds] = self::selectTimeout($deadlineNs);
            $read = null;
            $write = [$connection];
            $except = null;
            $selected = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected !== 1) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            $written = @\fwrite($connection, $remaining);
            if (!\is_int($written) || $written < 1) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            $remaining = \substr($remaining, $written);
        }
    }

    private function readFrame(int $deadlineNs): string
    {
        $connection = $this->connection;
        if (!\is_resource($connection)) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
        $buffer = '';
        while (true) {
            [$seconds, $microseconds] = self::selectTimeout($deadlineNs);
            $read = [$connection];
            $write = null;
            $except = null;
            $selected = @\stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected !== 1) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            $remaining = WorkerProcessGuardianProtocol::MAX_FRAME_BYTES + 1 - \strlen($buffer);
            if ($remaining < 1) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            $chunk = @\fread($connection, $remaining);
            if ($chunk === false || $chunk === '') {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            $buffer .= $chunk;
            $newline = \strpos($buffer, "\n");
            if ($newline === false) {
                continue;
            }
            if ($newline !== \strlen($buffer) - 1) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            return $buffer;
        }
    }

    private function knownChildPid(string $childId): int
    {
        $this->assertClaimed();
        if (\preg_match('/\Achild-[1-9][0-9]*\z/', $childId) !== 1 || !isset($this->children[$childId])) {
            throw WorkerLifecycleFailedException::childExited();
        }
        return $this->children[$childId];
    }

    private function assertClaimed(): void
    {
        if (!$this->claimed || $this->released || !\is_resource($this->connection)) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    private function nextRequestId(): int
    {
        if ($this->nextRequestId > self::MAX_REQUEST_ID) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
        return $this->nextRequestId++;
    }

    private function guardianRunning(): bool
    {
        if (!\is_resource($this->process)) {
            return false;
        }

        $status = @\proc_get_status($this->process);

        return $status['running'] === true;
    }

    private function waitForGuardianExit(int $timeoutMs): void
    {
        self::assertTimeout($timeoutMs);
        $deadlineNs = self::deadlineNs($timeoutMs);

        while ($this->guardianRunning() && \hrtime(true) < $deadlineNs) {
            \usleep(self::WAIT_TICK_US);
        }
    }

    private function forceTerminateGuardian(): void
    {
        if (\is_resource($this->process)) {
            if ($this->guardianRunning()) {
                @\proc_terminate($this->process, 9);
            }
            @\proc_close($this->process);
        }

        $this->process = null;
        $this->guardianPid = null;
    }

    private function closeConnection(): void
    {
        if (\is_resource($this->connection)) {
            @\fclose($this->connection);
        }
        $this->connection = null;
    }

    private function closeProcessResource(): void
    {
        if (\is_resource($this->process)) {
            @\proc_close($this->process);
        }
        $this->process = null;
        $this->guardianPid = null;
    }

    private function resetLaunch(): void
    {
        $this->process = null;
        $this->guardianPid = null;
        $this->connection = null;
        $this->children = [];
        $this->nextRequestId = 1;
    }

    private static function assertTimeout(int $timeoutMs): void
    {
        if ($timeoutMs < 1 || $timeoutMs > self::MAX_TIMEOUT_MS) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    private static function deadlineNs(int $timeoutMs): int
    {
        self::assertTimeout($timeoutMs);
        return \hrtime(true) + ($timeoutMs * 1_000_000);
    }

    private static function remainingMs(int $deadlineNs): int
    {
        $remainingNs = $deadlineNs - \hrtime(true);
        if ($remainingNs <= 0) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        return \max(1, (int)\ceil($remainingNs / 1_000_000));
    }

    /** @return array{0: non-negative-int, 1: int<0, 999999>} */
    private static function selectTimeout(int $deadlineNs): array
    {
        $remainingNs = $deadlineNs - \hrtime(true);
        if ($remainingNs <= 0) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
        return [
            \intdiv($remainingNs, 1_000_000_000),
            (int)\intdiv($remainingNs % 1_000_000_000, 1_000),
        ];
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
