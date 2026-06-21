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
 * Abstract deterministic Worker runtime failure.
 *
 * This is the base class for package-level `platform/worker` failures.
 *
 * Public exception messages are intentionally stable and safe. They contain
 * only the package error code and a stable reason token:
 *
 *     CORETSIA_WORKER_*: reason_token
 *
 * Messages MUST NOT include absolute paths, raw socket paths, raw TCP
 * endpoints, raw config values, task payloads, headers, tokens, stack traces,
 * previous throwable messages, process command lines, or environment-specific
 * data.
 *
 * Prefer throwing a narrower concrete worker exception when one applies.
 */
abstract class WorkerException extends \RuntimeException
{
    private const string ERROR_CODE_PATTERN = '/\ACORETSIA_WORKER_[A-Z0-9_]+\z/';
    private const string REASON_PATTERN = '/\A[a-z][a-z0-9_]*\z/';

    protected function __construct(
        private readonly string $workerErrorCode,
        private readonly string $workerReason,
    ) {
        if ($workerErrorCode === '') {
            throw new \InvalidArgumentException('worker-error-code-empty');
        }

        if (\preg_match(self::ERROR_CODE_PATTERN, $workerErrorCode) !== 1) {
            throw new \InvalidArgumentException('worker-error-code-invalid');
        }

        if ($workerReason === '') {
            throw new \InvalidArgumentException('worker-reason-empty');
        }

        if (\preg_match(self::REASON_PATTERN, $workerReason) !== 1) {
            throw new \InvalidArgumentException('worker-reason-invalid');
        }

        parent::__construct(self::message($this->workerErrorCode, $this->workerReason), 0);
    }

    public function errorCode(): string
    {
        return $this->workerErrorCode;
    }

    public function reason(): string
    {
        return $this->workerReason;
    }

    private static function message(string $errorCode, string $reason): string
    {
        return $errorCode . ': ' . $reason;
    }
}
