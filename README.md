<!--
  Coretsia Framework (Monorepo)

  Project: Coretsia Framework (Monorepo)
  Authors: Vladyslav Mudrichenko and contributors
  Copyright (c) 2026 Vladyslav Mudrichenko

  SPDX-FileCopyrightText: 2026 Vladyslav Mudrichenko
  SPDX-License-Identifier: Apache-2.0

  For contributors list, see git history.
  See LICENSE and NOTICE in the project root for full license information.
-->

# coretsia/platform-worker

**Experimental long-running worker runtime substrate.**

**No real queue/HTTP task sources yet.**

**Used by future Coretsia queue/HTTP/runtime integrations.**

`platform/worker` is the **long-running Worker runtime** package for the Coretsia Framework monorepo.

**Scope:** worker module metadata, worker service provider/factory wiring, worker pool specification, process-driver lifecycle orchestration, application worker task loops, deterministic worker state storage, payload-free control transport, package-contributed worker command classes, safe worker exceptions, and worker runtime observability summaries.

**Out of scope:** CLI binary dispatch, CLI command catalog construction, HTTP platform adapters, real HTTP request production, real queue adapter behavior, external queue acknowledgement/retry/dead-letter semantics, scheduler integrations, RoadRunner/Swoole/FrankenPHP adapters, public task-source plugin APIs, public worker-driver plugin APIs, Kernel UnitOfWork lifecycle ownership, Kernel hook discovery, reset discovery, reset execution semantics, observability exporters, and tooling-only behavior.

This README is a consumer-oriented package summary.

## Package identity

- **Path:** `framework/packages/platform/worker`
- **Package id:** `platform/worker`
- **Composer name:** `coretsia/platform-worker`
- **Module id:** `platform.worker`
- **Namespace:** `Coretsia\Platform\Worker\*` (PSR-4: `src/`)
- **Kind:** runtime
- **Config root:** `worker`
- **Child launcher:** `bin/coretsia-worker`

The child launcher is process-driver infrastructure. It is not the user-facing `coretsia worker:*` command dispatcher.

Monorepo versioning is **repo-wide only** via git tags `vMAJOR.MINOR.PATCH`.

Per-package independent versions **MUST NOT** be used.

## Dependency policy

This package is runtime-safe and process-oriented.

- **Depends on:**
  - `core/contracts`
  - `core/foundation`
  - `core/kernel`
  - PSR interfaces used only as ports
- **Forbidden:**
  - `platform/cli`
  - `platform/http`
  - `integrations/*`
  - `devtools/*`

`platform/worker` contributes worker command classes, but CLI discovery, command catalog construction, binary dispatch, terminal UX, and output rendering remain owned by `platform/cli`.

`platform/worker` may preflight HTTP task mode through `Psr\Http\Server\RequestHandlerInterface`, but it MUST NOT depend on `platform/http` or import `Coretsia\Platform\Http\*`.

## Runtime responsibilities

This package provides the worker runtime layer:

- worker module metadata through `Coretsia\Platform\Worker\Module\WorkerModule`;
- worker service provider registration through `Coretsia\Platform\Worker\Provider\WorkerServiceProvider`;
- stateless worker factory/wiring helper through `Coretsia\Platform\Worker\Provider\WorkerServiceFactory`;
- worker command classes:
  - `Coretsia\Platform\Worker\Console\WorkerStartCommand`
  - `Coretsia\Platform\Worker\Console\WorkerStopCommand`
  - `Coretsia\Platform\Worker\Console\WorkerStatusCommand`
- worker pool lifecycle orchestration through `Coretsia\Platform\Worker\Manager\WorkerManager`;
- package-internal process-driver seam through `WorkerManagerDriverInterface`;
- `pcntl` process driver through `PcntlWorkerManagerDriver`;
- `proc` process driver through `ProcWorkerManagerDriver`;
- normalized worker pool config through `WorkerPoolSpec`;
- immutable safe worker state through `WorkerPoolState`;
- deterministic worker state file I/O through `WorkerStateStore`;
- worker control channel behavior through `WorkerSocketServer`;
- sequential child-process task loop through `ApplicationWorker`;
- package-internal task factory seam through `TaskFactoryInternalInterface`;
- placeholder queue task factory through `QueueTaskFactory`;
- HTTP task-mode preflight factory through `HttpTaskFactory`;
- deterministic worker exceptions under `Coretsia\Platform\Worker\Exception`.

## Process model

The worker runtime uses a master-plus-workers model:

