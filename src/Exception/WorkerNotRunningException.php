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
 * Deterministic worker not-running lifecycle condition.
 *
 * This exception is used when a stop/status operation targets a worker pool
 * that has no readable running-state marker because the pool is not currently
 * running.
 *
 * The public message contains only:
 *
 *     CORETSIA_WORKER_NOT_RUNNING: worker-not-running
 *
 * It MUST NOT expose pid paths, state paths, socket paths, TCP endpoints,
 * absolute paths, payload fragments, headers, tokens, OS error text, previous
 * throwable messages, or environment-specific data.
 */
final class WorkerNotRunningException extends WorkerException
{
    public const string ERROR_CODE = 'CORETSIA_WORKER_NOT_RUNNING';

    public const string REASON_NOT_RUNNING = 'worker-not-running';

    /**
     * @var array<string, true>
     */
    private const array REASONS = [
        self::REASON_NOT_RUNNING => true,
    ];

    private function __construct(string $reason)
    {
        if (!isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('worker-not-running-reason-invalid');
        }

        parent::__construct(self::ERROR_CODE, $reason);
    }

    public static function notRunning(): self
    {
        return new self(self::REASON_NOT_RUNNING);
    }
}
