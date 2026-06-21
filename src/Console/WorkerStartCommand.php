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

namespace Coretsia\Platform\Worker\Console;

use Coretsia\Contracts\Cli\Command\CommandInterface;
use Coretsia\Contracts\Cli\Input\InputInterface;
use Coretsia\Contracts\Cli\Output\OutputInterface;
use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Driver\RuntimeDriverGuard;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverConflictException;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverInvalidConfigException;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

/**
 * Starts the configured worker pool.
 *
 * This command is package-local and contracts-only:
 *
 * - it implements the core/contracts CLI command port;
 * - it consumes parsed InputInterface accessors;
 * - it writes only through OutputInterface;
 * - it does not depend on platform/cli;
 * - it does not require full binary/catalog dispatch.
 *
 * Runtime-driver compatibility is checked after an explicit disabled-worker
 * short-circuit and before WorkerManager::start(). WorkerManager is resolved
 * lazily so resolving this command from the container cannot construct process
 * drivers, ApplicationWorker, TaskFactoryInternalInterface, or WorkerPoolSpec
 * before the command run path has enforced the required ordering.
 *
 * Guard failures are surfaced using the original RuntimeDriverGuard error code
 * and reason token, not translated into worker-specific conflict codes.
 *
 * This class must not:
 *
 * - resolve RequestHandlerInterface;
 * - fork;
 * - call proc_open();
 * - open sockets;
 * - write pid/state/socket/stop files;
 * - write ContextStore values;
 * - write stdout/stderr directly;
 * - expose raw config values, raw endpoints, absolute paths, env values,
 *   payloads, headers, tokens, or throwable messages.
 */
final readonly class WorkerStartCommand implements CommandInterface
{
    public const string NAME = 'worker:start';
    public const string SUMMARY = 'Start the configured worker pool.';
    public const string GROUP = 'worker';
    public const bool HIDDEN = false;
    public const string MODE = 'none';

    /**
     * @var list<array<string, mixed>>
     */
    public const array ARGUMENTS = [];

    /**
     * @var list<array<string, mixed>>
     */
    public const array OPTIONS = [];

    private const int EXIT_SUCCESS = 0;
    private const int EXIT_FAILURE = 1;

    private const string ERROR_CODE_WORKER_COMMAND_INVALID = 'CORETSIA_WORKER_COMMAND_INVALID';
    private const string ERROR_CODE_WORKER_DISABLED = 'CORETSIA_WORKER_DISABLED';
    private const string ERROR_CODE_WORKER_START_FAILED = 'CORETSIA_WORKER_START_FAILED';

    /**
     * @param \Closure(): WorkerManager $managerFactory
     */
    public function __construct(
        private ConfigRepositoryInterface $config,
        private ModulePlan $modulePlan,
        private RuntimeDriverGuard $runtimeDriverGuard,
        private WorkerServiceFactory $factory,
        private \Closure $managerFactory,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->assertParsedInput($input, $output)) {
            return self::EXIT_FAILURE;
        }

        try {
            if ($this->workerExplicitlyDisabled()) {
                $output->error(
                    self::ERROR_CODE_WORKER_DISABLED,
                    'worker-disabled',
                );

                return self::EXIT_FAILURE;
            }

            $this->assertRuntimeDriverCompatibility();

            $spec = $this->factory->workerPoolSpec($this->config);

            if (!$spec->enabled()) {
                $output->error(
                    self::ERROR_CODE_WORKER_DISABLED,
                    'worker-disabled',
                );

                return self::EXIT_FAILURE;
            }

            $state = $this->manager()->start($spec);

            $output->json(self::startSummary($state));

            return self::EXIT_SUCCESS;
        } catch (RuntimeDriverConflictException $exception) {
            $output->error(
                $exception->errorCode(),
                $exception->reason(),
            );

            return self::EXIT_FAILURE;
        } catch (RuntimeDriverInvalidConfigException $exception) {
            $output->error(
                $exception->errorCode(),
                $exception->reason(),
            );

            return self::EXIT_FAILURE;
        } catch (\Throwable) {
            $output->error(
                self::ERROR_CODE_WORKER_START_FAILED,
                'worker-start-failed',
            );

            return self::EXIT_FAILURE;
        }
    }

    private function assertRuntimeDriverCompatibility(): void
    {
        $this->runtimeDriverGuard->assertCompatible($this->config);

        if (!$this->workerHttpTaskModeRequested()) {
            return;
        }

        $this->runtimeDriverGuard->assertHttpDriverCompatibleWithModules(
            cfg: $this->config,
            plan: $this->modulePlan,
        );
    }

    private function workerExplicitlyDisabled(): bool
    {
        return $this->config->has('worker.enabled')
            && $this->config->get('worker.enabled') === false;
    }

    private function workerHttpTaskModeRequested(): bool
    {
        return $this->config->has('worker.enabled')
            && $this->config->get('worker.enabled') === true
            && $this->config->has('worker.task_type')
            && $this->config->get('worker.task_type') === 'http';
    }

    private function manager(): WorkerManager
    {
        return ($this->managerFactory)();
    }

    private function assertParsedInput(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->commandName() !== self::NAME) {
            $output->error(
                self::ERROR_CODE_WORKER_COMMAND_INVALID,
                'worker-command-name-invalid',
            );

            return false;
        }

        if ($input->arguments() !== []) {
            $output->error(
                self::ERROR_CODE_WORKER_COMMAND_INVALID,
                'worker-start-arguments-not-supported',
            );

            return false;
        }

        if ($input->options() !== []) {
            $output->error(
                self::ERROR_CODE_WORKER_COMMAND_INVALID,
                'worker-start-options-not-supported',
            );

            return false;
        }

        return true;
    }

    /**
     * @return array{
     *     status: 'started',
     *     pid: int,
     *     worker_count: int,
     *     driver: string,
     *     control_transport: string,
     *     endpoint_hash: string
     * }
     */
    private static function startSummary(WorkerPoolState $state): array
    {
        return [
            'status' => 'started',
            'pid' => $state->pid(),
            'worker_count' => $state->workerCount(),
            'driver' => $state->driver(),
            'control_transport' => $state->controlTransport(),
            'endpoint_hash' => $state->endpointHash(),
        ];
    }
}
