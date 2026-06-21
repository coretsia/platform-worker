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
use Coretsia\Kernel\Config\Exception\ConfigInvalidException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerConfigSubtreeShapeContractTest extends TestCase
{
    public function testWorkerConfigPhpReturnsSubtreeOnlyAndDoesNotRepeatRootKey(): void
    {
        $defaults = self::workerDefaults();

        self::assertFalse(
            \array_is_list($defaults),
            'platform/worker config/worker.php MUST return the worker subtree map.',
        );

        self::assertArrayNotHasKey(
            'worker',
            $defaults,
            'platform/worker config/worker.php MUST NOT repeat root key "worker".',
        );

        self::assertSame(
            [
                'enabled',
                'workers',
                'max_requests',
                'task_type',
                'socket_path',
                'driver',
                'proc',
                'control',
                'tcp',
                'state_path',
                'stop_flag_path',
                'stop_timeout_ms',
            ],
            \array_keys($defaults),
            'platform/worker config/worker.php key order is part of the deterministic defaults contract.',
        );
    }

    public function testWorkerConfigPhpContainsNoReservedAtKeysAtAnyDepth(): void
    {
        self::assertNoReservedAtKeys(
            value: self::workerDefaults(),
            path: 'worker',
        );
    }

    public function testWorkerConfigPhpContainsOnlyDeterministicScalarListMapValues(): void
    {
        self::assertDeterministicConfigValue(
            value: self::workerDefaults(),
            path: 'worker',
        );
    }

    public function testWorkerConfigPhpDefaultsPassWorkerRulesetValidation(): void
    {
        self::assertWorkerConfigValid(self::workerDefaults());
    }

    public function testWorkerRulesRejectRepeatedWorkerRootKeyInReturnedSubtree(): void
    {
        self::assertWorkerConfigInvalid([
            'worker' => self::workerDefaults(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('provideReservedAtKeyOverrides')]
    public function testWorkerRulesRejectReservedAtKeysAtAnyDeclaredMapDepth(array $overrides): void
    {
        self::assertWorkerConfigInvalid(
            self::mergeWorkerConfig(
                base: self::workerDefaults(),
                overrides: $overrides,
            ),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideReservedAtKeyOverrides(): iterable
    {
        yield 'root reserved key' => [
            [
                '@merge' => [],
            ],
        ];

        yield 'proc reserved key' => [
            [
                'proc' => [
                    '@replace' => [],
                ],
            ],
        ];

        yield 'control reserved key' => [
            [
                'control' => [
                    '@remove' => [],
                ],
            ],
        ];

        yield 'tcp reserved key' => [
            [
                'tcp' => [
                    '@append' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerDefaults(): array
    {
        $defaults = require self::workerConfigFile();

        self::assertIsArray($defaults, 'platform/worker config/worker.php MUST return an array.');

        /** @var array<string, mixed> $defaults */
        return $defaults;
    }

    private static function assertWorkerConfigValid(array $workerConfig): void
    {
        self::validator()->assertValid(
            config: [
                'worker' => $workerConfig,
            ],
            rulesets: [
                self::workerRuleset(),
            ],
        );

        self::assertTrue(true, 'Worker config passed worker ruleset validation.');
    }

    /**
     * @param array<string, mixed> $workerConfig
     */
    private static function assertWorkerConfigInvalid(array $workerConfig): void
    {
        try {
            self::assertWorkerConfigValid($workerConfig);

            self::fail('Expected worker config validation to fail.');
        } catch (ConfigInvalidException $exception) {
            self::assertInstanceOf(ConfigInvalidException::class, $exception);
        }
    }

    private static function validator(): ConfigValidator
    {
        return new ConfigValidator();
    }

    private static function workerRuleset(): ConfigRuleset
    {
        $rules = require self::workerRulesFile();

        self::assertIsArray($rules, 'platform/worker config/rules.php MUST return an array.');

        /** @var array<string, mixed> $rules */
        return ConfigRuleset::fromArray('worker', $rules);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function mergeWorkerConfig(array $base, array $overrides): array
    {
        return \array_replace_recursive($base, $overrides);
    }

    private static function assertNoReservedAtKeys(mixed $value, string $path): void
    {
        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $childPath = self::appendPath($path, $key);

            if (\is_string($key) && \str_starts_with($key, '@')) {
                self::fail('Worker config defaults MUST NOT contain reserved @* key at ' . $childPath . '.');
            }

            self::assertNoReservedAtKeys($item, $childPath);
        }
    }

    private static function assertDeterministicConfigValue(mixed $value, string $path): void
    {
        if (\is_bool($value) || \is_int($value) || \is_string($value)) {
            return;
        }

        if ($value === null) {
            self::fail('Worker config defaults MUST NOT contain null at ' . $path . '.');
        }

        if (\is_float($value)) {
            self::fail('Worker config defaults MUST NOT contain float at ' . $path . '.');
        }

        if (\is_object($value)) {
            self::fail('Worker config defaults MUST NOT contain object/closure at ' . $path . '.');
        }

        if (\is_resource($value)) {
            self::fail('Worker config defaults MUST NOT contain resource at ' . $path . '.');
        }

        if (!\is_array($value)) {
            self::fail('Worker config defaults contain unsupported value at ' . $path . '.');
        }

        if (\array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::assertDeterministicConfigValue($item, $path . '[' . $index . ']');
            }

            return;
        }

        foreach ($value as $key => $item) {
            if (!\is_string($key) || $key === '') {
                self::fail('Worker config defaults MUST use non-empty string keys at ' . $path . '.');
            }

            if (\str_contains($key, "\0") || \str_contains($key, "\r") || \str_contains($key, "\n")) {
                self::fail('Worker config defaults MUST NOT contain control characters in key at ' . $path . '.');
            }

            self::assertDeterministicConfigValue($item, $path . '.' . $key);
        }
    }

    private static function appendPath(string $path, int|string $key): string
    {
        if (\is_int($key)) {
            return $path . '[' . $key . ']';
        }

        return $path . '.' . $key;
    }

    private static function workerConfigFile(): string
    {
        $file = self::packageRoot() . '/config/worker.php';

        self::assertFileExists($file);

        return $file;
    }

    private static function workerRulesFile(): string
    {
        $file = self::packageRoot() . '/config/rules.php';

        self::assertFileExists($file);

        return $file;
    }

    private static function packageRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
