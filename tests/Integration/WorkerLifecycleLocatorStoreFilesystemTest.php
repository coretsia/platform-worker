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

use Coretsia\Platform\Worker\Communication\WorkerControlCredential;
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocator;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocatorStore;
use Coretsia\Platform\Worker\Runtime\WorkerLifecyclePaths;
use Coretsia\Platform\Worker\Tests\Support\PackageTestCase;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkerLifecycleLocatorStoreFilesystemTest extends PackageTestCase
{
    public function testStablePrivateLocatorRoundTripsAndDeletesBothPathsIdempotently(): void
    {
        $root = $this->temporaryDirectory('worker-lifecycle-locator');
        $store = self::store($root);
        $locator = WorkerLifecycleLocator::fromPoolSpec(
            WorkerSpecFactory::create([
                'stop_timeout_ms' => 2_300,
                'force_kill_timeout_ms' => 400,
            ]),
            WorkerControlCredential::fromEncoded(
                \str_repeat('a', 64),
            ),
        );
        $path = WorkerLifecyclePaths::resolve($root, WorkerLifecyclePaths::LOCATOR);
        $temporaryPath = WorkerLifecyclePaths::resolve(
            $root,
            WorkerLifecyclePaths::LOCATOR_TEMP,
        );

        self::assertNull($store->read());

        $store->write($locator);

        self::assertSame($locator->toArray(), $store->read()?->toArray());
        self::assertFileExists($path);
        self::assertFileDoesNotExist($temporaryPath);
        self::assertSame(
            '{"control_credential":"' . \str_repeat('a', 64) . '",'
            . '"control_transport":"unix","force_kill_timeout_ms":400,'
            . '"socket_path":"var/tmp/worker.sock","stop_timeout_ms":2300,'
            . '"tcp_host":null,"tcp_port":null,"version":1}'
            . "\n",
            \file_get_contents($path),
        );

        if (\PHP_OS_FAMILY !== 'Windows') {
            \clearstatcache(true, $path);
            self::assertSame(
                0600,
                ((int)\fileperms($path)) & 0777,
                'The private lifecycle locator must be owner-readable and owner-writable only.',
            );
        }

        \file_put_contents($temporaryPath, "stale\n");

        $store->delete();
        $store->delete();

        self::assertNull($store->read());
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($temporaryPath);
    }

    public function testWriteAtomicallyReplacesAnExistingLocator(): void
    {
        $root = $this->temporaryDirectory('worker-lifecycle-locator-replace');
        $store = self::store($root);
        $first = WorkerLifecycleLocator::fromPoolSpec(
            WorkerSpecFactory::create([
                'socket_path' => 'var/tmp/first-worker.sock',
            ]),
            WorkerControlCredential::fromEncoded(
                \str_repeat('a', 64),
            ),
        );
        $second = WorkerLifecycleLocator::fromPoolSpec(
            WorkerSpecFactory::create([
                'driver' => 'proc',
                'control' => ['transport' => 'tcp'],
                'tcp' => [
                    'host' => '127.0.0.1',
                    'port' => 9_444,
                ],
            ]),
            WorkerControlCredential::fromEncoded(
                \str_repeat('b', 64),
            ),
        );
        $path = WorkerLifecyclePaths::resolve($root, WorkerLifecyclePaths::LOCATOR);
        $temporaryPath = WorkerLifecyclePaths::resolve(
            $root,
            WorkerLifecyclePaths::LOCATOR_TEMP,
        );

        $store->write($first);
        $firstBytes = \file_get_contents($path);

        $store->write($second);

        self::assertSame($second->toArray(), $store->read()?->toArray());
        self::assertNotSame($firstBytes, \file_get_contents($path));
        self::assertFileDoesNotExist($temporaryPath);
    }

    public function testPosixLocatorWithBroadPermissionsIsRejected(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertSame('Windows', \PHP_OS_FAMILY);

            return;
        }

        $root = $this->temporaryDirectory(
            'worker-lifecycle-locator-permissions',
        );
        $store = self::store($root);
        $locator = WorkerLifecycleLocator::fromPoolSpec(
            WorkerSpecFactory::create(),
            WorkerControlCredential::fromEncoded(
                \str_repeat('a', 64),
            ),
        );
        $path = WorkerLifecyclePaths::resolve(
            $root,
            WorkerLifecyclePaths::LOCATOR,
        );

        $store->write($locator);
        self::assertTrue(\chmod($path, 0644));

        $this->expectException(WorkerCommunicationFailedException::class);

        $store->read();
    }

    #[DataProvider('invalidLocatorBytes')]
    public function testMalformedOrOversizedLocatorIsSafeCommunicationFailure(string $bytes): void
    {
        $root = $this->temporaryDirectory('worker-lifecycle-locator-invalid');
        $path = WorkerLifecyclePaths::resolve($root, WorkerLifecyclePaths::LOCATOR);
        \mkdir(\dirname($path), 0777, true);
        \file_put_contents($path, $bytes);

        try {
            self::store($root)->read();
            self::fail('Expected WorkerCommunicationFailedException.');
        } catch (WorkerCommunicationFailedException $exception) {
            self::assertSame(
                WorkerCommunicationFailedException::ERROR_CODE,
                $exception->errorCode(),
            );
            self::assertStringNotContainsString($root, $exception->getMessage());
            self::assertStringNotContainsString($path, $exception->getMessage());
        }
    }

    public function testLinkedOrNonRegularLocatorIsSafeCommunicationFailure(): void
    {
        $root = $this->temporaryDirectory('worker-lifecycle-locator-link');
        $target = $root . '/target.json';

        $path = WorkerLifecyclePaths::resolve(
            $root,
            WorkerLifecyclePaths::LOCATOR,
        );

        \mkdir(
            \dirname($path),
            0777,
            true,
        );

        \file_put_contents(
            $target,
            "{}\n",
        );

        $linked = \function_exists('symlink') && @\symlink($target, $path);

        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(
                $linked,
                'Unix test environments must exercise exact symlink rejection.',
            );
        } elseif (!$linked) {
            self::assertTrue(
                @\mkdir($path),
                'Windows must provide a non-regular locator path when symlink creation is unavailable.',
            );
        }

        self::assertTrue(
            \is_link($path) || \is_dir($path),
        );

        try {
            self::store($root)->read();

            self::fail('Expected WorkerCommunicationFailedException.');
        } catch (WorkerCommunicationFailedException $exception) {
            self::assertSame(
                WorkerCommunicationFailedException::ERROR_CODE,
                $exception->errorCode(),
            );

            self::assertStringNotContainsString(
                $root,
                $exception->getMessage(),
            );

            self::assertStringNotContainsString(
                $path,
                $exception->getMessage(),
            );
        }
    }

    public function testPrivateLocatorUsesRestrictiveCreationPolicyBeforeWriting(): void
    {
        $source = self::source('src/Runtime/WorkerLifecycleLocatorStore.php');
        $umask = \strpos($source, '\\umask(0177)');
        $open = \strpos($source, '@\\fopen(');
        $chmod = \strpos($source, '@\\chmod($temporaryPath, 0600)');
        $write = \strpos($source, 'self::writeAll($handle, $bytes)');

        self::assertIsInt($umask);
        self::assertIsInt($open);
        self::assertIsInt($chmod);
        self::assertIsInt($write);
        self::assertLessThan($open, $umask);
        self::assertLessThan($chmod, $open);
        self::assertLessThan($write, $chmod);
        self::assertStringContainsString(
            '\\umask($previousUmask)',
            $source,
        );
        self::assertStringContainsString(
            '(($permissions & 0777) !== 0600)',
            $source,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLocatorBytes(): iterable
    {
        yield 'invalid JSON' => ['{'];
        yield 'non-map root' => ["[]\n"];
        yield 'missing keys' => ["{}\n"];
        yield 'unknown key' => [
            '{"control_credential":"' . \str_repeat('a', 64) . '",'
            . '"control_transport":"unix","force_kill_timeout_ms":1000,'
            . '"instance_id":"stale","socket_path":"var/tmp/worker.sock",'
            . '"stop_timeout_ms":10000,"tcp_host":null,"tcp_port":null,'
            . '"version":1}'
            . "\n",
        ];
        yield 'oversized' => [\str_repeat('x', 4_097)];
    }

    private static function store(string $root): WorkerLifecycleLocatorStore
    {
        return new WorkerLifecycleLocatorStore(
            skeletonRoot: $root,
        );
    }
}
