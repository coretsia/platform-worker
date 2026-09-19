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

use Coretsia\Platform\Worker\Internal\WorkerLoopbackListener;

/**
 * Trusted-parent authentication endpoint for one child bootstrap.
 *
 * @internal
 */
final class WorkerProcessBootstrapEndpoint
{
    private const int MAX_PENDING_CANDIDATES = 8;
    private const int CANDIDATE_IDLE_NS = 250_000_000;
    private const int LOOP_TICK_US = 50_000;

    private mixed $listener;
    private ?string $credential;

    /**
     * @var array<int, array{
     *     stream: resource,
     *     buffer: string,
     *     accepted_ns: int,
     *     last_progress_ns: int
     * }>
     */
    private array $candidates = [];

    private function __construct(
        private readonly WorkerProcessBootstrapProtocol $protocol,
        private readonly string $role,
        mixed $listener,
        private readonly int $port,
        string $credential,
    ) {
        $this->listener = $listener;
        $this->credential = $credential;
    }

    public static function create(
        WorkerProcessBootstrapProtocol $protocol,
        string $role,
    ): self {
        if (!\in_array(
            $role,
            [
                WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                WorkerProcessBootstrapProtocol::ROLE_PROC_HOST,
            ],
            true,
        )) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        $listener = WorkerLoopbackListener::create();

        if (!\is_resource($listener) || !@\stream_set_blocking($listener, false)) {
            if (\is_resource($listener)) {
                @\fclose($listener);
            }

            throw WorkerProcessBootstrapFailure::failed();
        }

        $name = @\stream_socket_get_name($listener, false);
        if (!\is_string($name) || \preg_match('/:(\d+)\z/', $name, $match) !== 1) {
            @\fclose($listener);
            throw WorkerProcessBootstrapFailure::failed();
        }

        $port = (int)$match[1];
        if ($port < 1 || $port > 65_535) {
            @\fclose($listener);
            throw WorkerProcessBootstrapFailure::failed();
        }

        try {
            $credential = \bin2hex(\random_bytes(32));
        } catch (\Throwable) {
            @\fclose($listener);
            throw WorkerProcessBootstrapFailure::failed();
        }

        return new self($protocol, $role, $listener, $port, $credential);
    }

    public function launchFrame(int $timeoutMs, ?string $driver = null): string
    {
        $credential = $this->credential;
        if (!\is_resource($this->listener) || !\is_string($credential)) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        if ($this->role === WorkerProcessBootstrapProtocol::ROLE_GUARDIAN) {
            if (!\is_string($driver)) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            return $this->protocol->encodeGuardianLaunch(
                port: $this->port,
                credential: $credential,
                timeoutMs: $timeoutMs,
                driver: $driver,
            );
        }

        if ($driver !== null) {
            throw WorkerProcessBootstrapFailure::failed();
        }

        return $this->protocol->encodeProcHostLaunch(
            port: $this->port,
            credential: $credential,
            timeoutMs: $timeoutMs,
        );
    }

