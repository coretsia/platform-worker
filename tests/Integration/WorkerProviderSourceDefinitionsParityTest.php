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

use Coretsia\Foundation\Container\ContainerBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionContext;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionKind;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Platform\Worker\Console\WorkerHealthCommand;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerProviderSourceDefinitionsParityTest extends PackageTestCase
{
    public function testProviderContainsCanonicalSupervisorProcessAndControlDefinitions(): void
    {
        $provider = self::source('src/Provider/WorkerServiceProvider.php');
        foreach (
            [
                'WorkerSupervisor::class',
                'ContainerWorkerSupervisorResolver::class',
                'PcntlWorkerProcessDriver::class',
                'ContainerWorkerProcessDriverResolver::class',
                'WorkerProcessDriverResolverInterface::class',
                'WorkerChildCommandBuilder::class',
                'ProcWorkerProcessDriver::class',
                'WorkerProcessGuardianClient::class',
                'WorkerProcessGuardianInterface::class',
                'WorkerControlClient::class',
                'WorkerLifecycleLocatorStore::class',
                "'workerLifecycleLocatorStore'",
                'WorkerHealthCommand::class',
                'WorkerTaskSourceResolver::class',
                'WorkerTaskSourceInterface::class',
                'TagRegistry::class',
            ] as $required
        ) {
            self::assertStringContainsString($required, $provider);
        }

        foreach (
            [
                'WorkerManager',
                'WorkerSocketServer',
                'worker.manager_driver',
                'worker.process_driver',
                'ReservedTags::WORKER_TASK_SOURCE',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $provider);
        }
    }

    public function testSourceCliCommandTagsMatchCanonicalDefinitions(): void
    {
        $provider = new WorkerServiceProvider();
        $builder = new ContainerBuilder();
        $builder->register($provider);

        $definitions = new ContainerDefinitionBuilder();
        $provider->define($definitions, new ContainerDefinitionContext([]));

        $definitionMeta = [];
        foreach ($definitions->build()->toDescriptorStream() as $operation) {
            if (
                ($operation['kind'] ?? null) !== ContainerDefinitionKind::TAG->value
                || ($operation['tag'] ?? null) !== ReservedTags::CLI_COMMAND
            ) {
                continue;
            }

            self::assertSame(0, $operation['priority']);
            self::assertArrayNotHasKey($operation['serviceId'], $definitionMeta);
            $definitionMeta[$operation['serviceId']] = $operation['meta'];
        }

        $sourceMeta = [];
        $tags = $builder->tagRegistry()->all(ReservedTags::CLI_COMMAND);
        self::assertCount(4, $tags);
        foreach ($tags as $tag) {
            self::assertSame(0, $tag->priority());
            $sourceMeta[$tag->id()] = $tag->meta();
        }

        self::assertCount(4, $definitionMeta);
        foreach (
            [
                WorkerStartCommand::class,
                WorkerStopCommand::class,
                WorkerStatusCommand::class,
                WorkerHealthCommand::class,
            ] as $command
        ) {
            $expected = [
                'arguments' => $command::ARGUMENTS,
                'group' => $command::GROUP,
                'hidden' => $command::HIDDEN,
                'name' => $command::NAME,
                'options' => $command::OPTIONS,
                'summary' => $command::SUMMARY,
            ];

            self::assertArrayHasKey($command, $sourceMeta);
            self::assertArrayHasKey($command, $definitionMeta);
            self::assertSame($expected, $sourceMeta[$command]);
            self::assertSame($expected, $definitionMeta[$command]);
        }

        \ksort($sourceMeta);
        \ksort($definitionMeta);
        self::assertSame($sourceMeta, $definitionMeta);
    }
}
