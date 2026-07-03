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
 * Deterministic worker control-channel communication failure.
 *
 * This exception is used when worker control communication fails, including
 * socket/control transport failures.
 *
 * The public message contains only:
 *
 *     CORETSIA_WORKER_COMMUNICATION_FAILED: worker-communication-failed
 *
 * It MUST NOT expose raw socket paths, raw TCP host/port, absolute paths,
 * payload fragments, headers, tokens, OS error text, previous throwable
 * messages, or environment-specific data.
 */
final class WorkerCommunicationFailedException extends WorkerException
{
    public const string ERROR_CODE = 'CORETSIA_WORKER_COMMUNICATION_FAILED';

    public const string REASON_COMMUNICATION_FAILED = 'worker-communication-failed';

    /**
     * @var array<string, true>
     */
    private const array REASONS = [
        self::REASON_COMMUNICATION_FAILED => true,
    ];

    private function __construct(string $reason)
    {
        if (!isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('worker-communication-failed-reason-invalid');
        }

        parent::__construct(self::ERROR_CODE, $reason);
    }

    public static function communicationFailed(): self
    {
        return new self(self::REASON_COMMUNICATION_FAILED);
    }
}
