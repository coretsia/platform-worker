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
use Coretsia\Platform\Worker\Tests\Support\WorkerCommandHarness;

final class WorkerSupervisorCrashRecoveryTest extends SupervisorIntegrationTestCase
{
    public function testSupervisorOnlyDeathIsContainedByGuardianAndAllowsReplacement(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(
                \defined('SIGKILL'),
                'POSIX whole-process-tree crash coverage requires SIGKILL.',
            );
            self::assertTrue(
                \function_exists('posix_kill'),
                'POSIX whole-process-tree crash coverage requires posix_kill().',
            );
            self::assertTrue(
                \function_exists('posix_setsid'),
                'POSIX whole-process-tree crash coverage requires posix_setsid().',
            );
            self::assertTrue(
                \function_exists('posix_getpgid'),
                'POSIX whole-process-tree crash coverage requires posix_getpgid().',
            );
        }

        ['root' => $root, 'harness' => $crashed] = $this->newHarness();

        $firstStart = $crashed->startAndWaitForSummary();
        self::assertSame('running', $firstStart['status']);

        self::waitUntil(
            static fn (): bool => \count($crashed->pidLog()) >= 2,
            3_000,
            'Worker PID log was not populated.',
        );
        $oldPids = \array_values(
            \array_unique(
                \array_map(
                    static fn (array $record): int => $record['pid'],
                    $crashed->pidLog(),
                )
            )
        );
        self::assertNotSame([], $oldPids);
        self::assertFalse($crashed->lifecycleLockAvailable());

        $staleState = self::readJsonMap($crashed->statePath());
        $staleLocator = self::readJsonMap($crashed->locatorPath());
        $staleCredential = $staleLocator['control_credential'] ?? null;
        self::assertIsString($staleCredential);

        $crashed->crashSupervisorOnly();
        $crashed->waitForLoggedChildrenExit($oldPids, 10_000);
        self::waitUntil(
            static fn (): bool => $crashed->lifecycleLockAvailable(),
            10_000,
            'Guardian did not release generation fence after supervisor-only death.',
        );

        self::assertSame($staleState, self::readJsonMap($crashed->statePath()));
        self::assertSame($staleLocator, self::readJsonMap($crashed->locatorPath()));
        self::assertSame(
            'CORETSIA_WORKER_NOT_RUNNING',
            self::onlyError($crashed->invoke('status'))['code'] ?? null,
        );

        $replacement = new WorkerCommandHarness(
            skeletonRoot: $root,
            workerOverride: $crashed->workerConfig(),
        );

