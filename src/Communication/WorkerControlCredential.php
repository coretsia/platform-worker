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

namespace Coretsia\Platform\Worker\Communication;

/**
 * Immutable supervisor-instance credential for worker control requests.
 *
 * The encoded value is private capability data. It must not be rendered,
 * logged, serialized into public state, or exposed through diagnostics.
 */
final readonly class WorkerControlCredential
{
    private const string PATTERN = '/\A[a-f0-9]{64}\z/';

    private function __construct(
        #[\SensitiveParameter]
        private string $encoded,
    ) {
    }

    public static function generate(): self
    {
        return new self(
            \bin2hex(\random_bytes(32)),
        );
    }

    public static function fromEncoded(
        #[\SensitiveParameter]
        string $encoded,
    ): self {
        if (\preg_match(self::PATTERN, $encoded) !== 1) {
            throw new \InvalidArgumentException('worker-control-credential-invalid');
        }

        return new self($encoded);
    }

    public function encoded(): string
    {
        return $this->encoded;
    }

    public function matches(
        #[\SensitiveParameter]
        self $candidate,
    ): bool {
        return \hash_equals(
            $this->encoded,
            $candidate->encoded,
        );
    }
}
