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

namespace Coretsia\Platform\Worker\Process\Proc;

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;

/**
 * Encodes and validates the private process-host protocol.
 *
 * The protocol is versioned, line-delimited, bounded, exact-schema JSON. It is
 * used only between ProcWorkerProcessDriver infrastructure and the pre-lock
 * proc host. Raw commands and paths never appear in public exceptions.
 *
 * @phpstan-type HostRequest array{
 *     version: 1,
 *     request_id: positive-int,
 *     operation: 'spawn'|'poll'|'terminate'|'kill'|'close'|'shutdown',
 *     payload: array<int|string, mixed>
 * }
 * @phpstan-type HostResponse array{
 *     version: 1,
 *     request_id: positive-int,
 *     status: 'ok'|'error',
 *     payload: array<int|string, mixed>
 * }
 */
final readonly class WorkerProcProcessHostProtocol
{
    public const int VERSION = 1;
    public const int MAX_FRAME_BYTES = 65_536;

    public const string OPERATION_SPAWN = 'spawn';
    public const string OPERATION_POLL = 'poll';
    public const string OPERATION_TERMINATE = 'terminate';
    public const string OPERATION_KILL = 'kill';
    public const string OPERATION_CLOSE = 'close';
    public const string OPERATION_SHUTDOWN = 'shutdown';

    public const string STATUS_OK = 'ok';
    public const string STATUS_ERROR = 'error';

    public const string ERROR_CHILD_START_FAILED = 'child-start-failed';
    public const string ERROR_CHILD_INVALID = 'child-invalid';
    public const string ERROR_CHILD_RUNNING = 'child-running';
    public const string ERROR_OPERATION_FAILED = 'operation-failed';

    private const array OPERATIONS = [
        self::OPERATION_SPAWN => true,
        self::OPERATION_POLL => true,
        self::OPERATION_TERMINATE => true,
        self::OPERATION_KILL => true,
        self::OPERATION_CLOSE => true,
        self::OPERATION_SHUTDOWN => true,
    ];

    private const array ERRORS = [
        self::ERROR_CHILD_START_FAILED => true,
        self::ERROR_CHILD_INVALID => true,
        self::ERROR_CHILD_RUNNING => true,
        self::ERROR_OPERATION_FAILED => true,
    ];

    /**
     * @param array<int|string, mixed> $payload
     */
    public function encodeRequest(
        int $requestId,
        string $operation,
        array $payload,
    ): string {
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

    /** @return HostRequest */
    public function decodeRequest(string $frame): array
    {
        $value = $this->decode($frame);

        if (
            \array_keys($value) !== [
                'operation',
                'payload',
                'request_id',
                'version',
            ]
            || $value['version'] !== self::VERSION
            || !\is_int($value['request_id'])
            || !\is_string($value['operation'])
            || !\is_array($value['payload'])
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        self::assertRequestId($value['request_id']);
        self::assertOperation($value['operation']);
        self::assertRequestPayload(
            $value['operation'],
            $value['payload'],
        );

        /** @var HostRequest $value */
        return $value;
    }

    /**
     * @param array<int|string, mixed> $payload
     */
    public function encodeOkResponse(
        int $requestId,
        array $payload,
    ): string {
        self::assertRequestId($requestId);
        self::assertResponsePayload($payload);

        return $this->encode([
            'payload' => $payload,
            'request_id' => $requestId,
            'status' => self::STATUS_OK,
            'version' => self::VERSION,
        ]);
    }

    public function encodeErrorResponse(
        int $requestId,
        string $reason,
    ): string {
        self::assertRequestId($requestId);

        if (!isset(self::ERRORS[$reason])) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $this->encode([
            'payload' => [
                'reason' => $reason,
            ],
            'request_id' => $requestId,
            'status' => self::STATUS_ERROR,
            'version' => self::VERSION,
        ]);
    }

    /** @return HostResponse */
    public function decodeResponse(string $frame): array
    {
        $value = $this->decode($frame);

        if (
            \array_keys($value) !== [
                'payload',
                'request_id',
                'status',
                'version',
            ]
            || $value['version'] !== self::VERSION
            || !\is_int($value['request_id'])
            || !\is_string($value['status'])
            || !\is_array($value['payload'])
            || !\in_array(
                $value['status'],
                [self::STATUS_OK, self::STATUS_ERROR],
                true,
            )
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        self::assertRequestId($value['request_id']);
        self::assertResponsePayload($value['payload']);

        if ($value['status'] === self::STATUS_ERROR) {
            if (
                \array_keys($value['payload']) !== ['reason']
                || !\is_string($value['payload']['reason'])
                || !isset(self::ERRORS[$value['payload']['reason']])
            ) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }
        }

        /** @var HostResponse $value */
        return $value;
    }

    public function encodeHandoff(
        int $requestId,
        string $handoffToken,
        string $responseFrame,
    ): string {
        self::assertRequestId($requestId);
        self::assertToken($handoffToken);

        $response = $this->decodeResponse($responseFrame);

        if ($response['request_id'] !== $requestId) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $this->encode([
            'handoff_token' => $handoffToken,
            'request_id' => $requestId,
            'response' => $responseFrame,
            'version' => self::VERSION,
        ]);
    }

    /** @return HostResponse */
    public function decodeHandoff(
        string $frame,
        int $expectedRequestId,
        string $expectedToken,
    ): array {
        self::assertRequestId($expectedRequestId);
        self::assertToken($expectedToken);
        $value = $this->decode($frame);

        if (
            \array_keys($value) !== [
                'handoff_token',
                'request_id',
                'response',
                'version',
            ]
            || $value['version'] !== self::VERSION
            || !\is_int($value['request_id'])
            || !\is_string($value['handoff_token'])
            || !\is_string($value['response'])
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        self::assertRequestId($value['request_id']);
        self::assertToken($value['handoff_token']);

        if (
            $value['request_id'] !== $expectedRequestId
            || !\hash_equals($expectedToken, $value['handoff_token'])
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        $response = $this->decodeResponse($value['response']);

        if ($response['request_id'] !== $expectedRequestId) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $response;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            $frame = StableJsonEncoder::encodeStableMap($value);
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        if (
            $frame === ''
            || \strlen($frame) > self::MAX_FRAME_BYTES
            || !\str_ends_with($frame, "\n")
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        return $frame;
    }

    /** @return array<string, mixed> */
    private function decode(string $frame): array
    {
        if (
            $frame === ''
            || \strlen($frame) > self::MAX_FRAME_BYTES
            || !\str_ends_with($frame, "\n")
        ) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }

        try {
            return StableJsonDecoder::decodeStableMap($frame);
        } catch (\Throwable) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    private static function assertRequestId(int $requestId): void
    {
        if ($requestId < 1) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    private static function assertOperation(string $operation): void
    {
        if (!isset(self::OPERATIONS[$operation])) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    private static function assertToken(string $token): void
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw WorkerLifecycleFailedException::processHostFailed();
        }
    }

    /** @param array<int|string, mixed> $payload */
    private static function assertRequestPayload(
        string $operation,
        array $payload,
    ): void {
        if ($operation === self::OPERATION_SPAWN) {
            if (
                \array_keys($payload) !== [
                    'command',
                    'handoff_port',
                    'handoff_token',
                    'working_directory',
                ]
                || !\is_array($payload['command'])
                || !\array_is_list($payload['command'])
                || $payload['command'] === []
                || !\is_int($payload['handoff_port'])
                || $payload['handoff_port'] < 1
                || $payload['handoff_port'] > 65_535
                || !\is_string($payload['handoff_token'])
                || !\is_string($payload['working_directory'])
                || !self::isSafeAbsoluteOrRelativeDirectory($payload['working_directory'])
            ) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            self::assertToken($payload['handoff_token']);

            foreach ($payload['command'] as $part) {
                if (!self::isSafeCommandPart($part)) {
                    throw WorkerLifecycleFailedException::processHostFailed();
                }
            }

            return;
        }

        if (
            \in_array(
                $operation,
                [
                    self::OPERATION_POLL,
                    self::OPERATION_TERMINATE,
                    self::OPERATION_KILL,
                    self::OPERATION_CLOSE,
                ],
                true,
            )
        ) {
            if (
                \array_keys($payload) !== ['child_id']
                || !\is_string($payload['child_id'])
                || \preg_match('/\Achild-[1-9][0-9]*\z/', $payload['child_id']) !== 1
            ) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }

            return;
        }

        if ($operation === self::OPERATION_SHUTDOWN && $payload === []) {
            return;
        }

        throw WorkerLifecycleFailedException::processHostFailed();
    }

    /** @param array<int|string, mixed> $payload */
    private static function assertResponsePayload(array $payload): void
    {
        foreach ($payload as $value) {
            if (
                !\is_null($value)
                && !\is_bool($value)
                && !\is_int($value)
                && !\is_string($value)
                && !\is_array($value)
            ) {
                throw WorkerLifecycleFailedException::processHostFailed();
            }
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

    private static function isSafeAbsoluteOrRelativeDirectory(
        string $directory,
    ): bool {
        return $directory !== ''
            && \trim($directory) === $directory
            && \strlen($directory) <= 8192
            && \preg_match('/[\x00-\x1F\x7F]/', $directory) !== 1;
    }
}
