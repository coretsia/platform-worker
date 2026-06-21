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
use Coretsia\Contracts\Cli\Input\InputInterface;
use Coretsia\Contracts\Cli\Output\OutputInterface;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use PHPUnit\Framework\TestCase;

final class WorkerCommandsUseCliContractsOnlyTest extends TestCase
{
    public function testWorkerCommandClassesImplementContractsCommandInterface(): void
    {
        foreach (self::commandClasses() as $class) {
            self::assertTrue(
                \is_subclass_of($class, CommandInterface::class),
                $class . ' must implement ' . CommandInterface::class . '.',
            );

            $reflection = new \ReflectionClass($class);

            self::assertContains(
                CommandInterface::class,
                $reflection->getInterfaceNames(),
                $class . ' must directly or indirectly expose the contracts CLI command interface.',
            );
        }
    }

    public function testWorkerCommandClassesUseInputInterfaceAndOutputInterfaceInRunSignature(): void
    {
        foreach (self::commandClasses() as $class) {
            $method = new \ReflectionMethod($class, 'run');

            self::assertSame(2, $method->getNumberOfParameters(), $class . '::run() must accept exactly input/output.');

            $parameters = $method->getParameters();

            self::assertSame(
                InputInterface::class,
                self::namedType($parameters[0]),
                $class . '::run() first parameter must be ' . InputInterface::class . '.',
            );

            self::assertSame(
                OutputInterface::class,
                self::namedType($parameters[1]),
                $class . '::run() second parameter must be ' . OutputInterface::class . '.',
            );
        }
    }

