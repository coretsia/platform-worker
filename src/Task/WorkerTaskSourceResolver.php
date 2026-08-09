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

namespace Coretsia\Platform\Worker\Task;

use Coretsia\Contracts\Worker\WorkerTaskSourceInterface;
use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Foundation\Tag\TaggedService;
use Coretsia\Foundation\Tag\TagRegistry;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Psr\Container\ContainerInterface;

/**
 * Resolves exactly one task source for the selected worker task type.
 *
 * @internal
 */
final readonly class WorkerTaskSourceResolver
{
    public function __construct(
        private ContainerInterface $container,
        private TagRegistry $tags,
    ) {
    }

    public function resolve(WorkerTaskType $taskType): WorkerTaskSourceInterface
    {
        $matches = [];

        foreach ($this->tags->all(ReservedTags::WORKER_TASK_SOURCE) as $taggedService) {
            if (self::registeredTaskType($taggedService) === $taskType) {
                $matches[] = $taggedService;
            }
        }

        if ($matches === []) {
            throw WorkerStartFailedException::taskSourceMissing();
        }

        if (\count($matches) !== 1) {
            throw WorkerStartFailedException::taskSourceAmbiguous();
        }

        $service = $this->resolveService($matches[0]);

        if (!$service instanceof WorkerTaskSourceInterface) {
            throw WorkerStartFailedException::taskSourceInvalid();
        }

        try {
            $actualType = $service->taskType();
        } catch (\Throwable) {
            throw WorkerStartFailedException::taskSourceInvalid();
        }

        if ($actualType !== $taskType) {
            throw WorkerStartFailedException::taskSourceInvalid();
        }

        return $service;
    }

    private function resolveService(TaggedService $taggedService): mixed
    {
        try {
            $hasService = $this->container->has($taggedService->id());
        } catch (\Throwable) {
            throw WorkerStartFailedException::taskSourceUnresolvable();
        }

        if (!$hasService) {
            throw WorkerStartFailedException::taskSourceUnresolvable();
        }

        try {
            return $this->container->get($taggedService->id());
        } catch (\Throwable) {
            throw WorkerStartFailedException::taskSourceUnresolvable();
        }
    }

    private static function registeredTaskType(TaggedService $taggedService): WorkerTaskType
    {
        $meta = $taggedService->meta();

        if (\array_keys($meta) !== ['task_type'] || !\is_string($meta['task_type'])) {
            throw WorkerStartFailedException::taskSourceInvalid();
        }

        return WorkerTaskType::tryFrom($meta['task_type']) ?? throw WorkerStartFailedException::taskSourceInvalid();
    }
}
