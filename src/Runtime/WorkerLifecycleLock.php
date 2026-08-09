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

use Coretsia\Platform\Worker\Exception\WorkerAlreadyRunningException;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;

/**
 * Owns the persistent filesystem generation fence for one worker runtime.
 *
 * The lock file is opened with `c+b` on Windows and `c+be` on POSIX,
 * requesting close-on-exec as defense in depth where PHP supports it. The
 * handle remains guarded by non-blocking `flock`. The guardian owns the handle;
 * explicit fork-child detachment prevents PCNTL workers retaining the fence.
 * Releasing the lock closes the handle but never unlinks the anchor path.
 */
final class WorkerLifecycleLock
{
    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(private readonly string $skeletonRoot)
    {
        if ($skeletonRoot === '' || \str_contains($skeletonRoot, "\0")) {
            throw new \InvalidArgumentException('worker-lifecycle-root-invalid');
        }
    }

    public function acquire(): void
    {
        if (\is_resource($this->handle)) {
            throw WorkerAlreadyRunningException::alreadyRunning();
        }

        $handle = $this->open();
        if (!@\flock($handle, \LOCK_EX | \LOCK_NB)) {
            @\fclose($handle);
            throw WorkerAlreadyRunningException::alreadyRunning();
        }
        $this->handle = $handle;
    }

    public function isHeld(): bool
    {
        $handle = $this->open();
        try {
            if (@\flock($handle, \LOCK_EX | \LOCK_NB)) {
                @\flock($handle, \LOCK_UN);
                return false;
            }
            return true;
        } finally {
            @\fclose($handle);
        }
    }

    public function release(): void
    {
        if (!\is_resource($this->handle)) {
            return;
        }
        @\flock($this->handle, \LOCK_UN);
        @\fclose($this->handle);
        $this->handle = null;
    }

    public function detachInForkedChild(): void
    {
        if (\is_resource($this->handle)) {
            @\fclose($this->handle);
            $this->handle = null;
        }
    }

    /** @return resource */
    private function open(): mixed
    {
        $path = $this->path();
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw WorkerLifecycleFailedException::lifecycleLockFailed();
        }
        $handle = @\fopen(
            $path,
            self::openMode(),
        );
        if (!\is_resource($handle)) {
            throw WorkerLifecycleFailedException::lifecycleLockFailed();
        }
        return $handle;
    }

    /**
     * Returns the local lock-file mode.
     *
     * POSIX runtimes request close-on-exec as defense in depth. Windows keeps
     * the canonical binary read/write mode because PHP does not expose the
     * POSIX `fopen()` `e` flag there.
     */
    private static function openMode(): string
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? 'c+b'
            : 'c+be';
    }

    private function path(): string
    {
        return WorkerLifecyclePaths::resolve(
            $this->skeletonRoot,
            WorkerLifecyclePaths::LOCK,
        );
    }
}
