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
 * Validation rules for the `worker` configuration subtree.
 *
 * These rules intentionally keep the Worker config strict:
 * - the file validates the `worker` root subtree;
 * - unknown keys are rejected at every declared map level;
 * - reserved `@*` keys are rejected by the same strict-shape policy;
 * - worker process counts and request limits must be positive integers;
 * - task type is limited to `queue` or `http`;
 * - process driver selection is limited to `auto`, `pcntl`, or `proc`;
 * - control transport selection is limited to `auto`, `unix`, or `tcp`;
 * - configurable runtime paths must be relative safe paths, must not use the
 *   `skeleton/` prefix, and must not contain `@`-prefixed segments;
 * - lifecycle lock and locator paths are package-owned and are rejected as
 *   mutable config keys by the strict root shape;
 * - guardian and generation-fence ownership are package-owned invariants; no
 *   mutable `worker.guardian.*` configuration surface exists;
 * - numeric keys must be integers only; floats are rejected by `int` type rules;
 * - TCP port `0` is rejected through `min = 1`.
 *
 * Integer bounds are enforced through declarative `min` / `max` rules.
 * `worker.tcp.port` is locked to the deterministic TCP port range `1..65535`;
 * port `0` is forbidden because it makes endpoint identity and worker state
 * non-deterministic across runs.
 *
 * The defaults file must return the subtree only:
 *
 *     config/worker.php => ['workers' => 4, 'task_type' => 'queue', ...]
 *
 * It must not return:
 *
 *     ['worker' => [...]]
 */
return [
    'schemaVersion' => 1,
    'configRoot' => 'worker',
    'additionalKeys' => false,
    'keys' => [
        'workers' => [
            'required' => true,
            'type' => 'int',
            'min' => 1,
        ],
        'max_requests' => [
            'required' => true,
            'type' => 'int',
            'min' => 1,
        ],
        'task_type' => [
            'required' => true,
            'type' => 'string',
            'allowedValues' => [
                'http',
                'queue',
            ],
        ],
        'socket_path' => [
            'required' => true,
            'type' => 'relative-safe-path',
            'forbiddenPrefixes' => [
                'skeleton/',
            ],
            'forbiddenSegmentPrefixes' => [
                '@',
            ],
        ],
        'driver' => [
            'required' => true,
            'type' => 'string',
            'allowedValues' => [
                'auto',
                'pcntl',
                'proc',
            ],
        ],
        'proc' => [
            'required' => true,
            'type' => 'map',
            'additionalKeys' => false,
            'keys' => [
                'command' => [
                    'required' => true,
                    'type' => 'list',
                    'items' => [
                        'type' => 'non-empty-string',
                    ],
                ],
            ],
        ],
        'control' => [
            'required' => true,
            'type' => 'map',
            'additionalKeys' => false,
            'keys' => [
                'transport' => [
                    'required' => true,
                    'type' => 'string',
                    'allowedValues' => [
                        'auto',
                        'unix',
                        'tcp',
                    ],
                ],
            ],
        ],
        'tcp' => [
            'required' => true,
            'type' => 'map',
            'additionalKeys' => false,
            'keys' => [
                'host' => [
                    'required' => true,
                    'type' => 'string',
                    'allowedValues' => [
                        '127.0.0.1',
                    ],
                ],
                'port' => [
                    'required' => true,
                    'type' => 'int',
                    'min' => 1,
                    'max' => 65535,
                ],
            ],
        ],
        'state_path' => [
            'required' => true,
            'type' => 'relative-safe-path',
            'forbiddenPrefixes' => [
                'skeleton/',
            ],
            'forbiddenSegmentPrefixes' => [
                '@',
            ],
        ],
        'stop_flag_path' => [
            'required' => true,
            'type' => 'relative-safe-path',
            'forbiddenPrefixes' => [
                'skeleton/',
            ],
            'forbiddenSegmentPrefixes' => [
                '@',
            ],
        ],
        'start_timeout_ms' => [
            'required' => true,
            'type' => 'int',
            'min' => 1,
            'max' => 86_400_000,
        ],
        'stop_timeout_ms' => [
            'required' => true,
            'type' => 'int',
            'min' => 1,
            'max' => 86_400_000,
        ],
        'force_kill_timeout_ms' => [
            'required' => true,
            'type' => 'int',
            'min' => 1,
            'max' => 86_400_000,
        ],
    ],
];
