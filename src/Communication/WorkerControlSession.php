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
 * Represents one accepted and decoded supervisor control connection.
 *
 * The session owns no lifecycle policy; it only couples the live connection
 * resource with its validated request.
 */
final readonly class WorkerControlSession
{
    public function __construct(
        private mixed $connection,
        #[\SensitiveParameter]
        private WorkerControlRequest $request,
    ) {
        if (!\is_resource($connection)) {
            throw new \InvalidArgumentException('worker-control-session-invalid');
        }
    }

    public function connection(): mixed
    {
        return $this->connection;
    }

    public function request(): WorkerControlRequest
    {
        return $this->request;
    }
}
