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

use PHPUnit\Framework\TestCase;

final class CoretsiaWorkerChildLauncherContractTest extends TestCase
{
    private string $skeletonRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skeletonRoot = \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/')
            . '/coretsia-worker-child-launcher-'
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

    public function testLauncherAcceptsOnlyWorkerOwnedInternalArgs(): void
    {
        $result = $this->runLauncher([
            '--coretsia-worker-index=0',
            '--coretsia-worker-count=1',
            '--coretsia-worker-max-requests=1',
            '--coretsia-worker-task-type=queue',
            '--coretsia-worker-driver=proc',
            '--coretsia-worker-config=var/cache/app/config.php',
            '--coretsia-worker-container=var/cache/app/container.php',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'autoload-missing',
        );
    }

    public function testLauncherAcceptsCoretsiaWorkerConfigArg(): void
    {
        $result = $this->runLauncher([
            '--coretsia-worker-index=0',
            '--coretsia-worker-count=1',
            '--coretsia-worker-max-requests=1',
            '--coretsia-worker-task-type=queue',
            '--coretsia-worker-driver=proc',
            '--coretsia-worker-config=var/cache/app/config.php',
            '--coretsia-worker-container=var/cache/app/container.php',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'autoload-missing',
        );
    }

    public function testLauncherAcceptsCoretsiaWorkerContainerArg(): void
    {
        $result = $this->runLauncher([
            '--coretsia-worker-index=0',
            '--coretsia-worker-count=1',
            '--coretsia-worker-max-requests=1',
            '--coretsia-worker-task-type=queue',
            '--coretsia-worker-driver=proc',
            '--coretsia-worker-config=var/cache/app/config.php',
            '--coretsia-worker-container=var/cache/app/container.php',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'autoload-missing',
        );
    }

    public function testLauncherRejectsUnknownArgs(): void
    {
        $result = $this->runLauncher([
            '--coretsia-worker-index=0',
            '--coretsia-worker-count=1',
            '--coretsia-worker-max-requests=1',
            '--coretsia-worker-task-type=queue',
            '--coretsia-worker-driver=proc',
            '--coretsia-worker-config=var/cache/app/config.php',
            '--coretsia-worker-container=var/cache/app/container.php',
            '--unknown=1',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'argv-invalid',
        );
    }

    public function testLauncherRejectsMissingCoretsiaWorkerConfigArg(): void
    {
        $result = $this->runLauncher([
            '--coretsia-worker-index=0',
            '--coretsia-worker-count=1',
            '--coretsia-worker-max-requests=1',
            '--coretsia-worker-task-type=queue',
            '--coretsia-worker-driver=proc',
            '--coretsia-worker-container=var/cache/app/container.php',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'argv-invalid',
        );
    }

    public function testLauncherRejectsMissingCoretsiaWorkerContainerArg(): void
    {
        $result = $this->runLauncher([
            '--coretsia-worker-index=0',
            '--coretsia-worker-count=1',
            '--coretsia-worker-max-requests=1',
            '--coretsia-worker-task-type=queue',
            '--coretsia-worker-driver=proc',
            '--coretsia-worker-config=var/cache/app/config.php',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'argv-invalid',
        );
    }

    public function testLauncherDoesNotImportPlatformCliApplication(): void
    {
        $source = self::launcherSource();

        self::assertStringNotContainsString('Coretsia\\Platform\\Cli\\Application', $source);
        self::assertStringNotContainsString('Platform\\Cli\\Application', $source);
    }

    public function testLauncherDoesNotReferenceCommandCatalog(): void
    {
        $source = self::launcherSource();

        self::assertStringNotContainsString('CommandCatalog', $source);
    }

    public function testLauncherDoesNotReferenceCliCommandReservedTag(): void
    {
        $source = self::launcherSource();

        self::assertStringNotContainsString('ReservedTags::CLI_COMMAND', $source);
        self::assertStringNotContainsString('cli.command', $source);
        self::assertStringNotContainsString('ReservedTags', $source);
    }

    public function testLauncherImportsOnlyPublicKernelBootFacadeForArtifactRuntimeBoot(): void
    {
        $source = self::launcherSource();

        self::assertStringContainsString(
            'use Coretsia\\Kernel\\Boot\\ArtifactRuntimeBooter;',
            $source,
        );

        self::assertStringContainsString(
            'new ArtifactRuntimeBooter()->boot(',
            $source,
        );
    }

    public function testLauncherDoesNotImportKernelArtifactOrContainerInternalClasses(): void
    {
        $source = self::launcherSource();

        foreach (
            [
                'Coretsia\\Kernel\\Artifact\\',
                'Coretsia\\Kernel\\Container\\',
                'Coretsia\\Kernel\\Boot\\Internal\\',
                'Coretsia\\Kernel\\Boot\\Artifact\\',
                'Coretsia\\Kernel\\Boot\\Container\\',
                'ArtifactRuntimeContainer',
                'CompiledContainer',
                'ContainerArtifact',
                'ArtifactContainer',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runLauncher(array $args): array
    {
        $command = [
            \PHP_BINARY,
            self::launcherPath(),
            ...$args,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        \set_error_handler(static fn (): bool => true);

        try {
            $process = \proc_open(
                command: $command,
                descriptor_spec: $descriptors,
                pipes: $pipes,
                cwd: $this->skeletonRoot,
                env_vars: null,
                options: [],
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($process)) {
            self::fail('Failed to start launcher process.');
        }

        \fclose($pipes[0]);

        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);

        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);
        self::assertIsInt($exitCode);

        self::assertSafeDiagnostics($stdout);
        self::assertSafeDiagnostics($stderr);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private static function assertFailure(string $stderr, string $reason): void
    {
        $lines = \explode("\n", \trim($stderr));

        self::assertSame(
            [
                'CORETSIA_WORKER_CHILD_BOOT_FAILED',
                $reason,
            ],
            $lines,
        );
    }

    private static function assertSafeDiagnostics(string $bytes): void
    {
        foreach (
            [
                'var/cache/app/config.php',
                'var/cache/app/container.php',
                '/var/cache/app/config.php',
                '/var/cache/app/container.php',
                'C:\\',
                'Authorization',
                'authorization',
                'cookie',
                'payload',
                'secret',
                'token',
                'headers',
                'body',
                'stack trace',
                '#0 ',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $bytes);
        }
    }

    private static function launcherSource(): string
    {
        $source = \file_get_contents(self::launcherPath());

        self::assertIsString($source);

        return $source;
    }

    private static function launcherPath(): string
    {
        $path = __DIR__ . '/../../bin/coretsia-worker';

        self::assertFileExists($path);

        return $path;
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
