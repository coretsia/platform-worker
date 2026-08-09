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

namespace Coretsia\Platform\Worker\Process\Driver;

use Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessGuardianInterface;
use Coretsia\Platform\Worker\Process\WorkerChildCommandBuilder;
use Coretsia\Platform\Worker\Process\WorkerChildProcess;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/** Cross-platform proc command adapter backed by the package-owned guardian. */
final readonly class ProcWorkerProcessDriver implements WorkerProcessDriverInterface
{
    /** @param non-empty-list<non-empty-string> $workerCommand */
    public function __construct(
        private string $skeletonRoot,
        private array $workerCommand,
        private WorkerChildCommandBuilder $commandBuilder,
        private WorkerChildReadinessChannel $readinessChannel,
        private WorkerProcessGuardianInterface $guardian,
        private bool $driverAvailable,
    ) {
        if (
            $skeletonRoot === ''
            || \str_contains($skeletonRoot, "\0")
            || $workerCommand === []
            || !\array_is_list($workerCommand)
        ) {
            throw new \InvalidArgumentException('proc-worker-process-driver-invalid');
        }
        foreach ($workerCommand as $part) {
            if (
                !\is_string($part)
                || $part === ''
                || \trim($part) !== $part
                || \preg_match('/[\x00-\x1F\x7F]/', $part) === 1
            ) {
                throw new \InvalidArgumentException('proc-worker-process-driver-invalid');
            }
        }
    }

    public function name(): string
    {
        return self::DRIVER_PROC;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        return $spec->driver() === self::DRIVER_PROC && $this->driverAvailable;
    }

    public function spawn(WorkerPoolSpec $spec, int $workerIndex): WorkerChildProcess
    {
        if (!$this->supports($spec) || $workerIndex < 0 || $workerIndex >= $spec->workers()) {
            throw WorkerStartFailedException::childStartFailed();
        }
        $readinessEndpoint = $this->readinessChannel->createProcessEndpoint();
        $command = $this->commandBuilder->build($this->workerCommand, $spec, $workerIndex, $readinessEndpoint);
        try {
            $guardianChild = $this->guardian->spawn($command, $this->skeletonRoot, $spec->startTimeoutMs());
        } catch (\Throwable $exception) {
            $readinessEndpoint->close();
            throw $exception;
        }
        return new WorkerChildProcess(
            workerIndex: $workerIndex,
            pid: $guardianChild->pid(),
            driverName: self::DRIVER_PROC,
            processHandle: $guardianChild->id(),
            readinessEndpoint: $readinessEndpoint,
            generation: 1,
            startedAtNs: \hrtime(true),
        );
    }

    public function pollExit(WorkerChildProcess $child, int $timeoutMs): ?WorkerProcessExit
    {
        self::assertTimeout($timeoutMs);
        return $this->guardian->pollExit(self::childId($child), $timeoutMs);
    }

    public function terminate(WorkerChildProcess $child, int $timeoutMs): void
    {
        self::assertTimeout($timeoutMs);
        $this->guardian->terminate(self::childId($child), $timeoutMs);
    }

    public function kill(WorkerChildProcess $child, int $timeoutMs): void
    {
        self::assertTimeout($timeoutMs);
        $this->guardian->kill(self::childId($child), $timeoutMs);
    }

    public function close(WorkerChildProcess $child, int $timeoutMs): void
    {
        self::assertTimeout($timeoutMs);
        if ($child->closed()) {
            return;
        }
        $child->readinessEndpoint()->close();
        $this->guardian->close(self::childId($child), $timeoutMs);
        $child->markClosed();
    }

    private static function childId(WorkerChildProcess $child): string
    {
        if ($child->driverName() !== self::DRIVER_PROC) {
            throw WorkerLifecycleFailedException::childExited();
        }
        return $child->processHandle();
    }

    private static function assertTimeout(int $timeoutMs): void
    {
        if ($timeoutMs < 1 || $timeoutMs > 86_400_000) {
            throw WorkerLifecycleFailedException::invalidState();
        }
    }
}
