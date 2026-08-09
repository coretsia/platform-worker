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

namespace Coretsia\Platform\Worker\Communication;

/**
 * Closed vocabulary of operations accepted by the supervisor control channel.
 *
 * Pool startup is intentionally absent because `worker:start` owns foreground
 * supervisor creation directly.
 */
enum WorkerControlOperation: string
{
    case STATUS = 'status';
    case HEALTH = 'health';
    case STOP = 'stop';
}
