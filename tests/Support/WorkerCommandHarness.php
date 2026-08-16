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

use Coretsia\Platform\Worker\Runtime\WorkerLifecyclePaths;
use Coretsia\Platform\Worker\Runtime\WorkerShutdownBudget;

/**
 * Process wrapper around the worker command fixture.
 */
final class WorkerCommandHarness
{
    private const int DEFAULT_COMMAND_TIMEOUT_MS = 15_000;
    private const int TERMINATION_TIMEOUT_MS = 3_000;
    private const int EXIT_CODE_GRACE_MS = 2_000;
    private const int POLL_INTERVAL_US = 10_000;

    private string $configPath;
    private string $behaviorPath;

    /** @var array<string, mixed> */
    private array $workerConfig;

    /** @var resource|null */
    private mixed $startProcess = null;

    /** @var array<int, resource> */
    private array $startPipes = [];

    /** @var list<non-empty-string> */
    private array $startOutputPaths = [];

    private ?string $startExitCodePath = null;

    private string $startStdout = '';
    private string $startStderr = '';

    /**
     * @param array<string, mixed> $workerOverride
     * @param array<string, mixed> $behavior
     */
    public function __construct(
        private readonly string $skeletonRoot,
        array $workerOverride = [],
        array $behavior = [],
    ) {
        $defaults = require \dirname(__DIR__, 2) . '/config/worker.php';

        if (!\is_array($defaults)) {
            throw new \RuntimeException('worker-test-defaults-invalid');
        }

        $worker = WorkerSpecFactory::merge(
            $defaults,
            $workerOverride,
        );

        $this->workerConfig = $worker;

        $config = self::configDocument($worker);

        $this->configPath = $skeletonRoot . '/worker-test-config.json';
        $this->behaviorPath = $skeletonRoot . '/worker-test-behavior.json';

        self::writeJson($this->configPath, $config);
        self::writeJson($this->behaviorPath, $behavior);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        $this->discardStartProcess();
    }

    public function start(): void
    {
        if ($this->startProcess !== null) {
            throw new \LogicException('worker-harness-already-started');
        }

        [
            $process,
            $pipes,
            $outputPaths,
            $exitCodePath,
        ] = $this->openProcess('start');

        $this->startProcess = $process;
        $this->startPipes = $pipes;
        $this->startOutputPaths = $outputPaths;
        $this->startExitCodePath = $exitCodePath;
        $this->startStdout = '';
        $this->startStderr = '';
    }

