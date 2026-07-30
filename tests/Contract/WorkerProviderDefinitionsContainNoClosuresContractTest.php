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

use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionBuilder;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionContext;
use Coretsia\Foundation\Container\Definition\ContainerDefinitionProviderInterface;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\RuntimePathContext;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Task\HttpTaskFactory;
use Coretsia\Platform\Worker\Task\QueueTaskFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use PHPUnit\Framework\TestCase;

final class WorkerProviderDefinitionsContainNoClosuresContractTest extends TestCase
{
    public function testWorkerProviderDefinitionsContainNoClosures(): void
    {
        $provider = new WorkerServiceProvider();

        self::assertInstanceOf(
            ContainerDefinitionProviderInterface::class,
            $provider,
        );

        $definitions = new ContainerDefinitionBuilder();
        $provider->define(
            $definitions,
            new ContainerDefinitionContext([]),
        );

        $definitionSet = $definitions->build();
        $descriptorStream = $definitionSet->toDescriptorStream();

        self::assertNotSame([], $descriptorStream);
        self::assertContainsNoClosure($descriptorStream);
        self::assertContainsNoAnonymousFunctionTokens(
            self::classSource(WorkerServiceProvider::class),
        );

        self::assertIsString(
            \json_encode(
                $descriptorStream,
                \JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testWorkerProviderDeclaresCanonicalRequiredServiceIds(): void
    {
        $definitions = new ContainerDefinitionBuilder();

        new WorkerServiceProvider()->define(
            $definitions,
            new ContainerDefinitionContext([]),
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
            $definitions->build()->requiredServiceIds(),
        );
    }

    private static function assertContainsNoClosure(mixed $value): void
    {
        self::assertNotInstanceOf(\Closure::class, $value);

        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $nested) {
            self::assertContainsNoClosure($nested);
        }
    }

    private static function assertContainsNoAnonymousFunctionTokens(
        string $source,
    ): void {
        $tokens = \token_get_all($source);
        $count = \count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (!\is_array($token)) {
                continue;
            }

            self::assertNotSame(
                \T_FN,
                $token[0],
                'WorkerServiceProvider must not contain arrow-function closures.',
            );

            if ($token[0] !== \T_FUNCTION) {
                continue;
            }

            for ($next = $index + 1; $next < $count; $next++) {
                $candidate = $tokens[$next];

                if (
                    \is_array($candidate)
                    && $candidate[0] === \T_WHITESPACE
                ) {
                    continue;
                }

                if ($candidate === '&') {
                    continue;
                }

                self::assertIsArray(
                    $candidate,
                    'WorkerServiceProvider must not contain anonymous functions.',
                );
                self::assertSame(
                    \T_STRING,
                    $candidate[0],
                    'WorkerServiceProvider must not contain anonymous functions.',
                );

                break;
            }
        }
    }

    /**
     * @param class-string $className
     */
    private static function classSource(string $className): string
    {
        $reflection = new \ReflectionClass($className);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
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
