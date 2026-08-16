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

use Coretsia\Contracts\Cli\Output\OutputInterface;
use Coretsia\Contracts\Module\ModuleId;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Module\ModulePlanEntry;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverResolver;
use Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel;
use Coretsia\Platform\Worker\Communication\WorkerControlClient;
use Coretsia\Platform\Worker\Communication\WorkerControlProtocol;
use Coretsia\Platform\Worker\Communication\WorkerControlServer;
use Coretsia\Platform\Worker\Communication\WorkerControlTransport;
use Coretsia\Platform\Worker\Console\WorkerHealthCommand;
use Coretsia\Platform\Worker\Console\WorkerStartCommand;
use Coretsia\Platform\Worker\Console\WorkerStatusCommand;
use Coretsia\Platform\Worker\Console\WorkerStopCommand;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface;
use Coretsia\Platform\Worker\Internal\WorkerProcessDriverResolverInterface;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorResolverInterface;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher;
use Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol;
use Coretsia\Platform\Worker\Process\Driver\PcntlWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\Driver\ProcWorkerProcessDriver;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianClient;
use Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol;
use Coretsia\Platform\Worker\Process\WorkerChildCommandBuilder;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocatorStore;
use Coretsia\Platform\Worker\Runtime\WorkerLifecycleLock;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Runtime\WorkerStateStore;
use Coretsia\Platform\Worker\Runtime\WorkerStopSignal;
use Coretsia\Platform\Worker\Supervisor\WorkerChildTable;
use Coretsia\Platform\Worker\Supervisor\WorkerSignalController;
use Coretsia\Platform\Worker\Supervisor\WorkerSupervisor;
use Coretsia\Platform\Worker\Tests\Support\ArrayConfigRepository;
use Coretsia\Platform\Worker\Tests\Support\RecordingLogger;
use Coretsia\Platform\Worker\Tests\Support\RecordingMeter;
use Coretsia\Platform\Worker\Tests\Support\RecordingTracer;
use Coretsia\Platform\Worker\Tests\Support\TestInput;

$frameworkRoot = coretsia_worker_test_require_autoload();
coretsia_worker_test_register_test_autoloader();

final class CoretsiaWorkerHarnessOutput implements OutputInterface
{
    public function text(string $text): void
    {
        $this->write([
            'type' => 'text',
            'text' => $text,
        ]);
    }

    public function json(array $payload): void
    {
        $this->write([
            'type' => 'json',
            'payload' => $payload,
        ]);
    }

    public function error(string $code, string $message): void
    {
        $this->write([
            'type' => 'error',
            'code' => $code,
            'message' => $message,
        ]);
    }

    /** @param array<string, mixed> $value */
    private function write(array $value): void
    {
        \fwrite(
            STDOUT,
            \json_encode(
                $value,
                \JSON_UNESCAPED_SLASHES
                | \JSON_UNESCAPED_UNICODE
                | \JSON_THROW_ON_ERROR,
            ) . "\n",
        );

        \fflush(STDOUT);
    }
}


if ($argc !== 6) {
    exit(64);
}

$operation = $argv[1];
$skeletonRoot = $argv[2];
$configPath = $argv[3];
$behaviorPath = $argv[4];
$exitCodePath = $argv[5];

if (
    $exitCodePath === ''
    || \str_contains($exitCodePath, "\0")
) {
    exit(64);
}

if (
    $operation === 'start'
    && \PHP_OS_FAMILY !== 'Windows'
    && \function_exists('posix_setsid')
) {
    $sessionId = @\posix_setsid();

    if (!\is_int($sessionId) || $sessionId < 1) {
        exit(70);
    }
}

$config = coretsia_worker_test_decode_file($configPath);
$behavior = coretsia_worker_test_decode_file($behaviorPath);

