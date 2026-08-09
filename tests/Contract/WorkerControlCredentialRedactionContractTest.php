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

use Coretsia\Platform\Worker\Communication\WorkerControlClient;
use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use Coretsia\Platform\Worker\Communication\WorkerControlProtocol;
use Coretsia\Platform\Worker\Communication\WorkerControlRequest;
use Coretsia\Platform\Worker\Communication\WorkerControlServer;
use Coretsia\Platform\Worker\Communication\WorkerControlSession;
use Coretsia\Platform\Worker\Communication\WorkerControlTransport;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocator;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocatorStore;
use Coretsia\Platform\Worker\Supervisor\WorkerSupervisor;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerControlCredentialRedactionContractTest extends PackageTestCase
{
    public function testCredentialIsAbsentFromPublicStateResponsesCommandsAndObservability(): void
    {
        foreach (
            [
                'src/Runtime/WorkerPoolState.php',
                'src/Runtime/WorkerHealthState.php',
                'src/Runtime/WorkerStateStore.php',
                'src/Communication/WorkerControlResponse.php',
                'src/Console/WorkerStartCommand.php',
                'src/Console/WorkerStatusCommand.php',
                'src/Console/WorkerHealthCommand.php',
                'src/Console/WorkerStopCommand.php',
            ] as $path
        ) {
            $source = self::source($path);

            self::assertStringNotContainsString(
                "'control_credential' =>",
                $source,
                $path,
            );
            self::assertStringNotContainsString(
                "'credential' =>",
                $source,
                $path,
            );
        }
    }

    public function testCredentialIsNotAContainerServiceOrPublicDiagnosticField(): void
    {
        foreach (
            [
                'src/Provider/WorkerServiceFactory.php',
                'src/Provider/WorkerServiceProvider.php',
            ] as $path
        ) {
            self::assertStringNotContainsString(
                'WorkerControlCredential',
                self::source($path),
                $path,
            );
        }

        $client = self::source(
            'src/Communication/WorkerControlClient.php',
        );

        self::assertStringContainsString(
            'credential: $locator->controlCredential()',
            $client,
        );
        self::assertStringNotContainsString(
            "'control_credential' =>",
            $client,
        );
        self::assertStringNotContainsString(
            "'credential' =>",
            $client,
        );
    }

    public function testRawCredentialBoundariesAreMarkedSensitive(): void
    {
        foreach (
            [
                'src/Communication/WorkerControlCredential.php',
                'src/Communication/WorkerControlProtocol.php',
                'src/Communication/WorkerControlRequest.php',
                'src/Communication/WorkerControlServer.php',
                'src/Runtime/WorkerLifecycleLocator.php',
                'src/Runtime/WorkerLifecycleLocatorStore.php',
            ] as $path
        ) {
            self::assertStringContainsString(
                '#[\\SensitiveParameter]',
                self::source($path),
                $path,
            );
        }
    }

    public function testOnlyPrivateWireAndLocatorShapesEncodeCredential(): void
    {
        $encodedCallSites = [];

        foreach (
            [
                'src/Communication/WorkerControlRequest.php',
                'src/Communication/WorkerControlServer.php',
                'src/Runtime/WorkerLifecycleLocator.php',
                'src/Runtime/WorkerLifecycleLocatorStore.php',
                'src/Communication/WorkerControlClient.php',
                'src/Supervisor/WorkerSupervisor.php',
            ] as $path
        ) {
            if (\str_contains(self::source($path), '->encoded()')) {
                $encodedCallSites[] = $path;
            }
        }

        self::assertSame(
            [
                'src/Communication/WorkerControlRequest.php',
                'src/Runtime/WorkerLifecycleLocator.php',
            ],
            $encodedCallSites,
        );
    }

    public function testEverySecretBearingObjectParameterIsSensitive(): void
    {
        $secretTypes = [
            WorkerControlCredential::class,
            WorkerControlRequest::class,
            WorkerControlSession::class,
            WorkerLifecycleLocator::class,
        ];

        foreach (
            [
                WorkerControlCredential::class,
                WorkerControlRequest::class,
                WorkerControlSession::class,
                WorkerControlProtocol::class,
                WorkerControlServer::class,
                WorkerControlClient::class,
                WorkerControlTransport::class,
                WorkerLifecycleLocator::class,
                WorkerLifecycleLocatorStore::class,
                WorkerSupervisor::class,
            ] as $class
        ) {
            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();

                    if (
                        !$type instanceof \ReflectionNamedType
                        || !\in_array($type->getName(), $secretTypes, true)
                    ) {
                        continue;
                    }

                    self::assertNotSame(
                        [],
                        $parameter->getAttributes(\SensitiveParameter::class),
                        $class
                        . '::'
                        . $method->getName()
                        . '($'
                        . $parameter->getName()
                        . ') must be sensitive.',
                    );
                }
            }
        }
    }

    public function testOnlyPrivateLocatorAndRequestFramesSerializeCredential(): void
    {
        self::assertStringContainsString(
            "'control_credential' =>",
            self::source('src/Runtime/WorkerLifecycleLocator.php'),
        );
        self::assertStringContainsString(
            "'credential' =>",
            self::source('src/Communication/WorkerControlRequest.php'),
        );
        self::assertStringNotContainsString(
            '__toString',
            self::source('src/Communication/WorkerControlCredential.php'),
        );
    }
}
