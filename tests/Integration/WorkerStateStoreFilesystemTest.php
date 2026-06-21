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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\TestCase;

final class WorkerStateStoreFilesystemTest extends TestCase
{
    private string $skeletonRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skeletonRoot = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/coretsia-worker-state-store-'
            . \bin2hex(\random_bytes(8));

        if (!\mkdir($this->skeletonRoot, 0777, true) && !\is_dir($this->skeletonRoot)) {
            self::fail('Failed to create temporary skeleton root.');
        }
    }

    protected function tearDown(): void
    {
        self::removePath($this->skeletonRoot);

        parent::tearDown();
    }

    public function testWritesStableJsonUsingCementedSchema(): void
    {
        $spec = self::workerSpec([
            'workers' => 3,
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => 9515,
            ],
        ]);

        $state = self::store()->createState($spec, 12345);

        self::store()->write(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        $bytes = self::readStateBytes($this->skeletonRoot, $spec);

        self::assertSame(
            self::encoder()->encode($state->toArray()),
            $bytes,
        );

        $decoded = self::decodeJsonMap($bytes);
        $expected = $state->toArray();

        \ksort($decoded, \SORT_STRING);
        \ksort($expected, \SORT_STRING);

        self::assertSame(
            [
                'control_transport',
                'control_transport_requested',
                'driver',
                'driver_requested',
                'endpoint_hash',
                'pid',
                'version',
                'worker_count',
            ],
            \array_keys($decoded),
        );

        self::assertSame($expected, $decoded);
    }

    public function testWrittenJsonHasLfLineEndingsAndEndsWithFinalLf(): void
    {
        $spec = self::workerSpec();
        $state = self::store()->createState($spec, 12345);

        self::store()->write(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        $bytes = self::readStateBytes($this->skeletonRoot, $spec);

        self::assertStringEndsWith("\n", $bytes);
        self::assertStringNotContainsString("\r\n", $bytes);
        self::assertStringNotContainsString("\r", $bytes);
    }

    public function testReadsValidStateIntoWorkerPoolState(): void
    {
        $spec = self::workerSpec();
        $state = self::store()->createState($spec, 12345);

        self::store()->write(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        $read = self::store()->read(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
        );

        self::assertInstanceOf(WorkerPoolState::class, $read);
        self::assertSame($state->toArray(), $read->toArray());
    }

    public function testMissingStateMapsToNotRunningAndExistingInvalidStateMapsToInvalidState(): void
    {
        $missingSpec = self::workerSpec([
            'state_path' => 'var/tmp/missing.worker.state.json',
        ]);

        self::assertNotRunningRead(
            skeletonRoot: $this->skeletonRoot,
            spec: $missingSpec,
        );

        $unreadableSpec = self::workerSpec([
            'state_path' => 'var/tmp/unreadable.worker.state.json',
        ]);

        $statePath = self::statePath($this->skeletonRoot, $unreadableSpec);
        $dir = \dirname($statePath);

        if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            self::fail('Failed to create temporary state directory.');
        }

        if (!\mkdir($statePath, 0777, true) && !\is_dir($statePath)) {
            self::fail('Failed to create unreadable state placeholder.');
        }

        self::assertInvalidStateRead(
            skeletonRoot: $this->skeletonRoot,
            spec: $unreadableSpec,
        );
    }

    public function testRejectsInvalidJson(): void
    {
        $spec = self::workerSpec();

        self::writeRawStateBytes(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            bytes: "{ invalid json\n",
        );

        self::assertInvalidStateRead(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
        );
    }

    public function testRejectsNonMapJson(): void
    {
        foreach (["[]\n", "\"state\"\n"] as $bytes) {
            $spec = self::workerSpec([
                'state_path' => 'var/tmp/non-map-' . \hash('sha256', $bytes) . '.json',
            ]);

            self::writeRawStateBytes(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
                bytes: $bytes,
            );

            self::assertInvalidStateRead(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );
        }
    }

    public function testRejectsMissingRequiredSchemaKeys(): void
    {
        $spec = self::workerSpec();
        $state = self::validStatePayload();

        unset($state['endpoint_hash']);

        self::writeStatePayload(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        self::assertInvalidStateRead(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
        );
    }

    public function testRejectsExtraSchemaKeys(): void
    {
        $spec = self::workerSpec();
        $state = self::validStatePayload();
        $state['extra'] = 'forbidden';

        self::writeStatePayload(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        self::assertInvalidStateRead(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
        );
    }

    public function testRejectsVersionOtherThanOne(): void
    {
        $spec = self::workerSpec();
        $state = self::validStatePayload([
            'version' => 2,
        ]);

        self::writeStatePayload(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        self::assertInvalidStateRead(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
        );
    }

    public function testRejectsInvalidEndpointHash(): void
    {
        foreach (
            [
                'not-a-sha256-hash',
                \str_repeat('A', 64),
                \str_repeat('a', 63),
                \str_repeat('g', 64),
            ] as $endpointHash
        ) {
            $spec = self::workerSpec([
                'state_path' => 'var/tmp/invalid-endpoint-hash-' . \hash('sha256', $endpointHash) . '.json',
            ]);

            self::writeStatePayload(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
                state: self::validStatePayload([
                    'endpoint_hash' => $endpointHash,
                ]),
            );

            self::assertInvalidStateRead(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );
        }
    }

    public function testRejectsInvalidResolvedDriver(): void
    {
        $spec = self::workerSpec();

        self::writeStatePayload(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: self::validStatePayload([
                'driver' => 'auto',
            ]),
        );

        self::assertInvalidStateRead(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
        );
    }

    public function testRejectsInvalidResolvedControlTransport(): void
    {
        $spec = self::workerSpec();

        self::writeStatePayload(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: self::validStatePayload([
                'control_transport' => 'auto',
            ]),
        );

        self::assertInvalidStateRead(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
        );
    }

    public function testNotRunningFailureMessagesDoNotIncludeAbsolutePathsOrRawStateFilePath(): void
    {
        $spec = self::workerSpec([
            'state_path' => 'var/tmp/safe-failure.worker.state.json',
        ]);

        try {
            self::store()->read(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );

            self::fail('Expected WorkerNotRunningException was not thrown.');
        } catch (WorkerNotRunningException $exception) {
            self::assertSafeNotRunningMessage(
                exception: $exception,
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );
        }
    }

    public function testReadWriteDoesNotEmitStdoutOrStderr(): void
    {
        $spec = self::workerSpec();
        $state = self::store()->createState($spec, 12345);

        \ob_start();

        try {
            self::store()->write(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
                state: $state,
            );

            self::store()->read(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
            );

            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        $source = self::classSource(WorkerStateStore::class);

        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('fwrite(STDOUT', $source);
        self::assertStringNotContainsString('fwrite(STDERR', $source);
        self::assertStringNotContainsString('error_log(', $source);
    }

    private static function store(): WorkerStateStore
    {
        return new WorkerStateStore(
            encoder: self::encoder(),
            decoder: self::decoder(),
        );
    }

    private static function encoder(): StableJsonEncoder
    {
        return new StableJsonEncoder();
    }

    private static function decoder(): StableJsonDecoder
    {
        return new StableJsonDecoder();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function workerSpec(array $overrides = []): WorkerPoolSpec
    {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig($overrides),
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: false,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function workerConfig(array $overrides = []): array
    {
        return \array_replace_recursive(
            [
                'enabled' => true,
                'workers' => 2,
                'max_requests' => 100,
                'task_type' => 'queue',
                'socket_path' => 'var/worker/control.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9501,
                ],
                'state_path' => 'var/tmp/worker.state.json',
                'stop_flag_path' => 'var/worker/stop.flag',
                'stop_timeout_ms' => 5000,
            ],
            $overrides,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function validStatePayload(array $overrides = []): array
    {
        return \array_replace(
            [
                'version' => 1,
                'pid' => 12345,
                'worker_count' => 2,
                'driver_requested' => 'proc',
                'driver' => 'proc',
                'control_transport_requested' => 'tcp',
                'control_transport' => 'tcp',
                'endpoint_hash' => \hash('sha256', 'tcp:127.0.0.1:9501'),
            ],
            $overrides,
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function writeStatePayload(
        string $skeletonRoot,
        WorkerPoolSpec $spec,
        array $state,
    ): void {
        self::writeRawStateBytes(
            skeletonRoot: $skeletonRoot,
            spec: $spec,
            bytes: self::encoder()->encode($state),
        );
    }

    private static function writeRawStateBytes(
        string $skeletonRoot,
        WorkerPoolSpec $spec,
        string $bytes,
    ): void {
        $path = self::statePath($skeletonRoot, $spec);
        $dir = \dirname($path);

        if (!\is_dir($dir) && !\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            self::fail('Failed to create temporary state directory.');
        }

        if (\file_put_contents($path, $bytes) === false) {
            self::fail('Failed to write temporary state payload.');
        }
    }

    private static function readStateBytes(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        $path = self::statePath($skeletonRoot, $spec);
        $bytes = \file_get_contents($path);

        self::assertIsString($bytes);

        return $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonMap(string $bytes): array
    {
        $decoded = \json_decode($bytes, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertFalse(\array_is_list($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function assertInvalidStateRead(
        string $skeletonRoot,
        WorkerPoolSpec $spec,
    ): void {
        try {
            self::store()->read(
                skeletonRoot: $skeletonRoot,
                spec: $spec,
            );

            self::fail('Expected WorkerStartFailedException was not thrown.');
        } catch (WorkerStartFailedException $exception) {
            self::assertSafeFailureMessage(
                exception: $exception,
                skeletonRoot: $skeletonRoot,
                spec: $spec,
            );
        }
    }

    private static function assertNotRunningRead(
        string $skeletonRoot,
        WorkerPoolSpec $spec,
    ): void {
        try {
            self::store()->read(
                skeletonRoot: $skeletonRoot,
                spec: $spec,
            );

            self::fail('Expected WorkerNotRunningException was not thrown.');
        } catch (WorkerNotRunningException $exception) {
            self::assertSafeNotRunningMessage(
                exception: $exception,
                skeletonRoot: $skeletonRoot,
                spec: $spec,
            );
        }
    }

    private static function assertSafeNotRunningMessage(
        WorkerNotRunningException $exception,
        string $skeletonRoot,
        WorkerPoolSpec $spec,
    ): void {
        $message = $exception->getMessage();

        self::assertSame(WorkerNotRunningException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerNotRunningException::REASON_NOT_RUNNING, $exception->reason());
        self::assertSame(
            WorkerNotRunningException::ERROR_CODE . ': ' . WorkerNotRunningException::REASON_NOT_RUNNING,
            $message,
        );

        $absoluteStatePath = self::statePath($skeletonRoot, $spec);

        self::assertStringNotContainsString($skeletonRoot, $message);
        self::assertStringNotContainsString(\str_replace('/', '\\', $skeletonRoot), $message);
        self::assertStringNotContainsString($absoluteStatePath, $message);
        self::assertStringNotContainsString(\str_replace('/', '\\', $absoluteStatePath), $message);
        self::assertStringNotContainsString($spec->statePath(), $message);
        self::assertStringNotContainsString(\basename($spec->statePath()), $message);
    }

    private static function assertSafeFailureMessage(
        WorkerStartFailedException $exception,
        string $skeletonRoot,
        WorkerPoolSpec $spec,
    ): void {
        $message = $exception->getMessage();

        self::assertSame(WorkerStartFailedException::ERROR_CODE, $exception->errorCode());
        self::assertSame(WorkerStartFailedException::REASON_INVALID_STATE, $exception->reason());
        self::assertSame(
            WorkerStartFailedException::ERROR_CODE . ': ' . WorkerStartFailedException::REASON_INVALID_STATE,
            $message,
        );

        $absoluteStatePath = self::statePath($skeletonRoot, $spec);

        self::assertStringNotContainsString($skeletonRoot, $message);
        self::assertStringNotContainsString(\str_replace('/', '\\', $skeletonRoot), $message);
        self::assertStringNotContainsString($absoluteStatePath, $message);
        self::assertStringNotContainsString(\str_replace('/', '\\', $absoluteStatePath), $message);
        self::assertStringNotContainsString($spec->statePath(), $message);
        self::assertStringNotContainsString(\basename($spec->statePath()), $message);
    }

    private static function statePath(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/') . '/' . $spec->statePath();
    }

    /**
     * @param class-string $className
     */
    private static function classSource(string $className): string
    {
        $reflection = new \ReflectionClass($className);
        $file = $reflection->getFileName();

        self::assertIsString($file);

        $source = \file_get_contents($file);

        self::assertIsString($source);

        return $source;
    }

    private static function removePath(string $path): void
    {
        if ($path === '' || !\file_exists($path)) {
            return;
        }

        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }

        $items = \scandir($path);

        if (!\is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            self::removePath($path . '/' . $item);
        }

        @\rmdir($path);
    }
}
