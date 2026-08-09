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

/**
 * Cross-driver worker-child fixture used by the supervisor process harness.
 *
 * It intentionally implements only deterministic test behavior. Production
 * worker execution remains owned by bin/coretsia-worker and ApplicationWorker.
 */

use Coretsia\Contracts\Worker\WorkerTaskType;
use Coretsia\Foundation\Tag\ReservedTags;
use Coretsia\Foundation\Tag\TagRegistry;
use Coretsia\Foundation\Time\Stopwatch;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Coretsia\Platform\Worker\Runtime\WorkerStopSignal;
use Coretsia\Platform\Worker\Tests\Support\RecordingKernelRuntime;
use Coretsia\Platform\Worker\Tests\Support\RecordingMeter;
use Coretsia\Platform\Worker\Tests\Support\RecordingTracer;
use Coretsia\Platform\Worker\Tests\Support\RecordingWorkerTask;
use Coretsia\Platform\Worker\Tests\Support\RecordingWorkerTaskSource;
use Coretsia\Platform\Worker\Tests\Support\WorkerSpecFactory;
use Coretsia\Platform\Worker\Worker\ApplicationWorker;
use Psr\Container\ContainerInterface;

$options = coretsia_worker_supervisor_fixture_options(
    $_SERVER['argv'] ?? [],
);

$cwd = \getcwd();

if (!\is_string($cwd) || $cwd === '') {
    exit(1);
}

$behavior = coretsia_worker_supervisor_fixture_behavior($cwd . '/worker-test-behavior.json');
$workerIndex = $options['index'];
$generation = coretsia_worker_supervisor_fixture_generation(
    root: $cwd,
    workerIndex: $workerIndex,
);

coretsia_worker_supervisor_fixture_record_spawn(
    root: $cwd,
    workerIndex: $workerIndex,
    generation: $generation,
);

if (
    coretsia_worker_supervisor_fixture_contains_slot(
        $behavior['ignore_termination_signal_slots'] ?? [],
        $workerIndex,
    )
    && \PHP_OS_FAMILY !== 'Windows'
    && \function_exists('pcntl_async_signals')
    && \function_exists('pcntl_signal')
) {
    \pcntl_async_signals(true);
    @\pcntl_signal(\SIGTERM, static function (): void {
        // Test-only: force guardian escalation from TERM to KILL.
    }, true);
}

if (
    coretsia_worker_supervisor_fixture_contains_slot(
        $behavior['crash_before_ready_slots'] ?? [],
        $workerIndex,
    )
) {
    exit(1);
}

$stopPath = $cwd . '/var/tmp/worker.stop';

if (
    coretsia_worker_supervisor_fixture_contains_slot(
        $behavior['ready_gate_slots'] ?? [],
        $workerIndex,
    )
) {
    $gatePath = $cwd . '/var/tmp/worker-ready-gate';

    while (!\is_file($gatePath)) {
        if (\is_file($stopPath)) {
            exit(0);
        }

        \usleep(10_000);
    }
}

$delayMs = $behavior['ready_delay_by_slot'][$workerIndex]
    ?? $behavior['ready_delay_ms']
    ?? 0;

if (\is_int($delayMs) && $delayMs > 0) {
    \usleep($delayMs * 1000);
}

$neverReady = coretsia_worker_supervisor_fixture_contains_slot(
    $behavior['never_ready_slots'] ?? [],
    $workerIndex,
);

coretsia_worker_supervisor_fixture_task_source_start_failure(
    behavior: $behavior,
    workerIndex: $workerIndex,
    generation: $generation,
    root: $cwd,
    options: $options,
);

$applicationWorkerRun = coretsia_worker_supervisor_fixture_application_worker_run(
    behavior: $behavior,
    workerIndex: $workerIndex,
    generation: $generation,
    root: $cwd,
    options: $options,
);

if ($applicationWorkerRun !== null) {
    $applicationWorkerRun['worker']->assertReady(
        $applicationWorkerRun['spec'],
        $workerIndex,
    );
}

