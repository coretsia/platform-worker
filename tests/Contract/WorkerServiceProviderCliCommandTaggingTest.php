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
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use PHPUnit\Framework\TestCase;

final class WorkerServiceProviderCliCommandTaggingTest extends TestCase
{
    public function testProviderSourceImportsReservedTagsAndDoesNotImportPlatformCli(): void
    {
        $source = self::providerSource();

        self::assertStringContainsString(
            'use Coretsia\\Foundation\\Tag\\ReservedTags;',
            $source,
        );

        foreach (
            [
                'use Coretsia\\Platform\\Cli\\',
                'Coretsia\\Platform\\Cli\\',
                'namespace Coretsia\\Platform\\Cli',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                'WorkerServiceProvider must not import or reference platform/cli symbol: ' . $forbidden,
            );
        }
    }

    public function testProviderSourceDoesNotUseRawCliCommandTagString(): void
    {
        $source = self::providerSourceWithoutCommentsAndStrings();

        self::assertStringNotContainsString("'cli.command'", $source);
        self::assertStringNotContainsString('"cli.command"', $source);
        self::assertStringContainsString('ReservedTags::CLI_COMMAND', $source);
    }

    public function testProviderRegistersWorkerCommandsAsContainerServices(): void
    {
        $source = self::providerSourceWithoutComments();

        foreach (self::commandClasses() as $class) {
            self::assertMatchesRegularExpression(
                '/->factory\(\s*' . self::shortClass($class) . '::class\s*,/s',
                $source,
                'WorkerServiceProvider must register ' . $class . ' as a container service.',
            );
        }
    }

    public function testProviderTagsWorkerCommandsWithReservedCliCommandTag(): void
    {
        $source = self::providerSourceWithoutComments();

        foreach (self::commandClasses() as $class) {
            $shortClass = self::shortClass($class);

            self::assertMatchesRegularExpression(
                '/->tag\(\s*ReservedTags::CLI_COMMAND\s*,\s*' . $shortClass . '::class\s*,\s*meta:/s',
                $source,
                'WorkerServiceProvider must tag ' . $class . ' with ReservedTags::CLI_COMMAND.',
            );
        }
    }

    public function testTagMetadataUsesCommandClassConstants(): void
    {
        $source = self::providerSourceWithoutComments();

        foreach (self::commandClasses() as $class) {
            $shortClass = self::shortClass($class);

            foreach (self::metadataConstantNames() as $constantName) {
                self::assertStringContainsString(
                    $shortClass . '::' . $constantName,
                    $source,
                    'Tag metadata for ' . $class . ' must reference ' . $shortClass . '::' . $constantName . '.',
                );
            }
        }
    }

    /**
     * @throws \ReflectionException
     */
    public function testTagMetadataNameEqualsCommandInstanceName(): void
    {
        foreach (self::commandClasses() as $class) {
            $command = new \ReflectionClass($class)->newInstanceWithoutConstructor();

            self::assertInstanceOf(CommandInterface::class, $command);

            $meta = self::commandMetaFor($class);

            self::assertSame(
                $command->name(),
                $meta['name'],
                'Tag metadata name must equal command instance name() for ' . $class . '.',
            );
        }
    }

    public function testTagMetadataContainsOnlyAllowlistedKeysAndNoPriority(): void
    {
        foreach (self::commandClasses() as $class) {
            $meta = self::commandMetaFor($class);

            self::assertSame(
                self::allowlistedMetadataKeys(),
                \array_keys($meta),
                'Tag metadata for ' . $class . ' must contain only allowlisted keys in deterministic order.',
            );

            self::assertArrayNotHasKey('priority', $meta);
        }
    }

    public function testTagMetadataContainsNoUnsafeValues(): void
    {
        foreach (self::commandClasses() as $class) {
            $meta = self::commandMetaFor($class);

            self::assertSafeMetadataValue($class, $meta);
        }
    }

    public function testProviderDoesNotBuildOrResolveCommandCatalog(): void
    {
        $source = self::providerSourceWithoutCommentsAndStrings();

        foreach (
            [
                'CommandCatalog',
                'CommandRegistry',
                'CommandDispatcher',
                'Coretsia\\Platform\\Cli',
                'Platform\\Cli',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                'WorkerServiceProvider must not build or resolve CLI catalog/dispatch infrastructure.',
            );
        }
    }

