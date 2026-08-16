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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;

/**
 * Stable worker pool state JSON store.
 *
 * This is the only platform/worker runtime class allowed to write
 * `worker.state.json`.
 * State is supervisor-owned diagnostic data only; it is never the liveness or
 * generation-ownership authority. The guardian-owned `worker.lock` fence is the
 * authoritative active/recovering generation boundary.
 *
 * Persisted state is intentionally redacted and contains only the cemented
 * safe schema:
 *
 * - version
 * - pid
 * - status
 * - worker_count
 * - ready_worker_count
 * - driver_requested
 * - driver
 * - control_transport_requested
 * - control_transport
 * - endpoint_hash
 *
 * The persisted state never contains timestamps, environment values, raw socket
 * paths, raw TCP hosts/ports, absolute paths, task payloads, headers, or tokens.
 *
 * Public failures are deterministic and safe. They must not expose raw state
 * file paths, absolute paths, endpoint identifiers, OS error text, or decoded
 * state payloads.
 */
final readonly class WorkerStateStore
{
    public function __construct(
        private string $skeletonRoot,
    ) {
        if ($skeletonRoot === '' || \str_contains($skeletonRoot, "\0")) {
            throw new \InvalidArgumentException('worker-state-root-invalid');
        }
    }

    /**
     * Creates a safe runtime state DTO from an already-normalized pool spec.
     */
    public function createState(
        WorkerPoolSpec $spec,
        int $pid,
        WorkerPoolStatus $status,
        int $readyWorkerCount,
    ): WorkerPoolState {
        try {
            return new WorkerPoolState(
                pid: $pid,
                status: $status,
                workerCount: $spec->workers(),
                readyWorkerCount: $readyWorkerCount,
                driverRequested: $spec->driverRequested(),
                driver: $spec->driver(),
                controlTransportRequested: $spec->controlTransportRequested(),
                controlTransport: $spec->controlTransport(),
                endpointHash: self::endpointHash($spec),
            );
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::invalidState();
        }
    }

    /**
     * Returns the lowercase hexadecimal SHA-256 hash of the canonical endpoint
     * identifier.
     *
     * The raw endpoint identifier is intentionally not returned by this store
     * except through WorkerPoolSpec::endpointIdentifier(), where it is marked as
     * hashing-only data.
     */
    public static function endpointHash(WorkerPoolSpec $spec): string
    {
        return \hash('sha256', $spec->endpointIdentifier());
    }

    /**
     * Writes `worker.state.json` using the stable worker state schema.
     *
     * `$skeletonRoot` is used only for filesystem path resolution. It is never
     * stored, logged, returned, or included in exception messages.
     */
    public function write(WorkerPoolSpec $spec, WorkerPoolState $state): void
    {
        try {
            $bytes = StableJsonEncoder::encodeStableMap($state->toArray());
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        $path = $this->path($spec);
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        $tmp = $this->temporaryPath($spec);

        if (@\file_put_contents($tmp, $bytes, \LOCK_EX) === false || !@\rename($tmp, $path)) {
            @\unlink($tmp);

            throw WorkerLifecycleFailedException::invalidState();
        }
    }

    /**
     * Reads and validates the optional diagnostic state snapshot.
     *
     * A missing snapshot returns null and MUST NOT be interpreted as the
     * authoritative liveness signal. WorkerLifecycleLock is authoritative only
     * for active or recovering worker-generation ownership. Live supervisor
     * availability is established through the lifecycle locator and authenticated
     * control channel.
     *
     * Existing but unreadable state, invalid JSON, non-map JSON, schema drift,
     * forbidden extra keys, invalid value types, and invalid value domains all
     * map to the same deterministic safe invalid-state failure.
     */
    public function readSnapshot(WorkerPoolSpec $spec): ?WorkerPoolState
    {
        $path = $this->path($spec);

        if (!@\file_exists($path)) {
            return null;
        }

        if (!@\is_file($path)) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        $bytes = @\file_get_contents($path);

        if (!\is_string($bytes)) {
            throw WorkerLifecycleFailedException::invalidState();
        }

        try {
            return WorkerPoolState::fromArray(
                StableJsonDecoder::decodeStableMap($bytes),
            );
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::invalidState();
        }
    }

    public function delete(WorkerPoolSpec $spec): void
    {
        foreach ([$this->path($spec), $this->temporaryPath($spec)] as $path) {
            if (!@\file_exists($path)) {
                continue;
            }

            if (!@\is_file($path) || !@\unlink($path)) {
                throw WorkerLifecycleFailedException::runtimeCleanupFailed();
            }
        }
    }

    private function temporaryPath(WorkerPoolSpec $spec): string
    {
        return $this->path($spec) . '.tmp';
    }

    private function path(WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $this->skeletonRoot), '/') . '/' . $spec->statePath();
    }
}
