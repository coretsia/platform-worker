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

use Coretsia\Platform\Worker\Manager\WorkerManager;

/**
 * Package-internal lazy WorkerManager resolution seam.
 *
 * @internal
 *
 * This interface is not public package API and is not a framework-level
 * worker manager factory port. It exists only as the package-local seam
 * between:
 *
 * - WorkerStartCommand;
 * - ContainerWorkerManagerResolver;
 * - package-local worker tests.
 *
 * Implementations MUST preserve lazy resolution. They MUST NOT resolve or
 * construct WorkerManager during resolver construction.
 *
 * Resolution failures and invalid manager bindings MUST be mapped to safe
 * deterministic worker-start failures. Diagnostics MUST NOT expose service
 * ids, config values, runtime paths, environment values, or nested throwable
 * messages.
 *
 * This interface MUST NOT be moved to `core/contracts`.
 * It MUST NOT be documented as a public extension point.
 * It MUST NOT be exported through Composer extra metadata.
 */
interface WorkerManagerResolverInterface
{
    /**
     * Resolves the active WorkerManager on demand.
     *
     * @throws \Coretsia\Platform\Worker\Exception\WorkerStartFailedException
     */
    public function resolve(): WorkerManager;
}
