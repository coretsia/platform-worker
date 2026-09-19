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
 * Deterministic worker startup failure.
 *
 * WorkerStartFailedException represents Worker-owned startup failures,
 * including Worker startup preconditions validated before Kernel
 * runtime-driver matrix resolution.
 *
 * It also covers task-source resolution/readiness, child-process creation,
 * readiness, and signal-bootstrap failures owned by the Worker package.
 *
 * The public message contains only:
 *
 *     CORETSIA_WORKER_START_FAILED: worker-reason-token
 *
 * It MUST NOT expose raw config values, absolute paths, raw socket paths, raw
 * TCP endpoints, task payloads, headers, tokens, process command lines,
 * previous throwable messages, container exception messages, service ids,
 * adapter class names, stack traces, or environment-specific data.
 */
final class WorkerStartFailedException extends WorkerException
{
    public const string ERROR_CODE = 'CORETSIA_WORKER_START_FAILED';

    public const string REASON_START_FAILED = 'worker-start-failed';
    public const string REASON_MODULE_NOT_ENABLED = 'worker-module-not-enabled';
    public const string REASON_TASK_SOURCE_MISSING = 'worker-task-source-missing';
    public const string REASON_TASK_SOURCE_AMBIGUOUS = 'worker-task-source-ambiguous';
    public const string REASON_TASK_SOURCE_INVALID = 'worker-task-source-invalid';
    public const string REASON_TASK_SOURCE_UNRESOLVABLE = 'worker-task-source-unresolvable';
    public const string REASON_TASK_SOURCE_NOT_READY = 'worker-task-source-not-ready';
    public const string REASON_READINESS_TIMEOUT = 'worker-readiness-timeout';
    public const string REASON_READINESS_INVALID = 'worker-readiness-invalid';
    public const string REASON_CHILD_START_FAILED = 'worker-child-start-failed';
    public const string REASON_SIGNAL_HANDLING_UNAVAILABLE = 'worker-signal-handling-unavailable';

    private const array REASONS = [
        self::REASON_START_FAILED => true,
        self::REASON_MODULE_NOT_ENABLED => true,
        self::REASON_TASK_SOURCE_MISSING => true,
        self::REASON_TASK_SOURCE_AMBIGUOUS => true,
        self::REASON_TASK_SOURCE_INVALID => true,
        self::REASON_TASK_SOURCE_UNRESOLVABLE => true,
        self::REASON_TASK_SOURCE_NOT_READY => true,
        self::REASON_READINESS_TIMEOUT => true,
        self::REASON_READINESS_INVALID => true,
        self::REASON_CHILD_START_FAILED => true,
        self::REASON_SIGNAL_HANDLING_UNAVAILABLE => true,
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

    public static function moduleNotEnabled(): self
    {
        return new self(self::REASON_MODULE_NOT_ENABLED);
    }

    public static function taskSourceMissing(): self
    {
        return new self(self::REASON_TASK_SOURCE_MISSING);
    }

    public static function taskSourceAmbiguous(): self
    {
        return new self(self::REASON_TASK_SOURCE_AMBIGUOUS);
    }

    public static function taskSourceInvalid(): self
    {
        return new self(self::REASON_TASK_SOURCE_INVALID);
    }

    public static function taskSourceUnresolvable(): self
    {
        return new self(self::REASON_TASK_SOURCE_UNRESOLVABLE);
    }

    public static function taskSourceNotReady(): self
    {
        return new self(self::REASON_TASK_SOURCE_NOT_READY);
    }

    public static function readinessTimeout(): self
    {
        return new self(self::REASON_READINESS_TIMEOUT);
    }

    public static function readinessInvalid(): self
    {
        return new self(self::REASON_READINESS_INVALID);
    }

    public static function childStartFailed(): self
    {
        return new self(self::REASON_CHILD_START_FAILED);
    }

    public static function signalHandlingUnavailable(): self
    {
        return new self(self::REASON_SIGNAL_HANDLING_UNAVAILABLE);
    }
}
