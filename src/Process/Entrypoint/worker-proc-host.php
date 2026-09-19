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

namespace Coretsia\Platform\Worker\Process\Entrypoint;

use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapClient;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostProtocol;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostTransport;

/**
 * Package-owned composition root for the ProcHost executable.
 *
 * @return callable(): int
 */
return static function (): int {
    try {
        $bootstrapClient = new WorkerProcessBootstrapClient(
            new WorkerProcessBootstrapProtocol(),
        );
        $bootstrapClient->receiveProcHost();
        $connection = $bootstrapClient->connect();

        $runtime = new WorkerProcProcessHostEntrypointRuntime(
            protocol: new WorkerProcProcessHostProtocol(),
            transport: new WorkerProcProcessHostTransport(),
            connection: $connection,
        );

        return $runtime->run();
    } catch (\Throwable) {
        return 1;
    }
};
