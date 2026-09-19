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

use PHPUnit\Framework\TestCase;

final class WorkerConfigSubtreeShapeContractTest extends TestCase
{
    public function testDefaultsAndRulesExposeTheCompleteStrictRuntimeShape(): void
    {
        $root = \dirname(__DIR__, 2);
        $defaults = require $root . '/config/worker.php';
        $rules = require $root . '/config/rules.php';

        self::assertIsArray($defaults);
        self::assertIsArray($rules);
        self::assertArrayNotHasKey('worker', $defaults);

        self::assertSame(
            [
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
                'start_timeout_ms',
                'stop_timeout_ms',
                'force_kill_timeout_ms',
            ],
            \array_keys($defaults),
        );

        self::assertSame(false, $rules['additionalKeys']);
        self::assertArrayNotHasKey('lock_path', $defaults);
        self::assertArrayNotHasKey('lock_path', $rules['keys']);
        self::assertSame(\array_keys($defaults), \array_keys($rules['keys']));

        foreach (
            [
                'start_timeout_ms',
                'stop_timeout_ms',
                'force_kill_timeout_ms',
            ] as $key
        ) {
            self::assertSame(1, $rules['keys'][$key]['min']);
            self::assertSame(86_400_000, $rules['keys'][$key]['max']);
        }

        self::assertSame(
            ['127.0.0.1'],
            $rules['keys']['tcp']['keys']['host']['allowedValues'],
        );
    }
}
