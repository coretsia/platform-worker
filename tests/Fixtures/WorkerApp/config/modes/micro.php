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

/**
 * Worker integration fixture micro mode.
 *
 * This fixture intentionally expresses module enable/disable policy through a
 * mode preset override.
 *
 * It MUST NOT be replaced by `config/modules.php`.
 *
 * ModulePlanResolver must resolve modules from:
 *
 * - package/default mode presets;
 * - skeleton/app mode overrides;
 * - Composer module metadata.
 *
 * It must not read:
 *
 * - `config/modules.php`;
 * - `apps/<app>/config/modules.php`.
 */
return [
    'schemaVersion' => 1,
    'name' => 'micro',
    'description' => 'Worker integration fixture micro mode.',
    'required' => [
        'core.foundation',
        'core.kernel',
        'platform.worker',
    ],
    'optional' => [
        'platform.logging',
        'platform.metrics',
        'platform.tracing',
    ],
    'disabled' => [
        'platform.cli',
    ],
    'featureBundles' => [
        'observability' => 'minimal',
        'runtime' => 'worker',
    ],
    'metadata' => [
        'fixture' => 'platform.worker',
    ],
];