    public function testProviderRegistrationSourceDoesNotStartWorkersOrPerformRuntimeSideEffects(): void
    {
        $source = self::providerSourceWithoutCommentsAndStrings();

        foreach (
            [
                '->start(',
                'proc_open(',
                '\\proc_open(',
                'pcntl_fork(',
                '\\pcntl_fork(',
                'stream_socket_server(',
                '\\stream_socket_server(',
                'stream_socket_client(',
                '\\stream_socket_client(',
                'socket_create(',
                '\\socket_create(',
                'file_put_contents(',
                '\\file_put_contents(',
                'fopen(',
                '\\fopen(',
                'fwrite(',
                '\\fwrite(',
                'unlink(',
                '\\unlink(',
                'mkdir(',
                '\\mkdir(',
                'tokens(',
                'parse(',
                'CommandCatalog',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                'Registering WorkerServiceProvider must not perform runtime side effect: ' . $forbidden,
            );
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
     * @return list<non-empty-string>
     */
    private static function allowlistedMetadataKeys(): array
    {
        return [
            'name',
            'summary',
            'group',
            'hidden',
            'mode',
            'arguments',
            'options',
        ];
    }

    /**
     * @param class-string<CommandInterface> $class
     *
     * @return array{
     *     name: string,
     *     summary: string,
     *     group: string,
     *     hidden: bool,
     *     mode: string,
     *     arguments: list<array<string, mixed>>,
     *     options: list<array<string, mixed>>
     * }
     */
    private static function commandMetaFor(string $class): array
    {
        $method = new \ReflectionMethod(WorkerServiceProvider::class, 'commandMeta');

        /** @var array{
         *     name: string,
         *     summary: string,
         *     group: string,
         *     hidden: bool,
         *     mode: string,
         *     arguments: list<array<string, mixed>>,
         *     options: list<array<string, mixed>>
         * } $meta
         */
        $meta = $method->invokeArgs(
            null,
            [
                $class::NAME,
                $class::SUMMARY,
                $class::GROUP,
                $class::HIDDEN,
                $class::MODE,
                $class::ARGUMENTS,
                $class::OPTIONS,
            ],
        );

        return $meta;
    }

    /**
     * @param class-string $class
     */
    private static function shortClass(string $class): string
    {
        $position = \strrpos($class, '\\');

        if ($position === false) {
            return $class;
        }

        return \substr($class, $position + 1);
    }

    private static function providerSource(): string
    {
        $file = new \ReflectionClass(WorkerServiceProvider::class)->getFileName();

        self::assertIsString($file);
        self::assertFileExists($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }

    private static function providerSourceWithoutComments(): string
    {
        return self::stripTokens(
            stripComments: true,
            stripStrings: false,
        );
    }

    private static function providerSourceWithoutCommentsAndStrings(): string
    {
        return self::stripTokens(
            stripComments: true,
            stripStrings: true,
        );
    }

    private static function stripTokens(bool $stripComments, bool $stripStrings): string
    {
        $tokens = \token_get_all(self::providerSource());
        $source = '';

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                $source .= $token;

                continue;
            }

            if (
                $stripComments
                && (
                    $token[0] === \T_COMMENT
                    || $token[0] === \T_DOC_COMMENT
                )
            ) {
                continue;
            }

            if (
                $stripStrings
                && (
                    $token[0] === \T_CONSTANT_ENCAPSED_STRING
                    || $token[0] === \T_ENCAPSED_AND_WHITESPACE
                )
            ) {
                continue;
            }

            $source .= $token[1];
        }

        return $source;
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $value
     */
    private static function assertSafeMetadataValue(string $class, array $value): void
    {
        foreach ($value as $key => $metadataValue) {
            self::assertContains(
                $key,
                self::allowlistedMetadataKeys(),
                'Tag metadata contains a non-allowlisted key for ' . $class . '.',
            );

            self::assertNotInstanceOf(
                \Closure::class,
                $metadataValue,
                'Tag metadata must not contain closures.',
            );

            self::assertFalse(
                \is_object($metadataValue),
                'Tag metadata must not contain objects.',
            );

            self::assertFalse(
                \is_resource($metadataValue),
                'Tag metadata must not contain resources.',
            );

            foreach (self::stringLeaves($metadataValue) as $stringValue) {
                self::assertSafeString($class, $key, $stringValue);
            }
        }
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
    private static function assertSafeString(
        string $class,
        string|int $key,
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
                'Unsafe tag metadata fragment in ' . $class . '[' . $key . ']: ' . $forbidden,
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
                'Unsafe tag metadata value in ' . $class . '[' . $key . '].',
            );
        }
    }
}
