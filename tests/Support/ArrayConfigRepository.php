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

namespace Coretsia\Platform\Worker\Tests\Support;

use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Contracts\Config\ConfigValueSource;

/**
 * Deterministic in-memory merged configuration repository for tests.
 */
final readonly class ArrayConfigRepository implements ConfigRepositoryInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function has(string $keyPath): bool
    {
        self::lookup($this->values, $keyPath, $found);

        return $found;
    }

    public function get(string $keyPath, mixed $default = null): mixed
    {
        $value = self::lookup($this->values, $keyPath, $found);

        return $found ? $value : $default;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function sourceOf(string $keyPath): ?ConfigValueSource
    {
        return null;
    }

    public function explain(): array
    {
        return [];
    }

    private static function lookup(
        array $values,
        string $keyPath,
        ?bool &$found,
    ): mixed {
        $found = true;
        $current = $values;

        foreach (\explode('.', $keyPath) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                $found = false;

                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
