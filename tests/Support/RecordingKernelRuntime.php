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

use Coretsia\Contracts\Runtime\KernelRuntimeInterface;
use Coretsia\Contracts\Runtime\UnitOfWorkHandle;

class RecordingKernelRuntime implements KernelRuntimeInterface
{
    /** @var list<string> */
    public array $types = [];
    public int $calls = 0;
    public bool $throwAfterBody = false;

    public function runUnitOfWork(string $type, callable $body, array $attributes = []): mixed
    {
        $this->types[] = $type;
        $this->calls++;
        $result = $body();
        if ($this->throwAfterBody) {
            throw new \RuntimeException('kernel-after-failure');
        }
        return $result;
    }

    public function beginUnitOfWork(string $type, array $attributes = []): UnitOfWorkHandle
    {
        throw new \LogicException('not-used');
    }

    public function afterUnitOfWork(
        UnitOfWorkHandle $handle,
        string $outcome,
        ?\Throwable $error = null,
        array $extensions = []
    ): array {
        throw new \LogicException('not-used');
    }
}
