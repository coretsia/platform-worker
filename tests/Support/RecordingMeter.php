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

use Coretsia\Contracts\Observability\Metrics\MeterPortInterface;

final class RecordingMeter implements MeterPortInterface
{
    /** @var list<array{name:string,delta:int,labels:array<string,string|int|bool>}> */
    public array $increments = [];
    /** @var list<array{name:string,value:int,labels:array<string,string|int|bool>}> */
    public array $observations = [];
    public bool $throw = false;

    public function increment(string $name, int $delta = 1, array $labels = []): void
    {
        if ($this->throw) {
            throw new \RuntimeException('meter-failure');
        }
        $this->increments[] = ['name' => $name, 'delta' => $delta, 'labels' => $labels];
    }

    public function observe(string $name, int $value, array $labels = []): void
    {
        if ($this->throw) {
            throw new \RuntimeException('meter-failure');
        }
        $this->observations[] = ['name' => $name, 'value' => $value, 'labels' => $labels];
    }
}
