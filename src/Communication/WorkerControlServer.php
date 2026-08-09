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
use Coretsia\Platform\Worker\Runtime\WorkerHealthState;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

/**
 * Owns the supervisor-side listening endpoint and authenticated control sessions.
 *
 * This class performs transport, protocol, and supervisor-instance credential
 * verification only. Lifecycle decisions, health semantics, and shutdown
 * orchestration remain owned by WorkerSupervisor.
 */
final class WorkerControlServer
{
    /** @var resource|null */
    private mixed $server = null;
    private ?WorkerPoolSpec $spec = null;
    private ?WorkerControlCredential $credential = null;

    public function __construct(
        private readonly WorkerControlTransport $transport,
        private readonly WorkerControlProtocol $protocol,
    ) {
    }

    public function listen(
        WorkerPoolSpec $spec,
        #[\SensitiveParameter]
        WorkerControlCredential $credential,
    ): void {
        if (\is_resource($this->server)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $this->server = $this->transport->listen($spec);
        $this->spec = $spec;
        $this->credential = $credential;
    }

    public function accept(int $timeoutMs): ?WorkerControlSession
    {
        if (!\is_resource($this->server)) {
            throw WorkerCommunicationFailedException::communicationFailed();
        }

        $connection = $this->transport->accept($this->server, $timeoutMs);

        if ($connection === null) {
            return null;
        }

        try {
            $request = $this->protocol->decodeRequest(
                $this->transport->readFrame(
                    $connection,
                    WorkerControlProtocol::MAX_FRAME_BYTES,
                ),
            );

            $credential = $this->credential;

            if (
                $credential === null
                || !$credential->matches($request->credential())
            ) {
                $this->transport->close($connection);

                return null;
            }

            return new WorkerControlSession($connection, $request);
        } catch (\Throwable) {
            $this->transport->close($connection);

            return null;
        }
    }

    public function respondState(
        #[\SensitiveParameter]
        WorkerControlSession $session,
        WorkerPoolState $state,
    ): void {
        $this->respond(
            $session,
            WorkerControlResponse::ok(
                $session->request()->requestId(),
                ['state' => $state->toArray()],
            ),
        );
    }

    public function respondHealth(
        #[\SensitiveParameter]
        WorkerControlSession $session,
        WorkerHealthState $health,
    ): void {
        $this->respond(
            $session,
            WorkerControlResponse::ok(
                $session->request()->requestId(),
                ['health' => $health->toArray()],
            ),
        );
    }

    public function respondStopped(
        #[\SensitiveParameter]
        WorkerControlSession $session,
        WorkerPoolState $state,
    ): void {
        $this->respond(
            $session,
            WorkerControlResponse::stopped(
                $session->request()->requestId(),
                ['state' => $state->toArray()],
            ),
        );
    }

    public function closeSession(
        #[\SensitiveParameter]
        WorkerControlSession $session,
    ): void {
        $this->transport->close($session->connection());
    }

    public function closeListener(): void
    {
        if (\is_resource($this->server)) {
            $this->transport->close($this->server);
        }

        $this->server = null;
        $this->credential = null;

        if ($this->spec !== null) {
            $this->transport->cleanup($this->spec);
        }
    }

    public function close(): void
    {
        $this->closeListener();
        $this->spec = null;
        $this->credential = null;
    }

    public function reset(): void
    {
        $this->server = null;
        $this->spec = null;
        $this->credential = null;
    }

    private function respond(
        #[\SensitiveParameter]
        WorkerControlSession $session,
        WorkerControlResponse $response,
    ): void {
        $this->transport->writeFrame(
            $session->connection(),
            $this->protocol->encodeResponse($response),
        );
    }
}
