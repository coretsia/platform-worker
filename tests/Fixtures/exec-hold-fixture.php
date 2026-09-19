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

$readyFile = $values['ready-file'] ?? null;
$releaseFile = $values['release-file'] ?? null;
$timeoutMs = $values['timeout-ms'] ?? null;
$port = $values['coretsia-worker-readiness-port'] ?? null;
$token = $values['coretsia-worker-readiness-token'] ?? null;

if (
    !\is_string($readyFile)
    || $readyFile === ''
    || \str_contains($readyFile, "\0")
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

if (($port === null) !== ($token === null)) {
    exit(66);
}

if ($port !== null) {
    if (
        !\is_string($port)
        || !\ctype_digit($port)
        || (int)$port < 1
        || (int)$port > 65535
        || !\is_string($token)
        || \preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
    ) {
        exit(66);
    }

    $stream = @\stream_socket_client(
        'tcp://127.0.0.1:' . (int)$port,
        $errorCode,
        $errorMessage,
        1.0,
        \STREAM_CLIENT_CONNECT,
    );

    if (!\is_resource($stream) || !@\stream_set_timeout($stream, 1, 0)) {
        if (\is_resource($stream)) {
            @\fclose($stream);
        }

        exit(67);
    }

    $remaining = 'ready:' . $token . "\n";

    while ($remaining !== '') {
        $written = @\fwrite($stream, $remaining);

        if (!\is_int($written) || $written < 1) {
            @\fclose($stream);
            exit(68);
        }

        $remaining = \substr($remaining, $written);
    }

    if (!@\fflush($stream)) {
        @\fclose($stream);
        exit(68);
    }

    @\fclose($stream);
}

$readyDirectory = \dirname($readyFile);

if (
    !\is_dir($readyDirectory)
    && !@\mkdir($readyDirectory, 0777, true)
    && !\is_dir($readyDirectory)
) {
    exit(69);
}

if (@\file_put_contents($readyFile, "ready\n", \LOCK_EX) === false) {
    exit(69);
}

$deadline = \hrtime(true) + ((int)$timeoutMs * 1_000_000);

while (!@\file_exists($releaseFile) && \hrtime(true) < $deadline) {
    \usleep(10_000);
}

exit(@\file_exists($releaseFile) ? 0 : 70);
