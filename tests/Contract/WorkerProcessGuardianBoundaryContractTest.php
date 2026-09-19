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

namespace Coretsia\Platform\Worker\Tests\Contract;

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerProcessGuardianBoundaryContractTest extends PackageTestCase
{
    public function testGuardianIsTheOnlyRuntimeOwnerOfGenerationFenceAndRawPcntlLifecycle(): void
    {
        $supervisor = self::source('src/Supervisor/WorkerSupervisor.php');
        $pcntl = self::source('src/Process/Driver/PcntlWorkerProcessDriver.php');
        $proc = self::source('src/Process/Driver/ProcWorkerProcessDriver.php');
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        $procHost = self::source('src/Process/Entrypoint/WorkerProcProcessHostEntrypointRuntime.php');

        self::assertStringNotContainsString('WorkerLifecycleLock', $supervisor);
        self::assertStringContainsString('$this->guardian->claim(', $supervisor);
        self::assertStringContainsString('$this->guardian->release(', $supervisor);

        foreach (['pcntl_fork(', 'pcntl_exec(', 'pcntl_waitpid(', 'posix_kill('] as $rawPcntl) {
            self::assertStringNotContainsString($rawPcntl, $pcntl);
            self::assertStringContainsString($rawPcntl, $guardian);
        }
        self::assertStringNotContainsString('proc_open(', $proc);
        self::assertStringNotContainsString('WorkerProcProcessHostClient', $proc);
        self::assertStringContainsString('proc_open(', $procHost);

        self::assertStringContainsString('WorkerLifecycleLock', $guardian);
        self::assertStringContainsString('$lock->acquire()', $guardian);
        self::assertStringContainsString('$this->lifecycleLock?->release()', $guardian);
    }

    public function testOwnedGenerationCleanupReleasesFenceAfterProcHostShutdown(): void
    {
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');

        $cleanup = \strpos(
            $guardian,
            'private function cleanupOwnedGeneration(): void',
        );

        self::assertIsInt($cleanup);

        $procHostShutdown = \strpos(
            $guardian,
            '$this->processHost->shutdown(',
            $cleanup,
        );

        $fenceRelease = \strpos(
            $guardian,
            '$this->lifecycleLock?->release()',
            $cleanup,
        );

        self::assertIsInt($procHostShutdown);
        self::assertIsInt($fenceRelease);

        self::assertLessThan(
            $fenceRelease,
            $procHostShutdown,
            'Nested ProcHost shutdown must precede WorkerLifecycleLock release.',
        );
    }

    public function testGenerationOwnedProcHostCleanupCannotUseForcedTerminationFallback(): void
    {
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        $processHost = self::source('src/Process/Proc/WorkerProcProcessHostClient.php');

        $releaseStart = \strpos(
            $guardian,
            'private function release(): array',
        );
        $releaseEnd = \strpos(
            $guardian,
            'private function refreshExit(',
            $releaseStart,
        );
        $cleanupStart = \strpos(
            $guardian,
            'private function cleanupOwnedGeneration(): void',
        );
        $cleanupEnd = \strpos(
            $guardian,
            'private function signalAll(',
            $cleanupStart,
        );

        self::assertIsInt($releaseStart);
        self::assertIsInt($releaseEnd);
        self::assertIsInt($cleanupStart);
        self::assertIsInt($cleanupEnd);

        $release = \substr(
            $guardian,
            $releaseStart,
            $releaseEnd - $releaseStart,
        );
        $cleanup = \substr(
            $guardian,
            $cleanupStart,
            $cleanupEnd - $cleanupStart,
        );

        self::assertStringContainsString(
            'allowForcedTermination: false',
            $release,
        );
        self::assertStringNotContainsString(
            'allowForcedTermination: true',
            $release,
        );

        self::assertStringContainsString(
            'allowForcedTermination: false',
            $cleanup,
        );
        self::assertStringNotContainsString(
            'allowForcedTermination: true',
            $cleanup,
        );

        $fallbackStart = \strpos(
            $processHost,
            'private function closeOwnerChannelAndAwaitHostExit(',
        );
        $fallbackEnd = \strpos(
            $processHost,
            'private function closeConnection(): void',
            $fallbackStart,
        );

        self::assertIsInt($fallbackStart);
        self::assertIsInt($fallbackEnd);

        $fallback = \substr(
            $processHost,
            $fallbackStart,
            $fallbackEnd - $fallbackStart,
        );

        $forcedTerminationGuard = \strpos(
            $fallback,
            'if (!$allowForcedTermination)',
        );
        $hardKill = \strpos(
            $fallback,
            '@\proc_terminate($this->process, 9)',
        );

        self::assertIsInt($forcedTerminationGuard);
        self::assertIsInt($hardKill);

        self::assertLessThan(
            $hardKill,
            $forcedTerminationGuard,
            'Forced ProcHost termination must remain behind the explicit pre-claim permission gate.',
        );
    }

    public function testAmbiguousClaimCannotForceTerminatePotentiallyGenerationOwningGuardian(): void
    {
        $client = self::source('src/Process/Guardian/WorkerProcessGuardianClient.php');

        $claimStart = \strpos(
            $client,
            'public function claim(WorkerPoolSpec $spec, string $driverName): void',
        );
        $claimEnd = \strpos(
            $client,
            'public function spawn(',
            $claimStart,
        );

        self::assertIsInt($claimStart);
        self::assertIsInt($claimEnd);

        $claim = \substr(
            $client,
            $claimStart,
            $claimEnd - $claimStart,
        );

        $alreadyRunningCatch = \strpos(
            $claim,
            'catch (WorkerAlreadyRunningException $exception)',
        );
        $ambiguousCatch = \strpos(
            $claim,
            'catch (\\Throwable $exception)',
            $alreadyRunningCatch,
        );

        self::assertIsInt($alreadyRunningCatch);
        self::assertIsInt($ambiguousCatch);

        $explicitRejection = \substr(
            $claim,
            $alreadyRunningCatch,
            $ambiguousCatch - $alreadyRunningCatch,
        );
        $ambiguousCommit = \substr(
            $claim,
            $ambiguousCatch,
        );

        self::assertSame(
            1,
            \substr_count($claim, '$this->forceTerminateGuardian();'),
            'CLAIM handling must have exactly one forced Guardian-termination call site.',
        );

        self::assertStringContainsString(
            '$this->forceTerminateGuardian();',
            $explicitRejection,
            'Only explicit ALREADY_RUNNING rejection may forcibly terminate the candidate Guardian.',
        );

        self::assertStringNotContainsString(
            '$this->forceTerminateGuardian();',
            $ambiguousCommit,
            'Ambiguous CLAIM failure must transfer cleanup through EOF and must not hard-kill a potentially generation-owning Guardian.',
        );

        self::assertStringContainsString(
            '$this->closeConnection();',
            $ambiguousCommit,
            'Ambiguous CLAIM failure must close the owner connection so Guardian performs terminal cleanup.',
        );

        self::assertStringContainsString(
            'if (!$this->guardianRunning())',
            $ambiguousCommit,
            'Ambiguous CLAIM cleanup may reset local launch state only after Guardian exit is observed.',
        );
    }

    public function testBootstrapAuthenticationCannotAcquireGenerationFence(): void
    {
        foreach (
            [
                'src/Process/Bootstrap/WorkerProcessBootstrapProtocol.php',
                'src/Process/Bootstrap/WorkerProcessBootstrapEndpoint.php',
                'src/Process/Bootstrap/WorkerProcessBootstrapClient.php',
                'src/Process/Bootstrap/WorkerProcessBootstrapLauncher.php',
                'src/Process/Guardian/WorkerProcessGuardianClient.php',
            ] as $file
        ) {
            self::assertStringNotContainsString('$lock->acquire()', self::source($file), $file);
        }

        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        self::assertStringContainsString('$lock->acquire()', $guardian);
    }

    public function testGuardianCannotOwnSupervisorArtifacts(): void
    {
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        foreach (
            [
                'WorkerStateStore',
                'WorkerLifecycleLocatorStore',
                'WorkerControlServer',
                'WorkerStopSignal'
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $guardian);
        }
    }
}
