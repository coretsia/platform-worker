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
 * Immutable validated worker control request frame.
 *
 * Every request carries the credential of the active supervisor instance.
 * Request identifiers are bounded transport-correlation values only and must
 * never be emitted as metric labels.
 */
final readonly class WorkerControlRequest
{
    public const int VERSION = 1;
    private const string REQUEST_ID_PATTERN = '/\A[A-Za-z0-9._-]{1,64}\z/';

    public function __construct(
        private WorkerControlOperation $operation,
        private string $requestId,
        #[\SensitiveParameter]
        private WorkerControlCredential $credential,
    ) {
        if (\preg_match(self::REQUEST_ID_PATTERN, $requestId) !== 1) {
            throw new \InvalidArgumentException('worker-control-request-id-invalid');
        }
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function operation(): WorkerControlOperation
    {
        return $this->operation;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function credential(): WorkerControlCredential
    {
        return $this->credential;
    }

    /** @return array{version: 1, operation: string, request_id: string, credential: string} */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'operation' => $this->operation->value,
            'request_id' => $this->requestId,
            'credential' => $this->credential->encoded(),
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(
        #[\SensitiveParameter]
        array $value,
    ): self {
        $keys = \array_keys($value);
        \sort($keys, \SORT_STRING);

        if (
            $keys !== [
                'credential',
                'operation',
                'request_id',
                'version',
            ]
            || ($value['version'] ?? null) !== self::VERSION
            || !\is_string($value['operation'] ?? null)
            || !\is_string($value['request_id'] ?? null)
            || !\is_string($value['credential'] ?? null)
        ) {
            throw new \InvalidArgumentException('worker-control-request-invalid');
        }

        return new self(
            operation: WorkerControlOperation::from($value['operation']),
            requestId: $value['request_id'],
            credential: WorkerControlCredential::fromEncoded($value['credential']),
        );
    }
}
