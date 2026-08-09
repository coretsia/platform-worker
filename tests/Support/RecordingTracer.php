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

use Coretsia\Contracts\Observability\Tracing\SpanInterface;
use Coretsia\Contracts\Observability\Tracing\TracerPortInterface;

final class RecordingTracer implements TracerPortInterface
{
    /** @var list<RecordingSpan> */
    public array $spans = [];
    public bool $throwOnStart = false;

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        if ($this->throwOnStart) {
            throw new \RuntimeException('tracer-failure');
        }
        $span = new RecordingSpan($name);
        $span->setAttributes($attributes);
        $this->spans[] = $span;
        return $span;
    }

    public function inSpan(string $name, callable $callback, array $attributes = []): mixed
    {
        $span = $this->startSpan($name, $attributes);
        try {
            return $callback($span);
        } finally {
            $span->end();
        }
    }

    public function currentSpan(): ?SpanInterface
    {
        return $this->spans === [] ? null : $this->spans[\array_key_last($this->spans)];
    }
}
