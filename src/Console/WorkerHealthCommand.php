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
use Coretsia\Platform\Worker\Runtime\WorkerHealthState;

/**
 * Reports live worker-pool health through the supervisor control channel.
 *
 * The command does not inspect state files or runtime paths directly. It exits
 * successfully only when the active pool is running and every configured slot
 * is ready.
 *
 * Lifecycle commands do not resolve WorkerPoolSpec and do not use current
 * worker configuration to address an active supervisor.
 */
final readonly class WorkerHealthCommand implements CommandInterface
{
    public const string NAME = 'worker:health';
    public const string SUMMARY = 'Show the active worker pool health.';
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
            $health = $this->client->health();
            $output->json(self::summary($health));
            return $health->healthy() ? 0 : 1;
        } catch (WorkerException $exception) {
            $output->error($exception->errorCode(), $exception->reason());
        } catch (\Throwable) {
            $output->error('CORETSIA_WORKER_HEALTH_FAILED', 'worker-health-failed');
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
            $output->error('CORETSIA_WORKER_COMMAND_INVALID', 'worker-health-arguments-not-supported');
            return false;
        }
        if ($input->options() !== []) {
            $output->error('CORETSIA_WORKER_COMMAND_INVALID', 'worker-health-options-not-supported');
            return false;
        }
        return true;
    }

    /** @return array<string, int|string|bool> */
    private static function summary(WorkerHealthState $health): array
    {
        return [
            'status' => $health->healthy() ? 'healthy' : 'unhealthy',
            'pool_status' => $health->status()->value,
            'pid' => $health->pid(),
            'worker_count' => $health->workerCount(),
            'ready_worker_count' => $health->readyWorkerCount(),
            'healthy' => $health->healthy(),
            'reason' => $health->reason(),
            'driver' => $health->driver(),
            'control_transport' => $health->controlTransport(),
            'endpoint_hash' => $health->endpointHash(),
        ];
    }
}
