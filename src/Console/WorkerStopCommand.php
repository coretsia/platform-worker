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
use Coretsia\Platform\Worker\Exception\WorkerException;
use Coretsia\Platform\Worker\Internal\WorkerControlClientInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolState;

/**
 * Stops the active worker pool.
 *
 * This command is package-local and contracts-only:
 *
 * - it implements the core/contracts CLI command port;
 * - it consumes parsed InputInterface accessors;
 * - it writes only through OutputInterface;
 * - it does not depend on platform/cli;
 * - it does not require full binary/catalog dispatch.
 *
 * Stop behavior is delegated to WorkerControlClientInterface. The client
 * verifies liveness through the canonical lock, resolves the private locator,
 * sends a stop request, and reports success only after the terminal `stopped`
 * response is received.
 *
 * Lifecycle commands do not resolve WorkerPoolSpec and do not use current
 * worker configuration to address an active supervisor.
 *
 * The command must not write stop flags, read diagnostic state snapshots, own
 * control sockets, or expose raw runtime paths and endpoints.
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
    public const string SUMMARY = 'Stop the active worker pool.';
    public const string GROUP = 'worker';
    public const bool HIDDEN = false;
    public const string MODE = 'none';
    public const array ARGUMENTS = [];
    public const array OPTIONS = [];

    public function __construct(
        private WorkerControlClientInterface $client,
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
            $state = $this->client->stop();
            $output->json(self::summary($state));
            return 0;
        } catch (WorkerException $exception) {
            $output->error($exception->errorCode(), $exception->reason());
        } catch (\Throwable) {
            $output->error('CORETSIA_WORKER_STOP_FAILED', 'worker-stop-failed');
        }
        return 1;
    }

    private function validInput(
        InputInterface $input,
        OutputInterface $output,
    ): bool {
        if ($input->commandName() !== self::NAME) {
            $output->error('CORETSIA_WORKER_COMMAND_INVALID', 'worker-command-name-invalid');
            return false;
        }
        if ($input->arguments() !== []) {
            $output->error('CORETSIA_WORKER_COMMAND_INVALID', 'worker-stop-arguments-not-supported');
            return false;
        }
        if ($input->options() !== []) {
            $output->error('CORETSIA_WORKER_COMMAND_INVALID', 'worker-stop-options-not-supported');
            return false;
        }
        return true;
    }

    /** @return array<string, int|string> */
    private static function summary(WorkerPoolState $state): array
    {
        return [
            'status' => 'stopped',
            'pid' => $state->pid(),
            'worker_count' => $state->workerCount(),
            'ready_worker_count' => 0,
            'driver' => $state->driver(),
            'control_transport' => $state->controlTransport(),
            'endpoint_hash' => $state->endpointHash(),
        ];
    }
}
