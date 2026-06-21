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

use Coretsia\Foundation\Serialization\StableJsonDecoder;
use Coretsia\Foundation\Serialization\StableJsonEncoder;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use PHPUnit\Framework\TestCase;

final class WorkerStateJsonSchemaContractTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const array STATE_SCHEMA_KEYS = [
        'control_transport',
        'control_transport_requested',
        'driver',
        'driver_requested',
        'endpoint_hash',
        'pid',
        'version',
        'worker_count',
    ];

    private string $skeletonRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skeletonRoot = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/coretsia-worker-state-json-contract-'
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

    public function testTcpWorkerStateJsonUsesExactRedactedSchema(): void
    {
        $spec = self::tcpSpec(
            host: '10.20.30.40',
            port: 9511,
            statePath: 'var/tmp/tcp.worker.state.json',
        );

        $state = self::stateStore()->createState($spec, 12345);

        $json = self::writeAndReadStateJson(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        $decoded = self::decodeStateJson($json);

        self::assertExactStateSchema($decoded);
        self::assertSame(1, $decoded['version']);
        self::assertSame(12345, $decoded['pid']);
        self::assertSame(2, $decoded['worker_count']);
        self::assertSame('proc', $decoded['driver_requested']);
        self::assertSame('proc', $decoded['driver']);
        self::assertSame('tcp', $decoded['control_transport_requested']);
        self::assertSame('tcp', $decoded['control_transport']);

        self::assertEndpointHash(
            expected: \hash('sha256', 'tcp:10.20.30.40:9511'),
            actual: $decoded['endpoint_hash'],
        );

        self::assertRedactedStateJson(
            json: $json,
            forbiddenFragments: [
                'started_at',
                '"started_at"',
                '"env"',
                'env=',
                '10.20.30.40',
                'tcp:10.20.30.40:9511',
                'tcp://10.20.30.40:9511',
                ':9511',
                '"9511"',
                $this->skeletonRoot,
                \str_replace('/', '\\', $this->skeletonRoot),
                self::statePath($this->skeletonRoot, $spec),
                \str_replace('/', '\\', self::statePath($this->skeletonRoot, $spec)),
            ],
        );
    }

    public function testUnixWorkerStateJsonUsesExactRedactedSchema(): void
    {
        $spec = self::unixSpec(
            socketPath: 'var/tmp/private-worker-control.sock',
            statePath: 'var/tmp/unix.worker.state.json',
        );

        $state = self::stateStore()->createState($spec, 67890);

        $json = self::writeAndReadStateJson(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        $decoded = self::decodeStateJson($json);

        self::assertExactStateSchema($decoded);
        self::assertSame(1, $decoded['version']);
        self::assertSame(67890, $decoded['pid']);
        self::assertSame(2, $decoded['worker_count']);
        self::assertSame('pcntl', $decoded['driver_requested']);
        self::assertSame('pcntl', $decoded['driver']);
        self::assertSame('unix', $decoded['control_transport_requested']);
        self::assertSame('unix', $decoded['control_transport']);

        self::assertEndpointHash(
            expected: \hash('sha256', 'unix:var/tmp/private-worker-control.sock'),
            actual: $decoded['endpoint_hash'],
        );

        self::assertRedactedStateJson(
            json: $json,
            forbiddenFragments: [
                'started_at',
                '"started_at"',
                '"env"',
                'env=',
                'var/tmp/private-worker-control.sock',
                'private-worker-control.sock',
                'unix:var/tmp/private-worker-control.sock',
                'unix://',
                self::absoluteSocketPath($this->skeletonRoot, $spec),
                \str_replace('/', '\\', self::absoluteSocketPath($this->skeletonRoot, $spec)),
                $this->skeletonRoot,
                \str_replace('/', '\\', $this->skeletonRoot),
                self::statePath($this->skeletonRoot, $spec),
                \str_replace('/', '\\', self::statePath($this->skeletonRoot, $spec)),
            ],
        );
    }

    public function testSameWorkerStateAndSpecProduceStableJsonBytes(): void
    {
        $spec = self::tcpSpec(
            host: '10.20.30.42',
            port: 9513,
            statePath: 'var/tmp/stable.worker.state.json',
        );

        $state = self::stateStore()->createState($spec, 24680);

        $firstJson = self::writeAndReadStateJson(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        $secondJson = self::writeAndReadStateJson(
            skeletonRoot: $this->skeletonRoot,
            spec: $spec,
            state: $state,
        );

        self::assertSame($firstJson, $secondJson);

        self::assertStringEndsWith("\n", $firstJson);
        self::assertFalse(
            \str_ends_with($firstJson, "\n\n"),
            'Worker state JSON must end with exactly one final LF.',
        );

        $decoded = self::decodeStateJson($firstJson);

        self::assertSame(self::STATE_SCHEMA_KEYS, \array_keys($decoded));

        self::assertRedactedStateJson(
            json: $firstJson,
            forbiddenFragments: [
                '10.20.30.42',
                'tcp:10.20.30.42:9513',
                'tcp://10.20.30.42:9513',
                ':9513',
                '"9513"',
                $this->skeletonRoot,
                \str_replace('/', '\\', $this->skeletonRoot),
                self::statePath($this->skeletonRoot, $spec),
                \str_replace('/', '\\', self::statePath($this->skeletonRoot, $spec)),
            ],
        );
    }

    public function testWritingWorkerStateJsonDoesNotEmitStdoutOrStderr(): void
    {
        $spec = self::tcpSpec(
            host: '10.20.30.41',
            port: 9512,
            statePath: 'var/tmp/no-output.worker.state.json',
        );

        $state = self::stateStore()->createState($spec, 13579);

        self::captureStdout(
            fn (): string => self::writeAndReadStateJson(
                skeletonRoot: $this->skeletonRoot,
                spec: $spec,
                state: $state,
            ),
        );

        $source = self::classSource(WorkerStateStore::class)
            . "\n"
            . self::classSource(WorkerPoolState::class);

        self::assertStringNotContainsString('echo ', $source);
        self::assertStringNotContainsString('print ', $source);
        self::assertStringNotContainsString('var_dump(', $source);
        self::assertStringNotContainsString('print_r(', $source);
        self::assertStringNotContainsString('fwrite(STDOUT', $source);
        self::assertStringNotContainsString('fwrite(STDERR', $source);
        self::assertStringNotContainsString('error_log(', $source);
    }

    private static function stateStore(): WorkerStateStore
    {
        return new WorkerStateStore(
            encoder: new StableJsonEncoder(),
            decoder: new StableJsonDecoder(),
        );
    }

    private static function tcpSpec(string $host, int $port, string $statePath): WorkerPoolSpec
    {
        return self::workerSpec([
            'driver' => 'proc',
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => $host,
                'port' => $port,
            ],
            'state_path' => $statePath,
        ]);
    }

    private static function unixSpec(string $socketPath, string $statePath): WorkerPoolSpec
    {
        return self::workerSpec(
            overrides: [
                'driver' => 'pcntl',
                'control' => [
                    'transport' => 'unix',
                ],
                'socket_path' => $socketPath,
                'state_path' => $statePath,
            ],
            pcntlForkAvailable: false,
            platformFamily: 'Linux',
            unixDomainSocketsSupported: true,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function workerSpec(
        array $overrides = [],
        bool $pcntlForkAvailable = false,
        string $platformFamily = 'Linux',
        bool $unixDomainSocketsSupported = false,
    ): WorkerPoolSpec {
        return WorkerPoolSpec::fromConfig(
            config: self::workerConfig($overrides),
            pcntlForkAvailable: $pcntlForkAvailable,
            platformFamily: $platformFamily,
            unixDomainSocketsSupported: $unixDomainSocketsSupported,
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
                'socket_path' => 'var/tmp/worker.sock',
                'driver' => 'proc',
                'control' => [
                    'transport' => 'tcp',
                ],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9501,
                ],
                'state_path' => 'var/tmp/worker.state.json',
                'stop_flag_path' => 'var/tmp/worker.stop',
                'stop_timeout_ms' => 0,
            ],
            $overrides,
        );
    }

    private static function writeAndReadStateJson(
        string $skeletonRoot,
        WorkerPoolSpec $spec,
        WorkerPoolState $state,
    ): string {
        self::stateStore()->write(
            skeletonRoot: $skeletonRoot,
            spec: $spec,
            state: $state,
        );

        $bytes = \file_get_contents(self::statePath($skeletonRoot, $spec));

        self::assertIsString($bytes);

        return $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeStateJson(string $json): array
    {
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertFalse(\array_is_list($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function assertExactStateSchema(array $decoded): void
    {
        $keys = \array_keys($decoded);

        \sort($keys, \SORT_STRING);

        self::assertSame(self::STATE_SCHEMA_KEYS, $keys);
    }

    private static function assertEndpointHash(string $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $actual);
        self::assertSame($expected, $actual);
    }

    /**
     * @param list<string> $forbiddenFragments
     */
    private static function assertRedactedStateJson(string $json, array $forbiddenFragments): void
    {
        foreach ($forbiddenFragments as $fragment) {
            if ($fragment === '') {
                continue;
            }

            self::assertStringNotContainsString($fragment, $json);
        }

        self::assertNoHostPathPatterns($json);
    }

    private static function assertNoHostPathPatterns(string $json): void
    {
        foreach (
            [
                'unix-home' => '#/home/#',
                'mac-users' => '#/Users/#',
                'windows-drive' => '#\b[A-Z]:(?:\\\\+|/)#i',
                'windows-unc' => '#\\\\{2,}server\\\\+share#i',
            ] as $label => $pattern
        ) {
            $matched = \preg_match($pattern, $json);

            self::assertNotFalse(
                $matched,
                'Invalid worker state JSON redaction regex: ' . $label,
            );

            self::assertSame(
                0,
                $matched,
                'Worker state JSON must not expose unsafe host path pattern: ' . $label,
            );
        }
    }

    private static function captureStdout(\Closure $callback): mixed
    {
        \ob_start();

        try {
            $result = $callback();
            $stdout = \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();

            throw $throwable;
        }

        self::assertSame('', $stdout);

        return $result;
    }

    private static function statePath(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/') . '/' . $spec->statePath();
    }

    private static function absoluteSocketPath(string $skeletonRoot, WorkerPoolSpec $spec): string
    {
        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/') . '/' . $spec->socketPath();
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
