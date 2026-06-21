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

use Coretsia\Contracts\Cli\Command\CommandInterface;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use PHPUnit\Framework\TestCase;

final class WorkerCommandMetadataConstantsTest extends TestCase
{
    public function testWorkerCommandNamesAreStable(): void
    {
        self::assertSame('worker:start', WorkerStartCommand::NAME);
        self::assertSame('worker:stop', WorkerStopCommand::NAME);
        self::assertSame('worker:status', WorkerStatusCommand::NAME);
    }

    public function testWorkerCommandNamesMatchCanonicalRegex(): void
    {
        foreach (self::commandClasses() as $class) {
            $name = $class::NAME;

            self::assertSame(
                1,
                \preg_match('/' . CommandInterface::COMMAND_NAME_PATTERN . '/', $name),
                $class . '::NAME must match the canonical command name pattern.',
            );
        }
    }

    public function testNameMethodReturnsNameConstant(): void
    {
        foreach (self::commandClasses() as $class) {
            $command = new \ReflectionClass($class)->newInstanceWithoutConstructor();

            self::assertInstanceOf(CommandInterface::class, $command);

            self::assertSame(
                $class::NAME,
                $command->name(),
                $class . '::name() must return self::NAME.',
            );
        }
    }

    public function testRequiredCommandMetadataConstantsExistWithExpectedTypes(): void
    {
        foreach (self::commandClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            foreach (self::metadataConstantNames() as $constantName) {
                self::assertTrue(
                    $reflection->hasConstant($constantName),
                    $class . ' must define metadata constant ' . $constantName . '.',
                );

                $constant = $reflection->getReflectionConstant($constantName);

                self::assertNotNull($constant);
                self::assertTrue(
                    $constant->isPublic(),
                    $class . '::' . $constantName . ' must be public metadata.',
                );
            }

            self::assertIsString($class::NAME, $class . '::NAME must be string.');
            self::assertIsString($class::SUMMARY, $class . '::SUMMARY must be string.');
            self::assertIsString($class::GROUP, $class . '::GROUP must be string.');
            self::assertIsBool($class::HIDDEN, $class . '::HIDDEN must be bool.');
            self::assertIsString($class::MODE, $class . '::MODE must be string.');
            self::assertIsArray($class::ARGUMENTS, $class . '::ARGUMENTS must be array.');
            self::assertIsArray($class::OPTIONS, $class . '::OPTIONS must be array.');

            self::assertNotSame('', $class::NAME, $class . '::NAME must not be empty.');
            self::assertNotSame('', $class::SUMMARY, $class . '::SUMMARY must not be empty.');
            self::assertNotSame('', $class::GROUP, $class . '::GROUP must not be empty.');
            self::assertNotSame('', $class::MODE, $class . '::MODE must not be empty.');

            self::assertTrue(
                \array_is_list($class::ARGUMENTS),
                $class . '::ARGUMENTS must be a list.',
            );

            self::assertTrue(
                \array_is_list($class::OPTIONS),
                $class . '::OPTIONS must be a list.',
            );
        }
    }

    public function testCommandMetadataConstantsDoNotExposeUnsafeRuntimeData(): void
    {
        foreach (self::commandClasses() as $class) {
            foreach (self::metadataConstantNames() as $constantName) {
                $value = \constant($class . '::' . $constantName);

                foreach (self::stringLeaves($value) as $stringValue) {
                    self::assertSafeMetadataValue(
                        class: $class,
                        constantName: $constantName,
                        value: $stringValue,
                    );
                }
            }
        }
    }

    /**
     * @return list<class-string<CommandInterface>>
     */
    private static function commandClasses(): array
    {
        return [
            WorkerStartCommand::class,
            WorkerStopCommand::class,
            WorkerStatusCommand::class,
        ];
    }

    /**
     * @return list<non-empty-string>
     */
    private static function metadataConstantNames(): array
    {
        return [
            'NAME',
            'SUMMARY',
            'GROUP',
            'HIDDEN',
            'MODE',
            'ARGUMENTS',
            'OPTIONS',
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringLeaves(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $nestedValue) {
            foreach (self::stringLeaves($nestedValue) as $stringValue) {
                $strings[] = $stringValue;
            }
        }

        return $strings;
    }

    /**
     * @param class-string $class
     */
    private static function assertSafeMetadataValue(
        string $class,
        string $constantName,
        string $value,
    ): void {
        foreach (
            [
                'skeleton/',
                'var/',
                'var\\',
                'tmp/',
                'tmp\\',
                'cache/',
                'cache\\',
                'vendor/',
                'vendor\\',
                '/tmp',
                '\\tmp',
                '/var',
                '\\var',
                '://',
                'tcp:',
                'unix:',
                'http://',
                'https://',
                '127.0.0.1',
                '0.0.0.0',
                'localhost:',
                'PATH=',
                'HOME=',
                'APP_ENV',
                'APP_SECRET',
                'payload',
                'body',
                'headers',
                'Authorization',
                'authorization',
                'cookie',
                'secret',
                'token',
                'bearer',
                'password',
                'api_key',
                'apikey',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $value,
                $class . '::' . $constantName . ' must not expose unsafe metadata fragment: ' . $forbidden,
            );
        }

        foreach (
            [
                '/\A[A-Za-z]:[\/\\\\]/',
                '/(?:^|[^a-zA-Z0-9_])\/[a-zA-Z0-9._-]+/',
                '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}:[0-9]{1,5}\b/',
                '/\b[a-zA-Z0-9._-]+:[0-9]{2,5}\b/',
                '/\b[A-Z_]+=[^\s]+/',
            ] as $pattern
        ) {
            self::assertDoesNotMatchRegularExpression(
                $pattern,
                $value,
                $class . '::' . $constantName . ' must not expose unsafe metadata value.',
            );
        }
    }
}