if (!$neverReady) {
    coretsia_worker_supervisor_fixture_signal_ready(
        port: $options['readiness_port'],
        token: $options['readiness_token'],
    );
}

if ($applicationWorkerRun !== null) {
    if ($neverReady) {
        exit(1);
    }

    $processed = $applicationWorkerRun['worker']->run(
        $applicationWorkerRun['spec'],
        $workerIndex,
    );

    coretsia_worker_supervisor_fixture_record_application_worker_run(
        root: $cwd,
        workerIndex: $workerIndex,
        generation: $generation,
        maxRequests: $options['max_requests'],
        processed: $processed,
        kernelCalls: $applicationWorkerRun['kernel']->calls,
        taskReceiveCalls: $applicationWorkerRun['source']->receiveCalls,
    );

    $exitDelayMs = $applicationWorkerRun['exit_delay_ms'];

    if ($exitDelayMs > 0) {
        \usleep($exitDelayMs * 1000);
    }

    exit(0);
}

$exitAfterReady = $behavior['exit_after_ready'] ?? null;

if (
    \is_array($exitAfterReady)
    && ($exitAfterReady['slot'] ?? null) === $workerIndex
    && (
        ($exitAfterReady['first_generation_only'] ?? true) === false
        || $generation === 1
    )
) {
    if (($exitAfterReady['wait_for_release'] ?? false) === true) {
        $gatePath = $cwd . '/var/tmp/worker-exit-gate';
        $stopPath = $cwd . '/var/tmp/worker.stop';

        while (!\is_file($gatePath)) {
            if (\is_file($stopPath)) {
                exit(0);
            }

            \usleep(10_000);
        }
    }

    $exitDelayMs = $exitAfterReady['delay_ms'] ?? 100;

    if (\is_int($exitDelayMs) && $exitDelayMs > 0) {
        \usleep($exitDelayMs * 1000);
    }

    $code = $exitAfterReady['code'] ?? 0;

    exit(
        \is_int($code) && $code >= 0 && $code <= 255
            ? $code
            : 1
    );
}

$ignoreStop = coretsia_worker_supervisor_fixture_contains_slot(
    $behavior['ignore_stop_slots'] ?? [],
    $workerIndex,
);

while (true) {
    if (!$ignoreStop && \is_file($stopPath)) {
        exit(0);
    }

    \usleep(10_000);
}

/**
 * @param list<string> $argv
 * @return array{
 *     driver: 'pcntl'|'proc',
 *     index: int,
 *     worker_count: int,
 *     max_requests: int,
 *     task_type: string,
 *     readiness_port: int,
 *     readiness_token: string
 * }
 */
function coretsia_worker_supervisor_fixture_options(array $argv): array
{
    $values = [];

    foreach (\array_slice($argv, 1) as $argument) {
        if (
            !\is_string($argument)
            || !\str_starts_with($argument, '--')
        ) {
            exit(1);
        }

        $separator = \strpos($argument, '=');

        if ($separator === false) {
            exit(1);
        }

        $name = \substr(
            $argument,
            2,
            $separator - 2,
        );

        $value = \substr(
            $argument,
            $separator + 1,
        );

        if (
            $name === ''
            || $value === ''
            || \array_key_exists($name, $values)
        ) {
            exit(1);
        }

        $values[$name] = $value;
    }

    foreach (
        [
            'coretsia-worker-index',
            'coretsia-worker-count',
            'coretsia-worker-max-requests',
            'coretsia-worker-task-type',
            'coretsia-worker-driver',
            'coretsia-worker-readiness-port',
            'coretsia-worker-readiness-token',
        ] as $required
    ) {
        if (!\array_key_exists($required, $values)) {
            exit(1);
        }
    }

    $index = \filter_var(
        $values['coretsia-worker-index'],
        \FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]],
    );

    $workerCount = \filter_var(
        $values['coretsia-worker-count'],
        \FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]],
    );

    $maxRequests = \filter_var(
        $values['coretsia-worker-max-requests'],
        \FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]],
    );

    $taskType = $values['coretsia-worker-task-type'];
    $driver = $values['coretsia-worker-driver'];

    $port = \filter_var(
        $values['coretsia-worker-readiness-port'],
        \FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 65_535,
            ],
        ],
    );

    $token = $values['coretsia-worker-readiness-token'];

    if (
        !\is_int($index)
        || !\is_int($workerCount)
        || $index >= $workerCount
        || !\is_int($maxRequests)
        || !\in_array($taskType, ['http', 'queue'], true)
        || !\in_array($driver, ['pcntl', 'proc'], true)
        || !\is_int($port)
        || \preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
    ) {
        exit(1);
    }

    return [
        'driver' => $driver,
        'index' => $index,
        'worker_count' => $workerCount,
        'max_requests' => $maxRequests,
        'task_type' => $taskType,
        'readiness_port' => $port,
        'readiness_token' => $token,
    ];
}

