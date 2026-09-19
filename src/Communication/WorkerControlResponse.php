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

/**
 * Immutable validated worker control response frame.
 *
 * A stop response uses the terminal `stopped` status and is emitted only after
 * child reap, runtime cleanup, listener closure, and lifecycle-lock release.
 * Responses never contain or expose the request credential.
 */
final readonly class WorkerControlResponse
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_STOPPED = 'stopped';
    public const string STATUS_ERROR = 'error';

    /** @param array<string, mixed>|null $result */
    private function __construct(
        private string $requestId,
        private string $status,
        private ?array $result,
        private ?string $error,
    ) {
        if (\preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $requestId) !== 1 || !\in_array(
            $status,
            [self::STATUS_OK, self::STATUS_STOPPED, self::STATUS_ERROR],
            true
        )) {
            throw new \InvalidArgumentException('worker-control-response-invalid');
        }
        if ($status === self::STATUS_ERROR) {
            if ($result !== null || $error !== 'worker-communication-failed') {
                throw new \InvalidArgumentException('worker-control-response-invalid');
            }
        } elseif ($result === null || $error !== null) {
            throw new \InvalidArgumentException('worker-control-response-invalid');
        }
    }

    /** @param array<string, mixed> $result */
    public static function ok(string $requestId, array $result): self
    {
        return new self($requestId, self::STATUS_OK, $result, null);
    }

    /** @param array<string, mixed> $result */
    public static function stopped(string $requestId, array $result): self
    {
        return new self($requestId, self::STATUS_STOPPED, $result, null);
    }

    public static function error(string $requestId): self
    {
        return new self($requestId, self::STATUS_ERROR, null, 'worker-communication-failed');
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array<string, mixed>|null */
    public function result(): ?array
    {
        return $this->result;
    }

    public function errorReason(): ?string
    {
        return $this->error;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->status === self::STATUS_ERROR) {
            return [
                'version' => WorkerControlRequest::VERSION,
                'request_id' => $this->requestId,
                'status' => $this->status,
                'error' => $this->error,
            ];
        }
        return [
            'version' => WorkerControlRequest::VERSION,
            'request_id' => $this->requestId,
            'status' => $this->status,
            'result' => $this->result,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            ($value['version'] ?? null) !== WorkerControlRequest::VERSION
            || !\is_string($value['request_id'] ?? null)
            || !\is_string($value['status'] ?? null)
        ) {
            throw new \InvalidArgumentException('worker-control-response-invalid');
        }

        if ($value['status'] === self::STATUS_ERROR) {
            $keys = \array_keys($value);
            \sort($keys, \SORT_STRING);
            if (
                $keys !== ['error', 'request_id', 'status', 'version']
                || !\is_string($value['error'] ?? null)
            ) {
                throw new \InvalidArgumentException('worker-control-response-invalid');
            }
            return new self($value['request_id'], self::STATUS_ERROR, null, $value['error']);
        }

        $keys = \array_keys($value);
        \sort($keys, \SORT_STRING);
        if (
            $keys !== ['request_id', 'result', 'status', 'version']
            || !\is_array($value['result'] ?? null)
            || \array_is_list($value['result'])
        ) {
            throw new \InvalidArgumentException('worker-control-response-invalid');
        }
        return new self($value['request_id'], $value['status'], $value['result'], null);
    }
}
