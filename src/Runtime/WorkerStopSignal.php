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

use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;

/**
 * Encapsulates the supervisor-owned cooperative stop flag.
 *
 * WorkerSupervisor is the only writer and remover. The worker child observes
 * the flag outside an in-flight task: ApplicationWorker checks it before task
 * acquisition, and task sources may check it during interruptible receive()
 * through WorkerTaskSourceContextInterface.
 */
final readonly class WorkerStopSignal
{
    public function __construct(private string $skeletonRoot)
    {
        if ($skeletonRoot === '' || \str_contains($skeletonRoot, "\0")) {
            throw new \InvalidArgumentException('worker-stop-signal-root-invalid');
        }
    }

    public function request(WorkerPoolSpec $spec): void
    {
        $path = $this->path($spec);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw WorkerLifecycleFailedException::shutdownFailed();
        }
        if (@\file_put_contents($path, "stop\n", \LOCK_EX) === false) {
            throw WorkerLifecycleFailedException::shutdownFailed();
        }
    }

    public function isRequested(WorkerPoolSpec $spec): bool
    {
        return @\is_file($this->path($spec));
    }

    public function clear(WorkerPoolSpec $spec): void
    {
        $path = $this->path($spec);
        if (!@\file_exists($path)) {
            return;
        }
        if (!@\is_file($path) || !@\unlink($path)) {
            throw WorkerLifecycleFailedException::runtimeCleanupFailed();
        }
    }

    private function path(WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $this->skeletonRoot), '/') . '/' . $spec->stopFlagPath();
    }
}
