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

use Coretsia\Contracts\Config\ConfigRuleset;
use Coretsia\Kernel\Config\ConfigValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerConfigPathConstraintContractTest extends TestCase
{
    /**
     * @param non-empty-string $pathKey
     * @param non-empty-string $path
     */
    #[DataProvider('rejectedWorkerPathProvider')]
    public function testPhaseBRejectsPathsRejectedByWorkerPoolSpec(
        string $pathKey,
        string $path,
    ): void {
        $config = self::workerGlobalConfig();
        $config['worker'][$pathKey] = $path;

        $result = new ConfigValidator()->validate(
            $config,
            [self::workerRuleset()],
        );

        self::assertTrue($result->isFailure());
        self::assertCount(1, $result->violations());

        $violation = $result->violations()[0];

        self::assertSame('worker', $violation->root());
        self::assertSame($pathKey, $violation->path());
        self::assertSame('relative-safe-path', $violation->reason());
        self::assertSame('relative-safe-path', $violation->expected());
        self::assertSame('string', $violation->actualType());

        $diagnostics = \json_encode(
            $result->toArray(),
            \JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString($path, $diagnostics);
    }

    public function testWorkerRulesDeclareRuntimePathConstraints(): void
    {
        $rules = require self::workerRulesPath();

        self::assertIsArray($rules);

        foreach (
            [
                'socket_path',
                'state_path',
                'stop_flag_path',
            ] as $pathKey
        ) {
            $rule = $rules['keys'][$pathKey];

            self::assertSame('relative-safe-path', $rule['type']);
            self::assertSame(
                ['skeleton/'],
                $rule['forbiddenPrefixes'],
            );
            self::assertSame(
                ['@'],
                $rule['forbiddenSegmentPrefixes'],
            );
        }
    }

    public function testWorkerDefaultPathsPassPhaseBValidation(): void
    {
        $result = new ConfigValidator()->validate(
            self::workerGlobalConfig(),
            [self::workerRuleset()],
        );

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->violations());
    }

    /**
     * @return iterable<string, array{0:non-empty-string,1:non-empty-string}>
     */
    public static function rejectedWorkerPathProvider(): iterable
    {
        foreach (
            [
                'socket_path',
                'state_path',
                'stop_flag_path',
            ] as $pathKey
        ) {
            yield $pathKey . '-skeleton-prefix' => [
                $pathKey,
                'skeleton/var/tmp/worker.runtime',
            ];

            yield $pathKey . '-at-prefixed-segment' => [
                $pathKey,
                'var/@private/worker.runtime',
            ];
        }
    }

    /**
     * @return array{worker:array<string,mixed>}
     */
    private static function workerGlobalConfig(): array
    {
        $config = require self::workerConfigPath();

        self::assertIsArray($config);

        return [
            'worker' => $config,
        ];
    }

    private static function workerRuleset(): ConfigRuleset
    {
        $rules = require self::workerRulesPath();

        self::assertIsArray($rules);

        return ConfigRuleset::fromArray('worker', $rules);
    }

    private static function workerConfigPath(): string
    {
        return \dirname(__DIR__, 2) . '/config/worker.php';
    }

    private static function workerRulesPath(): string
    {
        return \dirname(__DIR__, 2) . '/config/rules.php';
    }
}
