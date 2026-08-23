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

namespace Coretsia\Platform\Worker\Tests\Support;

use Coretsia\Foundation\Observability\Metrics\NoopMeter;
use Coretsia\Foundation\Observability\Tracing\NoopTracer;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Artifacts\ArtifactEnvelopeFactory;
use Coretsia\Kernel\Artifacts\ArtifactWriter;
use Coretsia\Kernel\Artifacts\Builders\CompiledConfigBuilder;
use Coretsia\Kernel\Artifacts\Builders\CompiledContainerBuilder;
use Coretsia\Kernel\Artifacts\Builders\ModuleManifestBuilder;
use Coretsia\Kernel\Artifacts\Compiler\ArtifactCompiler;
use Coretsia\Kernel\Artifacts\Fingerprint\ConfigFingerprintInputBuilder;
use Coretsia\Kernel\Artifacts\Fingerprint\ContainerGraphFingerprintBucketBuilder;
use Coretsia\Kernel\Artifacts\Fingerprint\DeterministicFileLister;
use Coretsia\Kernel\Artifacts\Fingerprint\FingerprintCalculator;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGeneration;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGenerationLocator;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGenerationLock;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGenerationManifestBuilder;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGenerationManifestValidator;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGenerationPathResolver;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGenerationPublisher;
use Coretsia\Kernel\Artifacts\Generation\ArtifactGenerationValidator;
use Coretsia\Kernel\Artifacts\Operation\KernelArtifactOperation;
use Coretsia\Kernel\Artifacts\Paths\ArtifactPathResolver;
use Coretsia\Kernel\Artifacts\PayloadNormalizer;
use Coretsia\Kernel\Artifacts\Php\PhpArtifactReader;
use Coretsia\Kernel\Artifacts\Php\StablePhpArrayDumper;
use Coretsia\Kernel\Artifacts\Verifier\ArtifactSchemaValidator;
use Coretsia\Kernel\Artifacts\Verifier\CacheVerifier;
use Coretsia\Kernel\Boot\BootstrapConfigResolver;
use Coretsia\Kernel\Boot\BootstrapOverridesLoader;
use Coretsia\Kernel\Boot\DotenvLoader;
use Coretsia\Kernel\Boot\EnvRepositoryBuilder;
use Coretsia\Kernel\Config\ConfigKernel;
use Coretsia\Kernel\Config\ConfigMerger;
use Coretsia\Kernel\Config\ConfigRulesLoader;
use Coretsia\Kernel\Config\ConfigValidator;
use Coretsia\Kernel\Config\DirectiveProcessor;
use Coretsia\Kernel\Config\Explain\ConfigExplainer;
use Coretsia\Kernel\Config\Loaders\EnvironmentOverlayLoader;
use Coretsia\Kernel\Config\Loaders\PackageDefaultsConfigLoader;
use Coretsia\Kernel\Config\Loaders\SkeletonConfigLoader;
use Coretsia\Kernel\Config\Source\ComposerPackageInstallPathResolver;
use Coretsia\Kernel\Config\Source\ConfigSourceLocationBuilder;
use Coretsia\Kernel\Config\Validation\ConfigNamespaceGuard;
use Coretsia\Kernel\Container\ContainerCompiler;
use Coretsia\Kernel\Container\ContainerGraphCompletenessValidator;
use Coretsia\Kernel\Container\Provider\ContainerProviderPlanResolver;
use Coretsia\Kernel\Container\RuntimeContainerGraphCompiler;
use Coretsia\Kernel\Module\ComposerInstalledMetadataProvider;
use Coretsia\Kernel\Module\ComposerManifestReader;
use Coretsia\Kernel\Module\ModePresetLoaderFactory;
use Coretsia\Kernel\Module\ModePresetSchemaValidator;
use Coretsia\Kernel\Module\ModuleGraphResolver;
use Coretsia\Kernel\Module\ModulePlanResolver;
use Coretsia\Kernel\Module\TopologicalSorter;
use Psr\Log\NullLogger;

/**
 * Worker-local assembly for the production Kernel artifact pipeline.
 *
 * This support class intentionally constructs production collaborators instead
 * of importing core/kernel test support or substituting critical boundaries.
 */