```text
worker:start command
  -> RuntimeDriverGuard
  -> WorkerServiceFactory
  -> WorkerPoolSpec
  -> WorkerManager
  -> selected process driver
  -> master process state
  -> N worker children
  -> ApplicationWorker task loops
```

The master process owns pool lifecycle orchestration through `WorkerManager`.

The selected process driver owns concrete process behavior.

Each child process runs an `ApplicationWorker`.

Each `ApplicationWorker` processes tasks sequentially until:

- `worker.max_requests` is reached;
- a graceful stop flag is observed between tasks;
- the process exits due to a deterministic worker failure.

The worker control channel is lifecycle/control-only.

It MUST NOT transport task payloads.

## Driver and transport selection

Worker process driver selection is represented by `WorkerPoolSpec`.

Requested driver values:

```text
auto
pcntl
proc
```

Resolved driver values:

```text
pcntl
proc
```

When `worker.driver=auto`, resolution is deterministic:

```text
pcntl when pcntl_fork is available and the platform is not Windows
proc otherwise
```

The `pcntl` driver is Unix-like and fork-based.

The `proc` driver is the cross-platform fallback and starts child workers through `proc_open()`.

Worker control transport selection is also represented by `WorkerPoolSpec`.

Requested control transport values:

```text
auto
unix
tcp
```

Resolved control transport values:

```text
unix
tcp
```

When `worker.control.transport=auto`, resolution is deterministic:

```text
unix when the resolved driver is pcntl and unix domain sockets are supported
tcp otherwise
```

Raw socket paths and raw TCP endpoints are not public diagnostics.

Endpoint identity may be exposed publicly only through `endpoint_hash`.

## Configuration

The worker config root is:

```text
worker
```

The defaults file is:

```text
config/worker.php
```

It returns the `worker` subtree only.

It MUST NOT wrap the subtree in a repeated root key such as:

```php
['worker' => [...]]
```

Baseline defaults include:

```php
[
    'enabled' => false,
    'workers' => 4,
    'max_requests' => 1000,
    'task_type' => 'queue',
    'socket_path' => 'var/tmp/worker.sock',
    'driver' => 'auto',
    'proc' => [
        'command' => [
            '@php',
            'vendor/coretsia/platform-worker/bin/coretsia-worker',
        ],
    ],
    'control' => [
        'transport' => 'auto',
    ],
    'tcp' => [
        'host' => '127.0.0.1',
        'port' => 9327,
    ],
    'state_path' => 'var/tmp/worker.state.json',
    'stop_flag_path' => 'var/tmp/worker.stop',
    'stop_timeout_ms' => 3000,
]
```

Important config rules:

- `worker.enabled=false` by default.
- `worker.workers` must be a positive integer.
- `worker.max_requests` must be a positive integer.
- `worker.task_type` is `queue` or `http`.
- `worker.driver` is `auto`, `pcntl`, or `proc`.
- `worker.control.transport` is `auto`, `unix`, or `tcp`.
- `worker.tcp.port` must be an explicit TCP port from `1` to `65535`.
- TCP port `0` is forbidden.
- runtime paths must be skeleton-root-relative.
- runtime paths must not be absolute.
- runtime paths must not contain `..`, `skeleton/`, backslashes, whitespace, control characters, `://`, or segments beginning with `@`.

## Worker commands

This package provides command classes for:

```text
worker:start
worker:stop
worker:status
```

The command classes implement the contracts-level CLI command port:

```text
Coretsia\Contracts\Cli\Command\CommandInterface
```

They consume parsed input through:

```text
Coretsia\Contracts\Cli\Input\InputInterface
```

They write only through:

```text
Coretsia\Contracts\Cli\Output\OutputInterface
```

They MUST NOT write stdout or stderr directly.

They MUST NOT depend on `platform/cli`.

Full `coretsia worker:*` binary dispatch through container-backed CLI tag discovery is owned by a later `platform/cli` epic.

### `worker:start`

Starts the configured worker pool.

Start order is intentionally strict:

```text
WorkerStartCommand
  -> RuntimeDriverGuard
  -> WorkerServiceFactory::workerPoolSpec(...)
  -> WorkerManager::start(...)
```

Runtime-driver guard failures are surfaced with the original Kernel guard error code and reason token.

They are not translated into worker-specific conflict errors.

### `worker:stop`

Stops the configured worker pool through `WorkerManager`.

The command does not write stop flags directly, open control sockets directly, or read/write worker state files directly.

