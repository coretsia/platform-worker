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

use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Process\WorkerChildProcess;

/**
 * Implements the bounded one-frame readiness protocol between the supervisor
 * and one worker child.
 *
 * Production PCNTL and proc children connect back to a dedicated per-child
 * loopback TCP listener and publish one tokenized frame. Connected stream mode
 * remains available only for package test doubles.
 */
final readonly class WorkerChildReadinessChannel
{
    private const string FRAME = "ready\n";
    private const int MAX_FRAME_BYTES = 71;

    public static function signalReady(mixed $stream): void
    {
        self::writeFrame($stream, self::FRAME);
    }

    /**
     * Creates one tokenized loopback endpoint for a process child.
     */
    public function createProcessEndpoint(): WorkerChildReadinessEndpoint
    {
        $listener = @\stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );

        if (
            !\is_resource($listener)
            || !@\stream_set_blocking($listener, false)
        ) {
            if (\is_resource($listener)) {
                @\fclose($listener);
            }

            throw WorkerStartFailedException::childStartFailed();
        }

        $name = @\stream_socket_get_name($listener, false);
        $separator = \is_string($name)
            ? \strrpos($name, ':')
            : false;

        $portValue = $separator === false
            ? false
            : \substr($name, $separator + 1);

        $port = \is_string($portValue)
        && \ctype_digit($portValue)
            ? (int)$portValue
            : 0;

        try {
            $token = \bin2hex(\random_bytes(32));
        } catch (\Throwable) {
            @\fclose($listener);

            throw WorkerStartFailedException::childStartFailed();
        }

        if ($port < 1 || $port > 65535) {
            @\fclose($listener);

            throw WorkerStartFailedException::childStartFailed();
        }

        return WorkerChildReadinessEndpoint::tcpListener(
            listener: $listener,
            port: $port,
            token: $token,
        );
    }

    /**
     * Polls one child readiness endpoint without blocking.
     */
    public function poll(WorkerChildProcess $child): bool
    {
        $endpoint = $child->readinessEndpoint();

        if ($endpoint->closed()) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        if ($endpoint->mode() === WorkerChildReadinessEndpoint::MODE_TCP_LISTENER) {
            $this->acceptProcessConnection($endpoint);
        }

        $stream = $endpoint->mode() === WorkerChildReadinessEndpoint::MODE_STREAM
            ? $endpoint->streamResource()
            : $endpoint->connection();

        if (!\is_resource($stream)) {
            return false;
        }

        if (!@\stream_set_blocking($stream, false)) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        $read = [$stream];
        $write = null;
        $except = null;

        $selected = @\stream_select(
            $read,
            $write,
            $except,
            0,
            0,
        );

        if ($selected === 0) {
            return false;
        }

        if ($selected !== 1) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        $remaining = self::MAX_FRAME_BYTES
            + 1
            - \strlen($endpoint->buffer());

        if ($remaining < 1) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        $chunk = @\fread($stream, $remaining);

        if ($chunk === false) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        if ($chunk === '') {
            if (@\feof($stream)) {
                throw WorkerStartFailedException::readinessInvalid();
            }

            return false;
        }

        $endpoint->append($chunk);
        $buffer = $endpoint->buffer();

        if (\strlen($buffer) > self::MAX_FRAME_BYTES) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        if (!\str_contains($buffer, "\n")) {
            return false;
        }

        if ($buffer !== $endpoint->expectedFrame()) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        $endpoint->close();

        return true;
    }

    public function await(
        WorkerChildProcess $child,
        int $timeoutMs,
    ): void {
        $deadlineNs = self::deadlineNs($timeoutMs);

        do {
            if ($this->poll($child)) {
                return;
            }

            \usleep(1_000);
        } while (\hrtime(true) < $deadlineNs);

        throw WorkerStartFailedException::readinessTimeout();
    }

    private static function deadlineNs(int $timeoutMs): int
    {
        $nowNs = \hrtime(true);

        if (
            !\is_int($nowNs)
            || $timeoutMs < 1
            || $timeoutMs > \intdiv(
                \PHP_INT_MAX - $nowNs,
                1_000_000,
            )
        ) {
            throw WorkerStartFailedException::readinessTimeout();
        }

        return $nowNs + ($timeoutMs * 1_000_000);
    }

    private function acceptProcessConnection(
        WorkerChildReadinessEndpoint $endpoint,
    ): void {
        if (\is_resource($endpoint->connection())) {
            return;
        }

        $listener = $endpoint->streamResource();

        if (!\is_resource($listener)) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        $read = [$listener];
        $write = null;
        $except = null;

        $selected = @\stream_select(
            $read,
            $write,
            $except,
            0,
            0,
        );

        if ($selected === 0) {
            return;
        }

        if ($selected !== 1) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        $connection = @\stream_socket_accept(
            $listener,
            0,
        );

        if (
            !\is_resource($connection)
            || !@\stream_set_blocking($connection, false)
        ) {
            if (\is_resource($connection)) {
                @\fclose($connection);
            }

            throw WorkerStartFailedException::readinessInvalid();
        }

        $endpoint->attachConnection($connection);
    }

    private static function writeFrame(
        mixed $stream,
        string $frame,
    ): void {
        if (!\is_resource($stream)) {
            throw WorkerStartFailedException::readinessInvalid();
        }

        $remaining = $frame;

        while ($remaining !== '') {
            $written = @\fwrite($stream, $remaining);

            if (!\is_int($written) || $written < 1) {
                throw WorkerStartFailedException::readinessInvalid();
            }

            $remaining = \substr($remaining, $written);
        }

        if (!@\fflush($stream)) {
            throw WorkerStartFailedException::readinessInvalid();
        }
    }
}
