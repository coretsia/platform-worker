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

namespace Coretsia\Platform\Worker\Process\Proc;

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;

/**
 * Synchronous client for the guardian-owned pre-lock proc process host.
 *
 * The host is started before the guardian-supervisor ownership channel and lifecycle-lock
 * acquisition. This prevents inheritance of Worker-owned lifecycle
 * descriptors created after process-host preparation. Communication uses one
 * authenticated loopback TCP connection, avoiding proc_open pipe selection on
 * Windows. Before every worker-child launch, that connection is closed and
 * replaced through a one-shot guardian-owned handoff endpoint.
 *
 * This boundary does not by itself prove that arbitrary application or
 * integration descriptors opened before process-host startup are
 * close-on-exec. Those descriptors remain governed by the repository-wide
 * process-exec descriptor-safety contract.
 *
 * The host's authenticated guardian connection is a Worker-owned descriptor.
 * No proc-host protocol connection is open while the host calls proc_open(), so
 * the same descriptor-isolation guarantee applies on Windows and POSIX without
 * relying on platform-specific close-on-exec flags.
 */
final class WorkerProcProcessHostClient
{
    private const int WAIT_TICK_US = 1_000;
    private const int HOST_FALLBACK_SHUTDOWN_MS = 5_000;
    private const int MAX_TIMEOUT_MS = 86_400_000;
    private const int MAX_REQUEST_ID = 2_147_483_647;

    /** @var list<non-empty-string> */
    private array $command;

    private mixed $process = null;
    private mixed $connection = null;
    private ?int $hostPid = null;
    private int $nextRequestId = 1;
    private int $requestTimeoutMs = 1_000;

    /** @var array<string, positive-int> */
    private array $children = [];

    /**
     * @param list<non-empty-string> $command
     */
    public function __construct(
        array $command,
        private readonly string $workingDirectory,
        private readonly WorkerProcProcessHostProtocol $protocol,
        private readonly WorkerProcessBootstrapLauncher $bootstrapLauncher,
    ) {
        if (
            $command === []
            || !\array_is_list($command)
            || !self::isSafePath($workingDirectory)
        ) {
            throw new \InvalidArgumentException('worker-proc-host-client-invalid');
        }

        foreach ($command as $part) {
            if (!self::isSafeCommandPart($part)) {
                throw new \InvalidArgumentException('worker-proc-host-client-invalid');
            }
        }

        $this->command = $command;
    }