/**
 * @param array<string, mixed> $behavior
 * @param array{
 *     driver: 'pcntl'|'proc',
 *     index: int,
 *     worker_count: int,
 *     max_requests: int,
 *     task_type: string,
 *     readiness_port: int,
 *     readiness_token: string
 * } $options
 */
function coretsia_worker_supervisor_fixture_task_source_start_failure(
    array $behavior,
    int $workerIndex,
    int $generation,
    string $root,
    array $options,
): void {
    $configuration = $behavior['task_source_start_failure'] ?? null;

    if (
        !\is_array($configuration)
        || ($configuration['slot'] ?? null) !== $workerIndex
        || (
            ($configuration['first_generation_only'] ?? true) === true
            && $generation !== 1
        )
    ) {
        return;
    }

    $scenario = $configuration['scenario'] ?? null;

    if (
        $scenario !== 'missing'
        && $scenario !== 'ambiguous'
        && $scenario !== 'not_ready'
    ) {
        exit(1);
    }

    coretsia_worker_supervisor_fixture_require_autoload();
    coretsia_worker_supervisor_fixture_register_test_autoloader();

    $taskType = WorkerTaskType::from(
        $options['task_type'],
    );

    $spec = WorkerSpecFactory::create([
        'workers' => $options['worker_count'],
        'max_requests' => $options['max_requests'],
        'task_type' => $options['task_type'],
        'driver' => $options['driver'],
        'control' => [
            'transport' => 'tcp',
        ],
    ]);

    $tags = new TagRegistry();

    /** @var array<string, object> $services */
    $services = [];

    if ($scenario === 'ambiguous') {
        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.one',
            meta: [
                'task_type' => $taskType->value,
            ],
        );

        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.two',
            meta: [
                'task_type' => $taskType->value,
            ],
        );
    }

    if ($scenario === 'not_ready') {
        $source = new RecordingWorkerTaskSource(
            $taskType,
        );

        $source->readyFailure = new \RuntimeException('private-task-source-readiness-failure');

        $services['worker.source.selected'] = $source;

        $tags->add(
            ReservedTags::WORKER_TASK_SOURCE,
            'worker.source.selected',
            meta: [
                'task_type' => $taskType->value,
            ],
        );
    }

    $container = new class($services) implements ContainerInterface {
        /**
         * @param array<string, object> $services
         */
        public function __construct(
            private readonly array $services,
        ) {
        }

        public function get(string $id): mixed
        {
            if (!isset($this->services[$id])) {
                throw new \RuntimeException('private-task-source-container-failure');
            }

            return $this->services[$id];
        }

        public function has(string $id): bool
        {
            return isset($this->services[$id]);
        }
    };

    $factory = new WorkerServiceFactory();

    try {
        $resolver = $factory->workerTaskSourceResolver(
            container: $container,
            tags: $tags,
        );

        $source = $factory->workerTaskSource(
            spec: $spec,
            resolver: $resolver,
        );

        if ($scenario !== 'not_ready') {
            exit(1);
        }

        $worker = $factory->applicationWorker(
            stopSignal: new WorkerStopSignal($root),
            kernelRuntime: new RecordingKernelRuntime(),
            taskSource: $source,
            stopwatch: new Stopwatch(),
            tracer: new RecordingTracer(),
            meter: new RecordingMeter(),
        );

        $worker->assertReady(
            $spec,
            $workerIndex,
        );
    } catch (WorkerStartFailedException $exception) {
        $expectedReason = match ($scenario) {
            'missing' => WorkerStartFailedException::REASON_TASK_SOURCE_MISSING,
            'ambiguous' => WorkerStartFailedException::REASON_TASK_SOURCE_AMBIGUOUS,
            'not_ready' => WorkerStartFailedException::REASON_TASK_SOURCE_NOT_READY,
        };

        if ($exception->reason() !== $expectedReason) {
            exit(1);
        }

        /*
         * Expected source failure. Exit before the fixture reaches its
         * readiness-frame publication.
         */
        exit(1);
    }

    /*
     * The selected failure scenario unexpectedly passed source resolution and
     * readiness preflight.
     */
    exit(1);
}

