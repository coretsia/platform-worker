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

use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;

final class WorkerProviderDefinitionsContainNoClosuresContractTest extends PackageTestCase
{
    public function testProviderUsesSerializableDefinitionsAndContainsNoOldManagerServices(): void
    {
        $source = self::source('src/Provider/WorkerServiceProvider.php');
        self::assertSame(
            0,
            \preg_match('/=>\s*(?:static\s+)?function|=>\s*fn\s*\(/', $source),
            'Provider service definitions must not embed closures.',
        );
        self::assertStringNotContainsString('WorkerManager::class', $source);
        self::assertStringNotContainsString('WorkerSocketServer::class', $source);
    }
}
