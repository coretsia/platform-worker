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

final class WorkerProcessBootstrapBoundaryContractTest extends PackageTestCase
{
    public function testSharedLauncherOwnsInitialChildLaunchAndBootstrapPipePublication(): void
    {
        $launcher = self::source('src/Process/Bootstrap/WorkerProcessBootstrapLauncher.php');
        $endpoint = self::source('src/Process/Bootstrap/WorkerProcessBootstrapEndpoint.php');
        $guardian = self::source('src/Process/Guardian/WorkerProcessGuardianClient.php');
        $procHost = self::source('src/Process/Proc/WorkerProcProcessHostClient.php');

        self::assertStringContainsString('WorkerProcessBootstrapLauncher', $guardian);
        self::assertStringContainsString('WorkerProcessBootstrapLauncher', $procHost);
        self::assertStringNotContainsString('\\proc_open(', $guardian);
        self::assertStringNotContainsString('\\proc_open(', $procHost);
        self::assertStringNotContainsString('reserveLoopbackPort', $guardian . $procHost . $launcher . $endpoint);

        self::assertStringContainsString('\\proc_open(', $launcher);
        self::assertStringContainsString('$pipes[0]', $launcher);
        self::assertStringContainsString('writeBootstrapFrame(', $launcher);
        self::assertStringNotContainsString('\\proc_open(', $endpoint);
        self::assertStringNotContainsString('fwrite(', $endpoint);
        self::assertStringNotContainsString('hash_equals(', $launcher);
        self::assertStringContainsString('hash_equals(', $endpoint);

        self::assertStringContainsString(
            'stream_set_blocking($listener, false)',
            $endpoint,
        );

        self::assertStringContainsString(
            'stream_socket_accept($listener, 0)',
            $endpoint,
        );

        self::assertStringContainsString(
            '$endpoint->close();',
            $launcher,
        );

        self::assertStringContainsString(
            'cleanupDirectChild($process)',
            $launcher,
        );

        foreach ([$guardian, $procHost] as $client) {
            self::assertStringNotContainsString(
                '$pipes[0]',
                $client,
            );

            self::assertStringNotContainsString(
                'writeBootstrapFrame(',
                $client,
            );
        }

        $procOpen = \strpos($launcher, '\\proc_open(');
        $endpointCreate = \strpos($launcher, 'WorkerProcessBootstrapEndpoint::create');
        self::assertIsInt($procOpen);
        self::assertIsInt($endpointCreate);
        self::assertLessThan($endpointCreate, $procOpen);
    }

    public function testExecutableShellsRemainThinAndCompositionStaysSourceOwned(): void
    {
        $guardianBin = self::source('bin/coretsia-worker-guardian');
        $procHostBin = self::source('bin/coretsia-worker-proc-host');
        $guardianEntrypoint = self::source('src/Process/Entrypoint/worker-guardian.php');
        $procHostEntrypoint = self::source('src/Process/Entrypoint/worker-proc-host.php');
        $procHostRuntime = self::source('src/Process/Entrypoint/WorkerProcProcessHostEntrypointRuntime.php');
        $procHostFailure = self::source('src/Process/Entrypoint/WorkerProcProcessHostEntrypointFailure.php');

        foreach ([$guardianBin, $procHostBin] as $binary) {
            self::assertStringContainsString(
                'require $autoload;',
                $binary,
            );

            self::assertStringNotContainsString(
                'use Coretsia\\Platform\\Worker\\',
                $binary,
                'OS executable shells must not directly consume package-internal PSR-4 classes.',
            );
        }

        self::assertStringContainsString(
            '../src/Process/Entrypoint/worker-guardian.php',
            $guardianBin,
        );
        self::assertStringContainsString(
            '../src/Process/Entrypoint/worker-proc-host.php',
            $procHostBin,
        );

        self::assertStringContainsString(
            'WorkerProcessBootstrapClient',
            $guardianEntrypoint,
        );
        self::assertStringContainsString(
            'WorkerProcessBootstrapClient',
            $procHostEntrypoint,
        );

        self::assertStringContainsString(
            'new WorkerProcProcessHostEntrypointRuntime',
            $procHostEntrypoint,
        );

        self::assertStringNotContainsString(
            'final class WorkerProcProcessHostEntrypointRuntime',
            $procHostEntrypoint,
        );
        self::assertStringNotContainsString(
            'final class WorkerProcProcessHostEntrypointFailure',
            $procHostEntrypoint,
        );

        self::assertStringContainsString(
            'final class WorkerProcProcessHostEntrypointRuntime',
            $procHostRuntime,
        );
        self::assertStringContainsString(
            'final class WorkerProcProcessHostEntrypointFailure',
            $procHostFailure,
        );
    }

