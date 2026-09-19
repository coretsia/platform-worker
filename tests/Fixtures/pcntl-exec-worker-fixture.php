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
    if (!\is_string($argument) || !\str_starts_with($argument, '--coretsia-worker-')) {
        exit(64);
    }

    $separator = \strpos($argument, '=');

    if ($separator === false) {
        exit(64);
    }

    $values[\substr($argument, 2, $separator - 2)] = \substr($argument, $separator + 1);
}

$port = $values['coretsia-worker-readiness-port'] ?? null;
$token = $values['coretsia-worker-readiness-token'] ?? null;
$artifactRoot = $values['coretsia-worker-artifact-root'] ?? null;

if (
    !\is_string($port)
    || !\ctype_digit($port)
    || (int)$port < 1
    || (int)$port > 65535
    || !\is_string($token)
    || \preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
    || !\is_string($artifactRoot)
    || $artifactRoot === ''
    || \str_contains($artifactRoot, '..')
    || \str_contains($artifactRoot, '\\')
) {
    exit(65);
}

$failBeforeReadiness = $artifactRoot === 'var/fail-before-readiness';

$cwd = \getcwd();

if (!\is_string($cwd)) {
    exit(66);
}

if (!$failBeforeReadiness) {
    $markerDirectory = $cwd . '/' . $artifactRoot;

    if (
        !\is_dir($markerDirectory)
        && !@\mkdir($markerDirectory, 0777, true)
        && !\is_dir($markerDirectory)
    ) {
        exit(67);
    }

    $marker = \json_encode(
        [
            'fresh_process_image' => !\class_exists('PHPUnit\\Framework\\TestCase', false)
                && !\class_exists(
                    'Coretsia\\Platform\\Worker\\Tests\\Support\\PackageTestCase',
                    false,
                ),
            'pid' => \getmypid(),
        ],
        \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
    );

    if (!\is_string($marker)) {
        exit(68);
    }

    if (@\file_put_contents($markerDirectory . '/pcntl-exec-marker.json', $marker) === false) {
        exit(69);
    }
}

$stream = @\stream_socket_client(
    'tcp://127.0.0.1:' . (int)$port,
    $errorCode,
    $errorMessage,
    1.0,
    \STREAM_CLIENT_CONNECT,
);

if (!\is_resource($stream)) {
    exit(70);
}

if ($failBeforeReadiness) {
    @\fclose($stream);
    exit(1);
}

$remaining = 'ready:' . $token . "\n";

while ($remaining !== '') {
    $written = @\fwrite($stream, $remaining);

    if (!\is_int($written) || $written < 1) {
        @\fclose($stream);
        exit(71);
    }

    $remaining = \substr($remaining, $written);
}

if (!@\fflush($stream)) {
    @\fclose($stream);
    exit(72);
}

@\fclose($stream);
exit(0);
