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

use Coretsia\Platform\Worker\Exception\WorkerAlreadyRunningException;
use Coretsia\Platform\Worker\Exception\WorkerLifecycleFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapFailure;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianClient;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLock;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerProcessBootstrapFailureContainmentTest extends PackageTestCase
{
    public function testPreAuthenticationLauncherFailureIsBoundedAndAtomic(): void
    {
        $protocol = new WorkerProcessBootstrapProtocol();
        $launcher = new WorkerProcessBootstrapLauncher($protocol);
        $start = \hrtime(true);

        try {
            $launcher->launchAuthenticatedChild(
                command: [
                    \PHP_BINARY,
                    self::packageRoot() . '/tests/Fixtures/process-bootstrap-fixture.php',
                    'guardian-silent',
                ],
                workingDirectory: self::frameworkRoot(),
                role: WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                timeoutMs: 300,
                driver: 'proc',
            );
            self::fail('Silent child must not complete bootstrap authentication.');
        } catch (\Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapFailure) {
            $elapsedMs = (\hrtime(true) - $start) / 1_000_000;
            self::assertGreaterThanOrEqual(250, $elapsedMs);
            self::assertLessThan(
                2_000,
                $elapsedMs,
                'Pre-authentication direct-child cleanup must remain bounded.',
            );
        }
    }

    public function testSupervisorDoesNotPublishClaimedWithoutValidatedClaimAcknowledgement(): void
    {
        $root = $this->temporaryDirectory('bootstrap-claim-no-ack');

        $candidate = new WorkerProcessGuardianClient(
            command: [
                \PHP_BINARY,
                self::packageRoot()
                . '/tests/Fixtures/process-bootstrap-fixture.php',
                'guardian-no-runtime-response',
            ],
            bootstrapWorkingDirectory: self::frameworkRoot(),
            skeletonRoot: $root,
            protocol: new WorkerProcessGuardianProtocol(),
            bootstrapLauncher: new WorkerProcessBootstrapLauncher(
                new WorkerProcessBootstrapProtocol(),
            ),
        );

        $spec = WorkerSpecFactory::create([
            'driver' => 'proc',
            'start_timeout_ms' => 500,
        ]);

        try {
            $candidate->claim(
                $spec,
                'proc',
            );

            self::fail('Supervisor must not observe generation ownership without a validated CLAIM ACK.');
        } catch (WorkerLifecycleFailedException) {
            self::assertFalse(
                self::guardianClientProperty(
                    $candidate,
                    'claimed',
                ),
                'Missing CLAIM ACK must never publish Supervisor claimed=true.',
            );

            self::assertNull(
                self::guardianClientProperty(
                    $candidate,
                    'connection',
                ),
                'Ambiguous failed CLAIM must not retain a completed Supervisor session once the child has exited.',
            );

            self::assertNull(
                self::guardianClientProperty(
                    $candidate,
                    'process',
                ),
                'Exited no-ACK fixture must not leave a retained process resource.',
            );

            self::assertNull(
                self::guardianClientProperty(
                    $candidate,
                    'guardianPid',
                ),
                'Exited no-ACK fixture must not leave Guardian process metadata.',
            );
        }

        self::assertFalse(
            new WorkerLifecycleLock($root)->isHeld(),
            'The non-lifecycle bootstrap fixture must not create generation authority.',
        );
    }

    public function testGuardianPreAuthenticationFailureAfterProcHostStartupIsAtomic(): void
    {
        self::assertTrue(
            WorkerProcessCapabilities::procDriverAvailable(),
            'PROC guardian backend must be available in the integration-test environment.',
        );

        $root = $this->temporaryDirectory('bootstrap-pre-auth-proc-host');
        $markerPath = $root . '/proc-host.pid';
        $probe = new WorkerLifecycleLock($root);
        $start = \hrtime(true);

        try {
            new WorkerProcessBootstrapLauncher(
                new WorkerProcessBootstrapProtocol(),
            )->launchAuthenticatedChild(
                command: [
                    \PHP_BINARY,
                    self::packageRoot()
                    . '/tests/Fixtures/guardian-bootstrap-preauth-failure-fixture.php',
                    $markerPath,
                ],
                workingDirectory: self::frameworkRoot(),
                role: WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
                timeoutMs: 1_000,
                driver: 'proc',
            );

            self::fail('Guardian-side fixture must not complete Supervisor authentication.');
        } catch (WorkerProcessBootstrapFailure) {
            $elapsedMs = (\hrtime(true) - $start) / 1_000_000;

            self::assertGreaterThanOrEqual(
                900,
                $elapsedMs,
            );
            self::assertLessThan(
                4_000,
                $elapsedMs,
                'Nested ProcHost pre-authentication cleanup must remain bounded.',
            );
        }

        self::assertFileExists(
            $markerPath,
            'The nested ProcHost must authenticate before Supervisor bootstrap failure.',
        );

        $markerBytes = @\file_get_contents($markerPath);

        self::assertIsString($markerBytes);

        $marker = \preg_split(
            '/\r?\n/',
            \trim($markerBytes),
        );

        self::assertIsArray($marker);
        self::assertCount(
            3,
            $marker,
        );

        foreach ($marker as $value) {
            self::assertTrue(
                \ctype_digit($value),
            );
        }

        $guardianPid = (int)$marker[0];
        $procHostPid = (int)$marker[1];
        $supervisorBootstrapPort = (int)$marker[2];

        self::assertGreaterThan(
            0,
            $guardianPid,
        );
        self::assertGreaterThan(
            0,
            $procHostPid,
        );
        self::assertGreaterThan(
            0,
            $supervisorBootstrapPort,
        );
        self::assertLessThanOrEqual(
            65_535,
            $supervisorBootstrapPort,
        );

        self::assertFalse(
            self::processExists($guardianPid),
            'Guardian survived bounded pre-authentication launcher cleanup.',
        );

        self::waitUntil(
            static fn (): bool => !self::processExists($procHostPid),
            5_000,
            'ProcHost survived its Guardian owner being terminated during pre-auth cleanup.',
        );

        $rebound = @\stream_socket_server(
            'tcp://127.0.0.1:' . $supervisorBootstrapPort,
            $errorCode,
            $errorMessage,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );

        self::assertIsResource(
            $rebound,
            'Supervisor bootstrap listener survived failed bootstrap cleanup.',
        );

        @\fclose($rebound);

        self::assertFalse(
            $probe->isHeld(),
            'Pre-authentication failure must not acquire the generation fence.',
        );
    }

    public function testAuthenticatedGuardianOwnerLossBeforeClaimCleansNestedProcHostWithoutFence(): void
    {
        self::assertTrue(
            WorkerProcessCapabilities::procDriverAvailable(),
            'PROC guardian backend must be available in the integration-test environment.',
        );

        $root = $this->temporaryDirectory('bootstrap-pre-claim-owner-loss');
        $protocol = new WorkerProcessBootstrapProtocol();
        $session = new WorkerProcessBootstrapLauncher($protocol)->launchAuthenticatedChild(
            command: [
                \PHP_BINARY,
                self::packageRoot() . '/bin/coretsia-worker-guardian',
            ],
            workingDirectory: self::frameworkRoot(),
            role: WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
            timeoutMs: 5_000,
            driver: 'proc',
        );
        $probe = new WorkerLifecycleLock($root);
        $procHostPid = self::linuxDirectChildPid($session['pid']);

        try {
            self::assertFalse(
                $probe->isHeld(),
                'Bootstrap authentication alone must not acquire WorkerLifecycleLock.',
            );
            if ($procHostPid !== null) {
                self::assertTrue(
                    self::processExists($procHostPid),
                    'ProcHost must already be live before pre-claim owner loss.',
                );
            }

            @\fclose($session['connection']);
            self::waitForProcessExit($session['process'], 6_000);
            self::assertFalse($probe->isHeld());
            if ($procHostPid !== null) {
                self::waitUntil(
                    static fn (): bool => !self::processExists($procHostPid),
                    3_000,
                    'ProcHost survived authenticated Guardian pre-claim owner loss.',
                );
            }
        } finally {
            if (\is_resource($session['connection'])) {
                @\fclose($session['connection']);
            }
            self::terminateProcess($session['process']);
        }
    }

    public function testAlreadyRunningCandidateCleanupCannotDisturbExistingGenerationAuthority(): void
    {
        self::assertTrue(
            WorkerProcessCapabilities::procDriverAvailable(),
            'PROC guardian backend must be available in the integration-test environment.',
        );

        $root = $this->temporaryDirectory('bootstrap-already-running');

        $existing = new WorkerLifecycleLock($root);
        $existing->acquire();

        $existingReleased = false;
        $probe = new WorkerLifecycleLock($root);

        self::assertTrue(
            $probe->isHeld(),
        );

        $candidate = self::guardian($root);
        $spec = WorkerSpecFactory::create([
            'driver' => 'proc',
            'start_timeout_ms' => 3_000,
        ]);

        try {
            try {
                $candidate->claim(
                    $spec,
                    'proc',
                );

                self::fail('Candidate Guardian must not claim an already-owned generation fence.');
            } catch (WorkerAlreadyRunningException) {
                self::assertTrue(
                    $probe->isHeld(),
                    'Candidate cleanup must leave the existing generation fence held.',
                );

                self::assertFalse(
                    self::guardianClientProperty(
                        $candidate,
                        'claimed',
                    ),
                    'Explicit CLAIM rejection must not publish candidate claimed=true.',
                );

                self::assertNull(
                    self::guardianClientProperty(
                        $candidate,
                        'connection',
                    ),
                    'Rejected candidate must not retain an authenticated session.',
                );

                self::assertNull(
                    self::guardianClientProperty(
                        $candidate,
                        'process',
                    ),
                    'Rejected candidate must not retain its Guardian process resource.',
                );

                self::assertNull(
                    self::guardianClientProperty(
                        $candidate,
                        'guardianPid',
                    ),
                    'Rejected candidate must not retain Guardian process metadata.',
                );

                self::assertSame(
                    [],
                    self::guardianClientProperty(
                        $candidate,
                        'children',
                    ),
                    'Rejected candidate must not retain partial child ownership.',
                );
            }

            self::assertTrue(
                $probe->isHeld(),
                'Existing generation authority must remain held until its owner releases it.',
            );

            $existing->release();
            $existingReleased = true;

            self::assertFalse(
                $probe->isHeld(),
            );

            /*
             * Reusing the same client after explicit rejection proves resetLaunch()
             * left no partial candidate bootstrap session behind.
             */
            $candidate->claim(
                $spec,
                'proc',
            );

            self::assertTrue(
                $probe->isHeld(),
            );

            $candidate->release(
                5_000,
            );

            self::assertFalse(
                $probe->isHeld(),
            );
        } finally {
            if (!$existingReleased) {
                $existing->release();
            }
        }
    }

    public function testAlreadyRunningTopologyCleansCandidateGuardianAndProcHostWithoutTouchingExistingFence(): void
    {
        self::assertTrue(
            WorkerProcessCapabilities::procDriverAvailable(),
            'PROC guardian backend must be available in the integration-test environment.',
        );

        $root = $this->temporaryDirectory('bootstrap-already-running-topology');

        $existing = new WorkerLifecycleLock($root);
        $existing->acquire();

        $probe = new WorkerLifecycleLock($root);
        $bootstrapProtocol = new WorkerProcessBootstrapProtocol();

        $session = new WorkerProcessBootstrapLauncher($bootstrapProtocol)->launchAuthenticatedChild(
            command: [
                \PHP_BINARY,
                self::packageRoot() . '/bin/coretsia-worker-guardian',
            ],
            workingDirectory: self::frameworkRoot(),
            role: WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
            timeoutMs: 5_000,
            driver: 'proc',
        );

        $guardianProtocol = new WorkerProcessGuardianProtocol();
        $procHostPid = self::linuxDirectChildPid($session['pid']);

        try {
            self::assertTrue(
                $probe->isHeld(),
                'Existing generation fence must already be held before candidate CLAIM.',
            );

            if ($procHostPid !== null) {
                self::assertTrue(
                    self::processExists($procHostPid),
                    'Candidate ProcHost must already be live before candidate CLAIM.',
                );
            }

            self::writeFrame(
                $session['connection'],
                $guardianProtocol->encodeRequest(
                    requestId: 1,
                    operation: WorkerProcessGuardianProtocol::OPERATION_CLAIM,
                    payload: [
                        'force_kill_timeout_ms' => 1_000,
                        'skeleton_root' => $root,
                        'stop_timeout_ms' => 1_000,
                    ],
                ),
            );

            $response = $guardianProtocol->decodeResponse(
                self::readFrame(
                    $session['connection'],
                    3_000,
                ),
            );

            self::assertSame(
                1,
                $response['request_id'],
            );
            self::assertSame(
                WorkerProcessGuardianProtocol::STATUS_ERROR,
                $response['status'],
            );
            self::assertSame(
                [
                    'reason' => WorkerProcessGuardianProtocol::ERROR_ALREADY_RUNNING,
                ],
                $response['payload'],
            );

            /*
             * The canonical already-running response is explicit proof that this
             * candidate never acquired generation authority.
             */
            self::assertTrue(
                $probe->isHeld(),
                'Candidate rejection must not release the existing generation fence.',
            );

            @\fclose($session['connection']);

            self::waitForProcessExit(
                $session['process'],
                5_000,
            );

            self::assertTrue(
                $probe->isHeld(),
                'Candidate Guardian cleanup must not disturb existing generation authority.',
            );

            if ($procHostPid !== null) {
                self::waitUntil(
                    static fn (): bool => !self::processExists($procHostPid),
                    3_000,
                    'Candidate ProcHost survived canonical already-running cleanup.',
                );
            }
        } finally {
            if (\is_resource($session['connection'])) {
                @\fclose($session['connection']);
            }

            self::terminateProcess($session['process']);

            $existing->release();
        }

        self::assertFalse(
            $probe->isHeld(),
            'Generation fence must become free only after the existing owner releases it.',
        );
    }

    public function testLostClaimAcknowledgementAfterFenceCommitCleansGenerationBeforeReplacement(): void
    {
        self::assertTrue(
            WorkerProcessCapabilities::procDriverAvailable(),
            'PROC guardian backend must be available in the integration-test environment.',
        );

        $root = $this->temporaryDirectory('bootstrap-lost-claim-ack');
        $bootstrapProtocol = new WorkerProcessBootstrapProtocol();
        $session = new WorkerProcessBootstrapLauncher($bootstrapProtocol)->launchAuthenticatedChild(
            command: [
                \PHP_BINARY,
                self::packageRoot() . '/bin/coretsia-worker-guardian',
            ],
            workingDirectory: self::frameworkRoot(),
            role: WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
            timeoutMs: 5_000,
            driver: 'proc',
        );
        $guardianProtocol = new WorkerProcessGuardianProtocol();
        $probe = new WorkerLifecycleLock($root);
        $procHostPid = self::linuxDirectChildPid(
            $session['pid'],
        );

        try {
            if ($procHostPid !== null) {
                self::assertTrue(
                    self::processExists($procHostPid),
                    'ProcHost must already be live before CLAIM commit.',
                );
            }

            self::writeFrame(
                $session['connection'],
                $guardianProtocol->encodeRequest(
                    requestId: 1,
                    operation: WorkerProcessGuardianProtocol::OPERATION_CLAIM,
                    payload: [
                        'force_kill_timeout_ms' => 1_000,
                        'skeleton_root' => $root,
                        'stop_timeout_ms' => 1_000,
                    ],
                )
            );

            self::waitUntilReadable(
                $session['connection'],
                5_000,
                'Guardian did not publish CLAIM ACK after committing WorkerLifecycleLock.',
            );

            self::assertTrue(
                $probe->isHeld(),
                'CLAIM ACK became readable before WorkerLifecycleLock was committed.',
            );

            if ($procHostPid !== null) {
                self::assertTrue(
                    self::processExists($procHostPid),
                    'ProcHost must remain owned while the committed fence is held.',
                );
            }

            // Intentionally never consume CLAIM ACK. EOF transfers terminal cleanup to Guardian.
            @\fclose($session['connection']);

            self::waitUntil(
                static fn (): bool => !$probe->isHeld(),
                5_000,
                'Generation fence did not become free after Guardian-owned cleanup.',
            );

            if ($procHostPid !== null) {
                self::assertFalse(
                    self::processExists($procHostPid),
                    'WorkerLifecycleLock became free before nested ProcHost cleanup completed.',
                );
            }

            self::waitForProcessExit(
                $session['process'],
                5_000,
            );

            $replacement = self::guardian($root);
            $spec = WorkerSpecFactory::create([
                'driver' => 'proc',
                'start_timeout_ms' => 5_000,
            ]);
            $replacement->claim($spec, 'proc');
            self::assertTrue($probe->isHeld());
            $replacement->release(5_000);
            self::assertFalse($probe->isHeld());
        } finally {
            if (\is_resource($session['connection'])) {
                @\fclose($session['connection']);
            }
            self::terminateProcess($session['process']);
        }
    }

    public function testAmbiguousProcSpawnRetainsFenceUntilOwnedGenerationCleanupBeforeReplacement(): void
    {
        self::assertTrue(
            WorkerProcessCapabilities::procDriverAvailable(),
            'PROC guardian backend must be available in the integration-test environment.',
        );

        $root = $this->temporaryDirectory('bootstrap-ambiguous-proc-spawn');
        $pidFile = $root . '/child.pid';
        $releaseFile = $root . '/child.release';

        $bootstrapProtocol = new WorkerProcessBootstrapProtocol();

        $session = new WorkerProcessBootstrapLauncher($bootstrapProtocol)->launchAuthenticatedChild(
            command: [
                \PHP_BINARY,
                self::packageRoot() . '/bin/coretsia-worker-guardian',
            ],
            workingDirectory: self::frameworkRoot(),
            role: WorkerProcessBootstrapProtocol::ROLE_GUARDIAN,
            timeoutMs: 5_000,
            driver: 'proc',
        );

        $guardianProtocol = new WorkerProcessGuardianProtocol();
        $probe = new WorkerLifecycleLock($root);

        /*
         * Available on Linux through /proc and intentionally optional elsewhere.
         * The worker PID below remains the cross-platform generation-cleanup proof.
         */
        $procHostPid = self::linuxDirectChildPid($session['pid']);

        $replacement = null;
        $replacementClaimed = false;

        try {
            /*
             * Commit generation ownership first.
             */
            self::writeFrame(
                $session['connection'],
                $guardianProtocol->encodeRequest(
                    requestId: 1,
                    operation: WorkerProcessGuardianProtocol::OPERATION_CLAIM,
                    payload: [
                        'force_kill_timeout_ms' => 1_000,
                        'skeleton_root' => $root,
                        'stop_timeout_ms' => 1_000,
                    ],
                ),
            );

            $claim = $guardianProtocol->decodeResponse(
                self::readFrame(
                    $session['connection'],
                    3_000,
                ),
            );

            self::assertSame(
                1,
                $claim['request_id'],
            );

            self::assertSame(
                WorkerProcessGuardianProtocol::STATUS_OK,
                $claim['status'],
            );

            self::assertSame(
                ['acknowledged' => true],
                $claim['payload'],
            );

            self::assertTrue(
                $probe->isHeld(),
                'Guardian must own WorkerLifecycleLock before PROC spawn begins.',
            );

            if ($procHostPid !== null) {
                self::assertTrue(
                    self::processExists($procHostPid),
                    'ProcHost must be live while the claimed generation fence is held.',
                );
            }

            /*
             * Start a real PROC worker, but deliberately do not consume the SPAWN
             * response. Once the PID file appears, proc_open() has succeeded and
             * there is a real process-generation ownership obligation.
             */
            self::writeFrame(
                $session['connection'],
                $guardianProtocol->encodeRequest(
                    requestId: 2,
                    operation: WorkerProcessGuardianProtocol::OPERATION_SPAWN,
                    payload: [
                        'command' => [
                            \PHP_BINARY,
                            self::packageRoot()
                            . '/tests/Fixtures/exec-hold-pid-fixture.php',
                            '--pid-file=' . $pidFile,
                            '--release-file=' . $releaseFile,
                            '--timeout-ms=8000',
                        ],
                        'working_directory' => $root,
                    ],
                ),
            );

            self::waitUntil(
                static function () use ($pidFile): bool {
                    $pidBytes = @\file_get_contents($pidFile);

                    return \is_string($pidBytes) && \preg_match('/\A[1-9][0-9]*\r?\n\z/D', $pidBytes) === 1;
                },
                3_000,
                'PROC worker did not publish its PID before ambiguous SPAWN injection.',
            );

            $pidBytes = @\file_get_contents($pidFile);

            self::assertIsString($pidBytes);

            $pidValue = \trim($pidBytes);

            self::assertTrue(
                \ctype_digit($pidValue),
            );

            $childPid = (int)$pidValue;

            self::assertGreaterThan(
                0,
                $childPid,
            );

            self::assertTrue(
                self::processExists($childPid),
                'PROC worker must be live before Supervisor ownership is withdrawn.',
            );

            self::assertTrue(
                $probe->isHeld(),
                'WorkerLifecycleLock must remain held after the worker has actually launched.',
            );

            /*
             * Intentionally never consume SPAWN ACK.
             *
             * Supervisor therefore has an ambiguous launch result even though the
             * child demonstrably exists. EOF transfers terminal generation cleanup
             * to Guardian.
             */
            @\fclose($session['connection']);

            self::assertTrue(
                $probe->isHeld(),
                'Owner-channel loss must not release worker.lock before generation cleanup.',
            );

            /*
             * Fence release is monotonic. Once we observe it free, every process
             * owned by the old generation must already be gone.
             */
            $deadlineNs = \hrtime(true) + 6_000_000_000;

            while (
                $probe->isHeld()
                && \hrtime(true) < $deadlineNs
            ) {
                \usleep(10_000);
            }

            self::assertFalse(
                $probe->isHeld(),
                'Generation fence did not become free after ambiguous PROC spawn cleanup.',
            );

            self::assertFalse(
                self::processExists($childPid),
                'WorkerLifecycleLock became free before the ambiguously launched worker disappeared.',
            );

            /*
             * On Linux we can additionally identify the exact nested ProcHost and
             * prove that fence release occurred only after its exit.
             */
            if ($procHostPid !== null) {
                self::assertFalse(
                    self::processExists($procHostPid),
                    'WorkerLifecycleLock became free before nested ProcHost cleanup completed.',
                );
            }

            self::waitForProcessExit(
                $session['process'],
                5_000,
            );

            /*
             * Only after the old generation has disappeared may a replacement
             * Guardian establish a new generation.
             */
            $replacement = self::guardian($root);

            $spec = WorkerSpecFactory::create([
                'driver' => 'proc',
                'start_timeout_ms' => 5_000,
            ]);

            $replacement->claim(
                $spec,
                'proc',
            );

            $replacementClaimed = true;

            self::assertTrue(
                $probe->isHeld(),
                'Replacement Guardian must acquire the fence only after prior generation cleanup.',
            );

            $replacement->release(5_000);
            $replacementClaimed = false;

            self::assertFalse(
                $probe->isHeld(),
                'Replacement release must leave the generation fence free.',
            );
        } finally {
            /*
             * Failure-path hygiene only. A successful test never needs this file:
             * Guardian/ProcHost terminate the worker as part of owned cleanup.
             */
            if (!\is_file($releaseFile)) {
                @\file_put_contents(
                    $releaseFile,
                    "release\n",
                    \LOCK_EX,
                );
            }

            if (
                $replacementClaimed
                && $replacement instanceof WorkerProcessGuardianClient
            ) {
                try {
                    $replacement->release(5_000);
                } catch (\Throwable) {
                }
            }

            if (\is_resource($session['connection'])) {
                @\fclose($session['connection']);
            }

            self::terminateProcess($session['process']);
        }
    }

    private static function guardianClientProperty(
        WorkerProcessGuardianClient $client,
        string $property,
    ): mixed {
        return new \ReflectionProperty(
            $client,
            $property,
        )->getValue($client);
    }

    private static function linuxDirectChildPid(int $parentPid): ?int
    {
        $path = '/proc/' . $parentPid . '/task/' . $parentPid . '/children';
        if (!\is_file($path) || !\is_readable($path)) {
            return null;
        }

        $deadline = \hrtime(true) + 2_000_000_000;
        do {
            $bytes = @\file_get_contents($path);
            if (\is_string($bytes)) {
                $parts = \preg_split('/\s+/', \trim($bytes)) ?: [];
                foreach ($parts as $part) {
                    if ($part !== '' && \ctype_digit($part) && (int)$part > 0) {
                        return (int)$part;
                    }
                }
            }
            \usleep(10_000);
        } while (\hrtime(true) < $deadline);

        return null;
    }

    private static function guardian(string $root): WorkerProcessGuardianClient
    {
        $bootstrapProtocol = new WorkerProcessBootstrapProtocol();
        return new WorkerProcessGuardianClient(
            command: [\PHP_BINARY, self::packageRoot() . '/bin/coretsia-worker-guardian'],
            bootstrapWorkingDirectory: self::frameworkRoot(),
            skeletonRoot: $root,
            protocol: new WorkerProcessGuardianProtocol(),
            bootstrapLauncher: new WorkerProcessBootstrapLauncher($bootstrapProtocol),
        );
    }

    /** @param resource $stream */
    private static function writeFrame(mixed $stream, string $frame): void
    {
        $remaining = $frame;
        while ($remaining !== '') {
            $written = @\fwrite($stream, $remaining);
            self::assertIsInt($written);
            self::assertGreaterThan(0, $written);
            $remaining = \substr($remaining, $written);
        }
        @\fflush($stream);
    }

    /** @param resource $stream */
    private static function waitUntilReadable(
        mixed $stream,
        int $timeoutMs,
        string $failureMessage,
    ): void {
        $deadlineNs = \hrtime(true)
            + ($timeoutMs * 1_000_000);

        do {
            $remainingNs = $deadlineNs - \hrtime(true);

            if ($remainingNs <= 0) {
                break;
            }

            $read = [$stream];
            $write = null;
            $except = null;
            $remainingUs = (int)\max(
                1,
                \min(
                    100_000,
                    \intdiv(
                        $remainingNs,
                        1_000,
                    ),
                ),
            );

            $selected = @\stream_select(
                $read,
                $write,
                $except,
                0,
                $remainingUs,
            );

            if ($selected === 1) {
                return;
            }
        } while (\hrtime(true) < $deadlineNs);

        self::fail($failureMessage);
    }

    /** @param resource $stream */
    private static function readFrame(
        mixed $stream,
        int $timeoutMs,
    ): string {
        $deadline = \hrtime(true)
            + ($timeoutMs * 1_000_000);

        $buffer = '';

        while (true) {
            $newline = \strpos(
                $buffer,
                "\n",
            );

            if ($newline !== false) {
                self::assertSame(
                    \strlen($buffer) - 1,
                    $newline,
                    'Guardian response must contain exactly one complete frame.',
                );

                return $buffer;
            }

            if (\strlen($buffer) > WorkerProcessGuardianProtocol::MAX_FRAME_BYTES) {
                self::fail('Guardian response exceeded the protocol frame bound.');
            }

            $remainingNs = $deadline - \hrtime(true);

            if ($remainingNs <= 0) {
                self::fail('Timed out waiting for Guardian response frame.');
            }

            $read = [$stream];
            $write = null;
            $except = null;

            $remainingUs = (int)\max(
                1,
                \min(
                    100_000,
                    \intdiv(
                        $remainingNs,
                        1_000,
                    ),
                ),
            );

            $selected = @\stream_select(
                $read,
                $write,
                $except,
                0,
                $remainingUs,
            );

            if ($selected === false || $selected === 0) {
                continue;
            }

            $chunk = @\fread(
                $stream,
                4096,
            );

            if ($chunk === false) {
                self::fail('Failed to read Guardian response frame.');
            }

            if ($chunk === '') {
                if (@\feof($stream)) {
                    self::fail('Guardian connection closed before a response frame was received.');
                }

                continue;
            }

            $buffer .= $chunk;
        }
    }

    /** @param resource $process */
    private static function waitForProcessExit(mixed $process, int $timeoutMs): void
    {
        $deadline = \hrtime(true) + ($timeoutMs * 1_000_000);
        do {
            $status = @\proc_get_status($process);
            if (!\is_array($status) || ($status['running'] ?? false) !== true) {
                @\proc_close($process);
                return;
            }
            \usleep(10_000);
        } while (\hrtime(true) < $deadline);
        self::terminateProcess($process);
        self::fail('Guardian did not exit within the bounded cleanup interval.');
    }

    /** @param resource $process */
    private static function terminateProcess(mixed $process): void
    {
        if (!\is_resource($process)) {
            return;
        }
        $status = @\proc_get_status($process);
        if (\is_array($status) && ($status['running'] ?? false) === true) {
            @\proc_terminate($process, 9);
        }
        @\proc_close($process);
    }
}