$repository = new ArrayConfigRepository($config);
$factory = new WorkerServiceFactory();
$transport = new WorkerControlTransport($skeletonRoot);
$protocol = new WorkerControlProtocol();
$lock = new WorkerLifecycleLock($skeletonRoot);
$locatorStore = new WorkerLifecycleLocatorStore(
    skeletonRoot: $skeletonRoot,
);
$logger = new RecordingLogger();
$meter = new RecordingMeter();
$tracer = new RecordingTracer();
$stopwatch = new Stopwatch();
$output = new CoretsiaWorkerHarnessOutput();

if ($operation === 'start') {
    $server = new WorkerControlServer($transport, $protocol);
    $readiness = new WorkerChildReadinessChannel();
    $children = new WorkerChildTable();
    $signals = new WorkerSignalController();
    $stopSignal = new WorkerStopSignal($skeletonRoot);
    $stateStore = new WorkerStateStore(
        skeletonRoot: $skeletonRoot,
    );

    $specForDriver = $factory->workerPoolSpec($repository);
    $driverName = $specForDriver->driver();

    $guardian = new WorkerProcessGuardianClient(
        command: [
            PHP_BINARY,
            \dirname(__DIR__, 2) . '/bin/coretsia-worker-guardian',
        ],
        bootstrapWorkingDirectory: $frameworkRoot,
        skeletonRoot: $skeletonRoot,
        protocol: new WorkerProcessGuardianProtocol(),
        bootstrapLauncher: new WorkerProcessBootstrapLauncher(
            new WorkerProcessBootstrapProtocol(),
        ),
    );

    $childCommand = [
        PHP_BINARY,
        __DIR__ . '/supervisor-worker-child.php',
    ];
    $commandBuilder = new WorkerChildCommandBuilder('var/tmp');

    if ($driverName === 'proc') {
        $driver = new ProcWorkerProcessDriver(
            skeletonRoot: $skeletonRoot,
            workerCommand: $childCommand,
            commandBuilder: $commandBuilder,
            readinessChannel: $readiness,
            guardian: $guardian,
            driverAvailable: WorkerProcessCapabilities::procDriverAvailable(),
        );
    } else {
        $driver = new PcntlWorkerProcessDriver(
            skeletonRoot: $skeletonRoot,
            workerCommand: $childCommand,
            commandBuilder: $commandBuilder,
            readinessChannel: $readiness,
            guardian: $guardian,
            driverAvailable: WorkerProcessCapabilities::pcntlDriverAvailable(),
            platformFamily: PHP_OS_FAMILY,
        );
    }

    $driverResolver = new class($driver) implements WorkerProcessDriverResolverInterface {
        public function __construct(
            private readonly WorkerProcessDriverInterface $driver,
        ) {
        }

        public function resolve(WorkerPoolSpec $spec): WorkerProcessDriverInterface
        {
            if (!$this->driver->supports($spec)) {
                throw WorkerStartFailedException::childStartFailed();
            }

            return $this->driver;
        }
    };

    $supervisor = new WorkerSupervisor(
        driverResolver: $driverResolver,
        guardian: $guardian,
        locatorStore: $locatorStore,
        controlServer: $server,
        readinessChannel: $readiness,
        children: $children,
        signals: $signals,
        stateStore: $stateStore,
        stopSignal: $stopSignal,
        tracer: $tracer,
        meter: $meter,
        logger: $logger,
        stopwatch: $stopwatch,
    );

    $resolver = new class($supervisor) implements WorkerSupervisorResolverInterface {
        public function __construct(
            private readonly WorkerSupervisorInterface $supervisor,
        ) {
        }

        public function resolve(): WorkerSupervisorInterface
        {
            return $this->supervisor;
        }
    };

    $command = new WorkerStartCommand(
        config: $repository,
        modulePlan: coretsia_worker_test_module_plan(),
        runtimeEntrypointGuard: new WorkerRuntimeEntrypointGuard(
            new RuntimeDriverResolver(),
        ),
        factory: $factory,
        supervisorResolver: $resolver,
    );

    coretsia_worker_test_exit(
        exitCode: $command->run(
            new TestInput(WorkerStartCommand::NAME),
            $output,
        ),
        path: $exitCodePath,
    );
}

