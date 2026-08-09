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

namespace Coretsia\Platform\Worker\Runtime;

/**
 * Canonical package-owned worker lifecycle artifact paths.
 *
 * Lifecycle discovery anchors are deliberately not configurable. They remain
 * stable when mutable worker configuration changes, so lifecycle commands can
 * locate an already-running supervisor without rebuilding its startup spec.
 *
 * This class performs deterministic path validation and resolution only. It
 * never creates directories, reads files, writes files, logs resolved paths, or
 * exposes them through public diagnostics.
 */
final class WorkerLifecyclePaths
{
    public const string LOCK = 'var/tmp/worker.lock';
    public const string LOCATOR = 'var/tmp/worker.lifecycle.json';
    public const string LOCATOR_TEMP = 'var/tmp/worker.lifecycle.json.tmp';

    /**
     * Resolves a validated skeleton-root-relative path without filesystem I/O.
     *
     * The method also serves client-side Unix socket resolution for a validated
     * WorkerLifecycleLocator. The returned absolute path is transport-internal
     * data and must not be logged or included in exception messages.
     */
    public static function resolve(
        string $skeletonRoot,
        string $relativePath,
    ): string {
        if ($skeletonRoot === '' || \str_contains($skeletonRoot, "\0")) {
            throw new \InvalidArgumentException('worker-lifecycle-root-invalid');
        }

        self::assertRelativeSafePath($relativePath);

        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/')
            . '/'
            . $relativePath;
    }

    private static function assertRelativeSafePath(string $path): void
    {
        if (
            $path === ''
            || \trim($path) !== $path
            || \preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || \str_starts_with($path, '/')
            || \str_starts_with($path, '\\')
            || \preg_match('/\A[A-Za-z]:[\/\\\\]/', $path) === 1
            || \str_contains($path, '\\')
            || \str_contains($path, '://')
            || \str_starts_with($path, 'skeleton/')
        ) {
            throw new \InvalidArgumentException('worker-lifecycle-path-invalid');
        }

        foreach (\explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || \str_starts_with($segment, '@')
            ) {
                throw new \InvalidArgumentException('worker-lifecycle-path-invalid');
            }
        }
    }
}
