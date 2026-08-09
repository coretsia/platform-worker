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

namespace Coretsia\Platform\Worker\Communication;

use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocator;
use Coretsia\Platform\Worker\Runtime\WorkerLifecyclePaths;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Low-level socket transport for the worker control channel.
 *
 * Server addresses are derived from the startup WorkerPoolSpec. Client
 * addresses are derived exclusively from the active WorkerLifecycleLocator.
 * The transport performs bounded frame I/O and removes supervisor-owned Unix
 * socket artifacts. Unix listeners are created under a restrictive umask and
 * verified as owner-only before publication.
 */
final readonly class WorkerControlTransport
{
    public function __construct(private string $skeletonRoot)
    {
        if ($skeletonRoot === '' || \str_contains($skeletonRoot, "\0")) {
            throw new \InvalidArgumentException('worker-control-root-invalid');
        }
    }

    /** @return resource */
    public function listen(WorkerPoolSpec $spec): mixed
    {
        if ($spec->controlTransport() === 'unix') {
            $this->cleanup($spec);
            $directory = \dirname($this->unixPath($spec));

            if (
                !\is_dir($directory)
                && !@\mkdir($directory, 0777, true)
                && !\is_dir($directory)
            ) {
                throw WorkerCommunicationFailedException::communicationFailed();
            }
        }

        $previousUmask = null;

        if (
            $spec->controlTransport() === 'unix' && \PHP_OS_FAMILY !== 'Windows'
        ) {
            $previousUmask = \umask(0177);
        }

        try {
            $server = @\stream_socket_server(
                $this->serverAddress($spec),
                $errno,
                $error,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            );
        } finally {
            if (\is_int($previousUmask)) {
                \umask($previousUmask);
            }
        }

        if (!\is_resource($server)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        if ($spec->controlTransport() === 'unix') {
            $path = $this->unixPath($spec);

            if (!@\chmod($path, 0600)) {
                $this->close($server);
                $this->cleanup($spec);

                throw WorkerCommunicationFailedException::communicationFailed();
            }

            if (\PHP_OS_FAMILY !== 'Windows') {
                \clearstatcache(true, $path);
                $permissions = @\fileperms($path);

                if (
                    !\is_int($permissions)
                    || (($permissions & 0777) !== 0600)
                ) {
                    $this->close($server);
                    $this->cleanup($spec);

                    throw WorkerCommunicationFailedException::communicationFailed();
                }
            }
        }

        if (!@\stream_set_blocking($server, false)) {
            $this->close($server);
            $this->cleanup($spec);

            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return $server;
    }

    /** @return resource */
    public function connect(
        #[\SensitiveParameter]
        WorkerLifecycleLocator $locator,
        int $timeoutMs,
    ): mixed {
        if ($timeoutMs < 1) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $connection = @\stream_socket_client(
            $this->clientAddress($locator),
            $errorCode,
            $errorMessage,
            $timeoutMs / 1_000,
            \STREAM_CLIENT_CONNECT,
        );

        if (!\is_resource($connection)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        if (!@\stream_set_blocking($connection, true)) {
            $this->close($connection);

            throw WorkerCommunicationFailedException::communicationFailed();
        }

        try {
            $this->setTimeout(
                $connection,
                $timeoutMs,
            );
        } catch (WorkerCommunicationFailedException $exception) {
            $this->close($connection);

            throw $exception;
        }

        return $connection;
    }

    /** @return resource|null */
    public function accept(
        mixed $server,
        int $timeoutMs,
    ): mixed {
        if (!\is_resource($server) || $timeoutMs < 0) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $read = [$server];
        $write = null;
        $except = null;

        $selected = @\stream_select(
            $read,
            $write,
            $except,
            \intdiv($timeoutMs, 1_000),
            ($timeoutMs % 1_000) * 1_000,
        );

        if ($selected === 0) {
            return null;
        }

        if ($selected !== 1) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $connection = @\stream_socket_accept(
            $server,
            0,
        );

        if (!\is_resource($connection)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        /*
         * The listener is deliberately non-blocking so the supervisor can tick.
         * Accepted control sessions are different: one bounded request frame must
         * be read completely. On Windows an accepted TCP stream may retain
         * non-blocking behavior from the listener, causing fgets() to fail before
         * the client has written the request.
         */
        if (!@\stream_set_blocking($connection, true)) {
            $this->close($connection);

            throw WorkerCommunicationFailedException::communicationFailed();
        }

        try {
            $this->setTimeout(
                $connection,
                1_000,
            );
        } catch (WorkerCommunicationFailedException $exception) {
            $this->close($connection);

            throw $exception;
        }

        return $connection;
    }

    public function readFrame(mixed $stream, int $maxBytes): string
    {
        if (!\is_resource($stream) || $maxBytes < 1) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
        $frame = @\fgets($stream, $maxBytes + 1);
        if (!\is_string($frame) || !\str_ends_with($frame, "\n") || \strlen($frame) > $maxBytes) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
        return $frame;
    }

    public function writeFrame(mixed $stream, string $frame): void
    {
        if (!\is_resource($stream) || $frame === '' || !\str_ends_with($frame, "\n")) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
        $remaining = $frame;
        while ($remaining !== '') {
            $written = @\fwrite($stream, $remaining);
            if (!\is_int($written) || $written < 1) {
                throw WorkerCommunicationFailedException::communicationFailed();
            }
            $remaining = \substr($remaining, $written);
        }
        if (!@\fflush($stream)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }

    public function close(mixed $stream): void
    {
        if (\is_resource($stream)) {
            @\fclose($stream);
        }
    }

    public function cleanup(WorkerPoolSpec $spec): void
    {
        if ($spec->controlTransport() !== 'unix') {
            return;
        }

        $path = $this->unixPath($spec);

        if (!@\file_exists($path)) {
            return;
        }

        if (
            @\filetype($path) !== 'socket'
            || !@\unlink($path)
        ) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }

    private function setTimeout(
        mixed $stream,
        int $timeoutMs,
    ): void {
        if (
            !\is_resource($stream)
            || $timeoutMs < 1
            || !@\stream_set_timeout(
                $stream,
                \intdiv($timeoutMs, 1_000),
                ($timeoutMs % 1_000) * 1_000,
            )
        ) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }

    private function serverAddress(WorkerPoolSpec $spec): string
    {
        return match ($spec->controlTransport()) {
            'unix' => 'unix://' . $this->unixPath($spec),
            'tcp' => 'tcp://' . $spec->tcpHost() . ':' . $spec->tcpPort(),
            default => throw WorkerCommunicationFailedException::communicationFailed(),
        };
    }

    private function clientAddress(
        #[\SensitiveParameter]
        WorkerLifecycleLocator $locator,
    ): string {
        return match ($locator->controlTransport()) {
            'unix' => 'unix://' . $this->clientUnixPath($locator),
            'tcp' => $this->clientTcpAddress($locator),
            default => throw WorkerCommunicationFailedException::communicationFailed(),
        };
    }

    private function unixPath(WorkerPoolSpec $spec): string
    {
        return WorkerLifecyclePaths::resolve(
            $this->skeletonRoot,
            $spec->socketPath(),
        );
    }

    private function clientTcpAddress(
        #[\SensitiveParameter]
        WorkerLifecycleLocator $locator,
    ): string {
        $port = $locator->tcpPort();

        if ($locator->tcpHost() !== '127.0.0.1' || $port === null) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return 'tcp://127.0.0.1:' . $port;
    }

    private function clientUnixPath(
        #[\SensitiveParameter]
        WorkerLifecycleLocator $locator,
    ): string {
        $socketPath = $locator->socketPath();
        if ($socketPath === null) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        try {
            return WorkerLifecyclePaths::resolve(
                $this->skeletonRoot,
                $socketPath,
            );
        } catch (\Throwable) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }
}