    public function testBootstrapAndProviderRemainStaticSerializationAndPackageInternalInfrastructure(): void
    {
        $protocol = self::source('src/Process/Bootstrap/WorkerProcessBootstrapProtocol.php');
        $provider = self::source('src/Provider/WorkerServiceProvider.php');
        $runtime = self::source('src/Process/Guardian/WorkerProcessGuardianRuntime.php');
        $procHostRuntime = self::source('src/Process/Entrypoint/WorkerProcProcessHostEntrypointRuntime.php');

        self::assertStringContainsString('StableJsonEncoder::encodeStableMap', $protocol);
        self::assertStringContainsString('StableJsonDecoder::decodeStableMap', $protocol);
        self::assertStringNotContainsString('StableJsonEncoder $', $protocol);
        self::assertStringNotContainsString('StableJsonDecoder $', $protocol);
        self::assertStringNotContainsString('StableJsonEncoder::class', $provider);
        self::assertStringNotContainsString('StableJsonDecoder::class', $provider);

        self::assertStringContainsString('WorkerLifecycleLock', $runtime);
        self::assertStringNotContainsString('WorkerLifecycleLock', $protocol);
        self::assertStringNotContainsString(
            'WorkerLifecycleLock',
            self::source('src/Process/Bootstrap/WorkerProcessBootstrapEndpoint.php')
        );
        self::assertStringNotContainsString(
            'WorkerLifecycleLock',
            self::source('src/Process/Bootstrap/WorkerProcessBootstrapClient.php')
        );
        self::assertStringNotContainsString(
            'WorkerLifecycleLock',
            self::source('src/Process/Bootstrap/WorkerProcessBootstrapLauncher.php')
        );
        self::assertStringNotContainsString('WorkerLifecycleLock', $procHostRuntime);
    }

    public function testSourceTreeContainsNoLegacyInitialBootstrapAuthorityModel(): void
    {
        $files = [
            'src/Process/Bootstrap/WorkerProcessBootstrapProtocol.php',
            'src/Process/Bootstrap/WorkerProcessBootstrapEndpoint.php',
            'src/Process/Bootstrap/WorkerProcessBootstrapClient.php',
            'src/Process/Bootstrap/WorkerProcessBootstrapLauncher.php',

            'src/Process/Guardian/WorkerProcessGuardianClient.php',
            'src/Process/Guardian/WorkerProcessGuardianProtocol.php',

            'src/Process/Proc/WorkerProcProcessHostClient.php',
            'src/Process/Proc/WorkerProcProcessHostProtocol.php',

            'src/Process/Entrypoint/worker-guardian.php',
            'src/Process/Entrypoint/worker-proc-host.php',
            'src/Process/Entrypoint/WorkerProcProcessHostEntrypointRuntime.php',

            'bin/coretsia-worker-guardian',
            'bin/coretsia-worker-proc-host',
        ];
        $source = '';
        foreach ($files as $file) {
            $source .= self::source($file);
        }

        foreach (
            [
                'OPERATION_HELLO',
                'reserveLoopbackPort',
                '--coretsia-guardian-port',
                '--coretsia-guardian-token',
                '--coretsia-guardian-driver',
                '--coretsia-proc-host-port',
                '--coretsia-proc-host-token'
            ] as $legacy
        ) {
            self::assertStringNotContainsString($legacy, $source);
        }
    }

    public function testRetainedLoopbackListenersSharePlatformOwnershipPrimitive(): void
    {
        $listener = self::source('src/Internal/WorkerLoopbackListener.php');
        $capabilities = self::source('src/Internal/WorkerProcessCapabilities.php');

        foreach (
            [
                self::source('src/Process/Bootstrap/WorkerProcessBootstrapEndpoint.php'),
                self::source('src/Process/Proc/WorkerProcProcessHostHandoffEndpoint.php'),
                self::source('src/Communication/WorkerChildReadinessChannel.php'),
            ] as $owner
        ) {
            self::assertStringContainsString(
                'WorkerLoopbackListener::create()',
                $owner,
            );

            self::assertStringNotContainsString(
                'stream_socket_server(',
                $owner,
            );

            self::assertStringNotContainsString(
                'socket_create(',
                $owner,
            );
        }

        self::assertStringContainsString('SO_EXCLUSIVEADDRUSE', $listener);
        self::assertStringContainsString('SOMAXCONN', $listener);
        self::assertStringContainsString('socket_export_stream(', $listener);
        self::assertStringContainsString(
            'WorkerLoopbackListener::available($platformFamily)',
            $capabilities,
        );
    }
}