    /** @return array<string, mixed> */
    public function startAndWaitForSummary(
        int $timeoutMs = self::DEFAULT_COMMAND_TIMEOUT_MS,
    ): array {
        $this->start();

        $message = $this->readStartMessage($timeoutMs);

        if (($message['type'] ?? null) !== 'json') {
            throw new \RuntimeException(
                'worker-start-summary-not-received: '
                . \json_encode($message),
            );
        }

        $payload = $message['payload'] ?? null;

        if (!\is_array($payload)) {
            throw new \RuntimeException('worker-start-summary-invalid');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function waitForStartMessage(
        int $timeoutMs = self::DEFAULT_COMMAND_TIMEOUT_MS,
    ): array {
        return $this->readStartMessage($timeoutMs);
    }

    /**
     * @return array{
     *     exit_code: int,
     *     messages: list<array<string, mixed>>,
     *     stderr: string
     * }
     */
    public function invoke(
        string $operation,
        ?int $timeoutMs = null,
    ): array {
        [
            $process,
            $pipes,
            $outputPaths,
            $exitCodePath,
        ] = $this->openProcess($operation);

        try {
            $result = self::collectProcess(
                process: $process,
                pipes: $pipes,
                timeoutMs: $timeoutMs ?? $this->commandTimeoutMs($operation),
                timeoutReason: 'worker-harness-command-timeout',
                exitCodePath: $exitCodePath,
            );
        } finally {
            self::cleanupOutputPaths($outputPaths);

            self::cleanupExitCodePath($exitCodePath);
        }

        return [
            'exit_code' => $result['exit_code'],
            'messages' => self::decodeLines($result['stdout']),
            'stderr' => $result['stderr'],
        ];
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    public function finishStart(int $timeoutMs = 10000): array
    {
        if (!\is_resource($this->startProcess)) {
            throw new \LogicException('worker-harness-not-started');
        }

        $deadline = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $this->drainStartPipes();
            $status = \proc_get_status($this->startProcess);

            if (!\is_array($status)) {
                throw new \RuntimeException('worker-start-status-invalid');
            }

            if (($status['running'] ?? false) !== true) {
                break;
            }

            \usleep(10_000);
        } while (\hrtime(true) < $deadline);

        $status = \proc_get_status($this->startProcess);
        $reportedExitCode = \is_array($status)
        && \is_int($status['exitcode'] ?? null)
        && $status['exitcode'] >= 0
            ? $status['exitcode']
            : null;

        $timedOut = \is_array($status) && ($status['running'] ?? false) === true;

        $explicitExitCode = null;

        if ($timedOut) {
            self::terminateProcessTree($this->startProcess);

            self::waitForProcessExit(
                $this->startProcess,
                self::TERMINATION_TIMEOUT_MS,
            );
        } else {
            $explicitExitCode = self::waitForExitCode(
                $this->startExitCodePath,
                self::EXIT_CODE_GRACE_MS,
            );
        }

        $this->drainStartPipes();

        self::closePipes($this->startPipes);

        $closedExitCode = @\proc_close($this->startProcess);

        if (!$timedOut && $explicitExitCode === null) {
            $explicitExitCode = self::readExitCode($this->startExitCodePath);
        }
        $exitCode = $explicitExitCode
            ?? $reportedExitCode
            ?? (
                \is_int($closedExitCode)
            && $closedExitCode >= 0
                ? $closedExitCode
                : 1
            );
        $stdout = $this->startStdout;
        $stderr = $this->startStderr;

        self::cleanupOutputPaths($this->startOutputPaths);
        self::cleanupExitCodePath($this->startExitCodePath);

        $this->startProcess = null;
        $this->startPipes = [];
        $this->startOutputPaths = [];
        $this->startExitCodePath = null;
        $this->startStdout = '';
        $this->startStderr = '';

        if ($timedOut) {
            throw new \RuntimeException('worker-start-process-timeout: ' . $stderr);
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    public function startPid(): int
    {
        if (!\is_resource($this->startProcess)) {
            throw new \LogicException('worker-harness-not-started');
        }

        $status = \proc_get_status($this->startProcess);

        if (
            !\is_array($status)
            || !\is_int($status['pid'] ?? null)
            || $status['pid'] < 1
        ) {
            throw new \RuntimeException('worker-start-pid-invalid');
        }

        return $status['pid'];
    }

    public function terminateStart(int $signal): void
    {
        if (!\is_resource($this->startProcess)) {
            throw new \LogicException('worker-harness-not-started');
        }

        @\proc_terminate($this->startProcess, $signal);
    }

    /**
     * Abruptly terminates only the foreground supervisor process.
     *
     * The guardian and workers are deliberately left untouched so tests can
     * verify Coretsia-owned supervisor-death containment.
     */
    public function crashSupervisorOnly(): void
    {
        if (!\is_resource($this->startProcess)) {
            throw new \LogicException('worker-harness-not-started');
        }

        $pid = $this->startPid();

        if (\PHP_OS_FAMILY === 'Windows') {
            self::terminateWindowsPid($pid, false);
        } else {
            if (!\defined('SIGKILL') || !\function_exists('posix_kill')) {
                throw new \RuntimeException('worker-harness-abrupt-termination-unavailable');
            }
            @\posix_kill($pid, \SIGKILL);
            @\proc_terminate($this->startProcess, \SIGKILL);
        }

        self::waitForProcessExit($this->startProcess, self::TERMINATION_TIMEOUT_MS);
        $status = @\proc_get_status($this->startProcess);
        if (\is_array($status) && ($status['running'] ?? false) === true) {
            throw new \RuntimeException('worker-harness-supervisor-crash-timeout');
        }

        $this->drainStartPipes();
        self::closePipes($this->startPipes);
        @\proc_close($this->startProcess);
        self::cleanupOutputPaths($this->startOutputPaths);
        self::cleanupExitCodePath($this->startExitCodePath);

        $this->startProcess = null;
        $this->startPipes = [];
        $this->startOutputPaths = [];
        $this->startExitCodePath = null;
        $this->startStdout = '';
        $this->startStderr = '';
    }

    public function lifecycleLockAvailable(): bool
    {
        $handle = @\fopen($this->lockPath(), \PHP_OS_FAMILY === 'Windows' ? 'c+b' : 'c+be');
        if (!\is_resource($handle)) {
            return false;
        }
        try {
            if (!@\flock($handle, \LOCK_EX | \LOCK_NB)) {
                return false;
            }
            @\flock($handle, \LOCK_UN);
            return true;
        } finally {
            @\fclose($handle);
        }
    }

    /** @param list<int> $pids */
    public function waitForLoggedChildrenExit(array $pids, int $timeoutMs = 10_000): void
    {
        $deadlineNs = \hrtime(true) + ($timeoutMs * 1_000_000);
        do {
            $alive = false;
            foreach ($pids as $pid) {
                if (self::pidExists($pid)) {
                    $alive = true;
                    break;
                }
            }
            if (!$alive) {
                return;
            }
            \usleep(self::POLL_INTERVAL_US);
        } while (\hrtime(true) < $deadlineNs);

        throw new \RuntimeException('worker-harness-children-still-running');
    }

    /**
     * Abruptly terminates the externally-owned supervisor process tree.
     *
     * This deliberately bypasses worker:stop and supervisor cleanup so crash
     * recovery tests can observe stale lifecycle artifacts after OS-level death.
     */
    public function crashStartProcessTree(): void
    {
        if (!\is_resource($this->startProcess)) {
            throw new \LogicException('worker-harness-not-started');
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            self::terminateProcessTree($this->startProcess);
        } else {
            if (
                !\defined('SIGKILL')
                || !\function_exists('posix_kill')
                || !\function_exists('posix_getpgid')
            ) {
                throw new \RuntimeException('worker-harness-abrupt-termination-unavailable');
            }

            $supervisorPid = $this->startPid();
            $processGroupId = @\posix_getpgid($supervisorPid);

            if (
                !\is_int($processGroupId)
                || $processGroupId < 1
                || $processGroupId !== $supervisorPid
            ) {
                throw new \RuntimeException('worker-harness-process-group-not-isolated');
            }

            /*
             * The start fixture creates a dedicated POSIX session before launching
             * the guardian or any worker infrastructure. Killing the negative PGID
             * therefore models catastrophic termination of the complete externally
             * owned service unit, including supervisor, guardian, ProcHost when
             * present, and every worker descendant.
             */
            if (!@\posix_kill(-$processGroupId, \SIGKILL)) {
                throw new \RuntimeException('worker-harness-process-group-termination-failed');
            }
        }

        self::waitForProcessExit(
            $this->startProcess,
            self::TERMINATION_TIMEOUT_MS,
        );

        $status = @\proc_get_status($this->startProcess);

        if (
            \is_array($status) && ($status['running'] ?? false) === true
        ) {
            throw new \RuntimeException('worker-harness-abrupt-termination-timeout');
        }

        $this->drainStartPipes();
        self::closePipes($this->startPipes);
        @\proc_close($this->startProcess);

        self::cleanupOutputPaths($this->startOutputPaths);
        self::cleanupExitCodePath($this->startExitCodePath);

        $this->startProcess = null;
        $this->startPipes = [];
        $this->startOutputPaths = [];
        $this->startExitCodePath = null;
        $this->startStdout = '';
        $this->startStderr = '';
    }

    /** @return list<array{generation: int, pid: int, slot: int}> */
    public function pidLog(): array
    {
        $path = $this->skeletonRoot . '/var/tmp/worker-pids.jsonl';

        if (!\is_file($path)) {
            return [];
        }

        $handle = @\fopen($path, 'rb');

        if (!\is_resource($handle)) {
            return [];
        }

        try {
            if (!@\flock($handle, \LOCK_SH)) {
                return [];
            }

            $bytes = @\stream_get_contents($handle);
        } finally {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }

        if (!\is_string($bytes)) {
            return [];
        }

        $records = [];

        foreach (\preg_split('/\r?\n/', \trim($bytes)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $record = \json_decode(
                $line,
                true,
                512,
                \JSON_THROW_ON_ERROR,
            );

            if (
                \is_array($record)
                && \is_int($record['generation'] ?? null)
                && \is_int($record['pid'] ?? null)
                && \is_int($record['slot'] ?? null)
            ) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /** @return array<string, mixed> */
    public function workerConfig(): array
    {
        return $this->workerConfig;
    }

    /**
     * Replaces the config document used only by subsequent CLI processes.
     *
     * The retained workerConfig remains the startup configuration of the active
     * supervisor so process-collection deadlines cannot drift with current config.
     *
     * @param array<string, mixed> $worker
     */
    public function replaceWorkerConfig(array $worker): void
    {
        self::writeJson(
            $this->configPath,
            self::configDocument($worker),
        );
    }

    public function releaseReadiness(): void
    {
        $this->writeGate('worker-ready-gate');
    }

    public function releaseChildExit(): void
    {
        $this->writeGate('worker-exit-gate');
    }

    private function writeGate(
        string $name,
    ): void {
        $path = $this->skeletonRoot
            . '/var/tmp/'
            . $name;

        $directory = \dirname($path);

        if (
            !\is_dir($directory)
            && !@\mkdir(
                $directory,
                0777,
                true,
            )
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException('worker-gate-directory-failed');
        }

        if (
            @\file_put_contents(
                $path,
                "release\n",
                \LOCK_EX,
            ) === false
        ) {
            throw new \RuntimeException('worker-gate-write-failed');
        }
    }

    public function statePath(): string
    {
        return $this->skeletonRoot . '/var/tmp/worker.state.json';
    }

    public function stopPath(): string
    {
        return $this->skeletonRoot . '/var/tmp/worker.stop';
    }

    public function lockPath(): string
    {
        return WorkerLifecyclePaths::resolve(
            $this->skeletonRoot,
            WorkerLifecyclePaths::LOCK,
        );
    }

    public function locatorPath(): string
    {
        return WorkerLifecyclePaths::resolve(
            $this->skeletonRoot,
            WorkerLifecyclePaths::LOCATOR,
        );
    }

    public function locatorTemporaryPath(): string
    {
        return WorkerLifecyclePaths::resolve(
            $this->skeletonRoot,
            WorkerLifecyclePaths::LOCATOR_TEMP,
        );
    }

    public function socketPath(): string
    {
        return $this->skeletonRoot . '/var/tmp/worker.sock';
    }

    private function commandTimeoutMs(
        string $operation,
    ): int {
        if ($operation !== 'stop') {
            return self::DEFAULT_COMMAND_TIMEOUT_MS;
        }

        $stopTimeoutMs = $this->workerConfig['stop_timeout_ms'] ?? null;
        $forceKillTimeoutMs = $this->workerConfig['force_kill_timeout_ms'] ?? null;

        if (
            !\is_int($stopTimeoutMs)
            || $stopTimeoutMs < 1
            || !\is_int($forceKillTimeoutMs)
            || $forceKillTimeoutMs < 1
        ) {
            return self::DEFAULT_COMMAND_TIMEOUT_MS;
        }

        return WorkerShutdownBudget::stopRequestTimeoutMs(
            $stopTimeoutMs,
            $forceKillTimeoutMs,
        ) + self::TERMINATION_TIMEOUT_MS;
    }

    /**
     * @return array{
     *     0: resource,
     *     1: array<int, resource>,
     *     2: list<non-empty-string>,
     *     3: non-empty-string
     * }
     */
    private function openProcess(
        string $operation,
    ): array {
        $windows = \PHP_OS_FAMILY === 'Windows';

        $outputPaths = $windows
            ? $this->createWindowsOutputPaths()
            : [];

        try {
            $exitCodePath = $this->createExitCodePath();
        } catch (\Throwable $exception) {
            self::cleanupOutputPaths($outputPaths);

            throw $exception;
        }

        $command = [
            \PHP_BINARY,
            \dirname(__DIR__) . '/Fixtures/worker-command-harness.php',
            $operation,
            $this->skeletonRoot,
            $this->configPath,
            $this->behaviorPath,
            $exitCodePath,
        ];

        $descriptors = $windows
            ? [
                0 => ['file', self::nullDevice(), 'r'],
                1 => ['file', $outputPaths[0], 'wb'],
                2 => ['file', $outputPaths[1], 'wb'],
            ]
            : [
                0 => ['file', self::nullDevice(), 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

        /**
         * @var array{
         *     bypass_shell: true,
         *     create_process_group?: true
         * } $options
         */
        $options = [
            'bypass_shell' => true,
        ];

        if ($windows) {
            $options['create_process_group'] = true;
        }

        $pipes = [];

        $process = @\proc_open(
            command: $command,
            descriptor_spec: $descriptors,
            pipes: $pipes,
            cwd: $this->skeletonRoot,
            env_vars: null,
            options: $options,
        );

        if (!\is_resource($process)) {
            self::cleanupOutputPaths($outputPaths);
            self::cleanupExitCodePath($exitCodePath);

            throw new \RuntimeException('worker-harness-process-failed');
        }

        if ($windows) {
            $stdout = @\fopen(
                $outputPaths[0],
                'rb',
            );

            $stderr = @\fopen(
                $outputPaths[1],
                'rb',
            );

            if (
                !\is_resource($stdout)
                || !\is_resource($stderr)
            ) {
                if (\is_resource($stdout)) {
                    @\fclose($stdout);
                }

                if (\is_resource($stderr)) {
                    @\fclose($stderr);
                }

                self::terminateProcessTree($process);
                @\proc_close($process);

                self::cleanupOutputPaths($outputPaths);
                self::cleanupExitCodePath($exitCodePath);

                throw new \RuntimeException('worker-harness-output-files-invalid');
            }

            return [
                $process,
                [
                    1 => $stdout,
                    2 => $stderr,
                ],
                $outputPaths,
                $exitCodePath,
            ];
        }

        if (
            !isset($pipes[1], $pipes[2])
            || !\is_resource($pipes[1])
            || !\is_resource($pipes[2])
            || !@\stream_set_blocking($pipes[1], false)
            || !@\stream_set_blocking($pipes[2], false)
        ) {
            self::terminateProcessTree($process);
            self::closePipes($pipes);
            @\proc_close($process);

            self::cleanupExitCodePath($exitCodePath);

            throw new \RuntimeException('worker-harness-pipes-invalid');
        }

        return [
            $process,
            $pipes,
            [],
            $exitCodePath,
        ];
    }

    /**
     * @return array{
     *     0: non-empty-string,
     *     1: non-empty-string
     * }
     */
    private function createWindowsOutputPaths(): array
    {
        $directory = $this->skeletonRoot . '/var/tmp';

        if (
            !\is_dir($directory)
            && !@\mkdir(
                $directory,
                0777,
                true,
            )
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException('worker-harness-output-directory-failed');
        }

        try {
            $token = \bin2hex(\random_bytes(8));
        } catch (\Throwable) {
            throw new \RuntimeException('worker-harness-output-token-failed');
        }

        $base = $directory
            . '/wh-'
            . $token;

        $stdoutPath = $base . '.out';
        $stderrPath = $base . '.err';

        if (
            @\file_put_contents($stdoutPath, '') === false
            || @\file_put_contents($stderrPath, '') === false
        ) {
            @\unlink($stdoutPath);
            @\unlink($stderrPath);

            throw new \RuntimeException('worker-harness-output-files-failed');
        }

        return [
            $stdoutPath,
            $stderrPath,
        ];
    }

    /**
     * Returns a unique publication path.
     *
     * The final file intentionally does not exist yet. The child publishes it
     * atomically only after the command has produced its definitive exit code.
     *
     * @return non-empty-string
     */
    private function createExitCodePath(): string
    {
        $directory = $this->skeletonRoot . '/var/tmp';

        if (
            !\is_dir($directory)
            && !@\mkdir($directory, 0777, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException('worker-harness-exit-directory-failed');
        }

        try {
            $token = \bin2hex(\random_bytes(8));
        } catch (\Throwable) {
            throw new \RuntimeException('worker-harness-exit-token-failed');
        }

        $path = $directory
            . '/wh-exit-'
            . $token
            . '.txt';

        if (
            @\file_exists($path)
            || @\file_exists($path . '.tmp')
        ) {
            throw new \RuntimeException('worker-harness-exit-path-collision');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function readStartMessage(int $timeoutMs): array
    {
        if (!\is_resource($this->startProcess)) {
            throw new \LogicException('worker-harness-not-started');
        }

        if ($timeoutMs < 1) {
            throw new \InvalidArgumentException('worker-harness-timeout-invalid');
        }

        $deadline = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $this->drainStartPipes();

            $message = $this->shiftStartMessage();

            if ($message !== null) {
                return $message;
            }

            $status = @\proc_get_status($this->startProcess);

            if (!\is_array($status)) {
                $this->discardStartProcess();

                throw new \RuntimeException('worker-start-status-invalid');
            }

            if (($status['running'] ?? false) !== true) {
                /*
                 * The process may have written its final output between the
                 * previous pipe drain and proc_get_status().
                 */
                $this->drainStartPipes();

                $message = $this->shiftStartMessage();

                if ($message !== null) {
                    return $message;
                }

                $exitCode = \is_int($status['exitcode'] ?? null)
                && $status['exitcode'] >= 0
                    ? $status['exitcode']
                    : null;

                $stdout = $this->startStdout;
                $stderr = $this->startStderr;

                $this->discardStartProcess();

                throw new \RuntimeException(
                    'worker-start-process-exited-before-message: '
                    . 'exit_code='
                    . ($exitCode === null ? 'unknown' : (string)$exitCode)
                    . '; stdout='
                    . \var_export($stdout, true)
                    . '; stderr='
                    . \var_export($stderr, true),
                );
            }

            \usleep(self::POLL_INTERVAL_US);
        } while (\hrtime(true) < $deadline);

        /*
         * Close the boundary race where output was produced immediately after
         * the final loop condition check.
         */
        $this->drainStartPipes();

        $message = $this->shiftStartMessage();

        if ($message !== null) {
            return $message;
        }

        $stdout = $this->startStdout;
        $stderr = $this->startStderr;

        $this->discardStartProcess();

        throw new \RuntimeException(
            'worker-harness-message-timeout: '
            . 'stdout='
            . \var_export($stdout, true)
            . '; stderr='
            . \var_export($stderr, true),
        );
    }

    /** @return array<string, mixed>|null */
    private function shiftStartMessage(): ?array
    {
        $newline = \strpos($this->startStdout, "\n");

        if ($newline === false) {
            return null;
        }

        $line = \substr(
            $this->startStdout,
            0,
            $newline,
        );

        $this->startStdout = \substr(
            $this->startStdout,
            $newline + 1,
        );

        $message = \json_decode(
            $line,
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        if (!\is_array($message)) {
            throw new \RuntimeException('worker-harness-message-invalid');
        }

        return $message;
    }

    private function discardStartProcess(): void
    {
        if (!\is_resource($this->startProcess)) {
            self::closePipes($this->startPipes);
            self::cleanupOutputPaths($this->startOutputPaths);
            self::cleanupExitCodePath($this->startExitCodePath);

            $this->startProcess = null;
            $this->startPipes = [];
            $this->startOutputPaths = [];
            $this->startExitCodePath = null;
            $this->startStdout = '';
            $this->startStderr = '';

            return;
        }

        self::terminateProcessTree($this->startProcess);

        self::waitForProcessExit(
            $this->startProcess,
            self::TERMINATION_TIMEOUT_MS,
        );

        $this->drainStartPipes();

        self::closePipes($this->startPipes);

        @\proc_close($this->startProcess);

        self::cleanupOutputPaths($this->startOutputPaths);
        self::cleanupExitCodePath($this->startExitCodePath);

        $this->startProcess = null;
        $this->startPipes = [];
        $this->startOutputPaths = [];
        $this->startExitCodePath = null;
        $this->startStdout = '';
        $this->startStderr = '';
    }

    private function drainStartPipes(): void
    {
        $this->startStdout .= self::readAvailable(
            $this->startPipes[1] ?? null,
        );

        $this->startStderr .= self::readAvailable(
            $this->startPipes[2] ?? null,
        );
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     *
     * @return array{
     *     exit_code: int,
     *     stdout: string,
     *     stderr: string
     * }
     */
    private static function collectProcess(
        mixed $process,
        array $pipes,
        int $timeoutMs,
        string $timeoutReason,
        string $exitCodePath,
    ): array {
        if ($timeoutMs < 1) {
            throw new \InvalidArgumentException('worker-harness-timeout-invalid');
        }

        $stdout = '';
        $stderr = '';
        $reportedExitCode = null;
        $running = true;

        $deadlineNs = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $stdout .= self::readAvailable(
                $pipes[1] ?? null,
            );

            $stderr .= self::readAvailable(
                $pipes[2] ?? null,
            );

            $status = @\proc_get_status($process);

            if (!\is_array($status)) {
                self::terminateProcessTree($process);
                self::closePipes($pipes);
                @\proc_close($process);

                throw new \RuntimeException('worker-harness-process-status-invalid');
            }

            if (($status['running'] ?? false) !== true) {
                $running = false;

                if (
                    \is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0
                ) {
                    $reportedExitCode = $status['exitcode'];
                }

                break;
            }

            \usleep(self::POLL_INTERVAL_US);
        } while (\hrtime(true) < $deadlineNs);

        $explicitExitCode = null;

        if ($running) {
            self::terminateProcessTree($process);

            self::waitForProcessExit(
                $process,
                self::TERMINATION_TIMEOUT_MS,
            );
        } else {
            /*
             * On Windows proc_get_status() may report that the process wrapper has
             * terminated before the child-published sidecar is visible to this
             * process. The sidecar is the authoritative command exit result.
             */
            $explicitExitCode = self::waitForExitCode(
                $exitCodePath,
                self::EXIT_CODE_GRACE_MS,
            );
        }

        $stdout .= self::readAvailable(
            $pipes[1] ?? null,
        );

        $stderr .= self::readAvailable(
            $pipes[2] ?? null,
        );

        self::closePipes($pipes);

        $closedExitCode = @\proc_close($process);

        if (!$running && $explicitExitCode === null) {
            $explicitExitCode = self::readExitCode($exitCodePath);
        }

        if ($running) {
            throw new \RuntimeException($timeoutReason . ': ' . $stderr);
        }

        return [
            'exit_code' => $explicitExitCode
                ?? $reportedExitCode
                    ?? (
                        \is_int($closedExitCode)
                    && $closedExitCode >= 0
                        ? $closedExitCode
                        : 1
                    ),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private static function readAvailable(
        mixed $pipe,
    ): string {
        if (!\is_resource($pipe)) {
            return '';
        }

        $metadata = @\stream_get_meta_data($pipe);

        /*
         * A long-lived regular-file reader can retain an EOF view while another
         * process appends and flushes output on Windows. Read a fresh filesystem
         * snapshot from the reader's current byte position so already-published
         * command output cannot remain hidden behind that cached EOF state.
         *
         * The persistent stream still owns the read cursor. If a fresh-path read
         * is unavailable, fall back to the existing stream read after clearing
         * EOF by re-seeking to the current position. Anonymous Unix pipes are not
         * seekable and therefore continue directly to stream_get_contents().
         */
        if (
            \is_array($metadata)
            && ($metadata['seekable'] ?? false) === true
        ) {
            $position = @\ftell($pipe);
            $uri = $metadata['uri'] ?? null;

            if (
                \is_int($position)
                && $position >= 0
                && \is_string($uri)
                && $uri !== ''
            ) {
                $freshBytes = @\file_get_contents(
                    $uri,
                    false,
                    null,
                    $position,
                );

                if (
                    \is_string($freshBytes)
                    && @\fseek(
                        $pipe,
                        $position + \strlen($freshBytes),
                        \SEEK_SET,
                    ) === 0
                ) {
                    return $freshBytes;
                }
            }

            @\fseek(
                $pipe,
                0,
                \SEEK_CUR,
            );
        }

        $bytes = @\stream_get_contents($pipe);

        return \is_string($bytes)
            ? $bytes
            : '';
    }

    /** @param array<int, resource> $pipes */
    private static function closePipes(
        array $pipes,
    ): void {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
    }

    private static function readExitCode(
        ?string $path,
    ): ?int {
        if (
            $path === null
            || $path === ''
            || !\is_file($path)
        ) {
            return null;
        }

        $bytes = @\file_get_contents($path);

        if (!\is_string($bytes)) {
            return null;
        }

        $value = \trim($bytes);

        if (!\ctype_digit($value)) {
            return null;
        }

        $exitCode = (int)$value;

        return $exitCode >= 0 && $exitCode <= 255
            ? $exitCode
            : null;
    }

    private static function waitForExitCode(
        ?string $path,
        int $timeoutMs,
    ): ?int {
        if (
            $path === null
            || $path === ''
            || $timeoutMs < 1
        ) {
            return null;
        }

        $deadlineNs = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $exitCode = self::readExitCode($path);

            if ($exitCode !== null) {
                return $exitCode;
            }

            \usleep(self::POLL_INTERVAL_US);
        } while (\hrtime(true) < $deadlineNs);

        /*
         * One final read closes the boundary race where publication occurred
         * immediately after the final loop condition check.
         */
        return self::readExitCode($path);
    }

    /**
     * @param list<non-empty-string> $paths
     */
    private static function cleanupOutputPaths(
        array $paths,
    ): void {
        foreach ($paths as $path) {
            if (\is_string($path) && $path !== '') {
                @\unlink($path);
            }
        }
    }

    private static function cleanupExitCodePath(
        ?string $path,
    ): void {
        if (
            $path === null
            || $path === ''
        ) {
            return;
        }

        @\unlink($path);
        @\unlink($path . '.tmp');
    }

    private static function pidExists(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (\PHP_OS_FAMILY !== 'Windows' && \function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        return false;
    }

    private static function terminateWindowsPid(int $pid, bool $tree): void
    {
        if ($pid < 1) {
            return;
        }
        $null = self::nullDevice();
        $descriptors = [
            0 => ['file', $null, 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];
        $command = ['taskkill.exe', '/PID', (string)$pid];
        if ($tree) {
            $command[] = '/T';
        }
        $command[] = '/F';
        $pipes = [];
        $killer = @\proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (\is_resource($killer)) {
            @\proc_close($killer);
        }
    }

    private static function terminateProcessTree(
        mixed $process,
    ): void {
        if (!\is_resource($process)) {
            return;
        }

        $status = @\proc_get_status($process);

        $pid = \is_array($status)
        && \is_int($status['pid'] ?? null)
        && $status['pid'] > 0
            ? $status['pid']
            : null;

        if (\PHP_OS_FAMILY === 'Windows' && $pid !== null) {
            self::terminateWindowsPid($pid, true);
        }

        @\proc_terminate($process, 9);
    }

    private static function waitForProcessExit(
        mixed $process,
        int $timeoutMs,
    ): void {
        $deadlineNs = \hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $status = @\proc_get_status($process);

            if (
                !\is_array($status)
                || ($status['running'] ?? false) !== true
            ) {
                return;
            }

            \usleep(self::POLL_INTERVAL_US);
        } while (\hrtime(true) < $deadlineNs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeLines(string $bytes): array
    {
        $messages = [];

        foreach (\preg_split('/\r?\n/', \trim($bytes)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $message = \json_decode(
                $line,
                true,
                512,
                \JSON_THROW_ON_ERROR,
            );

            if (\is_array($message)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $worker */
    private static function configDocument(array $worker): array
    {
        return [
            'kernel' => [
                'runtime' => [
                    'http_driver' => 'http.classic',
                ],
            ],
            'worker' => $worker,
        ];
    }

    /** @param array<string, mixed> $value */
    private static function writeJson(
        string $path,
        array $value,
    ): void {
        $bytes = \json_encode(
            $value === [] ? (object)[] : $value,
            \JSON_UNESCAPED_SLASHES
                | \JSON_UNESCAPED_UNICODE
                | \JSON_PRETTY_PRINT
                | \JSON_THROW_ON_ERROR,
        ) . "\n";
        $temporaryPath = $path . '.tmp';
        $backupPath = $path . '.bak';

        if (
            @\file_put_contents(
                $temporaryPath,
                $bytes,
                \LOCK_EX,
            ) === false
        ) {
            throw new \RuntimeException('worker-harness-config-write-failed');
        }

        if (@\rename($temporaryPath, $path)) {
            return;
        }

        if (
            @\is_link($path)
            || !@\is_file($path)
            || @\file_exists($backupPath)
            || @\is_link($backupPath)
            || !@\rename($path, $backupPath)
        ) {
            @\unlink($temporaryPath);
            throw new \RuntimeException('worker-harness-config-write-failed');
        }

        if (@\rename($temporaryPath, $path)) {
            @\unlink($backupPath);
            return;
        }

        $restored = @\rename($backupPath, $path);
        @\unlink($temporaryPath);

        if (!$restored) {
            throw new \RuntimeException('worker-harness-config-restore-failed');
        }

        throw new \RuntimeException('worker-harness-config-write-failed');
    }

    private static function nullDevice(): string
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? 'NUL'
            : '/dev/null';
    }
}
