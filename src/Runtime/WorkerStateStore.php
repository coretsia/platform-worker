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
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;

/**
 * Stable worker pool state JSON store.
 *
 * This is the only platform/worker runtime class allowed to write
 * `worker.state.json`.
 *
 * Persisted state is intentionally redacted and contains only the cemented
 * safe schema:
 *
 * - version
 * - pid
 * - worker_count
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
    private const int SCHEMA_VERSION = 1;

    /**
     * @var array<string, true>
     */
    private const array STATE_KEYS = [
        'version' => true,
        'pid' => true,
        'worker_count' => true,
        'driver_requested' => true,
        'driver' => true,
        'control_transport_requested' => true,
        'control_transport' => true,
        'endpoint_hash' => true,
    ];

    public function __construct(
        private StableJsonEncoder $encoder,
        private StableJsonDecoder $decoder,
    ) {
    }

    /**
     * Creates a safe runtime state DTO from an already-normalized pool spec.
     */
    public function createState(WorkerPoolSpec $spec, int $pid): WorkerPoolState
    {
        try {
            return new WorkerPoolState(
                pid: $pid,
                workerCount: $spec->workers(),
                driverRequested: $spec->driverRequested(),
                driver: $spec->driver(),
                controlTransportRequested: $spec->controlTransportRequested(),
                controlTransport: $spec->controlTransport(),
                endpointHash: self::endpointHash($spec),
            );
        } catch (\InvalidArgumentException) {
            throw WorkerStartFailedException::invalidState();
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
    public function write(string $skeletonRoot, WorkerPoolSpec $spec, WorkerPoolState $state): void
    {
        $statePath = self::statePath($skeletonRoot, $spec);

        try {
            $bytes = $this->encoder->encode($state->toArray());
        } catch (\Throwable) {
            throw WorkerStartFailedException::invalidState();
        }

        self::writeBytes($statePath, $bytes);
    }

    /**
     * Reads and validates `worker.state.json`.
     *
     * Missing state marker means the worker pool is not currently running.
     *
     * Existing but unreadable state, invalid JSON, non-map JSON, schema drift,
     * forbidden extra keys, invalid value types, and invalid value domains all
     * map to the same deterministic safe invalid-state failure.
     */
    public function read(string $skeletonRoot, WorkerPoolSpec $spec): WorkerPoolState
    {
        $statePath = self::statePath($skeletonRoot, $spec);
        $contents = self::readBytes($statePath);

        try {
            $state = $this->decoder->decodeMap($contents);
        } catch (\Throwable) {
            throw WorkerStartFailedException::invalidState();
        }

        self::assertExactSchema($state);

        $version = self::requiredInt($state, 'version');
        if ($version !== self::SCHEMA_VERSION) {
            throw WorkerStartFailedException::invalidState();
        }

        try {
            return new WorkerPoolState(
                pid: self::requiredInt($state, 'pid'),
                workerCount: self::requiredInt($state, 'worker_count'),
                driverRequested: self::requiredString($state, 'driver_requested'),
                driver: self::requiredString($state, 'driver'),
                controlTransportRequested: self::requiredString($state, 'control_transport_requested'),
                controlTransport: self::requiredString($state, 'control_transport'),
                endpointHash: self::requiredString($state, 'endpoint_hash'),
            );
        } catch (\InvalidArgumentException) {
            throw WorkerStartFailedException::invalidState();
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function assertExactSchema(array $state): void
    {
        foreach (\array_keys($state) as $key) {
            if (!isset(self::STATE_KEYS[$key])) {
                throw WorkerStartFailedException::invalidState();
            }
        }

        foreach (\array_keys(self::STATE_KEYS) as $key) {
            if (!\array_key_exists($key, $state)) {
                throw WorkerStartFailedException::invalidState();
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function requiredInt(array $state, string $key): int
    {
        if (!\array_key_exists($key, $state) || !\is_int($state[$key])) {
            throw WorkerStartFailedException::invalidState();
        }

        return $state[$key];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function requiredString(array $state, string $key): string
    {
        if (!\array_key_exists($key, $state) || !\is_string($state[$key])) {
            throw WorkerStartFailedException::invalidState();
        }

        if ($state[$key] === '') {
            throw WorkerStartFailedException::invalidState();
        }

        return $state[$key];
    }

    private static function statePath(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        $skeletonRoot = \trim($skeletonRoot);

        if ($skeletonRoot === '' || \str_contains($skeletonRoot, "\0")) {
            throw WorkerStartFailedException::invalidState();
        }

        $root = \rtrim(\str_replace('\\', '/', $skeletonRoot), '/');

        if ($root === '') {
            throw WorkerStartFailedException::invalidState();
        }

        return $root . '/' . $spec->statePath();
    }

    private static function readBytes(string $path): string
    {
        \set_error_handler(static fn (): bool => true);

        try {
            if (!\file_exists($path)) {
                throw WorkerNotRunningException::notRunning();
            }

            if (!\is_file($path)) {
                throw WorkerStartFailedException::invalidState();
            }

            $contents = \file_get_contents($path);
        } finally {
            \restore_error_handler();
        }

        if (!\is_string($contents)) {
            throw WorkerStartFailedException::invalidState();
        }

        return $contents;
    }

    private static function writeBytes(string $path, string $bytes): void
    {
        $dir = \dirname($path);

        \set_error_handler(static fn (): bool => true);

        try {
            if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
                throw WorkerStartFailedException::invalidState();
            }

            $tmpPath = $path . '.tmp';

            if (\file_put_contents($tmpPath, $bytes, \LOCK_EX) === false) {
                throw WorkerStartFailedException::invalidState();
            }

            if (!\rename($tmpPath, $path)) {
                @\unlink($tmpPath);

                throw WorkerStartFailedException::invalidState();
            }
        } finally {
            \restore_error_handler();
        }
    }
}