final class WorkerArtifactPipelineTestSupport
{
    private const string ARTIFACTS_CACHE_DIR = 'var/cache';
    private const string APP_TARGET = 'worker';

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $kernelConfig
     * @param list<array<string,mixed>> $installedData
     * @param array<string,string> $installRoots
     */
    public static function operation(
        array $kernelConfig,
        array $installedData,
        array $installRoots,
        string $kernelPackageRoot,
    ): KernelArtifactOperation {
        $modesConfig = $kernelConfig['modes'] ?? null;
        $modulesConfig = $kernelConfig['modules'] ?? null;

        if (!\is_array($modesConfig) || !\is_array($modulesConfig)) {
            throw new \LogicException('worker-e2e-kernel-config-invalid');
        }

        $modePresetLoaderFactory = new ModePresetLoaderFactory(
            packageRoot: $kernelPackageRoot,
            modesConfig: $modesConfig,
            schemaValidator: new ModePresetSchemaValidator(),
        );

        $modulePlanResolver = new ModulePlanResolver(
            presetLoaderFactory: $modePresetLoaderFactory,
            manifestReader: new ComposerManifestReader(
                new ComposerInstalledMetadataProvider($installedData),
            ),
            graphResolver: new ModuleGraphResolver(
                new TopologicalSorter(),
            ),
            tracer: new NoopTracer(),
            meter: new NoopMeter(),
            stopwatch: new Stopwatch(),
            logger: new NullLogger(),
            modulesConfig: $modulesConfig,
        );

        return new KernelArtifactOperation(
            bootstrapConfigResolver: new BootstrapConfigResolver(
                new BootstrapOverridesLoader(),
            ),
            envRepositoryBuilder: new EnvRepositoryBuilder(
                new DotenvLoader(),
            ),
            modulePlanResolver: $modulePlanResolver,
            configSourceLocationBuilder: new ConfigSourceLocationBuilder(
                installPathResolver: new ComposerPackageInstallPathResolver($installRoots),
                modePresetLoaderFactory: $modePresetLoaderFactory,
            ),
            artifactCompiler: self::artifactCompiler($kernelConfig),
            cacheVerifier: self::cacheVerifier($kernelConfig),
            kernelConfig: $kernelConfig,
        );
    }

    /**
     * @param array<string,mixed> $composer
     */
    public static function copyPackageInputs(
        string $sourceRoot,
        string $targetRoot,
        array $composer,
    ): void {
        $coretsia = $composer['extra']['coretsia'] ?? null;
        $defaultsConfigPath = \is_array($coretsia)
            ? ($coretsia['defaultsConfigPath'] ?? null)
            : null;

        if (!\is_string($defaultsConfigPath) || $defaultsConfigPath === '') {
            throw new \LogicException('worker-e2e-defaults-config-path-invalid');
        }

        foreach (
            [
                'composer.json',
                $defaultsConfigPath,
                'config/rules.php',
            ] as $relativePath
        ) {
            self::copyFile(
                sourcePath: self::joinPath($sourceRoot, $relativePath),
                targetPath: self::joinPath($targetRoot, $relativePath),
            );
        }
    }

    /**
     * @param array<string,mixed> $value
     */
    public static function writePhpReturn(
        string $path,
        array $value,
    ): void {
        $directory = \dirname($path);

        if (!\is_dir($directory) && !@\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            throw new \RuntimeException('worker-e2e-directory-create-failed');
        }

        $written = @\file_put_contents(
            $path,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . \var_export($value, true) . ";\n",
        );

        if (!\is_int($written) || $written < 1) {
            throw new \RuntimeException('worker-e2e-file-write-failed');
        }
    }

    public static function artifactRoot(string $skeletonRoot): string
    {
        return \rtrim(\str_replace('\\', '/', $skeletonRoot), '/')
            . '/'
            . self::ARTIFACTS_CACHE_DIR
            . '/'
            . self::APP_TARGET;
    }

    public static function currentGeneration(string $skeletonRoot): ArtifactGeneration
    {
        $pathResolver = new ArtifactGenerationPathResolver();
        $schemaValidator = new ArtifactSchemaValidator();
        $generation = new ArtifactGenerationLocator(
            lock: new ArtifactGenerationLock($pathResolver),
            pathResolver: $pathResolver,
            validator: new ArtifactGenerationValidator(
                artifactReader: new PhpArtifactReader(),
                schemaValidator: $schemaValidator,
                manifestValidator: new ArtifactGenerationManifestValidator($schemaValidator),
            ),
        )->locate(self::artifactRoot($skeletonRoot));

        if (!$generation instanceof ArtifactGeneration) {
            throw new \RuntimeException('worker-e2e-current-generation-missing');
        }

        return $generation;
    }

