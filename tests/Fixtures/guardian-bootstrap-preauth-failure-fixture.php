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

use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapClient;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostClient;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostProtocol;

if ($argc !== 2) {
    exit(64);
}

$markerPath = $argv[1];

if (
    !\is_string($markerPath)
    || $markerPath === ''
    || \trim($markerPath) !== $markerPath
    || \str_contains($markerPath, "\0")
) {
    exit(64);
}

$cwd = \getcwd();

if (!\is_string($cwd) || $cwd === '') {
    exit(65);
}

$autoload = $cwd . '/vendor/autoload.php';

if (!\is_file($autoload) || !\is_readable($autoload)) {
    exit(66);
}

require $autoload;

$processHost = null;

try {
    $bootstrapProtocol = new WorkerProcessBootstrapProtocol();
    $bootstrapClient = new WorkerProcessBootstrapClient(
        $bootstrapProtocol,
    );
    $launch = $bootstrapClient->receiveGuardian();

    if ($launch['driver'] !== 'proc') {
        exit(67);
    }

    $bootstrapTarget = new \ReflectionProperty(
        $bootstrapClient,
        'target',
    )->getValue($bootstrapClient);

    $guardianPid = \getmypid();

    if (
        !\is_array($bootstrapTarget)
        || !\is_int($bootstrapTarget['port'] ?? null)
        || $bootstrapTarget['port'] < 1
        || $bootstrapTarget['port'] > 65_535
        || !\is_int($guardianPid)
        || $guardianPid < 1
    ) {
        exit(68);
    }

    $supervisorBootstrapPort = $bootstrapTarget['port'];

    $processHost = new WorkerProcProcessHostClient(
        command: [
            \PHP_BINARY,
            __DIR__ . '/../../bin/coretsia-worker-proc-host',
        ],
        workingDirectory: $cwd,
        protocol: new WorkerProcProcessHostProtocol(),
        bootstrapLauncher: new WorkerProcessBootstrapLauncher(
            $bootstrapProtocol,
        ),
    );

    $processHost->start(
        $bootstrapClient->remainingMs(),
    );

    /*
     * Test-only observation of an already authenticated nested ProcHost.
     * No production getter or test hook is introduced.
     */
    $hostPid = new \ReflectionProperty(
        $processHost,
        'hostPid',
    )->getValue($processHost);

    if (
        !\is_int($hostPid)
        || $hostPid < 1
        || @\file_put_contents(
            $markerPath,
            $guardianPid
            . "\n"
            . $hostPid
            . "\n"
            . $supervisorBootstrapPort
            . "\n",
            \LOCK_EX,
        ) === false
    ) {
        exit(69);
    }

    /*
     * Deliberately never authenticate back to the Supervisor endpoint.
     * The parent launcher must time out and terminate this exact process.
     * Its death closes the authenticated Guardian→ProcHost owner channel.
     */
    while (true) {
        \usleep(100_000);
    }
} catch (\Throwable) {
    if ($processHost instanceof WorkerProcProcessHostClient) {
        try {
            $processHost->shutdown(
                5_000,
                allowForcedTermination: true,
            );
        } catch (\Throwable) {
        }
    }

    exit(1);
}