        try {
            $replacementStart = $replacement->startAndWaitForSummary();
            self::assertSame('running', $replacementStart['status']);
            $freshLocator = self::readJsonMap($replacement->locatorPath());
            self::assertNotSame($staleCredential, $freshLocator['control_credential'] ?? null);

            foreach ($oldPids as $oldPid) {
                self::assertFalse(
                    self::processExists($oldPid),
                    'Old worker generation survived replacement startup.',
                );
            }

            self::assertSame('stopped', self::onlyPayload($replacement->invoke('stop'))['status']);
            self::assertSame(0, $replacement->finishStart()['exit_code']);
            self::assertRuntimeArtifactsCleaned($replacement);
        } finally {
            $replacement->close();
        }
    }

    public function testAbruptProcessTreeDeathAllowsDeterministicReplacement(): void
    {
        ['root' => $root, 'harness' => $crashed] = $this->newHarness();

        $firstStart = $crashed->startAndWaitForSummary();

        self::assertSame('running', $firstStart['status']);
        self::assertFileExists($crashed->statePath());
        self::assertFileExists($crashed->locatorPath());

        if (($firstStart['control_transport'] ?? null) === 'unix') {
            self::assertFileExists($crashed->socketPath());
        }

        $staleState = self::readJsonMap($crashed->statePath());
        $staleLocator = self::readJsonMap($crashed->locatorPath());
        $staleCredential = $staleLocator['control_credential'] ?? null;

        self::assertIsString($staleCredential);
        self::assertSame($firstStart['pid'], $staleState['pid'] ?? null);
        self::assertFalse(
            self::lifecycleLockAvailable($crashed),
            'A running worker generation must have its canonical generation fence held by the guardian.',
        );

        /*
         * Model catastrophic externally-owned process-tree termination. The
         * termination primitive does not guarantee one cross-platform ordering
         * between the supervisor and its descendants, so no graceful-cleanup or
         * stale-artifact outcome is assumed here.
         */
        $crashed->crashStartProcessTree();

        self::waitUntil(
            static fn (): bool => !self::processExists($firstStart['pid']),
            3_000,
            'Abruptly terminated supervisor process remained alive.',
        );
        self::waitForLifecycleLockRelease($crashed);

        /*
         * Catastrophic externally-owned tree termination may either leave the
         * supervisor-owned runtime artifacts stale or let the supervisor remove
         * some of them before the complete tree is gone. A free canonical
         * generation fence is authoritative in both cases. Any artifact that
         * remains must still be the exact pre-crash snapshot.
         */
        $postCrashState = self::readOptionalJsonMap(
            $crashed->statePath(),
        );
        $postCrashLocator = self::readOptionalJsonMap(
            $crashed->locatorPath(),
        );

        if ($postCrashState !== null) {
            self::assertSame($staleState, $postCrashState);
        }

        if ($postCrashLocator !== null) {
            self::assertSame($staleLocator, $postCrashLocator);
        }

        if (($firstStart['control_transport'] ?? null) === 'unix') {
            self::assertFileExists($crashed->socketPath());
        }

        $notRunning = self::onlyError($crashed->invoke('status'));

        self::assertSame(
            'CORETSIA_WORKER_NOT_RUNNING',
            $notRunning['code'] ?? null,
        );
        self::assertSame(
            $postCrashLocator,
            self::readOptionalJsonMap($crashed->locatorPath()),
            'status must not mutate lifecycle locator state when the canonical generation fence is free.',
        );

        $replacement = new WorkerCommandHarness(
            skeletonRoot: $root,
            workerOverride: $crashed->workerConfig(),
        );

        try {
            $replacementStart = $replacement->startAndWaitForSummary();
            $freshState = self::readJsonMap($replacement->statePath());
            $freshLocator = self::readJsonMap($replacement->locatorPath());
            $freshCredential = $freshLocator['control_credential'] ?? null;

            self::assertSame('running', $replacementStart['status']);
            self::assertSame(
                $replacementStart['pid'],
                $freshState['pid'] ?? null,
            );
            self::assertIsString($freshCredential);
            self::assertNotSame(
                $staleCredential,
                $freshCredential,
                'Replacement startup must publish a new supervisor-instance locator.',
            );
            self::assertFileDoesNotExist(
                $replacement->locatorTemporaryPath(),
            );

            $status = self::onlyPayload(
                $replacement->invoke('status'),
            );

            self::assertSame('running', $status['status']);
            self::assertSame(
                $replacementStart['pid'],
                $status['pid'],
            );

            $stop = self::onlyPayload(
                $replacement->invoke('stop'),
            );

            self::assertSame('stopped', $stop['status']);

            $finished = $replacement->finishStart();

            self::assertSame(
                0,
                $finished['exit_code'],
                $finished['stderr'],
            );

            self::assertRuntimeArtifactsCleaned($replacement);
        } finally {
            $replacement->close();
        }
    }

    /** @return null|array<string, mixed> */
    private static function readOptionalJsonMap(string $path): ?array
    {
        if (!\is_file($path)) {
            return null;
        }

        return self::readJsonMap($path);
    }

    /** @return array<string, mixed> */
    private static function readJsonMap(string $path): array
    {
        $bytes = @\file_get_contents($path);
        self::assertIsString($bytes);

        $value = \json_decode(
            $bytes,
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($value);
        self::assertFalse(\array_is_list($value));

        return $value;
    }

    private static function waitForLifecycleLockRelease(
        WorkerCommandHarness $harness,
    ): void {
        self::waitUntil(
            static fn (): bool => self::lifecycleLockAvailable($harness),
            3_000,
            'Lifecycle lock remained held after abrupt supervisor death.',
        );
    }

    private static function lifecycleLockAvailable(
        WorkerCommandHarness $harness,
    ): bool {
        $directory = \dirname($harness->lockPath());

        if (
            !\is_dir($directory)
            && !@\mkdir($directory, 0777, true)
            && !\is_dir($directory)
        ) {
            return false;
        }

        $handle = @\fopen(
            $harness->lockPath(),
            \PHP_OS_FAMILY === 'Windows'
                ? 'c+b'
                : 'c+be',
        );

        if (!\is_resource($handle)) {
            return false;
        }

        try {
            if (!@\flock($handle, \LOCK_EX | \LOCK_NB)) {
                return false;
            }

            @\flock($handle, \LOCK_UN);

            return true;
        } finally {
            @\fclose($handle);
        }
    }
}
