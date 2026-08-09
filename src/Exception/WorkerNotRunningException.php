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
 * The generation fence is currently free, so no active or recovering Coretsia
 * worker generation is owned by a guardian.
 */
final class WorkerNotRunningException extends WorkerException
{
    public const string ERROR_CODE = 'CORETSIA_WORKER_NOT_RUNNING';
    public const string REASON_NOT_RUNNING = 'worker-not-running';

    private function __construct()
    {
        parent::__construct(self::ERROR_CODE, self::REASON_NOT_RUNNING);
    }

    public static function notRunning(): self
    {
        return new self();
    }
}
