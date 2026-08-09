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

if ($argc < 3) {
    exit(64);
}

$port = null;
$token = null;
$exitCode = 0;
$runMs = 50;

foreach ($argv as $argument) {
    if (\str_starts_with($argument, '--coretsia-worker-readiness-port=')) {
        $port = (int)\substr(
            $argument,
            \strlen('--coretsia-worker-readiness-port='),
        );
    }

    if (\str_starts_with($argument, '--coretsia-worker-readiness-token=')) {
        $token = \substr(
            $argument,
            \strlen('--coretsia-worker-readiness-token='),
        );
    }

    if (\str_starts_with($argument, '--fixture-exit-code=')) {
        $exitCode = (int)\substr(
            $argument,
            \strlen('--fixture-exit-code='),
        );
    }

    if (\str_starts_with($argument, '--fixture-run-ms=')) {
        $runMs = (int)\substr(
            $argument,
            \strlen('--fixture-run-ms='),
        );
    }
}

if (
    !\is_int($port)
    || $port < 1
    || $port > 65535
    || !\is_string($token)
    || \preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
) {
    exit(65);
}

$stream = @\stream_socket_client(
    'tcp://127.0.0.1:' . $port,
    $errorCode,
    $errorMessage,
    1.0,
);

if (!\is_resource($stream)) {
    exit(66);
}

\fwrite($stream, 'ready:' . $token . "\n");
\fflush($stream);
\fclose($stream);

if ($runMs > 0) {
    \usleep($runMs * 1000);
}

exit($exitCode);