    public function start(int $timeoutMs): void
    {
        self::assertTimeout($timeoutMs);

        if (\is_resource($this->process) || \is_resource($this->connection)) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        try {
            $session = $this->bootstrapLauncher->launchAuthenticatedChild(
                command: $this->command,
                workingDirectory: $this->workingDirectory,
                role: WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
                timeoutMs: $timeoutMs,
            );
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $this->process = $session['process'];
        $this->hostPid = $session['pid'];
        $this->connection = $session['connection'];
        $this->requestTimeoutMs = \min($timeoutMs, 1_000);
    }

    /**
     * @param non-empty-list<non-empty-string> $command
     */
    public function spawn(
        array $command,
        string $workingDirectory,
        int $timeoutMs,
    ): WorkerProcProcessHostChild {
        self::assertTimeout($timeoutMs);
        $this->assertStarted();

        if (
            $command === []
            || !\array_is_list($command)
            || !self::isSafePath($workingDirectory)
        ) {
            throw WorkerStartFailedException::childStartFailed();
        }

        foreach ($command as $part) {
            if (!self::isSafeCommandPart($part)) {
                throw WorkerStartFailedException::childStartFailed();
            }
        }

        $handoff = WorkerProcProcessHostHandoffEndpoint::create();
        $requestId = $this->nextRequestId();
        $deadlineNs = self::deadlineNs($timeoutMs);
        $frame = $this->protocol->encodeRequest(
            requestId: $requestId,
            operation: WorkerProcProcessHostProtocol::OPERATION_SPAWN,
            payload: [
                'command' => $command,
                'handoff_port' => $handoff->port(),
                'handoff_token' => $handoff->token(),
                'working_directory' => $workingDirectory,
            ],
        );

        try {
            $this->writeFrame($frame, $deadlineNs);

            /*
             * The host validates the complete spawn frame before closing its copy
             * of this connection. Closing the guardian copy here completes the
             * old-channel boundary before the host can call proc_open().
             */
            $this->closeConnection();

            $this->connection = $handoff->accept($deadlineNs);
            $response = $this->protocol->decodeHandoff(
                frame: $this->readFrame($deadlineNs),
                expectedRequestId: $requestId,
                expectedToken: $handoff->token(),
            );
            $responsePayload = $this->responsePayload(
                response: $response,
                requestId: $requestId,
                childStartFailureAllowed: true,
            );

            if (
                \array_keys($responsePayload) !== ['child_id', 'pid']
                || !\is_string($responsePayload['child_id'])
                || \preg_match('/\Achild-[1-9][0-9]*\z/', $responsePayload['child_id']) !== 1
                || !\is_int($responsePayload['pid'])
                || $responsePayload['pid'] < 1
                || isset($this->children[$responsePayload['child_id']])
            ) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            $this->children[$responsePayload['child_id']] = $responsePayload['pid'];

            return new WorkerProcProcessHostChild(
                id: $responsePayload['child_id'],
                pid: $responsePayload['pid'],
            );
        } catch (WorkerStartFailedException $exception) {
            /*
             * A deterministic child-start failure is delivered through a valid
             * replacement connection, so the process host remains usable.
             */
            throw $exception;
        } catch (\Throwable $exception) {
            /*
             * A failed or invalid handoff leaves no trustworthy child identity.
             * Close the owner channel and let ProcHost perform terminal cleanup.
             * Do not hard-kill here because Guardian may already own worker.lock.
             */
            try {
                $this->closeOwnerChannelAndAwaitHostExit(
                    timeoutMs: self::HOST_FALLBACK_SHUTDOWN_MS,
                    allowForcedTermination: false,
                );
            } catch (\Throwable) {
                /* Preserve the live process handle for Guardian-owned fail-closed cleanup. */
            }

            if ($exception instanceof WorkerLifecycleFailedException) {
                throw $exception;
            }

            throw WorkerLifecycleFailedException::processHostFailed();
        } finally {
            $handoff->close();
        }
    }

    public function pollExit(
        string $childId,
        int $timeoutMs,
    ): ?WorkerProcessExit {
        $pid = $this->knownChildPid($childId);

        $response = $this->request(
            operation: WorkerProcProcessHostProtocol::OPERATION_POLL,
            payload: ['child_id' => $childId],
            timeoutMs: $this->boundedRequestTimeoutMs($timeoutMs),
        );

        if (\array_keys($response) === ['state']) {
            if ($response['state'] !== 'running') {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            return null;
        }

        if (
            \array_keys($response) !== [
                'exit_code',
                'expected',
                'pid',
                'signaled',
                'state',
                'terminating_signal',
            ]
            || $response['state'] !== 'exited'
            || $response['pid'] !== $pid
            || !\is_int($response['exit_code'])
            || $response['exit_code'] < 0
            || !\is_bool($response['signaled'])
            || !\is_int($response['terminating_signal'])
            || $response['terminating_signal'] < 0
            || !\is_bool($response['expected'])
            || (!$response['signaled'] && $response['terminating_signal'] !== 0)
            || ($response['signaled'] && $response['terminating_signal'] < 1)
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return new WorkerProcessExit(
            pid: $pid,
            exitCode: $response['exit_code'],
            signaled: $response['signaled'],
            terminatingSignal: $response['terminating_signal'],
            expected: $response['expected'],
        );
    }

    public function terminate(string $childId, int $timeoutMs): void
    {
        $this->acknowledgeChildOperation(
            WorkerProcProcessHostProtocol::OPERATION_TERMINATE,
            $childId,
            $timeoutMs,
        );
    }

    public function kill(string $childId, int $timeoutMs): void
    {
        $this->acknowledgeChildOperation(
            WorkerProcProcessHostProtocol::OPERATION_KILL,
            $childId,
            $timeoutMs,
        );
    }

    public function close(string $childId, int $timeoutMs): void
    {
        $this->acknowledgeChildOperation(
            WorkerProcProcessHostProtocol::OPERATION_CLOSE,
            $childId,
            $timeoutMs,
        );

        unset($this->children[$childId]);
    }

    public function shutdown(
        int $timeoutMs,
        bool $allowForcedTermination = false,
    ): void {
        self::assertTimeout($timeoutMs);

        if (!\is_resource($this->process)) {
            $this->reset();
            return;
        }

        $deadlineNs = self::deadlineNs($timeoutMs);

        try {
            if (\is_resource($this->connection)) {
                $response = $this->request(
                    operation: WorkerProcProcessHostProtocol::OPERATION_SHUTDOWN,
                    payload: [],
                    timeoutMs: $this->boundedRequestTimeoutMs(
                        self::remainingMs($deadlineNs),
                    ),
                );

                if (
                    \array_keys($response) !== ['acknowledged']
                    || $response['acknowledged'] !== true
                ) {
                    throw WorkerLifecycleFailedException::processHostFailed();
                }

                $this->closeConnection();
            }

            $this->waitForHostExit($deadlineNs);
            $this->closeProcessResource();
            $this->reset();
        } catch (\Throwable $exception) {
            /*
             * EOF is the ProcHost owner-loss signal. When Guardian already owns
             * worker.lock, hard-killing ProcHost cannot prove that its workers
             * were reaped, so the caller can require fail-closed containment.
             */
            try {
                $this->closeOwnerChannelAndAwaitHostExit(
                    timeoutMs: self::remainingMsOrOne($deadlineNs),
                    allowForcedTermination: $allowForcedTermination,
                );
            } catch (\Throwable) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            if ($exception instanceof WorkerLifecycleFailedException) {
                throw $exception;
            }

            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    private function acknowledgeChildOperation(
        string $operation,
        string $childId,
        int $timeoutMs,
    ): void {
        $this->knownChildPid($childId);

        $response = $this->request(
            operation: $operation,
            payload: ['child_id' => $childId],
            timeoutMs: $this->boundedRequestTimeoutMs($timeoutMs),
        );

        if (
            \array_keys($response) !== ['acknowledged']
            || $response['acknowledged'] !== true
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    /**
     * @param array<int|string, mixed> $payload
     *
     * @return array<int|string, mixed>
     */
    private function request(
        string $operation,
        array $payload,
        int $timeoutMs,
        bool $childStartFailureAllowed = false,
    ): array {
        self::assertTimeout($timeoutMs);
        $this->assertStarted();

        $requestId = $this->nextRequestId();
        $deadlineNs = self::deadlineNs($timeoutMs);
        $frame = $this->protocol->encodeRequest(
            requestId: $requestId,
            operation: $operation,
            payload: $payload,
        );

        $this->writeFrame($frame, $deadlineNs);
        $response = $this->protocol->decodeResponse(
            $this->readFrame($deadlineNs),
        );

        return $this->responsePayload(
            response: $response,
            requestId: $requestId,
            childStartFailureAllowed: $childStartFailureAllowed,
        );
    }

    /**
     * @param array{
     *     version: 1,
     *     request_id: positive-int,
     *     status: 'ok'|'error',
     *     payload: array<int|string, mixed>
     * } $response
     *
     * @return array<int|string, mixed>
     */
    private function responsePayload(
        array $response,
        int $requestId,
        bool $childStartFailureAllowed,
    ): array {
        if ($response['request_id'] !== $requestId) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        if ($response['status'] === WorkerProcProcessHostProtocol::STATUS_ERROR) {
            $reason = $response['payload']['reason'] ?? null;

            if (
                $childStartFailureAllowed && $reason
                === WorkerProcProcessHostProtocol::ERROR_CHILD_START_FAILED
            ) {
                throw WorkerStartFailedException::childStartFailed();
            }

            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $response['payload'];
    }

    private function writeFrame(string $frame, int $deadlineNs): void
    {
        $connection = $this->connection;

        if (!\is_resource($connection)) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $remaining = $frame;

        while ($remaining !== '') {
            $read = null;
            $write = [$connection];
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($deadlineNs);

            $selected = @\stream_select(
                $read,
                $write,
                $except,
                $seconds,
                $microseconds,
            );

            if ($selected === false) {
                /*
                 * SIGTERM/SIGINT may interrupt stream_select() after the guardian
                 * signal handler has recorded shutdown intent. Retry while the host and
                 * connection remain live and the deterministic deadline has not expired.
                 */
                if (
                    $this->hostRunning()
                    && !@\feof($connection)
                    && \hrtime(true) < $deadlineNs
                ) {
                    continue;
                }

                throw WorkerLifecycleFailedException::processHostFailed();
            }

            if ($selected !== 1) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            $written = @\fwrite($connection, $remaining);

            if (!\is_int($written) || $written < 1) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            $remaining = \substr($remaining, $written);
        }

        if (!@\fflush($connection)) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    private function readFrame(int $deadlineNs): string
    {
        $connection = $this->connection;

        if (!\is_resource($connection)) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $buffer = '';

        while (true) {
            $read = [$connection];
            $write = null;
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($deadlineNs);

            $selected = @\stream_select(
                $read,
                $write,
                $except,
                $seconds,
                $microseconds,
            );

            if ($selected === false) {
                if (
                    $this->hostRunning()
                    && !@\feof($connection)
                    && \hrtime(true) < $deadlineNs
                ) {
                    continue;
                }

                throw WorkerLifecycleFailedException::processHostFailed();
            }

            if ($selected !== 1) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            $remaining = WorkerProcProcessHostProtocol::MAX_FRAME_BYTES
                + 1
                - \strlen($buffer);

            if ($remaining < 1) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            $chunk = @\fread($connection, $remaining);

            if ($chunk === false || $chunk === '') {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            $buffer .= $chunk;
            $newline = \strpos($buffer, "\n");

            if ($newline === false) {
                continue;
            }

            if ($newline !== \strlen($buffer) - 1) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            return $buffer;
        }
    }

    private function boundedRequestTimeoutMs(int $timeoutMs): int
    {
        self::assertTimeout($timeoutMs);

        return \min($timeoutMs, $this->requestTimeoutMs);
    }

    private function knownChildPid(string $childId): int
    {
        $this->assertStarted();

        if (
            \preg_match('/\Achild-[1-9][0-9]*\z/', $childId) !== 1
            || !isset($this->children[$childId])
        ) {
            throw WorkerLifecycleFailedException::childExited();
        }

        return $this->children[$childId];
    }

    private function nextRequestId(): int
    {
        if ($this->nextRequestId > self::MAX_REQUEST_ID) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $this->nextRequestId++;
    }

    private function assertStarted(): void
    {
        if (!$this->started() || !$this->hostRunning()) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    private function started(): bool
    {
        return \is_resource($this->process) && \is_resource($this->connection);
    }

    private function hostRunning(): bool
    {
        if (!\is_resource($this->process) || $this->hostPid === null) {
            return false;
        }

        $status = @\proc_get_status($this->process);

        return $status['running'] === true
            && $status['pid'] === $this->hostPid;
    }

    private function waitForHostExit(int $deadlineNs): void
    {
        while ($this->hostRunning()) {
            if (\hrtime(true) >= $deadlineNs) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            \usleep(self::WAIT_TICK_US);
        }
    }

    private function closeOwnerChannelAndAwaitHostExit(
        int $timeoutMs,
        bool $allowForcedTermination,
    ): void {
        self::assertTimeout($timeoutMs);
        $this->closeConnection();

        if (!\is_resource($this->process)) {
            $this->reset();
            return;
        }

        $deadlineNs = self::deadlineNs($timeoutMs);
        while ($this->hostRunning() && \hrtime(true) < $deadlineNs) {
            \usleep(self::WAIT_TICK_US);
        }

        if ($this->hostRunning()) {
            if (!$allowForcedTermination) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            @\proc_terminate($this->process, 9);
        }

        @\proc_close($this->process);
        $this->reset();
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
    }

    private function reset(): void
    {
        $this->process = null;
        $this->connection = null;
        $this->children = [];
        $this->nextRequestId = 1;
        $this->requestTimeoutMs = 1_000;
        $this->hostPid = null;
    }

    private static function deadlineNs(int $timeoutMs): int
    {
        self::assertTimeout($timeoutMs);

        $nowNs = \hrtime(true);

        if (
            !\is_int($nowNs)
            || $timeoutMs > \intdiv(
                \PHP_INT_MAX - $nowNs,
                1_000_000,
            )
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $nowNs + ($timeoutMs * 1_000_000);
    }

    /** @return array{0: non-negative-int, 1: int<0, 999999>} */
    private static function selectTimeout(int $deadlineNs): array
    {
        $remainingNs = $deadlineNs - \hrtime(true);

        if ($remainingNs <= 0) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $seconds = \intdiv($remainingNs, 1_000_000_000);
        $microseconds = (int)\intdiv(
            $remainingNs % 1_000_000_000,
            1_000,
        );

        return [$seconds, $microseconds];
    }

    private static function remainingMs(int $deadlineNs): int
    {
        $remainingNs = $deadlineNs - \hrtime(true);

        if ($remainingNs <= 0) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return \max(1, (int)\ceil($remainingNs / 1_000_000));
    }

    private static function remainingMsOrOne(int $deadlineNs): int
    {
        $remainingNs = $deadlineNs - \hrtime(true);

        return $remainingNs <= 0
            ? 1
            : \max(1, (int)\ceil($remainingNs / 1_000_000));
    }

    private static function assertTimeout(int $timeoutMs): void
    {
        if (
            $timeoutMs < 1
            || $timeoutMs > self::MAX_TIMEOUT_MS
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }
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
            && \preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }
}