/**
 * @param array<string, mixed> $behavior
 * @param array{
 *     driver: 'pcntl'|'proc',
 *     index: int,
 *     worker_count: int,
 *     max_requests: int,
 *     task_type: string,
 *     readiness_port: int,
 *     readiness_token: string
 * } $options
 * @return array{
 *     worker: ApplicationWorker,
 *     spec: WorkerPoolSpec,
 *     kernel: RecordingKernelRuntime,
 *     source: RecordingWorkerTaskSource,
 *     exit_delay_ms: int
 * }|null
 */
function coretsia_worker_supervisor_fixture_application_worker_run(
    array $behavior,
    int $workerIndex,
    int $generation,
    string $root,
    array $options,
): ?array {
    $configuration = $behavior['application_worker_max_requests'] ?? null;

    if (
        !\is_array($configuration)
        || ($configuration['slot'] ?? null) !== $workerIndex
        || (
            ($configuration['first_generation_only'] ?? true) === true
            && $generation !== 1
        )
    ) {
        return null;
    }

    $exitDelayMs = $configuration['exit_delay_ms'] ?? 100;

    if (
        !\is_int($exitDelayMs)
        || $exitDelayMs < 0
        || $exitDelayMs > 10_000
    ) {
        exit(1);
    }

    coretsia_worker_supervisor_fixture_require_autoload();
    coretsia_worker_supervisor_fixture_register_test_autoloader();

    $spec = WorkerSpecFactory::create([
        'workers' => $options['worker_count'],
        'max_requests' => $options['max_requests'],
        'task_type' => $options['task_type'],
        'driver' => $options['driver'],
        'control' => [
            'transport' => 'tcp',
        ],
    ]);

    $kernel = new RecordingKernelRuntime();
    $source = new RecordingWorkerTaskSource(
        WorkerTaskType::from($options['task_type']),
    );

    for ($i = 0; $i < $options['max_requests']; ++$i) {
        $source->tasks[] = new RecordingWorkerTask($i);
    }

    return [
        'worker' => new ApplicationWorker(
            stopSignal: new WorkerStopSignal($root),
            kernelRuntime: $kernel,
            taskSource: $source,
            stopwatch: new Stopwatch(),
            tracer: new RecordingTracer(),
            meter: new RecordingMeter(),
        ),
        'spec' => $spec,
        'kernel' => $kernel,
        'source' => $source,
        'exit_delay_ms' => $exitDelayMs,
    ];
}

function coretsia_worker_supervisor_fixture_require_autoload(): void
{
    $directory = __DIR__;

    while (true) {
        $autoload = $directory . '/vendor/autoload.php';

        if (\is_file($autoload)) {
            require_once $autoload;

            return;
        }

        $parent = \dirname($directory);

        if ($parent === $directory) {
            exit(1);
        }

        $directory = $parent;
    }
}

function coretsia_worker_supervisor_fixture_register_test_autoloader(): void
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

