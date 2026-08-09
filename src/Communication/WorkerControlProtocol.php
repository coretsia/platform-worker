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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;

/**
 * Encodes and decodes the versioned, line-delimited worker control protocol.
 *
 * Frames use StableJsonEncoder and StableJsonDecoder, have an exact bounded
 * schema, and reject unknown keys, unsupported versions, and oversized input.
 * Version 1 requests contain one supervisor-instance credential; responses
 * never contain or expose that credential.
 */
final readonly class WorkerControlProtocol
{
    public const int MAX_FRAME_BYTES = 4096;

    public function __construct(
        private StableJsonEncoder $encoder,
        private StableJsonDecoder $decoder,
    ) {
    }

    public function encodeRequest(
        #[\SensitiveParameter]
        WorkerControlRequest $request,
    ): string {
        return $this->encode($request->toArray());
    }

    public function decodeRequest(
        #[\SensitiveParameter]
        string $frame,
    ): WorkerControlRequest {
        try {
            return WorkerControlRequest::fromArray($this->decode($frame));
        } catch (\Throwable) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }

    public function encodeResponse(WorkerControlResponse $response): string
    {
        return $this->encode($response->toArray());
    }

    public function decodeResponse(string $frame): WorkerControlResponse
    {
        try {
            return WorkerControlResponse::fromArray($this->decode($frame));
        } catch (\Throwable) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }

    /** @param array<string, mixed> $value */
    private function encode(
        #[\SensitiveParameter]
        array $value,
    ): string {
        try {
            $frame = $this->encoder->encodeMap($value);
        } catch (\Throwable) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
        if (
            \strlen($frame) > self::MAX_FRAME_BYTES
            || !\str_ends_with($frame, "\n")
        ) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
        return $frame;
    }

    /** @return array<string, mixed> */
    private function decode(
        #[\SensitiveParameter]
        string $frame,
    ): array {
        if (
            $frame === ''
            || \strlen($frame) > self::MAX_FRAME_BYTES
            || !\str_ends_with($frame, "\n")
        ) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
        try {
            return $this->decoder->decodeMap($frame);
        } catch (\Throwable) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }
    }
}