### `worker:status`

Reads worker pool status through `WorkerManager`.

The command does not read state files directly and does not inspect raw runtime paths or endpoints.

### Successful command summaries

Successful command summaries may expose only safe fields:

```text
status
pid
worker_count
driver
control_transport
endpoint_hash
```

Raw socket paths, raw TCP endpoints, config values, payloads, headers, tokens, absolute paths, and throwable messages MUST NOT be exposed.

## Runtime-driver guard boundary

Runtime-driver compatibility is Kernel-owned policy.

The canonical guard is:

```text
Coretsia\Kernel\Runtime\Driver\RuntimeDriverGuard
```

`WorkerStartCommand` must invoke the guard before starting the worker pool.

When `worker.task_type=http`, `WorkerStartCommand` must also invoke:

```text
RuntimeDriverGuard::assertHttpDriverCompatibleWithModules(...)
```

with the caller-provided `ModulePlan`.

Missing `platform.http` for HTTP worker mode must fail through `RuntimeDriverGuard` before request-handler resolution.

The worker package MUST NOT duplicate runtime-driver matrix logic.

The worker package MUST NOT reclassify runtime-driver guard failures as worker exceptions.

## UnitOfWork and reset boundary

`ApplicationWorker` executes each task through:

```text
Coretsia\Contracts\Runtime\KernelRuntimeInterface::runUnitOfWork(...)
```

Reset discipline between worker tasks is achieved only transitively through KernelRuntime.

The canonical lifecycle is:

```text
begin
  -> before hooks
  -> task
  -> after hooks
  -> ResetOrchestrator::resetAll()
```

`platform/worker` MUST NOT:

- call before/after UnitOfWork hooks directly;
- enumerate hook tags;
- enumerate reset tags;
- call `ResetOrchestrator::resetAll()` directly;
- create UnitOfWork ids directly;
- create correlation ids directly;
- write context values directly.

Kernel owns UnitOfWork lifecycle semantics.

Foundation owns reset orchestration infrastructure.

The worker package owns only the long-running loop and task submission into the Kernel runtime boundary.

## Task modes

Supported task types are:

```text
queue
http
```

### Queue task mode

`QueueTaskFactory` handles:

```text
worker.task_type=queue
```

The current queue task factory is package-local placeholder task work.

It does not implement a production queue adapter.

Real queue sources, transports, acknowledgement semantics, retry semantics, dead-letter behavior, and integration-specific adapters are owned by later integration epics.

### HTTP task mode

`HttpTaskFactory` handles:

```text
worker.task_type=http
```

It does not implement a production HTTP request source.

It does not create PSR-7 requests.

It does not depend on `platform/http`.

HTTP task mode first requires RuntimeDriverGuard/module compatibility to pass.

Only after that may it require a resolvable:

```text
Psr\Http\Server\RequestHandlerInterface
```

Request-handler preflight failures use deterministic worker start reasons:

```text
request_handler_missing
request_handler_unresolvable
request_handler_invalid
```

## State files

`WorkerStateStore` owns worker state file I/O.

The default worker state path is:

```text
var/tmp/worker.state.json
```

There is no separate `worker.pid_path` config key.

The master pid is stored inside the worker state schema.

The persisted state contains only:

```text
version
pid
worker_count
driver_requested
driver
control_transport_requested
control_transport
endpoint_hash
```

The state file MUST NOT contain:

- timestamps;
- environment values;
- raw socket paths;
- raw TCP hosts or ports;
- absolute paths;
- task payloads;
- HTTP headers;
- cookies;
- Authorization values;
- tokens;
- raw endpoint identifiers.

Missing state marker means the worker pool is not currently running.

Existing but invalid state means invalid state, not not-running.

## Control channel

`WorkerSocketServer` owns the worker control channel.

Supported control transports are:

```text
unix
tcp
```

Control operations are payload-free.

Allowed stable control operation tokens include:

```text
start
stop
status
health
```

The control channel MUST NOT transport task payloads.

Control communication failures map to deterministic worker communication failures.

Public diagnostics MUST NOT expose raw socket paths, socket basenames, raw TCP hosts, raw TCP ports, raw endpoint strings, payloads, headers, tokens, or throwable messages.

## Observability

Worker observability follows the canonical observability SSoT.

Worker span names:

```text
worker.process
worker.task
```

Worker metric names:

```text
worker.process_total
worker.task_total
worker.task_duration_ms
```

Allowed worker process metric label:

```text
status
```

Allowed worker task metric labels:

