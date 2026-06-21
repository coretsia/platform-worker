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
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

/**
 * Payload-free worker control channel.
 *
 * This class owns the low-level worker control protocol for resolved `unix`
 * and `tcp` control transports.
 *
 * The control channel is intentionally not a task transport. It supports only
 * safe control operations and safe summary frames:
 *
 * - start
 * - stop
 * - status
 * - health
 *
 * It MUST NOT transmit task payloads, headers, tokens, raw socket paths, raw TCP
 * endpoints, absolute paths, or decoded runtime payloads.
 *
 * Public failures are deterministic and safe. They all collapse to:
 *
 *     CORETSIA_WORKER_COMMUNICATION_FAILED: communication_failed
 *
 * No exception message emitted here contains socket paths, TCP hosts/ports,
 * endpoint identifiers, absolute paths, OS error text, payload fragments,
 * headers, or tokens.
 */
final readonly class WorkerSocketServer
{
    public const string OPERATION_START = 'start';
    public const string OPERATION_STOP = 'stop';
    public const string OPERATION_STATUS = 'status';
    public const string OPERATION_HEALTH = 'health';

    private const int MAX_FRAME_BYTES = 256;

    private const string CONTROL_TRANSPORT_UNIX = 'unix';
    private const string CONTROL_TRANSPORT_TCP = 'tcp';

    /**
     * Opens a control listening socket for the resolved worker control
     * transport.
     *
     * @return resource
     */
    public function listen(string $skeletonRoot, WorkerPoolSpec $spec): mixed
    {
        $address = self::serverAddress($skeletonRoot, $spec);

        \set_error_handler(static fn (): bool => true);

        try {
            $server = \stream_socket_server(
                $address,
                $errno,
                $errstr,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($server)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return $server;
    }

    /**
     * Opens a client connection to the resolved worker control transport.
     *
     * @return resource
     */
    public function connect(
        string $skeletonRoot,
        WorkerPoolSpec $spec,
        int $timeoutSeconds = 1,
    ): mixed {
        if ($timeoutSeconds < 0) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $address = self::clientAddress($skeletonRoot, $spec);

        \set_error_handler(static fn (): bool => true);

        try {
            $connection = \stream_socket_client(
                $address,
                $errno,
                $errstr,
                $timeoutSeconds,
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($connection)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return $connection;
    }

    /**
     * Accepts one incoming control connection.
     *
     * @return resource
     */
    public function accept(mixed $server, int $timeoutSeconds = 0): mixed
    {
        if ($timeoutSeconds < 0) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $server = self::assertStream($server);

        \set_error_handler(static fn (): bool => true);

        try {
            $connection = \stream_socket_accept($server, $timeoutSeconds);
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($connection)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return $connection;
    }

    public function close(mixed $stream): void
    {
        $stream = self::assertStream($stream);

        \set_error_handler(static fn (): bool => true);

        try {
            \fclose($stream);
        } finally {
            \restore_error_handler();
        }
    }

    public function sendStartRequest(mixed $connection): void
    {
        self::writeFrame($connection, self::OPERATION_START);
    }

    public function sendStopRequest(mixed $connection): void
    {
        self::writeFrame($connection, self::OPERATION_STOP);
    }

    public function sendStatusRequest(mixed $connection): void
    {
        self::writeFrame($connection, self::OPERATION_STATUS);
    }

    public function sendHealthRequest(mixed $connection): void
    {
        self::writeFrame($connection, self::OPERATION_HEALTH);
    }

    /**
     * Reads and validates one payload-free control operation frame.
     */
    public function readRequest(mixed $connection): string
    {
        $operation = self::readFrame($connection);

        if (!self::isKnownOperation($operation)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return $operation;
    }

    public function writeOkResponse(mixed $connection): void
    {
        self::writeFrame($connection, 'ok');
    }

    public function writeStopAcceptedResponse(mixed $connection): void
    {
        self::writeFrame($connection, 'stop_requested');
    }

    public function writeHealthResponse(mixed $connection): void
    {
        self::writeFrame($connection, 'health ok');
    }

    public function writeStartSummary(mixed $connection, WorkerPoolState $state): void
    {
        self::writeFrame($connection, self::summaryFrame('start', $state));
    }

    public function writeStatusSummary(mixed $connection, WorkerPoolState $state): void
    {
        self::writeFrame($connection, self::summaryFrame('status', $state));
    }

    /**
     * Reads one safe response frame.
     *
     * The returned value is a protocol frame, not a task payload.
     */
    public function readResponse(mixed $connection): string
    {
        return self::readFrame($connection);
    }

    /**
     * Returns the lowercase hexadecimal SHA-256 hash of the canonical endpoint
     * identifier.
     *
     * This method never returns the raw endpoint identifier.
     */
    public static function endpointHash(WorkerPoolSpec $spec): string
    {
        return \hash('sha256', $spec->endpointIdentifier());
    }

    private static function summaryFrame(string $kind, WorkerPoolState $state): string
    {
        if ($kind !== 'start' && $kind !== 'status') {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return $kind
            . ' pid=' . $state->pid()
            . ' worker_count=' . $state->workerCount()
            . ' driver=' . $state->driver()
            . ' control_transport=' . $state->controlTransport()
            . ' endpoint_hash=' . $state->endpointHash();
    }

    private static function writeFrame(mixed $connection, string $frame): void
    {
        $connection = self::assertStream($connection);
        self::assertSafeFrame($frame);

        \set_error_handler(static fn (): bool => true);

        try {
            $written = \fwrite($connection, $frame . "\n");
        } finally {
            \restore_error_handler();
        }

        if ($written === false || $written < \strlen($frame) + 1) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }

    private static function readFrame(mixed $connection): string
    {
        $connection = self::assertStream($connection);

        \set_error_handler(static fn (): bool => true);

        try {
            $line = \fgets($connection, self::MAX_FRAME_BYTES + 2);
        } finally {
            \restore_error_handler();
        }

        if (!\is_string($line)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $frame = \rtrim($line, "\r\n");

        self::assertSafeFrame($frame);

        return $frame;
    }

    private static function assertSafeFrame(string $frame): void
    {
        if ($frame === '') {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        if (\strlen($frame) > self::MAX_FRAME_BYTES) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $frame) === 1) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        if (!self::isAllowedFrame($frame)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }

    private static function isAllowedFrame(string $frame): bool
    {
        if (self::isKnownOperation($frame)) {
            return true;
        }

        if ($frame === 'ok' || $frame === 'stop_requested' || $frame === 'health ok') {
            return true;
        }

        if (
            \preg_match(
                '/\A(?:start|status) pid=[1-9][0-9]* worker_count=[1-9][0-9]* driver=(?:pcntl|proc) control_transport=(?:unix|tcp) endpoint_hash=[a-f0-9]{64}\z/',
                $frame,
            ) === 1
        ) {
            return true;
        }

        return false;
    }

    private static function isKnownOperation(string $operation): bool
    {
        return match ($operation) {
            self::OPERATION_START,
            self::OPERATION_STOP,
            self::OPERATION_STATUS,
            self::OPERATION_HEALTH => true,
            default => false,
        };
    }

    /**
     * @return resource
     */
    private static function assertStream(mixed $stream): mixed
    {
        if (!\is_resource($stream)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return $stream;
    }

    private static function serverAddress(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return match ($spec->controlTransport()) {
            self::CONTROL_TRANSPORT_UNIX => self::unixAddress($skeletonRoot, $spec, true),
            self::CONTROL_TRANSPORT_TCP => self::tcpAddress($spec),
            default => throw WorkerCommunicationFailedException::communicationFailed(),
        };
    }

    private static function clientAddress(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return match ($spec->controlTransport()) {
            self::CONTROL_TRANSPORT_UNIX => self::unixAddress($skeletonRoot, $spec, false),
            self::CONTROL_TRANSPORT_TCP => self::tcpAddress($spec),
            default => throw WorkerCommunicationFailedException::communicationFailed(),
        };
    }

    private static function unixAddress(
        string $skeletonRoot,
        WorkerPoolSpec $spec,
        bool $prepareForListen,
    ): string {
        $socketPath = self::resolveRelativePath($skeletonRoot, $spec->socketPath());

        if ($prepareForListen) {
            self::prepareUnixSocketPath($socketPath);
        }

        return 'unix://' . $socketPath;
    }

    private static function tcpAddress(WorkerPoolSpec $spec): string
    {
        return 'tcp://' . $spec->tcpHost() . ':' . $spec->tcpPort();
    }

    private static function resolveRelativePath(string $skeletonRoot, string $relativePath): string
    {
        $skeletonRoot = \trim($skeletonRoot);

        if ($skeletonRoot === '' || \str_contains($skeletonRoot, "\0")) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $root = \rtrim(\str_replace('\\', '/', $skeletonRoot), '/');

        if ($root === '') {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        return $root . '/' . $relativePath;
    }

    private static function prepareUnixSocketPath(string $socketPath): void
    {
        $dir = \dirname($socketPath);

        \set_error_handler(static fn (): bool => true);

        try {
            if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
                throw WorkerCommunicationFailedException::communicationFailed();
            }

            if (\file_exists($socketPath) && !\unlink($socketPath)) {
                throw WorkerCommunicationFailedException::communicationFailed();
            }
        } finally {
            \restore_error_handler();
        }
    }
}
