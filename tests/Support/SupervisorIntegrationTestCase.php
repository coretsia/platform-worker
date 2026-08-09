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

namespace Coretsia\Platform\Worker\Tests\Support;

use Coretsia\Platform\Worker\Runtime\WorkerPoolStatus;

/**
 * Shared process-level assertions for the foreground supervisor integration suite.
 */
abstract class SupervisorIntegrationTestCase extends PackageTestCase
{
    /** @var list<WorkerCommandHarness> */
    private array $workerHarnesses = [];

    protected function tearDown(): void
    {
        foreach (
            \array_reverse($this->workerHarnesses) as $harness
        ) {
            $harness->close();
        }

        $this->workerHarnesses = [];

        parent::tearDown();
    }

    protected function requireSupervisorCapabilities(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(
                \function_exists('proc_open'),
                'proc_open is required by the Windows supervisor harness.',
            );

            self::assertTrue(
                \function_exists('sapi_windows_set_ctrl_handler'),
                'The Windows CLI control handler is required by the supervisor.',
            );

            return;
        }

        foreach (
            [
                'pcntl_fork',
                'pcntl_waitpid',
                'pcntl_signal',
                'pcntl_async_signals',
                'posix_kill',
                'stream_socket_pair',
            ] as $function
        ) {
            self::assertTrue(
                \function_exists($function),
                $function . ' is required by the Unix supervisor harness.',
            );
        }
    }

    /**
     * @param array<string, mixed> $workerOverride
     * @param array<string, mixed> $behavior
     * @return array{root: string, harness: WorkerCommandHarness}
     */
    protected function newHarness(array $workerOverride = [], array $behavior = []): array
    {
        $this->requireSupervisorCapabilities();
        $root = $this->temporaryDirectory('coretsia-worker-supervisor');

        $platformConfig = \PHP_OS_FAMILY === 'Windows'
            ? [
                'driver' => 'proc',
                'control' => ['transport' => 'tcp'],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => self::unusedTcpPort(),
                ],
            ]
            : [
                'driver' => 'pcntl',
                'control' => ['transport' => 'unix'],
            ];

        $platformTimeouts = \PHP_OS_FAMILY === 'Windows'
            ? [
                /*
                 * Windows starts three PHP processes for this harness:
                 * supervisor, process host and worker children. Antivirus and
                 * process initialization must not create test-only timing races.
                 */
                'start_timeout_ms' => 15_000,
                'stop_timeout_ms' => 5_000,
                'force_kill_timeout_ms' => 2_000,
            ]
            : [
                'start_timeout_ms' => 2_000,
                'stop_timeout_ms' => 500,
                'force_kill_timeout_ms' => 250,
            ];

        $harness = new WorkerCommandHarness(
            skeletonRoot: $root,
            workerOverride: WorkerSpecFactory::merge(
                WorkerSpecFactory::merge(
                    [
                        'workers' => 2,
                        'max_requests' => 1000,
                        ...$platformTimeouts,
                    ],
                    $platformConfig,
                ),
                $workerOverride,
            ),
            behavior: $behavior,
        );

        $this->workerHarnesses[] = $harness;

        return [
            'root' => $root,
            'harness' => $harness,
        ];
    }

    /** @return array<string, mixed> */
    protected static function onlyPayload(
        array $result,
        int $expectedExitCode = 0,
    ): array {
        self::assertSame(
            $expectedExitCode,
            $result['exit_code'],
            \json_encode(
                [
                    'messages' => $result['messages'],
                    'stderr' => $result['stderr'],
                ],
                \JSON_UNESCAPED_SLASHES
                | \JSON_UNESCAPED_UNICODE
                | \JSON_PRETTY_PRINT
                | \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertCount(1, $result['messages']);
        self::assertSame('json', $result['messages'][0]['type'] ?? null);
        $payload = $result['messages'][0]['payload'] ?? null;
        self::assertIsArray($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    protected static function onlyError(array $result): array
    {
        self::assertNotSame(0, $result['exit_code']);
        self::assertCount(1, $result['messages']);
        self::assertSame('error', $result['messages'][0]['type'] ?? null);

        return $result['messages'][0];
    }

    protected static function waitForStateStatus(
        WorkerCommandHarness $harness,
        WorkerPoolStatus $status,
        int $timeoutMs = 3000,
    ): void {
        self::waitUntil(
            static function () use ($harness, $status): bool {
                if (!\is_file($harness->statePath())) {
                    return false;
                }

                $bytes = \file_get_contents($harness->statePath());
                if (!\is_string($bytes)) {
                    return false;
                }

                $state = \json_decode($bytes, true);

                return \is_array($state)
                    && ($state['status'] ?? null) === $status->value;
            },
            $timeoutMs,
            'Timed out waiting for worker state ' . $status->value,
        );
    }

    protected static function assertRuntimeArtifactsCleaned(
        WorkerCommandHarness $harness,
    ): void {
        self::assertFileDoesNotExist($harness->statePath());
        self::assertFileDoesNotExist($harness->stopPath());
        self::assertFileDoesNotExist($harness->socketPath());
        self::assertFileDoesNotExist($harness->locatorPath());
        self::assertFileDoesNotExist($harness->locatorTemporaryPath());

        $directory = \dirname($harness->lockPath());
        if (!\is_dir($directory)) {
            \mkdir($directory, 0777, true);
        }

        $handle = \fopen($harness->lockPath(), 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(\flock($handle, \LOCK_EX | \LOCK_NB));
        \flock($handle, \LOCK_UN);
        \fclose($handle);
    }

    protected static function assertLoggedChildrenExited(
        WorkerCommandHarness $harness,
    ): void {
        foreach ($harness->pidLog() as $record) {
            self::waitUntil(
                static fn (): bool => !self::processExists($record['pid']),
                3000,
                'Worker child remained alive: ' . $record['pid'],
            );
        }
    }
}
