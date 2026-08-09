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

namespace Coretsia\Platform\Worker\Tests\Unit;

use Coretsia\Contracts\Worker\WorkerTaskSourceInterface;
use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Foundation\Tag\TagRegistry;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Task\WorkerTaskSourceResolver;
use Coretsia\Platform\Worker\Tests\Support\RecordingWorkerTaskSource;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class WorkerTaskSourceResolverTest extends TestCase
{
    public function testRejectsMissingSelectedTaskSource(): void
    {
        $resolver = new WorkerTaskSourceResolver(
            new RecordingWorkerTaskSourceContainer([]),
            new TagRegistry(),
        );

        self::assertStartReason(
            WorkerStartFailedException::REASON_TASK_SOURCE_MISSING,
            static fn (): WorkerTaskSourceInterface => $resolver->resolve(WorkerTaskType::Queue),
        );
    }

    public function testResolvesExactlyOneMatchingSource(): void
    {
        $source = new RecordingWorkerTaskSource();
        $container = new RecordingWorkerTaskSourceContainer([
            'worker.source.queue' => $source,
            'worker.source.http' => new RecordingWorkerTaskSource(WorkerTaskType::Http),
        ]);
        $tags = new TagRegistry();
        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.queue',
            meta: ['task_type' => 'queue'],
        );
        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.http',
            meta: ['task_type' => 'http'],
        );

        $resolved = new WorkerTaskSourceResolver($container, $tags)
            ->resolve(WorkerTaskType::Queue);

        self::assertSame($source, $resolved);
        self::assertSame(['worker.source.queue'], $container->requested);
    }

    public function testRejectsAmbiguousMatchingSources(): void
    {
        $tags = new TagRegistry();
        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.one',
            meta: ['task_type' => 'queue'],
        );
        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.two',
            meta: ['task_type' => 'queue'],
        );

        $resolver = new WorkerTaskSourceResolver(
            new RecordingWorkerTaskSourceContainer([]),
            $tags,
        );

        self::assertStartReason(
            WorkerStartFailedException::REASON_TASK_SOURCE_AMBIGUOUS,
            static fn (): WorkerTaskSourceInterface => $resolver->resolve(WorkerTaskType::Queue),
        );
    }

    public function testRejectsInvalidMetadataAndUnknownTaskType(): void
    {
        foreach (
            [
                ['extra' => true, 'task_type' => 'queue'],
                ['task_type' => 'unknown'],
                ['task_type' => 1],
            ] as $meta
        ) {
            $tags = new TagRegistry();
            $tags->add(
                ReservedTags::WORKER_TASK_SOURCE,
                'worker.source.invalid',
                meta: $meta,
            );

            $resolver = new WorkerTaskSourceResolver(
                new RecordingWorkerTaskSourceContainer([]),
                $tags,
            );

            self::assertStartReason(
                WorkerStartFailedException::REASON_TASK_SOURCE_INVALID,
                static fn (): WorkerTaskSourceInterface => $resolver->resolve(WorkerTaskType::Queue),
            );
        }
    }

    public function testRejectsMissingOrThrowingContainerServiceAsUnresolvable(): void
    {
        $tags = new TagRegistry();
        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.queue',
            meta: ['task_type' => 'queue'],
        );

        self::assertStartReason(
            WorkerStartFailedException::REASON_TASK_SOURCE_UNRESOLVABLE,
            static fn (): WorkerTaskSourceInterface => new WorkerTaskSourceResolver(
                new RecordingWorkerTaskSourceContainer([]),
                $tags,
            )->resolve(WorkerTaskType::Queue),
        );

        $container = new RecordingWorkerTaskSourceContainer([
            'worker.source.queue' => new RecordingWorkerTaskSource(),
        ]);
        $container->throwOnGet = true;

        self::assertStartReason(
            WorkerStartFailedException::REASON_TASK_SOURCE_UNRESOLVABLE,
            static fn (): WorkerTaskSourceInterface => new WorkerTaskSourceResolver(
                $container,
                $tags,
            )->resolve(WorkerTaskType::Queue),
        );
    }

    public function testRejectsWrongServiceTypeAndTaskTypeMismatch(): void
    {
        $tags = new TagRegistry();
        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.queue',
            meta: ['task_type' => 'queue'],
        );

        self::assertStartReason(
            WorkerStartFailedException::REASON_TASK_SOURCE_INVALID,
            static fn (): WorkerTaskSourceInterface => new WorkerTaskSourceResolver(
                new RecordingWorkerTaskSourceContainer([
                    'worker.source.queue' => new \stdClass(),
                ]),
                $tags,
            )->resolve(WorkerTaskType::Queue),
        );

        self::assertStartReason(
            WorkerStartFailedException::REASON_TASK_SOURCE_INVALID,
            static fn (): WorkerTaskSourceInterface => new WorkerTaskSourceResolver(
                new RecordingWorkerTaskSourceContainer([
                    'worker.source.queue' => new RecordingWorkerTaskSource(WorkerTaskType::Http),
                ]),
                $tags,
            )->resolve(WorkerTaskType::Queue),
        );
    }

    private static function assertStartReason(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected worker start failure.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSame($reason, $exception->reason());
        }
    }
}

final class RecordingWorkerTaskSourceContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $requested = [];
    public bool $throwOnGet = false;

    /** @param array<string, object> $services */
    public function __construct(private readonly array $services)
    {
    }

    public function get(string $id): mixed
    {
        $this->requested[] = $id;

        if ($this->throwOnGet || !isset($this->services[$id])) {
            throw new \RuntimeException('private-container-failure');
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
