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

namespace Coretsia\Platform\Worker\Tests\Integration;

use Coretsia\Platform\Worker\Tests\Support\SupervisorIntegrationTestCase;

final class WorkerSupervisorMaxRequestsRecycleTest extends SupervisorIntegrationTestCase
{
    public function testApplicationWorkerMaxRequestsRecyclesSameSlotWithNextGeneration(): void
    {
        [
            'root' => $root,
            'harness' => $harness,
        ] = $this->newHarness(
            workerOverride: [
                'workers' => 1,
                'max_requests' => 3,
                'driver' => 'proc',
            ],
            behavior: [
                'application_worker_max_requests' => [
                    'slot' => 0,
                    'first_generation_only' => true,
                    'exit_delay_ms' => 100,
                ],
            ],
        );

        $summary = $harness->startAndWaitForSummary(
            10_000,
        );

        self::assertSame('running', $summary['status']);
        self::assertSame(1, $summary['worker_count']);
        self::assertSame(1, $summary['ready_worker_count']);

        $credentialBeforeRecycle = self::controlCredential(
            $harness->locatorPath(),
        );

        self::waitUntil(
            static fn (): bool => \count($harness->pidLog()) >= 2 && \count(self::applicationWorkerRuns($root)) >= 1,
            10_000,
            'ApplicationWorker max_requests did not recycle the child.',
        );

        self::waitUntil(
            static function () use ($harness): bool {
                $bytes = @\file_get_contents(
                    $harness->statePath(),
                );

                if (!\is_string($bytes)) {
                    return false;
                }

                try {
                    $state = \json_decode(
                        $bytes,
                        true,
                        512,
                        \JSON_THROW_ON_ERROR,
                    );
                } catch (\Throwable) {
                    return false;
                }

                return \is_array($state)
                    && ($state['status'] ?? null) === 'running'
                    && ($state['ready_worker_count'] ?? null) === 1;
            },
            10_000,
            'Recycled child did not publish readiness.',
        );

        $children = $harness->pidLog();

        self::assertSame(0, $children[0]['slot']);
        self::assertSame(1, $children[0]['generation']);
        self::assertSame(0, $children[1]['slot']);
        self::assertSame(2, $children[1]['generation']);
        self::assertNotSame(
            $children[0]['pid'],
            $children[1]['pid'],
        );

        $runs = self::applicationWorkerRuns($root);

        self::assertCount(1, $runs);
        self::assertSame(
            [
                'generation' => 1,
                'kernel_calls' => 3,
                'max_requests' => 3,
                'pid' => $children[0]['pid'],
                'processed' => 3,
                'slot' => 0,
                'task_receive_calls' => 3,
            ],
            $runs[0],
        );

        $status = self::onlyPayload(
            $harness->invoke('status'),
        );

        self::assertSame('running', $status['status']);
        self::assertSame(1, $status['worker_count']);
        self::assertSame(1, $status['ready_worker_count']);
        self::assertSame(
            $credentialBeforeRecycle,
            self::controlCredential($harness->locatorPath()),
        );

        self::onlyPayload(
            $harness->invoke('stop'),
        );

        self::assertSame(
            0,
            $harness->finishStart()['exit_code'],
        );
    }

    private static function controlCredential(string $path): string
    {
        $bytes = \file_get_contents($path);
        self::assertIsString($bytes);
        $value = \json_decode(
            $bytes,
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($value);
        $credential = $value['control_credential'] ?? null;
        self::assertIsString($credential);

        return $credential;
    }

    /**
     * @return list<array{
     *     generation: int,
     *     kernel_calls: int,
     *     max_requests: int,
     *     pid: int,
     *     processed: int,
     *     slot: int,
     *     task_receive_calls: int
     * }>
     */
    private static function applicationWorkerRuns(
        string $root,
    ): array {
        $path = $root . '/var/tmp/worker-application-runs.jsonl';

        if (!\is_file($path)) {
            return [];
        }

        $bytes = @\file_get_contents($path);

        if (!\is_string($bytes)) {
            return [];
        }

        $records = [];

        foreach (
            \preg_split('/\r?\n/', \trim($bytes)) ?: [] as $line
        ) {
            if ($line === '') {
                continue;
            }

            try {
                $record = \json_decode(
                    $line,
                    true,
                    512,
                    \JSON_THROW_ON_ERROR,
                );
            } catch (\Throwable) {
                return [];
            }

            if (
                !\is_array($record)
                || !\is_int($record['generation'] ?? null)
                || !\is_int($record['kernel_calls'] ?? null)
                || !\is_int($record['max_requests'] ?? null)
                || !\is_int($record['pid'] ?? null)
                || !\is_int($record['processed'] ?? null)
                || !\is_int($record['slot'] ?? null)
                || !\is_int($record['task_receive_calls'] ?? null)
            ) {
                return [];
            }

            $records[] = [
                'generation' => $record['generation'],
                'kernel_calls' => $record['kernel_calls'],
                'max_requests' => $record['max_requests'],
                'pid' => $record['pid'],
                'processed' => $record['processed'],
                'slot' => $record['slot'],
                'task_receive_calls' => $record['task_receive_calls'],
            ];
        }

        return $records;
    }
}
