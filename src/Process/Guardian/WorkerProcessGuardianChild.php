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

/** @internal */
final readonly class WorkerProcessGuardianChild
{
    public function __construct(
        private string $id,
        private int $pid,
    ) {
        if (\preg_match('/\Achild-[1-9][0-9]*\z/', $id) !== 1 || $pid < 1) {
            throw new \InvalidArgumentException('worker-process-guardian-child-invalid');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function pid(): int
    {
        return $this->pid;
    }
}