```text
operation
outcome
```

Forbidden metric labels include:

```text
worker_id
pid
path
socket
endpoint
payload
exception_class
error_reason
```

Worker logs and spans are summary-only.

Logger, tracer, meter, stopwatch, and context dependencies are injected.

Worker runtime classes MUST NOT instantiate observability adapters directly.

Observability failures MUST NOT change worker lifecycle semantics, task semantics, reset semantics, or selected public failure.

ApplicationWorker stopwatch start/stop failures MUST NOT change worker task execution, KernelRuntime delegation, task outcome selection, or worker task failure precedence. When worker task timing is unavailable, task duration metadata MUST collapse to `0`.

## Errors

Worker package failures use deterministic worker exceptions under:

```text
Coretsia\Platform\Worker\Exception
```

The base exception is:

```text
WorkerException
```

Concrete worker exceptions include:

```text
WorkerStartFailedException
WorkerForkFailedException
WorkerCommunicationFailedException
WorkerNotRunningException
```

Public worker exception messages have the canonical form:

```text
CORETSIA_WORKER_*: reason_token
```

Examples:

```text
CORETSIA_WORKER_START_FAILED: start_failed
CORETSIA_WORKER_START_FAILED: invalid_state
CORETSIA_WORKER_START_FAILED: request_handler_missing
CORETSIA_WORKER_START_FAILED: request_handler_unresolvable
CORETSIA_WORKER_START_FAILED: request_handler_invalid
CORETSIA_WORKER_FORK_FAILED: fork_failed
CORETSIA_WORKER_COMMUNICATION_FAILED: communication_failed
CORETSIA_WORKER_NOT_RUNNING: not_running
```

Worker exception messages MUST NOT include previous throwable messages, stack traces, absolute paths, raw socket paths, raw TCP endpoints, raw config values, payload fragments, headers, tokens, process command lines, or environment data.

Runtime-driver matrix failures remain Kernel runtime-driver guard failures.

They must not be reclassified as worker exceptions.

## Security / Redaction

The worker package treats the following values as unsafe for public diagnostics:

- raw socket paths;
- raw TCP hosts;
- raw TCP ports;
- raw endpoint identifiers;
- absolute paths;
- task payloads;
- HTTP request paths;
- HTTP headers;
- cookies;
- Authorization values;
- bearer tokens;
- secrets;
- environment values;
- config dumps;
- raw command lines;
- raw JSON payloads;
- stack traces;
- previous throwable messages.

Safe public summaries may include only:

```text
status
pid
worker_count
driver
control_transport
endpoint_hash
operation
outcome
duration_ms
```

Endpoint identity may be represented publicly only as a deterministic hash.

## Internal seams

The following interfaces are package-internal:

```text
Coretsia\Platform\Worker\Internal\WorkerManagerDriverInterface
Coretsia\Platform\Worker\Internal\TaskFactoryInternalInterface
```

They are not public package APIs.

They are not extension points for application code.

They MUST NOT be moved to `core/contracts`.

They MUST NOT be exported through Composer `extra` metadata as public API.

## Non-goals

This package does not provide:

- production queue backend behavior;
- queue acknowledgement semantics;
- queue retry semantics;
- queue dead-letter behavior;
- scheduler behavior;
- production HTTP request production;
- PSR-7 request construction;
- routing;
- middleware;
- CLI binary dispatch;
- command catalog construction;
- external process supervision;
- RoadRunner integration;
- Swoole integration;
- FrankenPHP integration;
- public worker plugin APIs;
- public task-source plugin APIs;
- container artifact schema;
- config merge implementation;
- config validation implementation;
- production observability exporter configuration.

## References

- [Worker Architecture](https://github.com/coretsia/monorepo/tree/main/docs/architecture/worker.md)
- [Runtime Driver Guard Architecture](https://github.com/coretsia/monorepo/tree/main/docs/architecture/runtime-driver-guard.md)
- [ADR-0017: Worker manager and application worker](https://github.com/coretsia/monorepo/tree/main/docs/adr/ADR-0017-worker-manager-application-worker.md)
- [Config Roots Registry](https://github.com/coretsia/monorepo/tree/main/docs/ssot/config-roots.md)
- [Observability SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/observability.md)
- [Runtime Drivers SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/runtime-drivers.md)
- [UnitOfWork and Reset Contracts SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/uow-and-reset-contracts.md)
- [Worker package source](https://github.com/coretsia/monorepo/tree/main/framework/packages/platform/worker)
