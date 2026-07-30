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

use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Foundation\Container\Container;
use Coretsia\Foundation\Container\ContainerBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionContext;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionKind;
use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Foundation\Tag\TagRegistry;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\RuntimePathContext;
use Coretsia\Platform\Worker\Communication\WorkerSocketServer;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Internal\WorkerManagerResolverInterface;
use Coretsia\Platform\Worker\Manager\ContainerWorkerManagerResolver;
use Coretsia\Platform\Worker\Manager\Driver\PcntlWorkerManagerDriver;
use Coretsia\Platform\Worker\Manager\Driver\ProcWorkerManagerDriver;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use PHPUnit\Framework\TestCase;

final class WorkerProviderSourceDefinitionsParityTest extends TestCase
{
    /**
     * @var list<class-string>
     */
    private const array SERVICE_IDS = [
        WorkerServiceFactory::class,
        WorkerPoolSpec::class,
        WorkerRuntimeEntrypointGuard::class,
        StableJsonEncoder::class,
        StableJsonDecoder::class,
        WorkerStateStore::class,
        WorkerSocketServer::class,
        QueueTaskFactory::class,
        HttpTaskFactory::class,
        TaskFactoryInternalInterface::class,
        ApplicationWorker::class,
        PcntlWorkerManagerDriver::class,
        ProcWorkerManagerDriver::class,
        WorkerManager::class,
        ContainerWorkerManagerResolver::class,
        WorkerStartCommand::class,
        WorkerStopCommand::class,
        WorkerStatusCommand::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    private const array ALIASES = [
        WorkerManagerResolverInterface::class => ContainerWorkerManagerResolver::class,
    ];

    /**
     * @var list<class-string>
     */
    private const array SOURCE_RESOLVABLE_SHARED_SERVICE_IDS = [
        WorkerServiceFactory::class,
        StableJsonEncoder::class,
        StableJsonDecoder::class,
        WorkerStateStore::class,
        WorkerSocketServer::class,
        QueueTaskFactory::class,
        ContainerWorkerManagerResolver::class,
    ];

    public function testSourceRegistrationMatchesCanonicalDefinitions(): void
    {
        $provider = new WorkerServiceProvider();

        $sourceBuilder = new ContainerBuilder();
        $sourceBuilder->register($provider);

        $sourceContainer = $sourceBuilder->build();

        $definitions = new ContainerDefinitionBuilder();
        $provider->define(
            $definitions,
            new ContainerDefinitionContext([]),
        );

        $definitionSet = $definitions->build();
        $operations = $definitionSet->toDescriptorStream();

        self::assertSame(
            self::sorted([
                ...self::SERVICE_IDS,
                ...\array_keys(self::ALIASES),
            ]),
            self::sourceBindingIds($sourceBuilder),
        );
        self::assertSame(
            self::SERVICE_IDS,
            self::canonicalServiceIds($operations),
        );
        self::assertSame(
            self::ALIASES,
            self::canonicalAliases($operations),
        );
        self::assertSame(
            self::expectedCommandTags(),
            self::canonicalTags($operations),
        );
        self::assertSame(
            self::sorted([
                ConfigRepositoryInterface::class,
                ModulePlan::class,
                RuntimePathContext::class,
                WorkerPoolSpec::class,
                WorkerRuntimeEntrypointGuard::class,
                ApplicationWorker::class,
                WorkerManager::class,
                QueueTaskFactory::class,
                HttpTaskFactory::class,
            ]),
            $definitionSet->requiredServiceIds(),
        );

        self::assertAliasParity($sourceContainer);
        self::assertSharedFlagParity($sourceContainer);
        self::assertTagParity($sourceBuilder->tagRegistry());
    }

    /**
     * @return list<string>
     */
    private static function sourceBindingIds(
        ContainerBuilder $builder,
    ): array {
        return self::sorted(
            \array_values(
                \array_filter(
                    $builder->serviceIds(),
                    static fn (string $id): bool => $id !== TagRegistry::class,
                ),
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return list<string>
     */
    private static function canonicalServiceIds(
        array $operations,
    ): array {
        $ids = [];

        foreach ($operations as $operation) {
            $kind = $operation['kind'] ?? null;

            if (!\is_string($kind) || !self::isServiceKind($kind)) {
                continue;
            }

            $id = $operation['id'] ?? null;
            $shared = $operation['shared'] ?? null;

            if (!\is_string($id) || !\is_bool($shared)) {
                throw new \LogicException('worker-provider-parity-service-invalid');
            }

            self::assertTrue($shared);

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return array<string, string>
     */
    private static function canonicalAliases(
        array $operations,
    ): array {
        $aliases = [];

        foreach ($operations as $operation) {
            if (
                ($operation['kind'] ?? null)
                !== ContainerDefinitionKind::ALIAS->value
            ) {
                continue;
            }

            $alias = $operation['alias'] ?? null;
            $serviceId = $operation['serviceId'] ?? null;

            if (!\is_string($alias) || !\is_string($serviceId)) {
                throw new \LogicException('worker-provider-parity-alias-invalid');
            }

            $aliases[$alias] = $serviceId;
        }

        return $aliases;
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalTags(
        array $operations,
    ): array {
        return \array_values(
            \array_filter(
                $operations,
                static fn (array $operation): bool => ($operation['kind'] ?? null)
                    === ContainerDefinitionKind::TAG->value,
            ),
        );
    }

    private static function assertAliasParity(
        Container $container,
    ): void {
        self::assertSame(
            $container->get(ContainerWorkerManagerResolver::class),
            $container->get(WorkerManagerResolverInterface::class),
        );
    }

    private static function assertSharedFlagParity(
        Container $container,
    ): void {
        foreach (self::SOURCE_RESOLVABLE_SHARED_SERVICE_IDS as $serviceId) {
            self::assertSame(
                $container->get($serviceId),
                $container->get($serviceId),
            );
        }
    }

    private static function assertTagParity(
        TagRegistry $registry,
    ): void {
        self::assertSame(
            [
                ReservedTags::CLI_COMMAND,
            ],
            $registry->tagNames(),
        );

        $services = $registry->all(ReservedTags::CLI_COMMAND);
        $expected = self::expectedCommandTags();

        \usort(
            $expected,
            static fn (array $left, array $right): int => \strcmp(
                (string)$left['serviceId'],
                (string)$right['serviceId'],
            ),
        );

        self::assertCount(\count($expected), $services);

        foreach ($services as $index => $service) {
            self::assertSame($expected[$index]['serviceId'], $service->id());
            self::assertSame($expected[$index]['priority'], $service->priority());
            self::assertSame($expected[$index]['meta'], $service->meta());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function expectedCommandTags(): array
    {
        return [
            self::commandTag(
                serviceId: WorkerStartCommand::class,
                name: WorkerStartCommand::NAME,
                summary: WorkerStartCommand::SUMMARY,
                group: WorkerStartCommand::GROUP,
                hidden: WorkerStartCommand::HIDDEN,
                mode: WorkerStartCommand::MODE,
                arguments: WorkerStartCommand::ARGUMENTS,
                options: WorkerStartCommand::OPTIONS,
            ),
            self::commandTag(
                serviceId: WorkerStopCommand::class,
                name: WorkerStopCommand::NAME,
                summary: WorkerStopCommand::SUMMARY,
                group: WorkerStopCommand::GROUP,
                hidden: WorkerStopCommand::HIDDEN,
                mode: WorkerStopCommand::MODE,
                arguments: WorkerStopCommand::ARGUMENTS,
                options: WorkerStopCommand::OPTIONS,
            ),
            self::commandTag(
                serviceId: WorkerStatusCommand::class,
                name: WorkerStatusCommand::NAME,
                summary: WorkerStatusCommand::SUMMARY,
                group: WorkerStatusCommand::GROUP,
                hidden: WorkerStatusCommand::HIDDEN,
                mode: WorkerStatusCommand::MODE,
                arguments: WorkerStatusCommand::ARGUMENTS,
                options: WorkerStatusCommand::OPTIONS,
            ),
        ];
    }

    /**
     * @param class-string $serviceId
     * @param list<array<string, mixed>> $arguments
     * @param list<array<string, mixed>> $options
     *
     * @return array<string, mixed>
     */
    private static function commandTag(
        string $serviceId,
        string $name,
        string $summary,
        string $group,
        bool $hidden,
        string $mode,
        array $arguments,
        array $options,
    ): array {
        return [
            'kind' => ContainerDefinitionKind::TAG->value,
            'meta' => [
                'arguments' => $arguments,
                'group' => $group,
                'hidden' => $hidden,
                'mode' => $mode,
                'name' => $name,
                'options' => $options,
                'summary' => $summary,
            ],
            'priority' => 0,
            'serviceId' => $serviceId,
            'tag' => ReservedTags::CLI_COMMAND,
        ];
    }

    private static function isServiceKind(string $kind): bool
    {
        return $kind
            === ContainerDefinitionKind::SERVICE_CLASS->value
            || $kind
            === ContainerDefinitionKind::SERVICE_FACTORY_CLASS_METHOD->value
            || $kind
            === ContainerDefinitionKind::SERVICE_FACTORY_SERVICE_METHOD->value;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        \usort(
            $values,
            static fn (string $left, string $right): int => \strcmp($left, $right),
        );

        return $values;
    }
}