$client = new WorkerControlClient(
    transport: $transport,
    protocol: $protocol,
    lifecycleLock: $lock,
    locatorStore: $locatorStore,
    tracer: $tracer,
    meter: $meter,
    logger: $logger,
    stopwatch: $stopwatch,
);

$command = match ($operation) {
    'status' => new WorkerStatusCommand(
        client: $client,
    ),
    'health' => new WorkerHealthCommand(
        client: $client,
    ),
    'stop' => new WorkerStopCommand(
        client: $client,
    ),
    default => null,
};

if ($command === null) {
    coretsia_worker_test_exit(
        exitCode: 64,
        path: $exitCodePath,
    );
}

coretsia_worker_test_exit(
    exitCode: $command->run(
        new TestInput($command->name()),
        $output,
    ),
    path: $exitCodePath,
);

function coretsia_worker_test_exit(
    int $exitCode,
    string $path,
): never {
    if ($exitCode < 0 || $exitCode > 255) {
        $exitCode = 1;
    }

    $temporaryPath = $path . '.tmp';

    /*
     * Publish the exit code atomically.
     *
     * The parent must never observe an existing but still-empty final file.
     * This is particularly important on Windows, where process-handle and
     * filesystem visibility ordering is not reliable enough for a single
     * immediate read after proc_get_status() reports termination.
     */
    @\unlink($temporaryPath);
    @\unlink($path);

    if (
        @\file_put_contents(
            $temporaryPath,
            (string)$exitCode . "\n",
            \LOCK_EX,
        ) === false
        || !@\rename(
            $temporaryPath,
            $path,
        )
    ) {
        @\unlink($temporaryPath);
        @\unlink($path);

        exit(70);
    }

    exit($exitCode);
}

function coretsia_worker_test_require_autoload(): string
{
    $directory = __DIR__;

    while (true) {
        $autoload = $directory . '/vendor/autoload.php';

        if (\is_file($autoload)) {
            require $autoload;

            return $directory;
        }

        $parent = \dirname($directory);

        if ($parent === $directory) {
            break;
        }

        $directory = $parent;
    }

    \fwrite(STDERR, "autoload-not-found\n");
    exit(70);
}

function coretsia_worker_test_register_test_autoloader(): void
{
    $prefix = 'Coretsia\\Platform\\Worker\\Tests\\';
    $testsRoot = \dirname(__DIR__);

    \spl_autoload_register(
        static function (string $class) use (
            $prefix,
            $testsRoot,
        ): void {
            if (!\str_starts_with($class, $prefix)) {
                return;
            }

            $relative = \substr(
                $class,
                \strlen($prefix),
            );

            if ($relative === '') {
                return;
            }

            $path = $testsRoot
                . '/'
                . \str_replace('\\', '/', $relative)
                . '.php';

            if (\is_file($path)) {
                require_once $path;
            }
        },
    );
}

/** @return array<string, mixed> */
function coretsia_worker_test_decode_file(string $path): array
{
    $bytes = \file_get_contents($path);

    if (!\is_string($bytes)) {
        exit(65);
    }

    $value = \json_decode(
        $bytes,
        true,
        512,
        \JSON_THROW_ON_ERROR,
    );

    if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
        exit(65);
    }

    return $value;
}

function coretsia_worker_test_module_plan(): ModulePlan
{
    $workerId = ModuleId::fromString('platform.worker');

    return new ModulePlan(
        app: 'worker',
        preset: 'test',
        enabled: [$workerId],
        disabled: [],
        optionalMissing: [],
        topologicalOrder: [$workerId],
        modules: [
            new ModulePlanEntry(
                moduleId: $workerId,
                composerName: 'coretsia/platform-worker',
            ),
        ],
        warnings: [],
    );
}
