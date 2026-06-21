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
 * Deterministic worker start failure.
 *
 * This exception is used when `platform/worker` cannot start the worker pool,
 * cannot accept worker state required for start, or cannot satisfy the HTTP
 * task-mode request-handler requirement after runtime-driver compatibility has
 * already passed.
 *
 * The public message contains only:
 *
 *     CORETSIA_WORKER_START_FAILED: reason_token
 *
 * It MUST NOT expose raw config values, absolute paths, raw socket paths, raw
 * TCP endpoints, request payloads, headers, tokens, process command lines,
 * previous throwable messages, container exception messages, service ids,
 * stack traces, or environment-specific data.
 */
final class WorkerStartFailedException extends WorkerException
{
    public const string ERROR_CODE = 'CORETSIA_WORKER_START_FAILED';

    public const string REASON_START_FAILED = 'start_failed';
    public const string REASON_INVALID_STATE = 'invalid_state';
    public const string REASON_REQUEST_HANDLER_MISSING = 'request_handler_missing';
    public const string REASON_REQUEST_HANDLER_UNRESOLVABLE = 'request_handler_unresolvable';
    public const string REASON_REQUEST_HANDLER_INVALID = 'request_handler_invalid';

    /**
     * @var array<string, true>
     */
    private const array REASONS = [
        self::REASON_START_FAILED => true,
        self::REASON_INVALID_STATE => true,
        self::REASON_REQUEST_HANDLER_MISSING => true,
        self::REASON_REQUEST_HANDLER_UNRESOLVABLE => true,
        self::REASON_REQUEST_HANDLER_INVALID => true,
    ];

    private function __construct(string $reason)
    {
        if (!isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('worker-start-failed-reason-invalid');
        }

        parent::__construct(self::ERROR_CODE, $reason);
    }

    public static function startFailed(): self
    {
        return new self(self::REASON_START_FAILED);
    }

    public static function invalidState(): self
    {
        return new self(self::REASON_INVALID_STATE);
    }

    public static function requestHandlerMissing(): self
    {
        return new self(self::REASON_REQUEST_HANDLER_MISSING);
    }

    public static function requestHandlerUnresolvable(): self
    {
        return new self(self::REASON_REQUEST_HANDLER_UNRESOLVABLE);
    }

    public static function requestHandlerInvalid(): self
    {
        return new self(self::REASON_REQUEST_HANDLER_INVALID);
    }
}
