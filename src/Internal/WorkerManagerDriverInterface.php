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

namespace Coretsia\Platform\Worker\Internal;

use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerForkFailedException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

/**
 * Package-internal process driver strategy seam.
 *
 * @internal
 *
 * This interface is not public package API and is not a framework-level port.
 * It exists only as the internal seam between:
 *
 * - WorkerManager
 * - PcntlWorkerManagerDriver
 * - ProcWorkerManagerDriver
 * - package-local worker tests
 *
 * It MUST NOT be moved to `core/contracts`.
 * It MUST NOT be documented as a public extension point.
 * It MUST NOT be exported through composer extra metadata.
 *
 * Implementations own only process-driver lifecycle behavior. They must not
 * contain task execution logic, call KernelRuntimeInterface, know about CLI
 * command dispatch, depend on platform/cli, or depend on platform/http.
 *
 * Implementations must not write to stdout/stderr directly and must not log
 * payloads, raw socket paths, raw TCP endpoints, absolute paths, headers, or
 * tokens.
 *
 * Lifecycle failures must be surfaced only through deterministic worker
 * exceptions.
 *
 * @phpstan-type WorkerManagerDriverName self::DRIVER_PCNTL|self::DRIVER_PROC
 */
interface WorkerManagerDriverInterface
{
    public const string DRIVER_PCNTL = 'pcntl';
    public const string DRIVER_PROC = 'proc';

    /**
     * Returns the deterministic process driver identity.
     *
     * @return self::DRIVER_PCNTL|self::DRIVER_PROC
     */
    public function name(): string;

    /**
     * Checks whether this process driver can support the already-normalized
     * worker pool specification.
     *
     * This method must be deterministic for the same spec and runtime
     * capability inputs owned by the concrete driver.
     */
    public function supports(WorkerPoolSpec $spec): bool;

    /**
     * Starts the worker pool.
     *
     * @throws WorkerStartFailedException
     * @throws WorkerForkFailedException
     * @throws WorkerCommunicationFailedException
     */
    public function start(WorkerPoolSpec $spec): WorkerPoolState;

    /**
     * Stops the worker pool.
     *
     * @throws WorkerStartFailedException
     * @throws WorkerCommunicationFailedException
     */
    public function stop(WorkerPoolSpec $spec): WorkerPoolState;

    /**
     * Returns the current worker pool status.
     *
     * @throws WorkerStartFailedException
     * @throws WorkerCommunicationFailedException
     */
    public function status(WorkerPoolSpec $spec): WorkerPoolState;
}
