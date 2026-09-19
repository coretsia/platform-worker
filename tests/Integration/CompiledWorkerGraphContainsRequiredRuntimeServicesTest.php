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

use Coretsia\Foundation\Container\Definition\ContainerDefinitionBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionContext;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class CompiledWorkerGraphContainsRequiredRuntimeServicesTest extends PackageTestCase
{
    public function testProviderDeclaresEveryRequiredRuntimeServiceAndAliasWithoutBootstrapPrimitives(): void
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
                'WorkerProcessGuardianClient::class',
                'WorkerProcessGuardianInterface::class',
                'WorkerSupervisorInterface::class',
                'WorkerSupervisorResolverInterface::class',
            ] as $service
        ) {
            self::assertStringContainsString($service, $source);
        }

        foreach (
            [
                'WorkerProcessGuardianTransport',
                'StableJsonEncoder::class',
                'StableJsonDecoder::class',
                'WorkerProcessBootstrapProtocol::class',
                'WorkerProcessBootstrapEndpoint::class',
                'WorkerProcessBootstrapClient::class',
                'WorkerProcessBootstrapLauncher::class',
                'WorkerProcessBootstrapFailure::class',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testActualWorkerDefinitionSetDoesNotDefineOrRequireBootstrapPrimitives(): void
    {
        $definitions = new ContainerDefinitionBuilder();

        new WorkerServiceProvider()->define(
            $definitions,
            new ContainerDefinitionContext([]),
        );

        $set = $definitions->build();
        $declaredIds = [];

        foreach ($set->toDescriptorStream() as $descriptor) {
            foreach (['id', 'alias'] as $key) {
                $id = $descriptor[$key] ?? null;

                if (\is_string($id)) {
                    $declaredIds[$id] = true;
                }
            }
        }

        foreach (
            [
                'Coretsia\\Platform\\Worker\\Runtime\\WorkerLifecycleLock',
                'Coretsia\\Platform\\Worker\\Process\\Guardian\\WorkerProcessGuardianProtocol',
                'Coretsia\\Platform\\Worker\\Process\\Guardian\\WorkerProcessGuardianClient',
                'Coretsia\\Platform\\Worker\\Internal\\WorkerProcessGuardianInterface',
            ] as $required
        ) {
            self::assertArrayHasKey(
                $required,
                $declaredIds,
            );
        }

        foreach (
            [
                'Coretsia\\Foundation\\Serialization\\StableJsonEncoder',
                'Coretsia\\Foundation\\Serialization\\StableJsonDecoder',
                'Coretsia\\Platform\\Worker\\Process\\Bootstrap\\WorkerProcessBootstrapProtocol',
                'Coretsia\\Platform\\Worker\\Process\\Bootstrap\\WorkerProcessBootstrapEndpoint',
                'Coretsia\\Platform\\Worker\\Process\\Bootstrap\\WorkerProcessBootstrapClient',
                'Coretsia\\Platform\\Worker\\Process\\Bootstrap\\WorkerProcessBootstrapLauncher',
                'Coretsia\\Platform\\Worker\\Process\\Bootstrap\\WorkerProcessBootstrapFailure',
                'Coretsia\\Platform\\Worker\\Process\\Guardian\\WorkerProcessGuardianTransport',
            ] as $forbidden
        ) {
            self::assertArrayNotHasKey(
                $forbidden,
                $declaredIds,
            );

            self::assertNotContains(
                $forbidden,
                $set->requiredServiceIds(),
            );
        }
    }

    public function testActualWorkerServiceMethodFactoryWiringMatchesFactorySignatures(): void
    {
        $definitions = new ContainerDefinitionBuilder();

        new WorkerServiceProvider()->define(
            $definitions,
            new ContainerDefinitionContext([]),
        );

        $operations = $definitions->build()->toDescriptorStream();
        $serviceClasses = [];

        foreach ($operations as $operation) {
            if (($operation['kind'] ?? null) !== 'service.class') {
                continue;
            }

            $id = $operation['id'] ?? null;
            $class = $operation['class'] ?? null;

            self::assertIsString($id);
            self::assertIsString($class);

            $serviceClasses[$id] = $class;
        }

        foreach ($operations as $operation) {
            if (
                ($operation['kind'] ?? null)
                !== 'service.factory.service-method'
            ) {
                continue;
            }

            $id = $operation['id'] ?? null;
            $factoryServiceId = $operation['factoryServiceId'] ?? null;
            $methodName = $operation['method'] ?? null;
            $arguments = $operation['arguments'] ?? null;

            self::assertIsString($id);
            self::assertIsString($factoryServiceId);
            self::assertIsString($methodName);
            self::assertIsArray($arguments);
            self::assertArrayHasKey(
                $factoryServiceId,
                $serviceClasses,
            );

            $method = new \ReflectionMethod(
                $serviceClasses[$factoryServiceId],
                $methodName,
            );

            self::assertTrue($method->isPublic());
            self::assertFalse($method->isStatic());

            $parameters = $method->getParameters();

            self::assertGreaterThanOrEqual(
                $method->getNumberOfRequiredParameters(),
                \count($arguments),
                $id,
            );

            self::assertLessThanOrEqual(
                \count($parameters),
                \count($arguments),
                $id,
            );

            foreach ($arguments as $index => $argument) {
                self::assertIsArray($argument);
                self::assertSame(
                    'service',
                    $argument['type'] ?? null,
                    $id,
                );
                self::assertArrayHasKey(
                    $index,
                    $parameters,
                    $id,
                );

                $parameterType = $parameters[$index]->getType();

                self::assertInstanceOf(
                    \ReflectionNamedType::class,
                    $parameterType,
                    $id,
                );
                self::assertFalse(
                    $parameterType->isBuiltin(),
                    $id,
                );
                self::assertSame(
                    $parameterType->getName(),
                    $argument['id'] ?? null,
                    $id,
                );
            }

            $returnType = $method->getReturnType();

            self::assertInstanceOf(
                \ReflectionNamedType::class,
                $returnType,
                $id,
            );
            self::assertFalse(
                $returnType->isBuiltin(),
                $id,
            );
            self::assertSame(
                $id,
                $returnType->getName(),
                $id,
            );
        }
    }
}
