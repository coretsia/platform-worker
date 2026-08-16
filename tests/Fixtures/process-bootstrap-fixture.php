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
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;

if ($argc !== 2) {
    exit(64);
}

$selector = $argv[1];
$role = match ($selector) {
    'guardian',
    'guardian-auth',
    'guardian-silent',
    'guardian-no-runtime-response' => 'guardian',

    'proc-host',
    'proc-host-auth',
    'proc-host-silent' => 'proc-host',

    default => null,
};
if ($role === null) {
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

try {
    $client = new WorkerProcessBootstrapClient(
        new WorkerProcessBootstrapProtocol(),
    );
    if ($role === 'guardian') {
        $client->receiveGuardian();
    } else {
        $client->receiveProcHost();
    }

    if (\str_ends_with($selector, '-silent')) {
        while (true) {
            \usleep(100_000);
        }
    }

    $connection = $client->connect();

    if ($selector === 'guardian-no-runtime-response') {
        /*
         * Bootstrap authentication succeeds, but no Guardian runtime response is
         * ever published. This fixture deliberately owns no lifecycle semantics.
         */
        while (!@\feof($connection)) {
            @\fread(
                $connection,
                256,
            );
            \usleep(10_000);
        }

        @\fclose($connection);
        exit(0);
    }

    // Keep the authentication frame isolated from the deterministic marker.
    \usleep(100_000);
    $marker = "process-bootstrap-fixture-ready\n";
    $remaining = $marker;
    while ($remaining !== '') {
        $written = @\fwrite($connection, $remaining);
        if (!\is_int($written) || $written < 1) {
            exit(67);
        }
        $remaining = \substr($remaining, $written);
    }
    @\fflush($connection);

    while (!@\feof($connection)) {
        @\fread($connection, 256);
        \usleep(10_000);
    }
    @\fclose($connection);
    exit(0);
} catch (\Throwable) {
    exit(1);
}
