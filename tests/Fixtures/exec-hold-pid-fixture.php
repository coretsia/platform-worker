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

$values = [];

foreach (\array_slice($argv, 1) as $argument) {
    if (!\is_string($argument) || !\str_starts_with($argument, '--')) {
        exit(64);
    }

    $separator = \strpos($argument, '=');

    if ($separator === false) {
        exit(64);
    }

    $key = \substr($argument, 2, $separator - 2);
    $value = \substr($argument, $separator + 1);

    if ($key === '' || \array_key_exists($key, $values)) {
        exit(64);
    }

    $values[$key] = $value;
}

$pidFile = $values['pid-file'] ?? null;
$releaseFile = $values['release-file'] ?? null;
$timeoutMs = $values['timeout-ms'] ?? null;

if (
    !\is_string($pidFile)
    || $pidFile === ''
    || \str_contains($pidFile, "\0")
    || !\is_string($releaseFile)
    || $releaseFile === ''
    || \str_contains($releaseFile, "\0")
    || !\is_string($timeoutMs)
    || !\ctype_digit($timeoutMs)
    || (int)$timeoutMs < 1
    || (int)$timeoutMs > 10_000
) {
    exit(65);
}

$directory = \dirname($pidFile);

if (
    !\is_dir($directory)
    && !@\mkdir($directory, 0777, true)
    && !\is_dir($directory)
) {
    exit(66);
}

if (
    @\file_put_contents(
        $pidFile,
        (string)\getmypid() . "\n",
        \LOCK_EX,
    ) === false
) {
    exit(66);
}

$deadlineNs = \hrtime(true) + ((int)$timeoutMs * 1_000_000);

while (!@\file_exists($releaseFile) && \hrtime(true) < $deadlineNs) {
    \usleep(10_000);
}

exit(@\file_exists($releaseFile) ? 0 : 70);
