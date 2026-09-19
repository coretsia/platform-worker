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

use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;

/**
 * Builds complete deterministic worker specs for tests.
 */
final class WorkerSpecFactory
{
    /**
     * @param array<string, mixed> $override
     */
    public static function create(array $override = []): WorkerPoolSpec
    {
        $config = require \dirname(__DIR__, 2) . '/config/worker.php';

        if (!\is_array($config)) {
            throw new \RuntimeException('worker-test-defaults-invalid');
        }

        $config = self::merge($config, $override);

        return WorkerPoolSpec::fromConfig(
            config: $config,
            pcntlDriverAvailable: true,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
            procDriverAvailable: true,
        );
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    public static function merge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (
                \array_key_exists($key, $left)
                && \is_array($left[$key])
                && \is_array($value)
                && !\array_is_list($left[$key])
                && !\array_is_list($value)
            ) {
                $left[$key] = self::merge($left[$key], $value);

                continue;
            }

            $left[$key] = $value;
        }

        return $left;
    }
}
