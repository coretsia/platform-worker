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

namespace Coretsia\Platform\Worker\Tests\Unit;

use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerControlCredentialTest extends TestCase
{
    public function testGeneratesDistinctLowercaseHexCredentials(): void
    {
        $first = WorkerControlCredential::generate();
        $second = WorkerControlCredential::generate();

        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $first->encoded(),
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $second->encoded(),
        );
        self::assertNotSame($first->encoded(), $second->encoded());
    }

    public function testValidEncodedCredentialRoundTripsAndMatchesExactly(): void
    {
        $encoded = \str_repeat('a', 64);
        $credential = WorkerControlCredential::fromEncoded($encoded);

        self::assertSame($encoded, $credential->encoded());
        self::assertTrue(
            $credential->matches(
                WorkerControlCredential::fromEncoded($encoded),
            ),
        );
        self::assertFalse(
            $credential->matches(
                WorkerControlCredential::fromEncoded(
                    \str_repeat('b', 64),
                ),
            ),
        );
        self::assertFalse(
            new \ReflectionClass($credential)->hasMethod('__toString'),
        );
    }

    #[DataProvider('invalidCredentials')]
    public function testRejectsInvalidEncodedCredential(string $encoded): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('worker-control-credential-invalid');

        WorkerControlCredential::fromEncoded($encoded);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCredentials(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [\str_repeat('a', 63)];
        yield 'long' => [\str_repeat('a', 65)];
        yield 'uppercase' => [\str_repeat('A', 64)];
        yield 'whitespace' => [\str_repeat('a', 63) . ' '];
        yield 'control' => [\str_repeat('a', 63) . "\n"];
        yield 'non-hex' => [\str_repeat('g', 64)];
    }
}
