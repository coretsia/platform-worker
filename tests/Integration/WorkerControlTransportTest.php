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

use Coretsia\Platform\Worker\Communication\WorkerControlTransport;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;

final class WorkerControlTransportTest extends PackageTestCase
{
    public function testAcceptReturnsNullWhenNoClientArrivesDuringTick(): void
    {
        $root = $this->temporaryDirectory('worker-control-transport');
        $transport = new WorkerControlTransport($root);

        $spec = WorkerSpecFactory::create(
            self::platformControlOverride(),
        );

        $listener = $transport->listen($spec);

        try {
            self::assertNull(
                $transport->accept(
                    $listener,
                    10,
                ),
            );
        } finally {
            $transport->close($listener);
            $transport->cleanup($spec);
        }
    }

    public function testListenerSecurityAndCleanupMatchResolvedTransport(): void
    {
        $root = $this->temporaryDirectory('worker-control-security');
        $transport = new WorkerControlTransport($root);

        $spec = WorkerSpecFactory::create(
            self::platformControlOverride(),
        );

        $listener = $transport->listen($spec);

        try {
            if ($spec->controlTransport() === 'unix') {
                $path = $root
                    . '/'
                    . $spec->socketPath();

                self::assertFileExists($path);

                self::assertSame(
                    0600,
                    \fileperms($path) & 0777,
                );
            } else {
                $name = \stream_socket_get_name(
                    $listener,
                    false,
                );

                self::assertIsString($name);

                self::assertStringStartsWith(
                    '127.0.0.1:',
                    $name,
                );
            }
        } finally {
            $transport->close($listener);
            $transport->cleanup($spec);
        }

        self::assertFileDoesNotExist(
            $root . '/' . $spec->socketPath(),
        );
    }

    public function testUnixListenerUsesRestrictiveCreationPolicy(): void
    {
        $source = self::source('src/Communication/WorkerControlTransport.php');

        self::assertStringContainsString(
            '\\umask(0177)',
            $source,
        );
        self::assertStringContainsString(
            '\\umask($previousUmask)',
            $source,
        );
        self::assertStringContainsString(
            '@\\chmod($path, 0600)',
            $source,
        );
        self::assertStringContainsString(
            '(($permissions & 0777) !== 0600)',
            $source,
        );
    }

    public function testAcceptedTcpSessionWaitsForDelayedRequestFrame(): void
    {
        $root = $this->temporaryDirectory('worker-control-delayed-frame');
        $transport = new WorkerControlTransport($root);

        $spec = WorkerSpecFactory::create([
            'workers' => 1,
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => self::unusedTcpPort(),
            ],
        ]);

        $listener = $transport->listen($spec);
        $connection = null;
        $process = null;

        $nullDevice = \PHP_OS_FAMILY === 'Windows'
            ? 'NUL'
            : '/dev/null';

        $script = <<<'PHP'
$address = $argv[1] ?? '';

$stream = @stream_socket_client(
    $address,
    $errorCode,
    $errorMessage,
    2.0,
    STREAM_CLIENT_CONNECT,
);

if (!is_resource($stream)) {
    exit(1);
}

/*
 * Ensure the server accepts the TCP connection before the request frame
 * becomes readable. A non-blocking accepted socket would fail immediately.
 */
usleep(250_000);

$frame = "delayed-control-frame\n";
$remaining = $frame;

while ($remaining !== '') {
    $written = @fwrite(
        $stream,
        $remaining,
    );

    if (!is_int($written) || $written < 1) {
        fclose($stream);

        exit(2);
    }

    $remaining = substr(
        $remaining,
        $written,
    );
}

if (!@fflush($stream)) {
    fclose($stream);

    exit(3);
}

fclose($stream);

exit(0);
PHP;

        try {
            $descriptors = [
                0 => [
                    'file',
                    $nullDevice,
                    'r',
                ],
                1 => [
                    'file',
                    $nullDevice,
                    'w',
                ],
                2 => [
                    'file',
                    $nullDevice,
                    'w',
                ],
            ];

            $pipes = [];

            $process = @\proc_open(
                command: [
                    \PHP_BINARY,
                    '-r',
                    $script,
                    'tcp://127.0.0.1:'
                    . $spec->tcpPort(),
                ],
                descriptor_spec: $descriptors,
                pipes: $pipes,
                cwd: $root,
                env_vars: null,
                options: [
                    'bypass_shell' => true,
                ],
            );

            self::assertIsResource($process);

            $connection = $transport->accept(
                $listener,
                2_000,
            );

            self::assertIsResource($connection);

            self::assertSame(
                "delayed-control-frame\n",
                $transport->readFrame(
                    $connection,
                    128,
                ),
            );
        } finally {
            if (\is_resource($connection)) {
                $transport->close($connection);
            }

            $transport->close($listener);
            $transport->cleanup($spec);

            if (\is_resource($process)) {
                $status = @\proc_get_status($process);

                if (\is_array($status) && ($status['running'] ?? false) === true) {
                    @\proc_terminate(
                        $process,
                        9,
                    );
                }

                @\proc_close($process);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function platformControlOverride(): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return [
                'workers' => 1,
                'control' => [
                    'transport' => 'unix',
                ],
            ];
        }

        return [
            'workers' => 1,
            'control' => [
                'transport' => 'tcp',
            ],
            'tcp' => [
                'host' => '127.0.0.1',
                'port' => self::unusedTcpPort(),
            ],
        ];
    }
}
