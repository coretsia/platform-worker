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

namespace Coretsia\Platform\Worker\Tests\Fake;

use Coretsia\Platform\Worker\Internal\WorkerProcessGuardianInterface;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianChild;
use Coretsia\Platform\Worker\Process\WorkerProcessExit;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/** Deterministic in-memory guardian fake for unit-level driver/supervisor tests. */
final class FakeWorkerProcessGuardian implements WorkerProcessGuardianInterface
{
    /** @var list<array{driver: string, spec: WorkerPoolSpec}> */
    public array $claims = [];

    /** @var list<array{command: list<string>, working_directory: string, timeout_ms: int}> */
    public array $spawns = [];

    /** @var list<array{operation: string, child_id: string, timeout_ms: int}> */
    public array $operations = [];

    /** @var list<int> */
    public array $releases = [];

    /** @var array<string, WorkerProcessExit|null> */
    private array $exits = [];
    private int $nextId = 1;
    private int $nextPid = 20_000;

    public function claim(WorkerPoolSpec $spec, string $driverName): void
    {
        $this->claims[] = ['driver' => $driverName, 'spec' => $spec];
    }

    public function spawn(array $command, string $workingDirectory, int $timeoutMs): WorkerProcessGuardianChild
    {
        $this->spawns[] = [
            'command' => $command,
            'working_directory' => $workingDirectory,
            'timeout_ms' => $timeoutMs,
        ];
        $id = 'child-' . $this->nextId++;
        $pid = $this->nextPid++;
        $this->exits[$id] = null;

        return new WorkerProcessGuardianChild($id, $pid);
    }

    public function pollExit(string $childId, int $timeoutMs): ?WorkerProcessExit
    {
        $this->record('poll', $childId, $timeoutMs);
        return $this->exits[$childId] ?? null;
    }

    public function terminate(string $childId, int $timeoutMs): void
    {
        $this->record('terminate', $childId, $timeoutMs);
    }

    public function kill(string $childId, int $timeoutMs): void
    {
        $this->record('kill', $childId, $timeoutMs);
    }

    public function close(string $childId, int $timeoutMs): void
    {
        $this->record('close', $childId, $timeoutMs);
        unset($this->exits[$childId]);
    }

    public function release(int $timeoutMs): void
    {
        $this->releases[] = $timeoutMs;
    }

    public function complete(string $childId, WorkerProcessExit $exit): void
    {
        if (!\array_key_exists($childId, $this->exits)) {
            throw new \InvalidArgumentException('fake-worker-process-guardian-child-invalid');
        }
        $this->exits[$childId] = $exit;
    }

    private function record(string $operation, string $childId, int $timeoutMs): void
    {
        if (!\array_key_exists($childId, $this->exits)) {
            throw new \InvalidArgumentException('fake-worker-process-guardian-child-invalid');
        }
        $this->operations[] = [
            'operation' => $operation,
            'child_id' => $childId,
            'timeout_ms' => $timeoutMs,
        ];
    }
}
