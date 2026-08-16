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

namespace Coretsia\Platform\Worker\Process\Bootstrap;

/**
 * Redacted package-internal process-bootstrap failure.
 *
 * This is an internal bootstrap control-flow failure, not a package-level
 * WorkerException. It must be translated or contained at the owning process
 * boundary before reaching application-facing Worker error handling.
 *
 * @internal
 */
final class WorkerProcessBootstrapFailure extends \RuntimeException
{
    private function __construct()
    {
        parent::__construct('worker-process-bootstrap-failed');
    }

    public static function failed(): self
    {
        return new self();
    }
}