    /**
     * @return array<string,mixed>
     */
    public static function artifactPayload(
        string $skeletonRoot,
        string $basename,
        string $expectedName,
    ): array {
        $generation = self::currentGeneration($skeletonRoot);
        $path = match ($basename) {
            ArtifactGeneration::MODULE_MANIFEST_BASENAME => $generation->moduleManifestPath(),
            ArtifactGeneration::CONFIG_BASENAME => $generation->configPath(),
            ArtifactGeneration::CONTAINER_BASENAME => $generation->containerPath(),
            ArtifactGeneration::GENERATION_MANIFEST_BASENAME => $generation->generationManifestPath(),
            default => throw new \InvalidArgumentException('worker-e2e-artifact-basename-invalid'),
        };

        $read = new PhpArtifactReader()->readExact($path);
        $envelope = $read['envelope'] ?? null;

        if (!\is_array($envelope)) {
            throw new \RuntimeException('worker-e2e-artifact-envelope-invalid');
        }

        new ArtifactSchemaValidator()->validateExpected(
            envelope: $envelope,
            expectedName: $expectedName,
            expectedSchemaVersion: 1,
        );

        $payload = $envelope['payload'] ?? null;

        if (!\is_array($payload)) {
            throw new \RuntimeException('worker-e2e-artifact-payload-invalid');
        }

        /** @var array<string,mixed> $payload */
        return $payload;
    }

    /**
     * @param array<string,mixed> $kernelConfig
     */
    private static function artifactCompiler(array $kernelConfig): ArtifactCompiler
    {
        $envelopeFactory = self::envelopeFactory();
        $phpArrayDumper = new StablePhpArrayDumper(
            new PayloadNormalizer(),
        );

        return new ArtifactCompiler(
            configKernel: self::configKernel($kernelConfig),
            fingerprintInputBuilder: self::fingerprintInputBuilder(),
            fingerprintCalculator: self::fingerprintCalculator(),
            moduleManifestBuilder: new ModuleManifestBuilder($envelopeFactory),
            compiledConfigBuilder: new CompiledConfigBuilder($envelopeFactory),
            runtimeContainerGraphCompiler: self::runtimeContainerGraphCompiler(),
            compiledContainerBuilder: new CompiledContainerBuilder($envelopeFactory),
            phpArrayDumper: $phpArrayDumper,
            generationPublisher: self::generationPublisher(
                envelopeFactory: $envelopeFactory,
                phpArrayDumper: $phpArrayDumper,
            ),
            pathResolver: new ArtifactPathResolver(),
        );
    }

    /**
     * @param array<string,mixed> $kernelConfig
     */
    private static function cacheVerifier(array $kernelConfig): CacheVerifier
    {
        $envelopeFactory = self::envelopeFactory();
        $phpArrayDumper = new StablePhpArrayDumper(
            new PayloadNormalizer(),
        );
        $artifactReader = new PhpArtifactReader();
        $schemaValidator = new ArtifactSchemaValidator();
        $generationPathResolver = new ArtifactGenerationPathResolver();
        $generationValidator = new ArtifactGenerationValidator(
            artifactReader: $artifactReader,
            schemaValidator: $schemaValidator,
            manifestValidator: new ArtifactGenerationManifestValidator($schemaValidator),
        );

        return new CacheVerifier(
            configKernel: self::configKernel($kernelConfig),
            fingerprintInputBuilder: self::fingerprintInputBuilder(),
            fingerprintCalculator: self::fingerprintCalculator(),
            moduleManifestBuilder: new ModuleManifestBuilder($envelopeFactory),
            compiledConfigBuilder: new CompiledConfigBuilder($envelopeFactory),
            runtimeContainerGraphCompiler: self::runtimeContainerGraphCompiler(),
            compiledContainerBuilder: new CompiledContainerBuilder($envelopeFactory),
            phpArrayDumper: $phpArrayDumper,
            generationManifestBuilder: new ArtifactGenerationManifestBuilder($envelopeFactory),
            generationLocator: new ArtifactGenerationLocator(
                lock: new ArtifactGenerationLock($generationPathResolver),
                pathResolver: $generationPathResolver,
                validator: $generationValidator,
            ),
            artifactReader: $artifactReader,
            pathResolver: new ArtifactPathResolver(),
            tracer: new NoopTracer(),
            meter: new NoopMeter(),
            logger: new NullLogger(),
            stopwatch: new Stopwatch(),
        );
    }

