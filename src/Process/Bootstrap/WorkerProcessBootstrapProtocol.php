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

namespace Coretsia\Platform\Worker\Process\Bootstrap;

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;

/**
 * Exact, bounded protocol for one-shot Worker child-process bootstrap.
 *
 * @internal
 */
final class WorkerProcessBootstrapProtocol
{
    public const int VERSION = 1;
    public const int MAX_LAUNCH_FRAME_BYTES = 1024;
    public const int MAX_AUTH_FRAME_BYTES = 512;
    public const int MAX_TIMEOUT_MS = 86_400_000;

    public const string ROLE_GUARDIAN = 'guardian';
    public const string ROLE_PROC_HOST = 'proc-host';

    /**
     * @return array{driver: 'pcntl'|'proc', timeout_ms: int<1, 86400000>, port: int<1, 65535>, credential: non-empty-string, role: 'guardian'}
     */
    public function decodeGuardianLaunch(string $frame): array
    {
        $value = $this->decodeCanonicalMap($frame, self::MAX_LAUNCH_FRAME_BYTES);

        if (
            \array_keys($value) !== [
                'credential',
                'driver',
                'port',
                'role',
                'timeout_ms',
                'version',
            ]
            || $value['version'] !== self::VERSION
            || $value['role'] !== self::ROLE_GUARDIAN
            || !\is_string($value['driver'])
            || !\in_array($value['driver'], ['pcntl', 'proc'], true)
            || !\is_int($value['port'])
            || !\is_string($value['credential'])
            || !\is_int($value['timeout_ms'])
        ) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        self::assertPort($value['port']);
        self::assertCredential($value['credential']);
        self::assertTimeout($value['timeout_ms']);

        /** @var array{driver: 'pcntl'|'proc', timeout_ms: int<1, 86400000>, port: int<1, 65535>, credential: non-empty-string, role: 'guardian'} $value */
        return $value;
    }

    /**
     * @return array{timeout_ms: int<1, 86400000>, port: int<1, 65535>, credential: non-empty-string, role: 'proc-host'}
     */
    public function decodeProcHostLaunch(string $frame): array
    {
        $value = $this->decodeCanonicalMap($frame, self::MAX_LAUNCH_FRAME_BYTES);

        if (
            \array_keys($value) !== [
                'credential',
                'port',
                'role',
                'timeout_ms',
                'version',
            ]
            || $value['version'] !== self::VERSION
            || $value['role'] !== self::ROLE_PROC_HOST
            || !\is_int($value['port'])
            || !\is_string($value['credential'])
            || !\is_int($value['timeout_ms'])
        ) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        self::assertPort($value['port']);
        self::assertCredential($value['credential']);
        self::assertTimeout($value['timeout_ms']);

        /** @var array{timeout_ms: int<1, 86400000>, port: int<1, 65535>, credential: non-empty-string, role: 'proc-host'} $value */
        return $value;
    }

    public function encodeGuardianLaunch(
        int $port,
        string $credential,
        int $timeoutMs,
        string $driver,
    ): string {
        if (!\in_array($driver, ['pcntl', 'proc'], true)) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        self::assertPort($port);
        self::assertCredential($credential);
        self::assertTimeout($timeoutMs);

        return $this->encodeBounded([
            'credential' => $credential,
            'driver' => $driver,
            'port' => $port,
            'role' => self::ROLE_GUARDIAN,
            'timeout_ms' => $timeoutMs,
            'version' => self::VERSION,
        ], self::MAX_LAUNCH_FRAME_BYTES);
    }

    public function encodeProcHostLaunch(
        int $port,
        string $credential,
        int $timeoutMs,
    ): string {
        self::assertPort($port);
        self::assertCredential($credential);
        self::assertTimeout($timeoutMs);

        return $this->encodeBounded([
            'credential' => $credential,
            'port' => $port,
            'role' => self::ROLE_PROC_HOST,
            'timeout_ms' => $timeoutMs,
            'version' => self::VERSION,
        ], self::MAX_LAUNCH_FRAME_BYTES);
    }

    public function encodeAuthentication(string $role, string $credential): string
    {
        self::assertRole($role);
        self::assertCredential($credential);

        return $this->encodeBounded([
            'credential' => $credential,
            'role' => $role,
            'version' => self::VERSION,
        ], self::MAX_AUTH_FRAME_BYTES);
    }

    public function decodeAuthentication(string $frame, string $expectedRole): string
    {
        self::assertRole($expectedRole);
        $value = $this->decodeCanonicalMap($frame, self::MAX_AUTH_FRAME_BYTES);

        if (
            \array_keys($value) !== ['credential', 'role', 'version']
            || $value['version'] !== self::VERSION
            || $value['role'] !== $expectedRole
            || !\is_string($value['credential'])
        ) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        self::assertCredential($value['credential']);

        return $value['credential'];
    }

    /** @param array<string, mixed> $value */
    private function encodeBounded(array $value, int $maxBytes): string
    {
        try {
            $frame = StableJsonEncoder::encodeStableMap($value);
        } catch (\Throwable) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        if ($frame === '' || \strlen($frame) > $maxBytes || !\str_ends_with($frame, "\n")) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        return $frame;
    }

    /** @return array<string, mixed> */
    private function decodeCanonicalMap(string $frame, int $maxBytes): array
    {
        if (
            $frame === ''
            || \strlen($frame) > $maxBytes
            || !\str_ends_with($frame, "\n")
            || \substr_count($frame, "\n") !== 1
        ) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        try {
            $value = StableJsonDecoder::decodeStableMap($frame);
            $canonical = StableJsonEncoder::encodeStableMap($value);
        } catch (\Throwable) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        if ($canonical !== $frame) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        return $value;
    }

    private static function assertRole(string $role): void
    {
        if (!\in_array($role, [self::ROLE_GUARDIAN, self::ROLE_PROC_HOST], true)) {
            throw WorkerProcessBootstrapFailure::failed();
        }
    }

    private static function assertPort(int $port): void
    {
        if ($port < 1 || $port > 65_535) {
            throw WorkerProcessBootstrapFailure::failed();
        }
    }

    private static function assertCredential(string $credential): void
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/', $credential) !== 1) {
            throw WorkerProcessBootstrapFailure::failed();
        }
    }

    private static function assertTimeout(int $timeoutMs): void
    {
        if ($timeoutMs < 1 || $timeoutMs > self::MAX_TIMEOUT_MS) {
            throw WorkerProcessBootstrapFailure::failed();
        }
    }
}
