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

namespace Coretsia\Platform\Worker\Tests\Unit;

use Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel;
use Coretsia\Platform\Worker\Communication\WorkerChildReadinessEndpoint;
use Coretsia\Platform\Worker\Process\WorkerChildCommandBuilder;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerChildCommandBuilderTest extends PackageTestCase
{
    public function testBuildsExactCanonicalArgumentOrder(): void
    {
        $endpoint = new WorkerChildReadinessChannel()->createProcessEndpoint();

        try {
            $spec = WorkerSpecFactory::create([
                'workers' => 2,
                'max_requests' => 17,
                'task_type' => 'queue',
                'driver' => 'proc',
            ]);

            $command = new WorkerChildCommandBuilder('var/cache/coretsia')->build(
                baseCommand: [\PHP_BINARY, '/package/bin/coretsia-worker'],
                spec: $spec,
                workerIndex: 1,
                readinessEndpoint: $endpoint,
            );

            self::assertSame(
                [
                    \PHP_BINARY,
                    '/package/bin/coretsia-worker',
                    '--coretsia-worker-index=1',
                    '--coretsia-worker-count=2',
                    '--coretsia-worker-max-requests=17',
                    '--coretsia-worker-task-type=queue',
                    '--coretsia-worker-driver=proc',
                    '--coretsia-worker-artifact-root=var/cache/coretsia',
                    '--coretsia-worker-readiness-port=' . $endpoint->port(),
                    '--coretsia-worker-readiness-token=' . $endpoint->token(),
                ],
                $command,
            );
        } finally {
            $endpoint->close();
        }
    }

    public function testRejectsUnsafeArtifactRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkerChildCommandBuilder('../private');
    }

    public function testRejectsInvalidWorkerIndex(): void
    {
        $endpoint = new WorkerChildReadinessChannel()->createProcessEndpoint();

        try {
            $this->expectException(\InvalidArgumentException::class);

            new WorkerChildCommandBuilder('var/cache/coretsia')->build(
                [\PHP_BINARY, '/package/bin/coretsia-worker'],
                WorkerSpecFactory::create(['workers' => 1]),
                1,
                $endpoint,
            );
        } finally {
            $endpoint->close();
        }
    }

    public function testRejectsControlCharactersInCommandParts(): void
    {
        $endpoint = new WorkerChildReadinessChannel()->createProcessEndpoint();

        try {
            $this->expectException(\InvalidArgumentException::class);

            new WorkerChildCommandBuilder('var/cache/coretsia')->build(
                ["php\n"],
                WorkerSpecFactory::create(),
                0,
                $endpoint,
            );
        } finally {
            $endpoint->close();
        }
    }

    public function testRejectsConnectedStreamReadinessEndpoint(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        $endpoint = WorkerChildReadinessEndpoint::stream($stream);

        try {
            $this->expectException(\InvalidArgumentException::class);

            new WorkerChildCommandBuilder('var/cache/coretsia')->build(
                [\PHP_BINARY, '/package/bin/coretsia-worker'],
                WorkerSpecFactory::create(),
                0,
                $endpoint,
            );
        } finally {
            $endpoint->close();
        }
    }
}
