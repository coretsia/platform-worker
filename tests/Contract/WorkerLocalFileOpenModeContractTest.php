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

final class WorkerLocalFileOpenModeContractTest extends PackageTestCase
{
    public function testLifecycleLockUsesPlatformAwareCloseOnExecMode(): void
    {
        $source = self::source('src/Runtime/WorkerLifecycleLock.php');

        self::assertStringContainsString('self::openMode()', $source);
        self::assertStringContainsString("? 'c+b'", $source);
        self::assertStringContainsString(": 'c+be'", $source);
        self::assertStringNotContainsString("fopen(\$path, 'c+b')", $source);
    }

    public function testLifecycleLocatorTemporaryFileUsesPlatformAwareCloseOnExecMode(): void
    {
        $source = self::source('src/Runtime/WorkerLifecycleLocatorStore.php');

        self::assertStringContainsString('self::temporaryOpenMode()', $source);
        self::assertStringContainsString("? 'x+b'", $source);
        self::assertStringContainsString(": 'x+be'", $source);
        self::assertStringNotContainsString("fopen(\$temporaryPath, 'x+b')", $source);
    }
}
