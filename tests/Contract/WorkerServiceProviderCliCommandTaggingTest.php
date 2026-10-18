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

use Coretsia\Foundation\Container\ContainerBuilder;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Platform\Worker\Console\WorkerHealthCommand;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerServiceProviderCliCommandTaggingTest extends PackageTestCase
{
    public function testAllFourCommandsAreDefinedAndTagged(): void
    {
        $source = self::source(
            'src/Provider/WorkerServiceProvider.php',
        );

        foreach (
            [
                WorkerStartCommand::class,
                WorkerStopCommand::class,
                WorkerStatusCommand::class,
                WorkerHealthCommand::class,
            ] as $command
        ) {
            self::assertStringContainsString(
                new \ReflectionClass($command)->getShortName() . '::class',
                $source,
            );
        }

        self::assertGreaterThanOrEqual(
            4,
            \substr_count($source, 'ReservedTags::CLI_COMMAND'),
        );

        $builder = new ContainerBuilder();
        $builder->register(new WorkerServiceProvider());
        $tags = $builder->tagRegistry()->all(ReservedTags::CLI_COMMAND);

        $expected = [];
        foreach (
            [
                WorkerStartCommand::class,
                WorkerStopCommand::class,
                WorkerStatusCommand::class,
                WorkerHealthCommand::class,
            ] as $command
        ) {
            $expected[$command] = [
                'arguments' => $command::ARGUMENTS,
                'group' => $command::GROUP,
                'hidden' => $command::HIDDEN,
                'name' => $command::NAME,
                'options' => $command::OPTIONS,
                'summary' => $command::SUMMARY,
            ];
        }

        self::assertCount(4, $tags);
        $actual = [];
        foreach ($tags as $tag) {
            self::assertSame(0, $tag->priority());
            self::assertArrayHasKey($tag->id(), $expected);
            $actual[$tag->id()] = $tag->meta();
            self::assertSame(
                ['arguments', 'group', 'hidden', 'name', 'options', 'summary'],
                \array_keys($tag->meta()),
            );
        }

        \ksort($expected);
        \ksort($actual);
        self::assertSame($expected, $actual);
    }
}
