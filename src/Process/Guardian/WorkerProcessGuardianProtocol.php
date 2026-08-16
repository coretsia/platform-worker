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

namespace Coretsia\Platform\Worker\Process\Guardian;

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;

/**
 * Versioned, bounded, exact-schema private supervisor ↔ guardian protocol.
 *
 * Raw command vectors, absolute paths, ports and credentials are private IPC
 * data and must never be copied into public exception messages.
 *
 * @internal
 */
final readonly class WorkerProcessGuardianProtocol
{
    public const int VERSION = 1;
    public const int MAX_FRAME_BYTES = 65_536;

    public const string OPERATION_CLAIM = 'claim';
    public const string OPERATION_SPAWN = 'spawn';
    public const string OPERATION_POLL = 'poll';
    public const string OPERATION_TERMINATE = 'terminate';
    public const string OPERATION_KILL = 'kill';
    public const string OPERATION_CLOSE = 'close';
    public const string OPERATION_RELEASE = 'release';

    public const string STATUS_OK = 'ok';
    public const string STATUS_ERROR = 'error';

    public const string ERROR_ALREADY_RUNNING = 'already-running';
    public const string ERROR_CHILD_START_FAILED = 'child-start-failed';
    public const string ERROR_FORK_FAILED = 'fork-failed';
    public const string ERROR_CHILD_INVALID = 'child-invalid';
    public const string ERROR_CHILD_RUNNING = 'child-running';
    public const string ERROR_PROCESS_HOST_FAILED = 'process-host-failed';
    public const string ERROR_OPERATION_FAILED = 'operation-failed';

    private const array OPERATIONS = [
        self::OPERATION_CLAIM => true,
        self::OPERATION_SPAWN => true,
        self::OPERATION_POLL => true,
        self::OPERATION_TERMINATE => true,
        self::OPERATION_KILL => true,
        self::OPERATION_CLOSE => true,
        self::OPERATION_RELEASE => true,
    ];

    private const array ERRORS = [
        self::ERROR_ALREADY_RUNNING => true,
        self::ERROR_CHILD_START_FAILED => true,
        self::ERROR_FORK_FAILED => true,
        self::ERROR_CHILD_INVALID => true,
        self::ERROR_CHILD_RUNNING => true,
        self::ERROR_PROCESS_HOST_FAILED => true,
        self::ERROR_OPERATION_FAILED => true,
    ];

    /** @param array<int|string, mixed> $payload */
    public function encodeRequest(int $requestId, string $operation, array $payload): string
    {
        self::assertRequestId($requestId);
        self::assertOperation($operation);
        self::assertRequestPayload($operation, $payload);

        return $this->encode([
            'operation' => $operation,
            'payload' => $payload,
            'request_id' => $requestId,
            'version' => self::VERSION,
        ]);
    }

    /** @return array{version: 1, request_id: positive-int, operation: string, payload: array<int|string, mixed>} */
    public function decodeRequest(string $frame): array
    {
        $value = $this->decode($frame);

        if (
            \array_keys($value) !== ['operation', 'payload', 'request_id', 'version']
            || $value['version'] !== self::VERSION
            || !\is_int($value['request_id'])
            || !\is_string($value['operation'])
            || !\is_array($value['payload'])
        ) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        self::assertRequestId($value['request_id']);
        self::assertOperation($value['operation']);
        self::assertRequestPayload($value['operation'], $value['payload']);

        /** @var array{version: 1, request_id: positive-int, operation: string, payload: array<int|string, mixed>} $value */
        return $value;
    }

    /** @param array<int|string, mixed> $payload */
    public function encodeOkResponse(int $requestId, array $payload): string
    {
        self::assertRequestId($requestId);
        self::assertResponsePayload($payload);

        return $this->encode([
            'payload' => $payload,
            'request_id' => $requestId,
            'status' => self::STATUS_OK,
            'version' => self::VERSION,
        ]);
    }

    public function encodeErrorResponse(int $requestId, string $reason): string
    {
        self::assertRequestId($requestId);
        if (!isset(self::ERRORS[$reason])) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        return $this->encode([
            'payload' => ['reason' => $reason],
            'request_id' => $requestId,
            'status' => self::STATUS_ERROR,
            'version' => self::VERSION,
        ]);
    }

    /** @return array{version: 1, request_id: positive-int, status: 'ok'|'error', payload: array<int|string, mixed>} */
    public function decodeResponse(string $frame): array
    {
        $value = $this->decode($frame);

        if (
            \array_keys($value) !== ['payload', 'request_id', 'status', 'version']
            || $value['version'] !== self::VERSION
            || !\is_int($value['request_id'])
            || !\is_string($value['status'])
            || !\is_array($value['payload'])
            || !\in_array($value['status'], [self::STATUS_OK, self::STATUS_ERROR], true)
        ) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        self::assertRequestId($value['request_id']);
        self::assertResponsePayload($value['payload']);

        if ($value['status'] === self::STATUS_ERROR) {
            if (
                \array_keys($value['payload']) !== ['reason']
                || !\is_string($value['payload']['reason'])
                || !isset(self::ERRORS[$value['payload']['reason']])
            ) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
        }

        /** @var array{version: 1, request_id: positive-int, status: 'ok'|'error', payload: array<int|string, mixed>} $value */
        return $value;
    }

    private function encode(array $value): string
    {
        try {
            $frame = StableJsonEncoder::encodeStableMap($value);
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        if ($frame === '' || \strlen($frame) > self::MAX_FRAME_BYTES || !\str_ends_with($frame, "\n")) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        return $frame;
    }

    /** @return array<string, mixed> */
    private function decode(string $frame): array
    {
        if ($frame === '' || \strlen($frame) > self::MAX_FRAME_BYTES || !\str_ends_with($frame, "\n")) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }

        try {
            return StableJsonDecoder::decodeStableMap($frame);
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    private static function assertRequestId(int $requestId): void
    {
        if ($requestId < 1 || $requestId > 2_147_483_647) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    private static function assertOperation(string $operation): void
    {
        if (!isset(self::OPERATIONS[$operation])) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    /** @param array<int|string, mixed> $payload */
    private static function assertRequestPayload(string $operation, array $payload): void
    {
        if ($operation === self::OPERATION_CLAIM) {
            if (
                \array_keys($payload) !== ['force_kill_timeout_ms', 'skeleton_root', 'stop_timeout_ms']
                || !\is_int($payload['force_kill_timeout_ms'])
                || !\is_string($payload['skeleton_root'])
                || !\is_int($payload['stop_timeout_ms'])
                || !self::isSafeDirectory($payload['skeleton_root'])
            ) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            self::assertTimeout($payload['stop_timeout_ms']);
            self::assertTimeout($payload['force_kill_timeout_ms']);
            return;
        }

        if ($operation === self::OPERATION_SPAWN) {
            if (
                \array_keys($payload) !== ['command', 'working_directory']
                || !\is_array($payload['command'])
                || !\array_is_list($payload['command'])
                || $payload['command'] === []
                || !\is_string($payload['working_directory'])
                || !self::isSafeDirectory($payload['working_directory'])
            ) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            foreach ($payload['command'] as $part) {
                if (!self::isSafeCommandPart($part)) {
                    throw WorkerLifecycleFailedException::processGuardianFailed();
                }
            }
            return;
        }

        if (\in_array(
            $operation,
            [self::OPERATION_POLL, self::OPERATION_TERMINATE, self::OPERATION_KILL, self::OPERATION_CLOSE],
            true
        )) {
            if (
                \array_keys($payload) !== ['child_id']
                || !\is_string($payload['child_id'])
                || \preg_match('/\Achild-[1-9][0-9]*\z/', $payload['child_id']) !== 1
            ) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
            return;
        }

        if ($operation === self::OPERATION_RELEASE && $payload === []) {
            return;
        }

        throw WorkerLifecycleFailedException::processGuardianFailed();
    }

    /** @param array<int|string, mixed> $payload */
    private static function assertResponsePayload(array $payload): void
    {
        foreach ($payload as $value) {
            if (!self::isSafeResponseValue($value, 0)) {
                throw WorkerLifecycleFailedException::processGuardianFailed();
            }
        }
    }

    private static function isSafeResponseValue(mixed $value, int $depth): bool
    {
        if ($depth > 4) {
            return false;
        }
        if (\is_null($value) || \is_bool($value) || \is_int($value)) {
            return true;
        }
        if (\is_string($value)) {
            return \strlen($value) <= 8192 && \preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
        }
        if (!\is_array($value) || \count($value) > 64) {
            return false;
        }
        foreach ($value as $nested) {
            if (!self::isSafeResponseValue($nested, $depth + 1)) {
                return false;
            }
        }
        return true;
    }

    private static function assertTimeout(int $timeoutMs): void
    {
        if ($timeoutMs < 1 || $timeoutMs > 86_400_000) {
            throw WorkerLifecycleFailedException::processGuardianFailed();
        }
    }

    private static function isSafeCommandPart(mixed $part): bool
    {
        return \is_string($part)
            && $part !== ''
            && \trim($part) === $part
            && \strlen($part) <= 8192
            && \preg_match('/[\x00-\x1F\x7F]/', $part) !== 1;
    }

    private static function isSafeDirectory(string $directory): bool
    {
        return $directory !== ''
            && \trim($directory) === $directory
            && \strlen($directory) <= 8192
            && !\str_contains($directory, "\0")
            && \preg_match('/[\x00-\x1F\x7F]/', $directory) !== 1;
    }
}
