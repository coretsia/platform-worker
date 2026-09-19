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

namespace Coretsia\Platform\Worker\Tests\Integration;

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class ArtifactOnlyWorkerContainerBootTest extends PackageTestCase
{
    public function testPackageMetadataPointsOnlyToCurrentModuleAndProvider(): void
    {
        $composer = \json_decode(self::source('composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(
            'Coretsia\\Platform\\Worker\\Module\\WorkerModule',
            $composer['extra']['coretsia']['moduleClass'] ?? null,
        );
        self::assertSame(
            ['Coretsia\\Platform\\Worker\\Provider\\WorkerServiceProvider'],
            $composer['extra']['coretsia']['providers'] ?? null,
        );
    }
}
