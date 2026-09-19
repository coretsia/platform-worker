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

namespace Coretsia\Platform\Worker\Supervisor;

use Coretsia\Platform\Worker\Process\WorkerChildProcess;

/**
 * Typed supervisor-owned table of worker slots.
 *
 * Entries are keyed by deterministic worker index and retain the child handle,
 * generation, readiness state, and shutdown state without raw array lifecycle
 * records escaping this class.
 */
final class WorkerChildTable
{
    /** @var array<int, WorkerChildEntry> */
    private array $entries = [];

    public function add(WorkerChildProcess $child): void
    {
        $index = $child->workerIndex();

        if (isset($this->entries[$index])) {
            throw new \InvalidArgumentException('worker-child-table-duplicate');
        }

        $this->entries[$index] = new WorkerChildEntry($child);

        \ksort($this->entries, \SORT_NUMERIC);
    }

    public function markReady(int $workerIndex): void
    {
        $this->entry($workerIndex)->markReady();
    }

    public function markTerminating(int $workerIndex): void
    {
        $this->entry($workerIndex)->markTerminating();
    }

    public function markKilling(int $workerIndex): void
    {
        $this->entry($workerIndex)->markKilling();
    }

    public function isReady(int $workerIndex): bool
    {
        return $this->entry($workerIndex)->readiness() === WorkerChildReadinessState::READY;
    }

    public function remove(int $workerIndex): WorkerChildProcess
    {
        $entry = $this->entry($workerIndex);
        unset($this->entries[$workerIndex]);

        return $entry->child();
    }

    /** @return list<WorkerChildProcess> */
    public function all(): array
    {
        return \array_values(
            \array_map(
                static fn (WorkerChildEntry $entry): WorkerChildProcess => $entry->child(),
                $this->entries,
            )
        );
    }

    /** @return list<WorkerChildProcess> */
    public function unready(): array
    {
        $children = [];

        foreach ($this->entries as $entry) {
            if ($entry->readiness() === WorkerChildReadinessState::PENDING) {
                $children[] = $entry->child();
            }
        }

        return $children;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function empty(): bool
    {
        return $this->entries === [];
    }

    public function readyCount(): int
    {
        $count = 0;

        foreach ($this->entries as $entry) {
            if ($entry->readiness() === WorkerChildReadinessState::READY) {
                $count++;
            }
        }

        return $count;
    }


    public function clear(): void
    {
        $this->entries = [];
    }

    private function entry(int $workerIndex): WorkerChildEntry
    {
        $entry = $this->entries[$workerIndex] ?? null;

        if (!$entry instanceof WorkerChildEntry) {
            throw new \InvalidArgumentException('worker-child-table-missing');
        }

        return $entry;
    }
}
