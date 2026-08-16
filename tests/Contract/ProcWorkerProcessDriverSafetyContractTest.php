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

final class ProcWorkerProcessDriverSafetyContractTest extends PackageTestCase
{
    public function testProcDriverDelegatesAllProcessOwnershipToGuardian(): void
    {
        $driver = self::source('src/Process/Driver/ProcWorkerProcessDriver.php');
        self::assertStringContainsString('WorkerProcessGuardianInterface', $driver);
        foreach (['spawn', 'pollExit', 'terminate', 'kill', 'close'] as $method) {
            self::assertStringContainsString('$this->guardian->' . $method, $driver);
        }
        foreach (['proc_open(', 'WorkerProcProcessHostClient', 'WorkerLifecycleLock'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $driver);
        }
    }

    public function testGuardianConsumesBootstrapBeforeProcHostStartAndSupervisorAuthentication(): void
    {
        $entrypoint = self::source('src/Process/Entrypoint/worker-guardian.php');
        $client = self::source('src/Process/Bootstrap/WorkerProcessBootstrapClient.php');

        $receive = \strpos($entrypoint, '$bootstrapClient->receiveGuardian()');
        $startHost = \strpos($entrypoint, '$processHost->start(');
        $connect = \strpos($entrypoint, '$bootstrapClient->connect()');
        self::assertIsInt($receive);
        self::assertIsInt($startHost);
        self::assertIsInt($connect);
        self::assertLessThan($startHost, $receive);
        self::assertLessThan($connect, $startHost);
        self::assertStringContainsString('finally {', $client);
        self::assertStringContainsString('@\fclose($stream);', $client);
    }

    public function testProcHostConsumesBootstrapBeforeAnyWorkerProcOpen(): void
    {
        $entrypoint = self::source('src/Process/Entrypoint/worker-proc-host.php');
        $client = self::source('src/Process/Bootstrap/WorkerProcessBootstrapClient.php');
        $runtime = self::source('src/Process/Entrypoint/WorkerProcProcessHostEntrypointRuntime.php');

        $receive = \strpos(
            $entrypoint,
            '$bootstrapClient->receiveProcHost()',
        );
        $connect = \strpos(
            $entrypoint,
            '$bootstrapClient->connect()',
        );
        $runtimeConstruction = \strpos(
            $entrypoint,
            'new WorkerProcProcessHostEntrypointRuntime',
        );
        $runtimeRun = \strpos(
            $entrypoint,
            '$runtime->run()',
        );

        self::assertIsInt($receive);
        self::assertIsInt($connect);
        self::assertIsInt($runtimeConstruction);
        self::assertIsInt($runtimeRun);

        self::assertLessThan(
            $connect,
            $receive,
            'ProcHost bootstrap input must be consumed before bootstrap authentication.',
        );
        self::assertLessThan(
            $runtimeConstruction,
            $connect,
            'ProcHost authentication must complete before runtime construction.',
        );
        self::assertLessThan(
            $runtimeRun,
            $runtimeConstruction,
            'ProcHost runtime must not run before bootstrap completion.',
        );

        self::assertStringContainsString(
            '$frame = $this->readAndCloseBootstrapInput();',
            $client,
        );
        self::assertStringContainsString(
            'finally {',
            $client,
        );
        self::assertStringContainsString(
            '@\fclose($stream);',
            $client,
        );

        self::assertStringContainsString(
            'proc_open(',
            $runtime,
        );
        self::assertStringNotContainsString(
            'proc_open(',
            $entrypoint,
        );
    }

    public function testProcessHostRotatesGuardianConnectionAroundEveryWorkerProcOpen(): void
    {
        $runtime = self::source('src/Process/Entrypoint/WorkerProcProcessHostEntrypointRuntime.php');
        $client = self::source('src/Process/Proc/WorkerProcProcessHostClient.php');
        self::assertStringContainsString('WorkerProcProcessHostHandoffEndpoint::create', $client);
        self::assertStringContainsString('$this->closeConnection();', $runtime);
        self::assertStringContainsString('$this->spawn($payload)', $runtime);
        self::assertStringContainsString('$this->transport->connect(', $runtime);
    }

    public function testProcCapabilityIncludesSecureBootstrapTransportAndSignalRequirements(): void
    {
        $capabilities = self::source('src/Internal/WorkerProcessCapabilities.php');
        foreach (
            [
                'processBootstrapAvailable',
                'procProcessHostTransportAvailable',
                'procDriverAvailable'
            ] as $required
        ) {
            self::assertStringContainsString($required, $capabilities);
        }
        self::assertStringNotContainsString('guardianTransportAvailable', $capabilities);
        foreach (
            [
                'proc_open',
                'proc_get_status',
                'proc_terminate',
                'proc_close',
                'stream_socket_client'
            ] as $fn
        ) {
            self::assertStringContainsString("\\function_exists('{$fn}')", $capabilities);
        }
        foreach (['sapi_windows_set_ctrl_handler', 'pcntl_async_signals', 'pcntl_signal'] as $fn) {
            self::assertStringContainsString("\\function_exists('{$fn}')", $capabilities);
        }
    }
}
