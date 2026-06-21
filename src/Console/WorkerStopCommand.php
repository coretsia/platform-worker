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
use Coretsia\Platform\Worker\Exception\WorkerCommunicationFailedException;
use Coretsia\Platform\Worker\Exception\WorkerNotRunningException;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Manager\WorkerManager;
use Coretsia\Platform\Worker\Provider\WorkerServiceFactory;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

/**
 * Stops the configured worker pool.
 *
 * This command is package-local and contracts-only:
 *
 * - it implements the core/contracts CLI command port;
 * - it consumes parsed InputInterface accessors;
 * - it writes only through OutputInterface;
 * - it does not depend on platform/cli;
 * - it does not require full binary/catalog dispatch.
 *
 * Stop behavior is delegated to WorkerManager. This command must not write stop
 * flags, open control sockets, read/write worker state files, or expose raw
 * runtime paths/endpoints.
 *
 * Lifecycle logging and observability summary emission remain delegated to
 * WorkerManager and its injected logger/observability ports.
 *
 * This class must not:
 *
 * - fork;
 * - call proc_open();
 * - open sockets;
 * - write pid/state/socket/stop files;
 * - write ContextStore values;
 * - write stdout/stderr directly;
 * - expose raw config values, raw endpoints, absolute paths, env values,
 *   payloads, headers, tokens, or throwable messages.
 */
final readonly class WorkerStopCommand implements CommandInterface
{
    public const string NAME = 'worker:stop';
    public const string SUMMARY = 'Stop the configured worker pool.';
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
    private const string ERROR_CODE_WORKER_STOP_FAILED = 'CORETSIA_WORKER_STOP_FAILED';

    public function __construct(
        private ConfigRepositoryInterface $config,
        private WorkerServiceFactory $factory,
        private WorkerManager $manager,
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
            $spec = $this->factory->workerPoolSpec($this->config);
            $state = $this->manager->stop($spec);

            $output->json(self::stopSummary($state));

            return self::EXIT_SUCCESS;
        } catch (WorkerNotRunningException $exception) {
            $output->error(
                $exception->errorCode(),
                $exception->reason(),
            );

            return self::EXIT_FAILURE;
        } catch (WorkerCommunicationFailedException $exception) {
            $output->error(
                $exception->errorCode(),
                $exception->reason(),
            );

            return self::EXIT_FAILURE;
        } catch (WorkerStartFailedException) {
            $output->error(
                self::ERROR_CODE_WORKER_STOP_FAILED,
                'worker-stop-failed',
            );

            return self::EXIT_FAILURE;
        } catch (\Throwable) {
            $output->error(
                self::ERROR_CODE_WORKER_STOP_FAILED,
                'worker-stop-failed',
            );

            return self::EXIT_FAILURE;
        }
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
                'worker-stop-arguments-not-supported',
            );

            return false;
        }

        if ($input->options() !== []) {
            $output->error(
                self::ERROR_CODE_WORKER_COMMAND_INVALID,
                'worker-stop-options-not-supported',
            );

            return false;
        }

        return true;
    }

    /**
     * @return array{
     *     status: 'stopped',
     *     pid: int,
     *     worker_count: int,
     *     driver: string,
     *     control_transport: string,
     *     endpoint_hash: string
     * }
     */
    private static function stopSummary(WorkerPoolState $state): array
    {
        return [
            'status' => 'stopped',
            'pid' => $state->pid(),
            'worker_count' => $state->workerCount(),
            'driver' => $state->driver(),
            'control_transport' => $state->controlTransport(),
            'endpoint_hash' => $state->endpointHash(),
        ];
    }
}
