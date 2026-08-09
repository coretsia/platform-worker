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

namespace Coretsia\Platform\Worker\Exception;

/**
 * Signals that another active or recovering worker generation owns
 * the canonical lifecycle fence.
 *
 * The failure is deterministic and exposes neither fence paths nor
 * process details.
 */
final class WorkerAlreadyRunningException extends WorkerException
{
    public const string ERROR_CODE = 'CORETSIA_WORKER_ALREADY_RUNNING';
    public const string REASON_ALREADY_RUNNING = 'worker-already-running';

    private function __construct()
    {
        parent::__construct(self::ERROR_CODE, self::REASON_ALREADY_RUNNING);
    }

    public static function alreadyRunning(): self
    {
        return new self();
    }
}