function coretsia_worker_supervisor_fixture_record_application_worker_run(
    string $root,
    int $workerIndex,
    int $generation,
    int $maxRequests,
    int $processed,
    int $kernelCalls,
    int $taskReceiveCalls,
): void {
    $path = $root . '/var/tmp/worker-application-runs.jsonl';

    $line = \json_encode(
        [
                'generation' => $generation,
                'kernel_calls' => $kernelCalls,
                'max_requests' => $maxRequests,
                'pid' => \getmypid(),
                'processed' => $processed,
                'slot' => $workerIndex,
                'task_receive_calls' => $taskReceiveCalls,
            ],
        \JSON_UNESCAPED_SLASHES
            | \JSON_UNESCAPED_UNICODE
            | \JSON_THROW_ON_ERROR,
    ) . "\n";

    if (
        @\file_put_contents(
            $path,
            $line,
            \FILE_APPEND | \LOCK_EX,
        ) === false
    ) {
        exit(1);
    }
}

/** @return array<string, mixed> */
function coretsia_worker_supervisor_fixture_behavior(
    string $path,
): array {
    $bytes = @\file_get_contents($path);

    if (!\is_string($bytes)) {
        return [];
    }

    try {
        $value = \json_decode(
            $bytes,
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
    } catch (\Throwable) {
        return [];
    }

    return \is_array($value)
    && !\array_is_list($value)
        ? $value
        : [];
}

function coretsia_worker_supervisor_fixture_generation(
    string $root,
    int $workerIndex,
): int {
    $directory = $root . '/var/tmp';

    if (
        !\is_dir($directory)
        && !@\mkdir($directory, 0777, true)
        && !\is_dir($directory)
    ) {
        exit(1);
    }

    $path = $directory
        . '/worker-generation-'
        . $workerIndex
        . '.txt';

    $handle = @\fopen($path, 'c+b');

    if (
        !\is_resource($handle)
        || !@\flock($handle, \LOCK_EX)
    ) {
        if (\is_resource($handle)) {
            @\fclose($handle);
        }

        exit(1);
    }

    try {
        @\rewind($handle);
        $bytes = @\stream_get_contents($handle);

        $current = \is_string($bytes)
        && \ctype_digit(\trim($bytes))
            ? (int)\trim($bytes)
            : 0;

        $generation = $current + 1;

        if (
            !@\ftruncate($handle, 0)
            || !@\rewind($handle)
            || @\fwrite($handle, (string)$generation) === false
            || !@\fflush($handle)
        ) {
            exit(1);
        }

        return $generation;
    } finally {
        @\flock($handle, \LOCK_UN);
        @\fclose($handle);
    }
}

function coretsia_worker_supervisor_fixture_record_spawn(
    string $root,
    int $workerIndex,
    int $generation,
): void {
    $path = $root . '/var/tmp/worker-pids.jsonl';

    $line = \json_encode(
        [
                'generation' => $generation,
                'pid' => \getmypid(),
                'slot' => $workerIndex,
            ],
        \JSON_UNESCAPED_SLASHES
            | \JSON_UNESCAPED_UNICODE
            | \JSON_THROW_ON_ERROR,
    ) . "\n";

    @\file_put_contents(
        $path,
        $line,
        \FILE_APPEND | \LOCK_EX,
    );
}

function coretsia_worker_supervisor_fixture_signal_ready(
    int $port,
    string $token,
): void {
    $stream = @\stream_socket_client(
        'tcp://127.0.0.1:' . $port,
        $errorCode,
        $errorMessage,
        1.0,
        \STREAM_CLIENT_CONNECT,
    );

    if (!\is_resource($stream)) {
        exit(1);
    }

    try {
        $remaining = 'ready:'
            . $token
            . "\n";

        while ($remaining !== '') {
            $written = @\fwrite(
                $stream,
                $remaining,
            );

            if (
                !\is_int($written)
                || $written < 1
            ) {
                exit(1);
            }

            $remaining = \substr(
                $remaining,
                $written,
            );
        }

        if (!@\fflush($stream)) {
            exit(1);
        }
    } finally {
        @\fclose($stream);
    }
}

/** @param list<int>|mixed $slots */
function coretsia_worker_supervisor_fixture_contains_slot(
    mixed $slots,
    int $workerIndex,
): bool {
    return \is_array($slots)
        && \in_array(
            $workerIndex,
            $slots,
            true,
        );
}