    /**
     * @param array<string,mixed> $kernelConfig
     */
    private static function configKernel(array $kernelConfig): ConfigKernel
    {
        $forbiddenRoots = $kernelConfig['config']['forbidden_top_level_roots'] ?? null;

        if (!\is_array($forbiddenRoots) || !\array_is_list($forbiddenRoots)) {
            throw new \LogicException('worker-e2e-config-namespace-policy-invalid');
        }

        $normalizedForbiddenRoots = [];

        foreach ($forbiddenRoots as $root) {
            if (!\is_string($root) || $root === '') {
                throw new \LogicException('worker-e2e-config-namespace-policy-invalid');
            }

            $normalizedForbiddenRoots[] = $root;
        }

        $directiveProcessor = new DirectiveProcessor(
            new ConfigNamespaceGuard($normalizedForbiddenRoots),
        );

        return new ConfigKernel(
            merger: new ConfigMerger($directiveProcessor),
            rulesLoader: new ConfigRulesLoader(),
            validator: new ConfigValidator(),
            explainer: new ConfigExplainer(),
            packageDefaultsLoader: new PackageDefaultsConfigLoader($directiveProcessor),
            skeletonLoader: new SkeletonConfigLoader($directiveProcessor),
            environmentOverlayLoader: new EnvironmentOverlayLoader(),
            meter: new NoopMeter(),
            tracer: new NoopTracer(),
            stopwatch: new Stopwatch(),
            logger: new NullLogger(),
        );
    }

    private static function fingerprintInputBuilder(): ConfigFingerprintInputBuilder
    {
        return new ConfigFingerprintInputBuilder(
            containerGraphBucketBuilder: new ContainerGraphFingerprintBucketBuilder(),
            payloadNormalizer: new PayloadNormalizer(),
            fileLister: new DeterministicFileLister(),
        );
    }

    private static function fingerprintCalculator(): FingerprintCalculator
    {
        return new FingerprintCalculator(
            payloadNormalizer: new PayloadNormalizer(),
            tracer: new NoopTracer(),
            meter: new NoopMeter(),
            logger: new NullLogger(),
            stopwatch: new Stopwatch(),
        );
    }

    private static function runtimeContainerGraphCompiler(): RuntimeContainerGraphCompiler
    {
        return new RuntimeContainerGraphCompiler(
            providerPlanResolver: new ContainerProviderPlanResolver(),
            containerCompiler: new ContainerCompiler(
                tracer: new NoopTracer(),
                meter: new NoopMeter(),
                logger: new NullLogger(),
                stopwatch: new Stopwatch(),
            ),
            completenessValidator: new ContainerGraphCompletenessValidator(),
        );
    }

    private static function generationPublisher(
        ArtifactEnvelopeFactory $envelopeFactory,
        StablePhpArrayDumper $phpArrayDumper,
    ): ArtifactGenerationPublisher {
        $generationPathResolver = new ArtifactGenerationPathResolver();
        $schemaValidator = new ArtifactSchemaValidator();

        return new ArtifactGenerationPublisher(
            artifactWriter: new ArtifactWriter(
                phpArrayDumper: new StablePhpArrayDumper(
                    new PayloadNormalizer(),
                ),
                tracer: new NoopTracer(),
                meter: new NoopMeter(),
                logger: new NullLogger(),
                stopwatch: new Stopwatch(),
            ),
            phpArrayDumper: $phpArrayDumper,
            manifestBuilder: new ArtifactGenerationManifestBuilder($envelopeFactory),
            validator: new ArtifactGenerationValidator(
                artifactReader: new PhpArtifactReader(),
                schemaValidator: $schemaValidator,
                manifestValidator: new ArtifactGenerationManifestValidator($schemaValidator),
            ),
            lock: new ArtifactGenerationLock($generationPathResolver),
            pathResolver: $generationPathResolver,
        );
    }

    private static function envelopeFactory(): ArtifactEnvelopeFactory
    {
        return new ArtifactEnvelopeFactory(
            new PayloadNormalizer(),
        );
    }

    private static function copyFile(
        string $sourcePath,
        string $targetPath,
    ): void {
        if (!\is_file($sourcePath)) {
            throw new \RuntimeException('worker-e2e-source-file-missing');
        }

        $directory = \dirname($targetPath);

        if (!\is_dir($directory) && !@\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            throw new \RuntimeException('worker-e2e-directory-create-failed');
        }

        if (!@\copy($sourcePath, $targetPath)) {
            throw new \RuntimeException('worker-e2e-source-copy-failed');
        }
    }

    private static function joinPath(
        string $root,
        string $relativePath,
    ): string {
        return \rtrim($root, '/\\')
            . \DIRECTORY_SEPARATOR
            . \str_replace(
                '/',
                \DIRECTORY_SEPARATOR,
                $relativePath,
            );
    }
}
