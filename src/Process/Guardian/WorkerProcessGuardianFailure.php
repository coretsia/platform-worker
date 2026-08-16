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

/**
 * Package-internal Guardian protocol/runtime failure.
 *
 * The safe reason belongs to the private Guardian wire protocol. This failure
 * is not part of the package-level WorkerException taxonomy.
 *
 * @internal
 */
final class WorkerProcessGuardianFailure extends \RuntimeException
{
    public function __construct(private readonly string $safeReason)
    {
        parent::__construct(self::class);
    }

    public function safeReason(): string
    {
        return $this->safeReason;
    }
}