    public function testWorkerCommandClassesDoNotImportPlatformCli(): void
    {
        foreach (self::commandClasses() as $class) {
            $source = self::source($class);

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
                    $class . ' must not import or reference platform/cli symbol: ' . $forbidden,
                );
            }
        }
    }

    public function testWorkerCommandClassesDoNotImplementPlatformCliCommandContractOrAdapter(): void
    {
        foreach (self::commandClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getInterfaceNames() as $interface) {
                self::assertFalse(
                    \str_starts_with($interface, 'Coretsia\\Platform\\Cli\\'),
                    $class . ' must not implement a platform/cli interface.',
                );

                self::assertStringNotContainsString(
                    '\\Platform\\Cli\\',
                    $interface,
                    $class . ' must not implement a platform/cli command contract or adapter.',
                );
            }

            $source = self::sourceWithoutComments($class);

            self::assertDoesNotMatchRegularExpression(
                '/\bimplements\b[^{;]*\\\\?Coretsia\\\\Platform\\\\Cli\\\\/m',
                $source,
                $class . ' must not implement a platform/cli command contract or adapter.',
            );

            self::assertStringNotContainsString(
                'Platform\\Cli',
                $source,
                $class . ' must not reference platform/cli command adapters.',
            );
        }
    }

    public function testWorkerCommandClassesDoNotUseDirectOutputSinks(): void
    {
        foreach (self::commandClasses() as $class) {
            $violations = self::directOutputSinkViolations(self::source($class));

            self::assertSame(
                [],
                $violations,
                $class . ' must not use direct output sinks.',
            );
        }
    }

    public function testWorkerCommandClassesDoNotUseTextualOutputSinkConstructsInExecutableCode(): void
    {
        foreach (self::commandClasses() as $class) {
            $code = self::sourceWithoutCommentsAndStrings($class);

            foreach (
                [
                    'echo' => '/\becho\b/',
                    'print' => '/\bprint\b/',
                    'printf' => '/\bprintf\s*\(/',
                    'fwrite(STDOUT)' => '/\bfwrite\s*\(\s*STDOUT\b/',
                    'fwrite(STDERR)' => '/\bfwrite\s*\(\s*STDERR\b/',
                    'var_dump' => '/\bvar_dump\s*\(/',
                    'print_r' => '/\bprint_r\s*\(/',
                    'error_log' => '/\berror_log\s*\(/',
                ] as $sink => $pattern
            ) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $code,
                    $class . ' must not use direct output sink: ' . $sink,
                );
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

    private static function namedType(\ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();

        self::assertInstanceOf(
            \ReflectionNamedType::class,
            $type,
            $parameter->getName() . ' must use a named type.',
        );

        self::assertFalse(
            $type->allowsNull(),
            $parameter->getName() . ' must not allow null.',
        );

        return $type->getName();
    }

    /**
     * @param class-string $class
     */
    private static function source(string $class): string
    {
        $file = new \ReflectionClass($class)->getFileName();

        self::assertIsString($file);
        self::assertFileExists($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }

    /**
     * @param class-string $class
     */
    private static function sourceWithoutComments(string $class): string
    {
        $source = self::source($class);
        $tokens = \token_get_all($source);

        $code = '';

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                $code .= $token;

                continue;
            }

            if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    /**
     * @param class-string $class
     */
    private static function sourceWithoutCommentsAndStrings(string $class): string
    {
        $source = self::source($class);
        $tokens = \token_get_all($source);

        $code = '';

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                $code .= $token;

                continue;
            }

            if (
                $token[0] === \T_COMMENT
                || $token[0] === \T_DOC_COMMENT
                || $token[0] === \T_CONSTANT_ENCAPSED_STRING
                || $token[0] === \T_ENCAPSED_AND_WHITESPACE
            ) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    /**
     * @return list<non-empty-string>
     */
    private static function directOutputSinkViolations(string $source): array
    {
        $tokens = \token_get_all($source);
        $violations = [];

        foreach ($tokens as $index => $token) {
            if (\is_array($token) && $token[0] === \T_ECHO) {
                $violations[] = 'echo';

                continue;
            }

            if (\is_array($token) && $token[0] === \T_PRINT) {
                $violations[] = 'print';

                continue;
            }

            if (!\is_array($token) || $token[0] !== \T_STRING) {
                continue;
            }

            $name = \strtolower($token[1]);

            if (
                \in_array(
                    $name,
                    [
                        'printf',
                        'var_dump',
                        'print_r',
                        'error_log',
                    ],
                    true,
                )
                && self::nextSignificantTokenValue($tokens, $index) === '('
            ) {
                $violations[] = $name;

                continue;
            }

            if (
                $name === 'fwrite'
                && self::nextSignificantTokenValue($tokens, $index) === '('
            ) {
                $firstArgument = self::firstCallArgumentTokenValue($tokens, $index);

                if ($firstArgument === 'STDOUT' || $firstArgument === 'STDERR') {
                    $violations[] = 'fwrite(' . $firstArgument . ')';
                }
            }
        }

        \sort($violations, \SORT_STRING);

        return \array_values(\array_unique($violations));
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function nextSignificantTokenValue(array $tokens, int $index): ?string
    {
        for ($i = $index + 1; $i < \count($tokens); $i++) {
            $token = $tokens[$i];

            if (self::isIgnorableToken($token)) {
                continue;
            }

            return self::tokenValue($token);
        }

        return null;
    }

    /**
     * Returns the first significant token after the opening `(` of a function call.
     *
     * @param list<mixed> $tokens
     */
    private static function firstCallArgumentTokenValue(array $tokens, int $functionNameIndex): ?string
    {
        $openingParenIndex = null;

        for ($i = $functionNameIndex + 1; $i < \count($tokens); $i++) {
            $token = $tokens[$i];

            if (self::isIgnorableToken($token)) {
                continue;
            }

            if (self::tokenValue($token) !== '(') {
                return null;
            }

            $openingParenIndex = $i;

            break;
        }

        if ($openingParenIndex === null) {
            return null;
        }

        for ($i = $openingParenIndex + 1; $i < \count($tokens); $i++) {
            $token = $tokens[$i];

            if (self::isIgnorableToken($token)) {
                continue;
            }

            return self::tokenValue($token);
        }

        return null;
    }

    private static function isIgnorableToken(mixed $token): bool
    {
        return \is_array($token)
            && (
                $token[0] === \T_WHITESPACE
                || $token[0] === \T_COMMENT
                || $token[0] === \T_DOC_COMMENT
            );
    }

    private static function tokenValue(mixed $token): string
    {
        if (\is_string($token)) {
            return $token;
        }

        self::assertIsArray($token);
        self::assertArrayHasKey(1, $token);
        self::assertIsString($token[1]);

        return $token[1];
    }
}
