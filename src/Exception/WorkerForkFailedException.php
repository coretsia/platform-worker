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
 * Deterministic worker fork failure.
 *
 * This exception is used by the pcntl worker manager driver when process fork
 * cannot be completed.
 *
 * The public message contains only:
 *
 *     CORETSIA_WORKER_FORK_FAILED: worker-fork-failed
 *
 * It MUST NOT expose process internals, OS error text, command lines, absolute
 * paths, raw socket paths, raw TCP endpoints, previous throwable messages, or
 * environment-specific data.
 */
final class WorkerForkFailedException extends WorkerException
{
    public const string ERROR_CODE = 'CORETSIA_WORKER_FORK_FAILED';

    public const string REASON_FORK_FAILED = 'worker-fork-failed';

    /**
     * @var array<string, true>
     */
    private const array REASONS = [
        self::REASON_FORK_FAILED => true,
    ];

    private function __construct(string $reason)
    {
        if (!isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('worker-fork-failed-reason-invalid');
        }

        parent::__construct(self::ERROR_CODE, $reason);
    }

    public static function forkFailed(): self
    {
        return new self(self::REASON_FORK_FAILED);
    }
}
