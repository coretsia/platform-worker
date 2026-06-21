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

use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WorkerInternalInterfacesAreNotPublicApiContractTest extends TestCase
{
    /**
     * @param class-string $interface
     */
    #[DataProvider('provideInternalInterfaces')]
    public function testWorkerInternalInterfacesAreUnderInternalNamespace(string $interface): void
    {
        $reflection = new ReflectionClass($interface);

        self::assertTrue($reflection->isInterface());
        self::assertStringStartsWith(
            'Coretsia\\Platform\\Worker\\Internal\\',
            $reflection->getName(),
        );
    }

    /**
     * @param class-string $interface
     */
    #[DataProvider('provideInternalInterfaces')]
    public function testWorkerInternalInterfacesContainInternalDocblockMarker(string $interface): void
    {
        $reflection = new ReflectionClass($interface);
        $docComment = $reflection->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@internal', $docComment);
    }

    public function testReadmeDocumentsWorkerInternalInterfacesAsInternalNotExtensionPoints(): void
    {
        $readme = self::readme();

        self::assertStringContainsString(
            'The following interfaces are package-internal:',
            $readme,
        );

        self::assertStringContainsString(
            'They are not public package APIs.',
            $readme,
        );

        self::assertStringContainsString(
            'They are not extension points for application code.',
            $readme,
        );

        foreach (self::forbiddenReadmeExtensionPointPhrases() as $phrase) {
            self::assertStringNotContainsString(
                $phrase,
                $readme,
                'README must not document worker internal interfaces as extension points.',
            );
        }
    }

    public function testComposerExtraDoesNotExportWorkerInternalInterfaces(): void
    {
        $composer = self::composerJson();

        self::assertArrayHasKey('extra', $composer);
        self::assertIsArray($composer['extra']);

        $extraJson = \json_encode(
            $composer['extra'],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );

        self::assertIsString($extraJson);

        foreach (self::internalInterfaceNames() as $name) {
            self::assertStringNotContainsString(
                $name,
                $extraJson,
                'composer extra MUST NOT export worker internal interface: ' . $name,
            );
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideInternalInterfaces(): iterable
    {
        yield 'worker manager driver' => [
            WorkerManagerDriverInterface::class,
        ];

        yield 'task factory internal' => [
            TaskFactoryInternalInterface::class,
        ];
    }

    /**
     * @return list<string>
     */
    private static function internalInterfaceNames(): array
    {
        return [
            WorkerManagerDriverInterface::class,
            TaskFactoryInternalInterface::class,
            'WorkerManagerDriverInterface',
            'TaskFactoryInternalInterface',
        ];
    }

    /**
     * @return list<string>
     */
    private static function forbiddenReadmeExtensionPointPhrases(): array
    {
        return [
            'WorkerManagerDriverInterface is a public extension point',
            'TaskFactoryInternalInterface is a public extension point',
            'WorkerManagerDriverInterface is an extension point',
            'TaskFactoryInternalInterface is an extension point',
            'implement WorkerManagerDriverInterface in application code',
            'implement TaskFactoryInternalInterface in application code',
            'extend WorkerManagerDriverInterface',
            'extend TaskFactoryInternalInterface',
            'public process-driver extension point',
            'public task-source extension point',
            'third-party process-driver API',
            'third-party task-source API',
        ];
    }

    private static function readme(): string
    {
        $contents = \file_get_contents(self::packageRoot() . '/README.md');

        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private static function composerJson(): array
    {
        $contents = \file_get_contents(self::packageRoot() . '/composer.json');

        self::assertIsString($contents);

        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function packageRoot(): string
    {
        $file = new ReflectionClass(WorkerManagerDriverInterface::class)->getFileName();

        self::assertIsString($file);

        return \dirname($file, 3);
    }
}
