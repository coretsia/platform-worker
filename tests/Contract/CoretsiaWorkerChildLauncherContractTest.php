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

    public function testLauncherAcceptsSingleArtifactRootArg(): void
    {
        $result = $this->runLauncher(
            self::validInternalArgs(),
        );

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'autoload-missing',
        );
    }

    public function testLauncherRejectsLegacyModuleManifestArg(): void
    {
        $result = $this->runLauncher([
            ...self::validInternalArgs(),
            '--coretsia-worker-module-manifest='
            . 'var/cache/app/module-manifest.php',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'argv-invalid',
        );
    }

    public function testLauncherRejectsLegacyConfigArg(): void
    {
        $result = $this->runLauncher([
            ...self::validInternalArgs(),
            '--coretsia-worker-config=var/cache/app/config.php',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'argv-invalid',
        );
    }

    public function testLauncherRejectsLegacyContainerArg(): void
    {
        $result = $this->runLauncher([
            ...self::validInternalArgs(),
            '--coretsia-worker-container=var/cache/app/container.php',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'argv-invalid',
        );
    }

    public function testLauncherRejectsUnknownArgs(): void
    {
        $result = $this->runLauncher([
            ...self::validInternalArgs(),
            '--unknown=1',
        ]);

        self::assertSame(1, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFailure(
            stderr: $result['stderr'],
            reason: 'argv-invalid',
        );
    }

    public function testLauncherRejectsMissingArtifactRootArg(): void
    {
        $result = $this->runLauncher([
            '--coretsia-worker-index=0',
            '--coretsia-worker-count=1',
            '--coretsia-worker-max-requests=1',
            '--coretsia-worker-task-type=queue',
            '--coretsia-worker-driver=proc',
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
            'use Coretsia\\Kernel\\Boot\\ArtifactRuntimeInput;',
            $source,
        );

        self::assertStringContainsString(
            'new ArtifactRuntimeBooter()->boot(',
            $source,
        );
        self::assertStringContainsString(
            'input: new ArtifactRuntimeInput(',
            $source,
        );
        self::assertStringContainsString(
            "relativePath: \$args['artifact_root']",
            $source,
        );
        self::assertStringContainsString(
            'artifactRoot: $artifactRoot,',
            $source,
        );

        self::assertStringNotContainsString(
            'moduleManifestArtifactPath:',
            $source,
        );
        self::assertStringNotContainsString(
            'configArtifactPath:',
            $source,
        );
        self::assertStringNotContainsString(
            'containerArtifactPath:',
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

    public function testLauncherUsesWorkerRuntimeEntrypointBoundaryWithoutInternalImports(): void
    {
        $source = self::launcherSource();

        self::assertStringContainsString(
            'use Coretsia\\Platform\\Worker\\Runtime\\WorkerRuntimeEntrypointGuard;',
            $source,
        );

        self::assertStringContainsString(
            'WorkerRuntimeEntrypointGuard::class',
            $source,
        );

        self::assertStringContainsString(
            'spec: $spec',
            $source,
        );

        self::assertStringNotContainsString(
            'Coretsia\\Platform\\Worker\\Internal\\WorkerRuntimeDriverContributions',
            $source,
        );

        self::assertStringNotContainsString(
            'use Coretsia\\Kernel\\Runtime\\Entrypoint\\RuntimeEntrypointGuard;',
            $source,
        );

        self::assertStringNotContainsString(
            'WorkerRuntimeDriverContributions::fromSpec(',
            $source,
        );

        self::assertStringNotContainsString(
            'runtimeDriverContributions:',
            $source,
        );
        self::assertStringContainsString(
            'coretsia_worker_child_assert_runtime_entrypoint_allowed(',
            $source,
        );

        $specOffset = \strpos(
            $source,
            '$spec = coretsia_worker_child_service($container, WorkerPoolSpec::class);',
        );
        $argsCheckOffset = \strpos(
            $source,
            'coretsia_worker_child_assert_args_match_spec($args, $spec);',
        );
        $guardOffset = \strpos(
            $source,
            'coretsia_worker_child_assert_runtime_entrypoint_allowed(',
            $argsCheckOffset === false ? 0 : $argsCheckOffset,
        );
        $workerOffset = \strpos(
            $source,
            '$worker = coretsia_worker_child_service($container, ApplicationWorker::class);',
        );

        self::assertIsInt($specOffset);
        self::assertIsInt($argsCheckOffset);
        self::assertIsInt($guardOffset);
        self::assertIsInt($workerOffset);

        self::assertTrue(
            $specOffset < $argsCheckOffset
            && $argsCheckOffset < $guardOffset
            && $guardOffset < $workerOffset,
            'Worker child must resolve and validate WorkerPoolSpec, invoke the '
            . 'Worker-owned runtime entrypoint boundary, and only then resolve '
            . 'ApplicationWorker.',
        );
    }

    /**
     * @return list<string>
     */
    private static function validInternalArgs(): array
    {
        return [
            '--coretsia-worker-index=0',
            '--coretsia-worker-count=1',
            '--coretsia-worker-max-requests=1',
            '--coretsia-worker-task-type=queue',
            '--coretsia-worker-driver=proc',
            '--coretsia-worker-artifact-root=var/cache/app',
        ];
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
                'var/cache/app',
                '/var/cache/app',
                'var/cache/app/module-manifest.php',
                'var/cache/app/config.php',
                'var/cache/app/container.php',
                '/var/cache/app/module-manifest.php',
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
