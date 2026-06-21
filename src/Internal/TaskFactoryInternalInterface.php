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

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Package-internal worker task factory seam.
 *
 * @internal
 *
 * This interface is not public package API and is not a framework-level
 * task-source port. It exists only as the package-local seam between:
 *
 * - ApplicationWorker
 * - HttpTaskFactory
 * - QueueTaskFactory
 * - package-local worker tests
 *
 * It MUST NOT be moved to `core/contracts`.
 * It MUST NOT be documented as a public extension point.
 * It MUST NOT be exported through composer extra metadata.
 *
 * This epic does not introduce external worker task providers. Real HTTP task
 * sources remain owned by later platform/http or runtime-adapter epics. Real
 * queue task sources remain owned by later integration epics.
 *
 * Implementations must produce only package-internal task work consumed by
 * ApplicationWorker. Task work must expose a deterministic safe operation id
 * for observability and must not expose raw payloads, raw socket paths, raw TCP
 * endpoints, headers, tokens, config values, or transport internals.
 *
 * Implementations must not read from platform/cli, depend on platform/http, or
 * write to stdout/stderr directly.
 *
 * @phpstan-type WorkerTaskType self::TASK_TYPE_QUEUE|self::TASK_TYPE_HTTP
 * @phpstan-type WorkerOperationId self::TASK_TYPE_QUEUE|self::TASK_TYPE_HTTP
 * @phpstan-type WorkerTaskWork array{
 *     operation_id: WorkerOperationId,
 *     run: \Closure(): mixed
 * }
 */
interface TaskFactoryInternalInterface
{
    public const string TASK_TYPE_QUEUE = 'queue';
    public const string TASK_TYPE_HTTP = 'http';

    /**
     * Returns the worker task type owned by this package-internal factory.
     *
     * @return WorkerTaskType
     */
    public function taskType(): string;

    /**
     * Checks whether this factory supports the normalized worker pool
     * specification.
     *
     * Implementations should make this a deterministic check against
     * WorkerPoolSpec::taskType() and local package capabilities only.
     */
    public function supports(WorkerPoolSpec $spec): bool;

    /**
     * Returns the deterministic safe operation id used for observability label
     * `operation`.
     *
     * The returned value must be a stable low-cardinality token. It must not
     * include raw payload data, socket paths, TCP endpoints, headers, tokens,
     * config values, queue names, URLs, request paths, or adapter internals.
     *
     * @return WorkerOperationId
     *
     * @throws WorkerStartFailedException
     */
    public function operationId(WorkerPoolSpec $spec): string;

    /**
     * Creates package-internal task work consumed by ApplicationWorker.
     *
     * The returned task work is not a public extension shape. It is internal to
     * platform/worker and must not be documented as a userland task-provider
     * contract.
     *
     * Unsupported task types or invalid task setup must fail deterministically
     * with a worker exception. HTTP task mode may use the narrower
     * WorkerStartFailedException::requestHandlerMissing() when the request
     * handler requirement is not satisfied after runtime-driver compatibility
     * has already passed.
     *
     * @return WorkerTaskWork
     *
     * @throws WorkerStartFailedException
     */
    public function create(WorkerPoolSpec $spec): array;
}
