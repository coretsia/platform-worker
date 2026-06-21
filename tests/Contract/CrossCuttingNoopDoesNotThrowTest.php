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

use Coretsia\Platform\Worker\Module\WorkerModule;
use Coretsia\Platform\Worker\Provider\WorkerServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CrossCuttingNoopDoesNotThrowTest extends TestCase
{
    public function testModuleAndProviderAreLoadableAndDoNotThrow(): void
    {
        $module = new WorkerModule();

        self::assertSame('platform.worker', $module->id());
        self::assertSame('platform/worker', $module->packageId());
        self::assertSame('coretsia/platform-worker', $module->composerPackage());
        self::assertSame('runtime', $module->kind());
        self::assertSame('worker', $module->configRoot());

        $providers = $module->providers();

        self::assertSame(
            [
                WorkerServiceProvider::class,
            ],
            $providers,
            'WorkerModule::providers() MUST return the Worker provider in module-declared order.',
        );

        foreach ($providers as $providerFqcn) {
            self::assertIsString($providerFqcn);
            self::assertNotSame('', $providerFqcn);
            self::assertTrue(\class_exists($providerFqcn), 'Worker provider class MUST be loadable.');
        }

        $provider = new WorkerServiceProvider();
        $providerReflection = new ReflectionClass($provider);

        self::assertTrue($providerReflection->hasMethod('register'));
        self::assertSame(
            WorkerServiceProvider::class,
            $providerReflection->getName(),
        );
    }

    public function testComposerMetadataIsLoadableAndMatchesWorkerModuleMetadata(): void
    {
        $composer = self::packageComposer();

        self::assertSame('coretsia/platform-worker', $composer['name'] ?? null);
        self::assertSame('library', $composer['type'] ?? null);

        self::assertSame(
            [
                'Coretsia\\Platform\\Worker\\' => 'src/',
            ],
            $composer['autoload']['psr-4'] ?? null,
        );

        self::assertSame(
            [
                'Coretsia\\Platform\\Worker\\Tests\\' => 'tests/',
            ],
            $composer['autoload-dev']['psr-4'] ?? null,
        );

        self::assertSame(
            'runtime',
            $composer['extra']['coretsia']['kind'] ?? null,
        );

        self::assertSame(
            WorkerModule::MODULE_ID,
            $composer['extra']['coretsia']['moduleId'] ?? null,
        );

        self::assertSame(
            WorkerModule::class,
            $composer['extra']['coretsia']['moduleClass'] ?? null,
        );

        self::assertSame(
            [
                WorkerServiceProvider::class,
            ],
            $composer['extra']['coretsia']['providers'] ?? null,
        );

        self::assertSame(
            [
                'core.kernel',
            ],
            $composer['extra']['coretsia']['requires'] ?? null,
        );

        self::assertSame(
            'config/worker.php',
            $composer['extra']['coretsia']['defaultsConfigPath'] ?? null,
        );
    }

    public function testConfigFilesAreLoadableAndHaveNoSideEffects(): void
    {
        $workerFile = self::packageRoot() . '/config/worker.php';
        $rulesFile = self::packageRoot() . '/config/rules.php';

        self::assertFileExists($workerFile);
        self::assertFileExists($rulesFile);

        \ob_start();
        $workerSubtree = require $workerFile;
        $out = \ob_get_clean();

        self::assertIsString($out);
        self::assertSame('', $out, 'config/worker.php MUST NOT emit output.');
        self::assertIsArray($workerSubtree, 'config/worker.php MUST return an array subtree.');
        self::assertFalse(
            \array_is_list($workerSubtree),
            'config/worker.php MUST return the worker subtree map.',
        );
        self::assertArrayNotHasKey(
            'worker',
            $workerSubtree,
            'config/worker.php MUST NOT repeat the root key ("worker").',
        );

        self::assertSame(false, $workerSubtree['enabled'] ?? null);
        self::assertSame(4, $workerSubtree['workers'] ?? null);
        self::assertSame(1000, $workerSubtree['max_requests'] ?? null);
        self::assertSame('queue', $workerSubtree['task_type'] ?? null);
        self::assertSame('var/tmp/worker.sock', $workerSubtree['socket_path'] ?? null);
        self::assertSame('auto', $workerSubtree['driver'] ?? null);
        self::assertSame(
            [
                '@php',
                'vendor/coretsia/platform-worker/bin/coretsia-worker',
            ],
            $workerSubtree['proc']['command'] ?? null,
        );
        self::assertSame('auto', $workerSubtree['control']['transport'] ?? null);
        self::assertSame('127.0.0.1', $workerSubtree['tcp']['host'] ?? null);
        self::assertSame(9327, $workerSubtree['tcp']['port'] ?? null);
        self::assertSame('var/tmp/worker.state.json', $workerSubtree['state_path'] ?? null);
        self::assertSame('var/tmp/worker.stop', $workerSubtree['stop_flag_path'] ?? null);
        self::assertSame(3000, $workerSubtree['stop_timeout_ms'] ?? null);

        \ob_start();
        $rules = require $rulesFile;
        $out = \ob_get_clean();

        self::assertIsString($out);
        self::assertSame('', $out, 'config/rules.php MUST NOT emit output.');
        self::assertIsArray($rules, 'config/rules.php MUST return a plain declarative ruleset array.');

        self::assertSame(1, $rules['schemaVersion'] ?? null);
        self::assertSame('worker', $rules['configRoot'] ?? null);
        self::assertSame(false, $rules['additionalKeys'] ?? null);

        self::assertArrayHasKey('keys', $rules);
        self::assertIsArray($rules['keys']);

        foreach (
            [
                'enabled',
                'workers',
                'max_requests',
                'task_type',
                'socket_path',
                'driver',
                'proc',
                'control',
                'tcp',
                'state_path',
                'stop_flag_path',
                'stop_timeout_ms',
            ] as $key
        ) {
            self::assertArrayHasKey($key, $rules['keys']);
        }

        self::assertSame('bool', $rules['keys']['enabled']['type'] ?? null);
        self::assertSame('int', $rules['keys']['workers']['type'] ?? null);
        self::assertSame('int', $rules['keys']['max_requests']['type'] ?? null);
        self::assertSame(['http', 'queue'], $rules['keys']['task_type']['allowedValues'] ?? null);
        self::assertSame('relative-safe-path', $rules['keys']['socket_path']['type'] ?? null);
        self::assertSame(['auto', 'pcntl', 'proc'], $rules['keys']['driver']['allowedValues'] ?? null);
        self::assertSame(
            ['auto', 'unix', 'tcp'],
            $rules['keys']['control']['keys']['transport']['allowedValues'] ?? null
        );
        self::assertSame(1, $rules['keys']['tcp']['keys']['port']['min'] ?? null);
        self::assertSame(65535, $rules['keys']['tcp']['keys']['port']['max'] ?? null);
        self::assertSame('relative-safe-path', $rules['keys']['state_path']['type'] ?? null);
        self::assertSame('relative-safe-path', $rules['keys']['stop_flag_path']['type'] ?? null);
        self::assertSame(0, $rules['keys']['stop_timeout_ms']['min'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function packageComposer(): array
    {
        $composerPath = self::packageRoot() . '/composer.json';

        self::assertFileExists($composerPath);

        $contents = \file_get_contents($composerPath);

        self::assertIsString($contents);

        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function packageRoot(): string
    {
        return \dirname(__DIR__, 2);
    }
}
