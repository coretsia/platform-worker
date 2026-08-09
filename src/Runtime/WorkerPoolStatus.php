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

namespace Coretsia\Platform\Worker\Runtime;

/**
 * Persisted and live supervisor lifecycle states.
 *
 * `stopped` is intentionally not persisted; it exists only as a terminal
 * control-response status after runtime artifacts have been removed.
 */
enum WorkerPoolStatus: string
{
    case STARTING = 'starting';
    case RUNNING = 'running';
    case STOPPING = 'stopping';
}
