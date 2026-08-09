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
 * Deterministic worker lifecycle failure.
 *
 * This exception covers invalid lifecycle state, task-source runtime failures,
 * unexpected child exits, shutdown, runtime cleanup, lifecycle-lock,
 * lifecycle-locator, process-guardian, and proc-host failures that are not
 * owned exclusively by worker startup.
 *
 * The public message contains only:
 *
 *     CORETSIA_WORKER_LIFECYCLE_FAILED: worker-reason-token
 *
 * It MUST NOT expose raw config values, absolute paths, raw socket paths, raw
 * TCP endpoints, task payloads, headers, tokens, process command lines,
 * previous throwable messages, container exception messages, service ids,
 * adapter class names, stack traces, or environment-specific data.
 */
final class WorkerLifecycleFailedException extends WorkerException
{
    public const string ERROR_CODE = 'CORETSIA_WORKER_LIFECYCLE_FAILED';

    public const string REASON_LIFECYCLE_FAILED = 'worker-lifecycle-failed';
    public const string REASON_INVALID_STATE = 'worker-invalid-state';
    public const string REASON_TASK_SOURCE_TERMINATED = 'worker-task-source-terminated';
    public const string REASON_TASK_SOURCE_RECEIVE_FAILED = 'worker-task-source-receive-failed';
    public const string REASON_TASK_SETTLEMENT_FAILED = 'worker-task-settlement-failed';
    public const string REASON_CHILD_EXITED = 'worker-child-exited';
    public const string REASON_SHUTDOWN_FAILED = 'worker-shutdown-failed';
    public const string REASON_RUNTIME_CLEANUP_FAILED = 'worker-runtime-cleanup-failed';
    public const string REASON_LIFECYCLE_LOCK_FAILED = 'worker-lifecycle-lock-failed';
    public const string REASON_LIFECYCLE_LOCATOR_FAILED = 'worker-lifecycle-locator-failed';
    public const string REASON_PROCESS_HOST_FAILED = 'worker-process-host-failed';
    public const string REASON_PROCESS_GUARDIAN_FAILED = 'worker-process-guardian-failed';

    private const array REASONS = [
        self::REASON_LIFECYCLE_FAILED => true,
        self::REASON_INVALID_STATE => true,
        self::REASON_TASK_SOURCE_TERMINATED => true,
        self::REASON_TASK_SOURCE_RECEIVE_FAILED => true,
        self::REASON_TASK_SETTLEMENT_FAILED => true,
        self::REASON_CHILD_EXITED => true,
        self::REASON_SHUTDOWN_FAILED => true,
        self::REASON_RUNTIME_CLEANUP_FAILED => true,
        self::REASON_LIFECYCLE_LOCK_FAILED => true,
        self::REASON_LIFECYCLE_LOCATOR_FAILED => true,
        self::REASON_PROCESS_HOST_FAILED => true,
        self::REASON_PROCESS_GUARDIAN_FAILED => true,
    ];

    private function __construct(string $reason)
    {
        if (!isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('worker-lifecycle-failed-reason-invalid');
        }

        parent::__construct(self::ERROR_CODE, $reason);
    }

    public static function lifecycleFailed(): self
    {
        return new self(self::REASON_LIFECYCLE_FAILED);
    }

    public static function invalidState(): self
    {
        return new self(self::REASON_INVALID_STATE);
    }

    public static function taskSourceTerminated(): self
    {
        return new self(self::REASON_TASK_SOURCE_TERMINATED);
    }

    public static function taskSourceReceiveFailed(): self
    {
        return new self(self::REASON_TASK_SOURCE_RECEIVE_FAILED);
    }

    public static function taskSettlementFailed(): self
    {
        return new self(self::REASON_TASK_SETTLEMENT_FAILED);
    }

    public static function childExited(): self
    {
        return new self(self::REASON_CHILD_EXITED);
    }

    public static function shutdownFailed(): self
    {
        return new self(self::REASON_SHUTDOWN_FAILED);
    }

    public static function runtimeCleanupFailed(): self
    {
        return new self(self::REASON_RUNTIME_CLEANUP_FAILED);
    }

    public static function lifecycleLockFailed(): self
    {
        return new self(self::REASON_LIFECYCLE_LOCK_FAILED);
    }

    public static function lifecycleLocatorFailed(): self
    {
        return new self(self::REASON_LIFECYCLE_LOCATOR_FAILED);
    }

    public static function processHostFailed(): self
    {
        return new self(self::REASON_PROCESS_HOST_FAILED);
    }

    public static function processGuardianFailed(): self
    {
        return new self(self::REASON_PROCESS_GUARDIAN_FAILED);
    }
}
