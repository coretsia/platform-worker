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

namespace Coretsia\Platform\Worker\Task;

use Coretsia\Contracts\Config\ConfigRepositoryInterface;
use Coretsia\Kernel\Module\ModulePlan;
use Coretsia\Kernel\Runtime\Entrypoint\RuntimeEntrypointGuard;
use Coretsia\Platform\Worker\Exception\WorkerStartFailedException;
use Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface;
use Coretsia\Platform\Worker\Runtime\WorkerPoolSpec;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Package-local HTTP task preflight factory.
 *
 * This factory handles only `worker.task_type=http`.
 *
 * It intentionally does not implement a real HTTP request source in this epic.
 * It does not create PSR-7 requests, does not read transport payloads, does not
 * own HTTP adapter behavior, and does not import platform/http classes.
 *
 * Real HTTP task payload production remains owned by a later platform/http or
 * runtime-adapter epic.
 *
 * HTTP task mode has two preflight requirements:
 *
 * - RuntimeEntrypointGuard compatibility must pass first;
 * - RequestHandlerInterface must then be resolvable from the container.
 *
 * Request-handler-missing failures must happen only after the canonical runtime
 * entrypoint guard has accepted the caller-provided ModulePlan. This class must
 * not bypass RuntimeEntrypointGuard and must not duplicate runtime-driver policy.
 *
 * The operation id is the stable low-cardinality token `http`. It must remain
 * safe for observability metric label `operation` and must not include raw HTTP
 * payloads, request paths, headers, cookies, Authorization values, tokens, body
 * fragments, socket paths, TCP endpoints, config values, or adapter internals.
 *
 * This class must not depend on platform/http and must not write to
 * stdout/stderr directly.
 *
 * @phpstan-import-type WorkerTaskWork from TaskFactoryInternalInterface
 */
final readonly class HttpTaskFactory implements TaskFactoryInternalInterface
{
    public function __construct(
        private ConfigRepositoryInterface $config,
        private ModulePlan $modulePlan,
        private RuntimeEntrypointGuard $runtimeEntrypointGuard,
        private ContainerInterface $container,
    ) {
    }

    public function taskType(): string
    {
        return self::TASK_TYPE_HTTP;
    }

    public function supports(WorkerPoolSpec $spec): bool
    {
        return $spec->taskType() === self::TASK_TYPE_HTTP;
    }

    public function operationId(WorkerPoolSpec $spec): string
    {
        if (!$this->supports($spec)) {
            throw WorkerStartFailedException::startFailed();
        }

        return self::TASK_TYPE_HTTP;
    }

    /**
     * @return WorkerTaskWork
     */
    public function create(WorkerPoolSpec $spec): array
    {
        $operationId = $this->operationId($spec);

        $this->assertRuntimeEntrypointCompatibilityHasPassed();
        $this->assertRequestHandlerResolvable();

        return [
            'operation_id' => $operationId,
            'run' => static function (): null {
                return null;
            },
        ];
    }

    private function assertRuntimeEntrypointCompatibilityHasPassed(): void
    {
        $this->runtimeEntrypointGuard->assertEntrypointAllowed(
            config: $this->config,
            modulePlan: $this->modulePlan,
        );
    }

    private function assertRequestHandlerResolvable(): void
    {
        try {
            $hasHandler = $this->container->has(RequestHandlerInterface::class);
        } catch (\Throwable) {
            throw WorkerStartFailedException::requestHandlerUnresolvable();
        }

        if (!$hasHandler) {
            throw WorkerStartFailedException::requestHandlerMissing();
        }

        try {
            $handler = $this->container->get(RequestHandlerInterface::class);
        } catch (NotFoundExceptionInterface) {
            throw WorkerStartFailedException::requestHandlerMissing();
        } catch (ContainerExceptionInterface) {
            throw WorkerStartFailedException::requestHandlerUnresolvable();
        } catch (\Throwable) {
            throw WorkerStartFailedException::requestHandlerUnresolvable();
        }

        if (!$handler instanceof RequestHandlerInterface) {
            throw WorkerStartFailedException::requestHandlerInvalid();
        }
    }
}
