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

namespace Coretsia\Platform\Worker\Process;

use Coretsia\Platform\Worker\Communication\WorkerChildReadinessEndpoint;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Builds the exact argv vector for the package-owned worker child launcher.
 *
 * This value builder owns only deterministic argument construction. It does
 * not read configuration, open readiness endpoints, start processes, resolve
 * runtime services, or expose launch arguments through diagnostics.
 */
final readonly class WorkerChildCommandBuilder
{
    public function __construct(
        private string $artifactRoot,
    ) {
        if (!self::isRelativeSafePath($artifactRoot)) {
            throw new \InvalidArgumentException('worker-child-command-builder-invalid');
        }
    }

    /**
     * Builds one shell-free child command vector in canonical argument order.
     *
     * @param non-empty-list<non-empty-string> $baseCommand
     *
     * @return non-empty-list<non-empty-string>
     */
    public function build(
        array $baseCommand,
        WorkerPoolSpec $spec,
        int $workerIndex,
        WorkerChildReadinessEndpoint $readinessEndpoint,
    ): array {
        if (
            $baseCommand === []
            || !\array_is_list($baseCommand)
            || $workerIndex < 0
            || $workerIndex >= $spec->workers()
            || $readinessEndpoint->mode() !== WorkerChildReadinessEndpoint::MODE_TCP_LISTENER
            || !\in_array(
                $spec->driver(),
                [
                    WorkerProcessDriverInterface::DRIVER_PCNTL,
                    WorkerProcessDriverInterface::DRIVER_PROC,
                ],
                true,
            )
        ) {
            throw new \InvalidArgumentException('worker-child-command-builder-invalid');
        }

        foreach ($baseCommand as $part) {
            if (
                !\is_string($part)
                || $part === ''
                || \trim($part) !== $part
                || \preg_match('/[\x00-\x1F\x7F]/', $part) === 1
            ) {
                throw new \InvalidArgumentException('worker-child-command-builder-invalid');
            }
        }

        return [
            ...$baseCommand,
            '--coretsia-worker-index=' . $workerIndex,
            '--coretsia-worker-count=' . $spec->workers(),
            '--coretsia-worker-max-requests=' . $spec->maxRequests(),
            '--coretsia-worker-task-type=' . $spec->taskType(),
            '--coretsia-worker-driver=' . $spec->driver(),
            '--coretsia-worker-artifact-root=' . $this->artifactRoot,
            '--coretsia-worker-readiness-port=' . $readinessEndpoint->port(),
            '--coretsia-worker-readiness-token=' . $readinessEndpoint->token(),
        ];
    }

    private static function isRelativeSafePath(string $path): bool
    {
        if (
            $path === ''
            || \trim($path) !== $path
            || \preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || \preg_match('/\s/u', $path) !== 0
            || \str_starts_with($path, '/')
            || \str_starts_with($path, '\\')
            || \preg_match('/\A[A-Za-z]:[\/\\\\]/', $path) === 1
            || \str_contains($path, '\\')
            || \str_contains($path, '://')
            || \str_contains($path, '//')
        ) {
            return false;
        }

        foreach (\explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || \str_starts_with($segment, '@')
            ) {
                return false;
            }
        }

        return true;
    }
}
