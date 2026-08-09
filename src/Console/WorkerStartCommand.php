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
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverConflictException;
use Coretsia\Kernel\Runtime\Exception\RuntimeDriverInvalidConfigException;
use Coretsia\Platform\Worker\Exception\WorkerException;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface;
use Coretsia\Platform\Worker\Internal\WorkerSupervisorResolverInterface;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;
use Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard;

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
 * Runtime entrypoint compatibility is checked before the foreground
 * WorkerSupervisor is resolved. The supervisor remains lazy so resolving this
 * command from the container cannot construct process drivers, ApplicationWorker,
 * WorkerTaskSourceInterface, or lifecycle resources before the command run
 * path has enforced the required ordering.
 *
 * Guard failures are surfaced using the original runtime driver matrix error
 * code and reason token, not translated into worker-specific conflict codes.
 *
 * This class must not:
 *
 * - resolve or instantiate task-source services directly;
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
    public const array ARGUMENTS = [];
    public const array OPTIONS = [];

    private const string ERROR_CODE_INVALID = 'CORETSIA_WORKER_COMMAND_INVALID';
    private const string ERROR_CODE_FAILED = 'CORETSIA_WORKER_START_FAILED';

    public function __construct(
        private ConfigRepositoryInterface $config,
        private ModulePlan $modulePlan,
        private WorkerRuntimeEntrypointGuard $runtimeEntrypointGuard,
        private WorkerServiceFactory $factory,
        private WorkerSupervisorResolverInterface $supervisorResolver,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        if (!$this->validInput($input, $output)) {
            return 1;
        }
        try {
            $spec = $this->factory->workerPoolSpec($this->config);
            $this->runtimeEntrypointGuard->assertEntrypointAllowed(
                config: $this->config,
                modulePlan: $this->modulePlan,
                spec: $spec,
            );

            return $this->supervisor()->run(
                $spec,
                static function (WorkerPoolState $state) use ($output): void {
                    $output->json(self::summary($state));
                },
            );
        } catch (RuntimeDriverConflictException|RuntimeDriverInvalidConfigException $exception) {
            $output->error($exception->errorCode(), $exception->reason());
        } catch (WorkerException $exception) {
            $output->error($exception->errorCode(), $exception->reason());
        } catch (\Throwable) {
            $output->error(self::ERROR_CODE_FAILED, 'worker-start-failed');
        }
        return 1;
    }

    private function supervisor(): WorkerSupervisorInterface
    {
        return $this->supervisorResolver->resolve();
    }

    private function validInput(
        InputInterface $input,
        OutputInterface $output,
    ): bool {
        if ($input->commandName() !== self::NAME) {
            $output->error(self::ERROR_CODE_INVALID, 'worker-command-name-invalid');
            return false;
        }
        if ($input->arguments() !== []) {
            $output->error(self::ERROR_CODE_INVALID, 'worker-start-arguments-not-supported');
            return false;
        }
        if ($input->options() !== []) {
            $output->error(self::ERROR_CODE_INVALID, 'worker-start-options-not-supported');
            return false;
        }
        return true;
    }

    /** @return array<string, int|string> */
    private static function summary(WorkerPoolState $state): array
    {
        return [
            'status' => $state->status()->value,
            'pid' => $state->pid(),
            'worker_count' => $state->workerCount(),
            'ready_worker_count' => $state->readyWorkerCount(),
            'driver' => $state->driver(),
            'control_transport' => $state->controlTransport(),
            'endpoint_hash' => $state->endpointHash(),
        ];
    }
}
