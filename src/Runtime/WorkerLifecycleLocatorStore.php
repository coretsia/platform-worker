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
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;

/**
 * Exclusive filesystem owner of the private worker lifecycle locator.
 *
 * The locator is published atomically by the supervisor while its associated
 * guardian owns the canonical generation fence. It contains a
 * supervisor-instance control credential and is therefore a private capability
 * artifact, not merely endpoint discovery metadata. Lifecycle clients may read
 * it only after confirming that the canonical generation fence is held.
 *
 * A held generation fence establishes active or recovering worker-generation
 * ownership. It does not by itself prove that the supervisor control endpoint
 * is reachable.
 *
 * Raw locator bytes, decoded endpoint fields, and resolved absolute paths never
 * leave this storage boundary through logs, diagnostics, or exception messages.
 * Exclusive temporary files request close-on-exec on POSIX as defense in depth.
 */
final readonly class WorkerLifecycleLocatorStore
{
    private const int MAX_BYTES = 4_096;

    public function __construct(
        private string $skeletonRoot,
    ) {
        if ($skeletonRoot === '' || \str_contains($skeletonRoot, "\0")) {
            throw new \InvalidArgumentException('worker-lifecycle-locator-root-invalid');
        }
    }

    public function write(
        #[\SensitiveParameter]
        WorkerLifecycleLocator $locator,
    ): void {
        try {
            $bytes = StableJsonEncoder::encodeStableMap($locator->toArray());
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
        }

        if ($bytes === '' || \strlen($bytes) > self::MAX_BYTES) {
            throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
        }

        $path = $this->path();
        $temporaryPath = $this->temporaryPath();
        $directory = \dirname($path);

        if (
            !\is_dir($directory)
            && !@\mkdir($directory, 0777, true)
            && !\is_dir($directory)
        ) {
            throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
        }

        $previousUmask = null;

        if (\PHP_OS_FAMILY !== 'Windows') {
            $previousUmask = \umask(0177);
        }

        try {
            $handle = @\fopen(
                $temporaryPath,
                self::temporaryOpenMode(),
            );
        } finally {
            if (\is_int($previousUmask)) {
                \umask($previousUmask);
            }
        }

        if (!\is_resource($handle)) {
            throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
        }

        $published = false;

        try {
            if (!@\chmod($temporaryPath, 0600)) {
                throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
            }

            if (\PHP_OS_FAMILY !== 'Windows') {
                \clearstatcache(true, $temporaryPath);
                $permissions = @\fileperms($temporaryPath);

                if (
                    !\is_int($permissions)
                    || (($permissions & 0777) !== 0600)
                ) {
                    throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
                }
            }

            self::writeAll($handle, $bytes);

            if (!@\fflush($handle)) {
                throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
            }

            if (!@\fclose($handle)) {
                $handle = null;
                throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
            }
            $handle = null;

            if (!@\rename($temporaryPath, $path)) {
                throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
            }

            $published = true;
        } catch (WorkerLifecycleFailedException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
        } finally {
            if (\is_resource($handle)) {
                @\fclose($handle);
            }

            if (!$published) {
                @\unlink($temporaryPath);
            }
        }
    }

    public function read(): ?WorkerLifecycleLocator
    {
        $path = $this->path();

        if (!@\file_exists($path) && !@\is_link($path)) {
            return null;
        }

        try {
            if (@\is_link($path) || !@\is_file($path)) {
                throw new \RuntimeException('worker-lifecycle-locator-file-invalid');
            }

            \clearstatcache(true, $path);

            if (\PHP_OS_FAMILY !== 'Windows') {
                $permissions = @\fileperms($path);

                if (
                    !\is_int($permissions)
                    || (($permissions & 0777) !== 0600)
                ) {
                    throw new \RuntimeException('worker-lifecycle-locator-permissions-invalid');
                }
            }

            $size = @\filesize($path);
            if (!\is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
                throw new \RuntimeException('worker-lifecycle-locator-size-invalid');
            }

            $bytes = @\file_get_contents($path);
            if (
                !\is_string($bytes)
                || \strlen($bytes) !== $size
            ) {
                throw new \RuntimeException('worker-lifecycle-locator-read-failed');
            }

            return WorkerLifecycleLocator::fromArray(
                StableJsonDecoder::decodeStableMap($bytes),
            );
        } catch (\Throwable) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }

    public function delete(): void
    {
        foreach ([$this->path(), $this->temporaryPath()] as $path) {
            if (!@\file_exists($path) && !@\is_link($path)) {
                continue;
            }

            if (@\is_link($path) || !@\is_file($path) || !@\unlink($path)) {
                throw WorkerLifecycleFailedException::runtimeCleanupFailed();
            }
        }
    }

    /**
     * Returns the exclusive temporary-file mode.
     *
     * POSIX runtimes request close-on-exec. Windows keeps the equivalent valid
     * binary read/write mode without the POSIX-only `e` flag.
     */
    private static function temporaryOpenMode(): string
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? 'x+b'
            : 'x+be';
    }

    /** @param resource $handle */
    private static function writeAll(mixed $handle, string $bytes): void
    {
        $remaining = $bytes;

        while ($remaining !== '') {
            $written = @\fwrite($handle, $remaining);

            if (!\is_int($written) || $written < 1) {
                throw WorkerLifecycleFailedException::lifecycleLocatorFailed();
            }

            $remaining = \substr($remaining, $written);
        }
    }

    private function path(): string
    {
        return WorkerLifecyclePaths::resolve(
            $this->skeletonRoot,
            WorkerLifecyclePaths::LOCATOR,
        );
    }

    private function temporaryPath(): string
    {
        return WorkerLifecyclePaths::resolve(
            $this->skeletonRoot,
            WorkerLifecyclePaths::LOCATOR_TEMP,
        );
    }
}
