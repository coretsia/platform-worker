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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostClient;
use Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostProtocol;

if ($argc !== 3) {
    exit(2);
}

$frameworkRoot = $argv[1];
$runtimeRoot = $argv[2];
$autoload = $frameworkRoot . '/vendor/autoload.php';

if (!\is_file($autoload) || !\is_readable($autoload)) {
    exit(3);
}

require $autoload;

if (!\is_dir($runtimeRoot) && !@\mkdir($runtimeRoot, 0777, true) && !\is_dir($runtimeRoot)) {
    exit(4);
}

$client = new WorkerProcProcessHostClient(
    command: [\PHP_BINARY, __DIR__ . '/../../bin/coretsia-worker-proc-host'],
    workingDirectory: $frameworkRoot,
    protocol: new WorkerProcProcessHostProtocol(
        new StableJsonEncoder(),
        new StableJsonDecoder(),
    ),
);

try {
    $client->start(5_000);
    $child = $client->spawn(
        command: [\PHP_BINARY, '-r', 'while (true) { usleep(100000); }'],
        workingDirectory: $runtimeRoot,
        timeoutMs: 3_000,
    );

    $ready = \json_encode(
        ['child_pid' => $child->pid(), 'owner_pid' => \getmypid()],
        \JSON_THROW_ON_ERROR,
    );

    if (@\file_put_contents($runtimeRoot . '/proc-host-owner.ready', $ready . "\n", \LOCK_EX) === false) {
        exit(5);
    }

    while (true) {
        \usleep(100_000);
    }
} catch (\Throwable) {
    exit(6);
}