    /**
     * @param null|\Closure(): bool $childRunning
     * @return resource
     */
    public function authenticate(
        int $deadlineNs,
        ?\Closure $childRunning = null,
    ): mixed {
        while (true) {
            if (\hrtime(true) >= $deadlineNs) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            if ($childRunning instanceof \Closure && !$childRunning()) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            $this->evictExpiredCandidates();

            $listener = $this->listener;
            if (!\is_resource($listener) || !\is_string($this->credential)) {
                throw WorkerProcessBootstrapFailure::failed();
            }

            $read = [$listener];
            foreach ($this->candidates as $candidate) {
                $read[] = $candidate['stream'];
            }

            $write = null;
            $except = null;
            $remainingUs = (int)\max(
                1,
                \min(
                    self::LOOP_TICK_US,
                    \intdiv(\max(1, $deadlineNs - \hrtime(true)), 1_000),
                ),
            );

            $selected = @\stream_select(
                $read,
                $write,
                $except,
                0,
                $remainingUs,
            );

            if ($selected === false) {
                continue;
            }

            if ($selected === 0) {
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $listener) {
                    $this->acceptCandidates();
                    continue;
                }

                $id = \get_resource_id($stream);
                if (!isset($this->candidates[$id])) {
                    continue;
                }

                $authenticated = $this->consumeCandidate($id);
                if (\is_resource($authenticated)) {
                    $this->completeAuthentication($authenticated);
                    return $authenticated;
                }
            }
        }
    }

    public function close(): void
    {
        foreach (\array_keys($this->candidates) as $id) {
            $this->closeCandidate($id);
        }

        if (\is_resource($this->listener)) {
            @\fclose($this->listener);
        }

        $this->listener = null;
        $this->credential = null;
    }

    private function acceptCandidates(): void
    {
        $listener = $this->listener;
        if (!\is_resource($listener)) {
            return;
        }

        while (true) {
            $candidate = @\stream_socket_accept($listener, 0);
            if (!\is_resource($candidate)) {
                return;
            }

            if (!@\stream_set_blocking($candidate, false)) {
                @\fclose($candidate);
                continue;
            }

            if (\count($this->candidates) >= self::MAX_PENDING_CANDIDATES) {
                $this->evictOldestCandidate();
            }

            $nowNs = \hrtime(true);
            $id = \get_resource_id($candidate);
            $this->candidates[$id] = [
                'stream' => $candidate,
                'buffer' => '',
                'accepted_ns' => $nowNs,
                'last_progress_ns' => $nowNs,
            ];
        }
    }

    /** @return resource|null */
    private function consumeCandidate(int $id): mixed
    {
        $entry = $this->candidates[$id] ?? null;
        if (!\is_array($entry) || !\is_resource($entry['stream'])) {
            $this->closeCandidate($id);
            return null;
        }

        $remaining = WorkerProcessBootstrapProtocol::MAX_AUTH_FRAME_BYTES
            + 1
            - \strlen($entry['buffer']);

        if ($remaining < 1) {
            $this->closeCandidate($id);
            return null;
        }

        $chunk = @\fread($entry['stream'], $remaining);
        if ($chunk === false) {
            $this->closeCandidate($id);
            return null;
        }

        if ($chunk === '') {
            if (@\feof($entry['stream'])) {
                $this->closeCandidate($id);
            }
            return null;
        }

        $entry['buffer'] .= $chunk;
        $entry['last_progress_ns'] = \hrtime(true);
        $this->candidates[$id] = $entry;

        if (\strlen($entry['buffer']) > WorkerProcessBootstrapProtocol::MAX_AUTH_FRAME_BYTES) {
            $this->closeCandidate($id);
            return null;
        }

        $newline = \strpos($entry['buffer'], "\n");
        if ($newline === false) {
            return null;
        }

        if ($newline !== \strlen($entry['buffer']) - 1) {
            $this->closeCandidate($id);
            return null;
        }

        try {
            $candidateCredential = $this->protocol->decodeAuthentication(
                $entry['buffer'],
                $this->role,
            );
        } catch (\Throwable) {
            $this->closeCandidate($id);
            return null;
        }

        $credential = $this->credential;
        if (!\is_string($credential) || !\hash_equals($credential, $candidateCredential)) {
            $this->closeCandidate($id);
            return null;
        }

        unset($this->candidates[$id]);
        return $entry['stream'];
    }

    private function completeAuthentication(mixed $authenticated): void
    {
        foreach (\array_keys($this->candidates) as $id) {
            $this->closeCandidate($id);
        }

        if (\is_resource($this->listener)) {
            @\fclose($this->listener);
        }

        $this->listener = null;
        $this->credential = null;

        if (!\is_resource($authenticated) || !@\stream_set_blocking($authenticated, false)) {
            if (\is_resource($authenticated)) {
                @\fclose($authenticated);
            }
            throw WorkerProcessBootstrapFailure::failed();
        }
    }

    private function evictExpiredCandidates(): void
    {
        $cutoffNs = \hrtime(true) - self::CANDIDATE_IDLE_NS;

        foreach ($this->candidates as $id => $candidate) {
            if ($candidate['last_progress_ns'] <= $cutoffNs) {
                $this->closeCandidate($id);
            }
        }
    }

    private function evictOldestCandidate(): void
    {
        $oldestId = null;
        $oldestAcceptedNs = \PHP_INT_MAX;

        foreach ($this->candidates as $id => $candidate) {
            if ($candidate['accepted_ns'] < $oldestAcceptedNs) {
                $oldestId = $id;
                $oldestAcceptedNs = $candidate['accepted_ns'];
            }
        }

        if (\is_int($oldestId)) {
            $this->closeCandidate($oldestId);
        }
    }

    private function closeCandidate(int $id): void
    {
        $candidate = $this->candidates[$id]['stream'] ?? null;
        if (\is_resource($candidate)) {
            @\fclose($candidate);
        }
        unset($this->candidates[$id]);
    }
}
