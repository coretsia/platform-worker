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

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class CompiledWorkerGraphContainsRequiredRuntimeServicesTest extends PackageTestCase
{
    public function testProviderDeclaresEveryRequiredRuntimeServiceAndAlias(): void
    {
        $source = self::source('src/Provider/WorkerServiceProvider.php');
        foreach (
            [
                'WorkerLifecycleLock::class',
                'WorkerLifecycleLocatorStore::class',
                'WorkerStopSignal::class',
                'WorkerControlTransport::class',
                'WorkerControlProtocol::class',
                'WorkerControlServer::class',
                'WorkerControlClient::class',
                'WorkerChildReadinessChannel::class',
                'WorkerChildTable::class',
                'WorkerSignalController::class',
                'WorkerChildCommandBuilder::class',
                'WorkerTaskSourceResolver::class',
                'WorkerTaskSourceInterface::class',
                'TagRegistry::class',
                'ApplicationWorker::class',
                'ContainerWorkerProcessDriverResolver::class',
                'WorkerProcessDriverResolverInterface::class',
                'WorkerSupervisor::class',
                'WorkerProcessGuardianProtocol::class',
                'WorkerProcessGuardianTransport::class',
                'WorkerProcessGuardianClient::class',
                'WorkerProcessGuardianInterface::class',
                'WorkerSupervisorInterface::class',
                'WorkerSupervisorResolverInterface::class',
            ] as $service
        ) {
            self::assertStringContainsString($service, $source);
        }
    }
}
