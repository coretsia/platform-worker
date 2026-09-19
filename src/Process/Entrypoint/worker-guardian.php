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
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianRuntime;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostClient;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostProtocol;

/**
 * Package-owned composition root for the Guardian executable.
 *
 * @return callable(string, string): int
 */
return static function (
    string $workingDirectory,
    string $procHostExecutable,
): int {
    $processHost = null;

    try {
        $bootstrapProtocol = new WorkerProcessBootstrapProtocol();
        $bootstrapClient = new WorkerProcessBootstrapClient($bootstrapProtocol);
        $launch = $bootstrapClient->receiveGuardian();
        $bootstrapLauncher = new WorkerProcessBootstrapLauncher($bootstrapProtocol);

        /*
         * PROC topology requirement: bootstrap stdin is already closed before the
         * process host starts, and ProcHost authentication completes before the
         * Guardian establishes its Supervisor connection.
         */
        if ($launch['driver'] === 'proc') {
            $processHost = new WorkerProcProcessHostClient(
                command: [\PHP_BINARY, $procHostExecutable],
                workingDirectory: $workingDirectory,
                protocol: new WorkerProcProcessHostProtocol(),
                bootstrapLauncher: $bootstrapLauncher,
            );
            $processHost->start($bootstrapClient->remainingMs());
        }

        $connection = $bootstrapClient->connect();
        $runtime = new WorkerProcessGuardianRuntime(
            driverName: $launch['driver'],
            protocol: new WorkerProcessGuardianProtocol(),
            connection: $connection,
            processHost: $processHost,
        );
    } catch (\Throwable) {
        if ($processHost instanceof WorkerProcProcessHostClient) {
            try {
                $processHost->shutdown(5_000, allowForcedTermination: true);
            } catch (\Throwable) {
            }
        }

        return 1;
    }

    try {
        return $runtime->run();
    } catch (\Throwable) {
        /*
         * WorkerProcessGuardianRuntime owns terminal generation cleanup once
         * runtime execution begins. Do not force-terminate ProcHost here.
         */
        return 1;
    }
};
