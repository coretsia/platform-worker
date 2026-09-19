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

use Coretsia\Platform\Worker\Module\WorkerModule;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use PHPUnit\Framework\TestCase;

final class CrossCuttingNoopDoesNotThrowTest extends TestCase
{
    public function testModuleProviderAndConfigAreLoadableWithoutSideEffects(): void
    {
        $module = new WorkerModule();

        self::assertSame('platform.worker', $module->id());
        self::assertSame([WorkerServiceProvider::class], $module->providers());
        self::assertInstanceOf(WorkerServiceProvider::class, new WorkerServiceProvider());

        $root = \dirname(__DIR__, 2);

        \ob_start();
        $defaults = require $root . '/config/worker.php';
        $defaultsOutput = \ob_get_clean();

        \ob_start();
        $rules = require $root . '/config/rules.php';
        $rulesOutput = \ob_get_clean();

        self::assertSame('', $defaultsOutput);
        self::assertSame('', $rulesOutput);
        self::assertIsArray($defaults);
        self::assertIsArray($rules);
    }

    public function testPackageMetadataDeclaresOnlyRuntimeModuleSurface(): void
    {
        $composer = \json_decode(
            (string)\file_get_contents(
                \dirname(__DIR__, 2) . '/composer.json',
            ),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertSame('coretsia/platform-worker', $composer['name']);
        self::assertSame('runtime', $composer['extra']['coretsia']['kind']);
        self::assertSame('platform.worker', $composer['extra']['coretsia']['moduleId']);
        self::assertSame(
            [WorkerServiceProvider::class],
            $composer['extra']['coretsia']['providers'],
        );
        self::assertArrayNotHasKey(
            'coretsia/platform-http',
            $composer['require'],
        );
        self::assertNotContains(
            'platform.http',
            $composer['extra']['coretsia']['requires'],
        );
    }
}
