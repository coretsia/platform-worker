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

`platform/worker` is the long-running Worker runtime package for the Coretsia Framework.

It provides one foreground persistent worker supervisor, cross-platform child-process adapters, live lifecycle control, deterministic readiness and shutdown, and transport-neutral orchestration of real task sources contributed by runtime/integration packages.

Scope: worker module metadata, canonical declarative worker container definitions, worker service provider/factory wiring, worker pool specification, foreground supervisor orchestration, mandatory worker-generation guardian and fencing, child readiness, lazy selected-driver resolution, guardian-backed single-child process drivers, PCNTL fork-exec isolation, nested proc process-host infrastructure, supervisor-death containment, atomic-generation process-child boot, max-request recycle, deterministic worker state storage, payload-free live control, package-contributed worker commands, safe worker exceptions, and bounded worker observability summaries.

Out of scope: CLI binary dispatch, CLI command catalog construction, service-manager configuration and restart policy, concrete HTTP platform adapters, real HTTP request production, concrete queue adapter behavior, queue acknowledgement/retry/dead-letter policy, scheduler integrations, RoadRunner/Swoole/FrankenPHP adapters, public process-driver plugin APIs, Kernel UnitOfWork lifecycle ownership, Kernel hook discovery, reset discovery, reset execution semantics, observability exporters, and tooling-only behavior. The public transport-neutral task-source SPI itself is contracts-owned and consumed by this package.

This README is a consumer-oriented package summary.

## Worker-generation guardian and supervisor-death containment

Every worker generation has one mandatory package-internal `WorkerProcessGuardian`. There is no `worker.guardian.enabled` configuration. The Guardian owns the canonical `var/tmp/worker.lock` generation fence, PCNTL worker-process lifetime, nested ProcHost lifetime for the proc backend, and generation cleanup. The ProcHost owns raw `proc_open()` worker resources for the proc backend under Guardian ownership. The foreground Supervisor owns state, locator, control endpoint, readiness aggregation, recycle policy, and normal shutdown orchestration.

```text
PCNTL:
service manager -> WorkerSupervisor -> WorkerProcessGuardian [worker.lock] -> workers

PROC:
service manager -> WorkerSupervisor -> WorkerProcessGuardian [worker.lock] -> WorkerProcProcessHost -> workers
```

Abrupt loss of only the foreground supervisor closes the authenticated guardian ownership channel. The guardian keeps `worker.lock` held, terminates workers, escalates to hard kill when required, reaps/closes the complete old generation, shuts down the nested proc host when applicable, and releases the fence last. A replacement start during cleanup fails with `CORETSIA_WORKER_ALREADY_RUNNING`; restart cadence remains external.

The guardian does not own or clean supervisor state, locator, socket, or stop-flag artifacts. These may remain stale after abrupt supervisor death; after the guardian releases the fence, a free lock is authoritative for `NOT_RUNNING` and the next successful start replaces stale artifacts.

The current Worker process topology and ownership boundaries are documented in `docs/architecture/worker.md` and ADR-0017.

## Trusted initial process bootstrap

Guardian and ProcHost use the same package-internal authenticated-child bootstrap:

```text
Supervisor
-> WorkerProcessBootstrapLauncher
-> proc_open exact Guardian
-> private Guardian stdin capability
-> retained Supervisor listener created after child launch
-> authenticated Guardian
-> CLAIM
-> WorkerLifecycleLock

Guardian
-> WorkerProcessBootstrapLauncher
-> proc_open exact ProcHost
-> private ProcHost stdin capability
-> retained Guardian listener created after child launch
-> authenticated ProcHost
```

The parent creates and retains the bootstrap listener only after the exact child has been launched. A fresh 256-bit one-shot credential is delivered only through the private child stdin; it is not placed in argv or environment and is never sent by the parent to an unauthenticated network peer. On Windows, secure retained-listener ownership requires the sockets extension and `SO_EXCLUSIVEADDRUSE`; Worker process bootstrap fails closed when that capability is unavailable. Invalid, oversized, expired, silent, wrong-role, and wrong-credential candidates are contained without transferring bootstrap authority.

Bootstrap authentication is not worker-generation ownership. The Guardian owns generation authority only after `WorkerLifecycleLock::acquire()` succeeds for a valid `CLAIM`; the Supervisor treats that commit as observed only after receiving and validating `CLAIM ACK`. Missing ACK therefore does not authorize local rollback by force-killing a potentially generation-owning Guardian.

For `proc`, the Guardian closes its bootstrap stdin and completes ProcHost bootstrap before establishing the authenticated Supervisor connection. The ProcHost closes its bootstrap stdin before any worker `proc_open()`. For `pcntl`, Guardian bootstrap stdin closes before any worker fork. Nested bootstrap phases receive only the remaining startup deadline.

`pcntl` identifies the worker-child backend. Guardian launch itself uses the common portable process-bootstrap launcher.

Worker process bootstrap uses the canonical static Stable JSON primitives owned by Foundation; Stable JSON is not Worker-owned runtime DI state. Worker owns only its process-bootstrap schema and process semantics.

The normative process-bootstrap authority and failure-containment contract is `docs/ssot/worker-process-bootstrap.md`.

## Package identity

- Path: `framework/packages/platform/worker`
- Package id: `platform/worker`
- Composer name: `coretsia/platform-worker`
- Module id: `platform.worker`
- Namespace: `Coretsia\Platform\Worker\*` (PSR-4: `src/`)
- Kind: runtime
- Config root: `worker`
- Child launcher: `bin/coretsia-worker`
- Process guardian: `bin/coretsia-worker-guardian`
- Proc process host: `bin/coretsia-worker-proc-host`

The child launcher, process guardian, and proc process host are internal process infrastructure.

`bin/coretsia-worker-guardian` and `bin/coretsia-worker-proc-host` are thin OS executable shells. They own only pre-autoload process validation, Composer autoload, loading the package-owned process composition module, invoking that module, and propagating its terminal exit status.

Post-autoload Guardian and ProcHost composition is owned by:

```text
src/Process/Entrypoint/worker-guardian.php
src/Process/Entrypoint/worker-proc-host.php
```

Those composition modules use package-internal Worker implementation from the package `src/` source root. The executable shells MUST NOT widen that implementation into public API and MUST NOT directly consume `@internal` PSR-4 implementation classes across the executable/source-root boundary.

Named implementation classes used by an entrypoint module remain normal PSR-4 classes in matching files and remain `@internal`.

This source-layout boundary does not change process ownership, bootstrap authentication, generation fencing, ProcHost handoff, or worker-child lifecycle semantics.

They are not the user-facing `coretsia worker:*` command dispatcher.

`bin/coretsia-worker` performs artifact-only PCNTL and proc child boot.

The ProcHost process launched through `bin/coretsia-worker-proc-host` owns raw `proc_open()` worker resources through the package-internal `WorkerProcProcessHostEntrypointRuntime`, on behalf of the worker-generation guardian.

Monorepo versioning is repo-wide only via git tags `vMAJOR.MINOR.PATCH`.

Per-package independent versions MUST NOT be used.

## Dependency policy

This package is runtime-safe and process-oriented.

- Depends on:
  - `core/contracts`
  - `core/foundation`
  - `core/kernel`
  - PSR interfaces used only as ports
- Forbidden:
  - `platform/cli`
  - `platform/http`
  - `integrations/*`
  - `devtools/*`

`platform/worker` contributes worker command classes, but CLI discovery, command catalog construction, binary dispatch, terminal UX, and output rendering remain owned by `platform/cli`.

`platform/worker` does not own HTTP request-handler or PSR-7 integration. HTTP task-source adapter packages own their HTTP contracts/runtime dependencies. `platform/worker` MUST NOT depend on `platform/http` or import `Coretsia\Platform\Http\*`.

## Runtime responsibilities

This package provides the Worker runtime layer:

- worker module metadata through `Coretsia\Platform\Worker\Module\WorkerModule`;
- worker service provider registration through `Coretsia\Platform\Worker\Provider\WorkerServiceProvider`;
- stateless worker factory/wiring helpers through `Coretsia\Platform\Worker\Provider\WorkerServiceFactory`;
- worker command classes:
  - `Coretsia\Platform\Worker\Console\WorkerStartCommand`;
  - `Coretsia\Platform\Worker\Console\WorkerStopCommand`;
  - `Coretsia\Platform\Worker\Console\WorkerStatusCommand`;
  - `Coretsia\Platform\Worker\Console\WorkerHealthCommand`;
- foreground pool lifecycle orchestration through `Coretsia\Platform\Worker\Supervisor\WorkerSupervisor`;
- lazy supervisor resolution through:
  - `Coretsia\Platform\Worker\Internal\WorkerSupervisorResolverInterface`;
  - `Coretsia\Platform\Worker\Supervisor\ContainerWorkerSupervisorResolver`;
- package-internal supervisor boundary through `Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface`;
- package-internal single-child process-driver boundary through `Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface`;
- lazy selected-driver boundary through:
  - `Coretsia\Platform\Worker\Internal\WorkerProcessDriverResolverInterface`;
  - `Coretsia\Platform\Worker\Process\ContainerWorkerProcessDriverResolver`;
- canonical shell-free child argv construction through `Coretsia\Platform\Worker\Process\WorkerChildCommandBuilder`;
- Unix-like child execution through `Coretsia\Platform\Worker\Process\Driver\PcntlWorkerProcessDriver`;
- cross-platform proc child execution through `Coretsia\Platform\Worker\Process\Driver\ProcWorkerProcessDriver`;
- trusted package-internal Guardian and ProcHost bootstrap through:
  - `Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapProtocol`;
  - `Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapEndpoint`;
  - `Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapClient`;
  - `Coretsia\Platform\Worker\Process\Bootstrap\WorkerProcessBootstrapLauncher`;
- guardian-owned proc process-host lifecycle through:
  - `Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianClient`;
  - `Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianProtocol`;
  - `Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostClient`;
  - `Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostProtocol`;
  - `Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostHandoffEndpoint`;
  - `Coretsia\Platform\Worker\Process\Proc\WorkerProcProcessHostTransport`;
  - `Coretsia\Platform\Worker\Process\Entrypoint\WorkerProcProcessHostEntrypointRuntime`;
  - `bin/coretsia-worker-proc-host`;
- PCNTL fork-exec resource isolation inside `Coretsia\Platform\Worker\Process\Guardian\WorkerProcessGuardianRuntime`;
- canonical package-owned lifecycle paths through `Coretsia\Platform\Worker\Runtime\WorkerLifecyclePaths`;
- worker-generation fencing through guardian-owned `Coretsia\Platform\Worker\Runtime\WorkerLifecycleLock`;
- immutable active-supervisor discovery data through `Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocator`;
- atomic private locator storage through `Coretsia\Platform\Worker\Runtime\WorkerLifecycleLocatorStore`;
- canonical shutdown request and cleanup budgets through `Coretsia\Platform\Worker\Runtime\WorkerShutdownBudget`;
- cooperative task-acquisition shutdown signaling through `Coretsia\Platform\Worker\Runtime\WorkerStopSignal`;
- typed child ownership through `Coretsia\Platform\Worker\Supervisor\WorkerChildTable`;
- synchronous shutdown-intent handling through `Coretsia\Platform\Worker\Supervisor\WorkerSignalController`;
- child readiness through `Coretsia\Platform\Worker\Communication\WorkerChildReadinessChannel`;
- live control behavior through:
  - `Coretsia\Platform\Worker\Communication\WorkerControlTransport`;
  - `Coretsia\Platform\Worker\Communication\WorkerControlProtocol`;
  - `Coretsia\Platform\Worker\Communication\WorkerControlServer`;
  - `Coretsia\Platform\Worker\Communication\WorkerControlClient`;
- package-internal live control boundary through `Coretsia\Platform\Worker\Internal\WorkerControlClientInterface`;
- normalized worker pool config through `Coretsia\Platform\Worker\Runtime\WorkerPoolSpec`;
- immutable safe pool state through `Coretsia\Platform\Worker\Runtime\WorkerPoolState`;
- immutable live health projection through `Coretsia\Platform\Worker\Runtime\WorkerHealthState`;
- deterministic diagnostic state I/O through `Coretsia\Platform\Worker\Runtime\WorkerStateStore`;
- one-root process-child artifact handoff through `--coretsia-worker-artifact-root`;
- artifact-only PCNTL and proc child container boot through Kernel `ArtifactRuntimeBooter`;
- sequential child task execution through `Coretsia\Platform\Worker\Worker\ApplicationWorker`;
- safe child task-source context through `Coretsia\Platform\Worker\Runtime\WorkerTaskSourceContext`;
- exact-one task-source resolution through `Coretsia\Platform\Worker\Task\WorkerTaskSourceResolver`;
- transport-neutral task acquisition/settlement through `Coretsia\Contracts\Worker\WorkerTaskSourceInterface` and `WorkerTaskInterface`;
- package-local Kernel runtime-driver contribution mapping through `Coretsia\Platform\Worker\Internal\WorkerRuntimeDriverContributions`;
- Worker-owned runtime-entrypoint compatibility through `Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard`;
- deterministic Worker exceptions under `Coretsia\Platform\Worker\Exception`.

## Process model

The Worker runtime uses one foreground persistent supervisor with one mandatory package-internal worker-generation guardian:

```text
external service manager / container runtime
└─ worker:start
   └─ WorkerSupervisor
      ├─ private lifecycle locator
      ├─ control server
      ├─ child table
      ├─ readiness aggregation
      ├─ diagnostic state publication
      ├─ signal intent
      ├─ recycle and shutdown orchestration
      └─ WorkerProcessGuardian
         ├─ owns canonical worker.lock generation fence
         ├─ PCNTL: owns worker fork/signal/wait/reap
         └─ PROC: owns WorkerProcProcessHost
```

The canonical startup path is:

```text
WorkerStartCommand
  -> WorkerServiceFactory::workerPoolSpec(...)
  -> WorkerPoolSpec
  -> WorkerRuntimeEntrypointGuard
       -> WorkerRuntimeDriverContributions::fromSpec(...) [internal]
       -> RuntimeDriverResolver
  -> WorkerSupervisorResolverInterface::resolve()
  -> WorkerSupervisorInterface::run(...)
       -> WorkerProcessDriverResolverInterface::resolve(WorkerPoolSpec)
       -> launch and authenticate WorkerProcessGuardian
       -> guardian claims canonical WorkerLifecycleLock
       -> delete stale lifecycle locator, state, and stop signal
       -> generate supervisor-instance control credential
       -> open authenticated WorkerControlServer
       -> install supervisor signal handling
       -> publish starting state
       -> publish private WorkerLifecycleLocator
       -> spawn configured child slots
       -> wait for every child to become ready
       -> publish running state
       -> enter persistent event loop
```

The persistent event loop:

```text
serves status / health / stop
-> polls readiness
-> polls child exits
-> recycles expected ready-child exits
-> fails the complete pool on unexpected exits
-> processes requested or signal-driven shutdown
```

`WorkerPoolSpec` is the normalized Worker-owned source of truth for:

```text
task type
requested and resolved OS process driver
requested and resolved control transport
worker count
max requests
configurable socket, state, and stop-signal paths
TCP control settings
lifecycle deadlines
```

Worker task type maps to Kernel runtime-driver contributions:

```text
queue -> bg.worker_queue
http  -> http.worker
```

Worker OS process-driver selection is separate:

```text
pcntl
proc
```

The foreground supervisor orchestrates the complete pool lifecycle while the guardian owns worker-process lifetime and the generation fence.

Process drivers own only low-level operations for one child.

Each child runs one `ApplicationWorker`.

Each `ApplicationWorker` processes tasks sequentially until:

- `worker.max_requests` is reached;
- the supervisor-written cooperative stop signal is observed outside in-flight tasks, including during interruptible task acquisition;
- task execution or child bootstrap produces a terminal process failure.

The canonical lifecycle lock is the sole worker-generation ownership and fencing authority. The guardian owns it; the foreground supervisor does not.

The state file is diagnostic only.

The control channel is lifecycle-only and supports:

```text
status
health
stop
```

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

WorkerSupervisor depends on `WorkerProcessDriverResolverInterface`, not on concrete process drivers.

`ContainerWorkerProcessDriverResolver` performs one exact package-owned mapping from `WorkerPoolSpec::driver()` and resolves only the selected concrete driver. It does not enumerate process-driver tags, fall back to another driver, or construct the unselected driver.

When `worker.driver=auto`, resolution is deterministic:

```text
pcntl when the required PCNTL fork/exec and POSIX capabilities are available and the platform is not Windows
proc when the guardian plus secure proc process-host capability is available
deterministic lifecycle-validation failure when neither adapter is available
```

The `pcntl` value selects the Unix-like Guardian-owned fork/exec worker-child backend. `PcntlWorkerProcessDriver` remains a strict command/readiness adapter; the Guardian forks the worker child, the forked child detaches Guardian-owned inherited resources, and it immediately executes the package-owned artifact-only launcher through `pcntl_exec()`.

At the PCNTL fork boundary the guardian explicitly closes its supervisor-ownership stream and detaches the generation-fence descriptor. Coretsia does not claim closure of arbitrary third-party descriptors.

It does not enumerate or close arbitrary application, integration, extension, deployment, or third-party descriptors.

Integrations used in a process-capable runtime must follow the repository-wide process-exec descriptor-safety SSoT:

```text
docs/ssot/process-exec-descriptor-safety.md
```

Neither the PCNTL driver nor the proc driver alone proves arbitrary integration-descriptor isolation.

The `proc` driver is the cross-platform process adapter.

It delegates worker-process operations to `WorkerProcessGuardianInterface`. For the proc backend, the Guardian owns `WorkerProcProcessHost` lifetime, while raw `proc_open()` worker resources are owned inside the ProcHost process by the package-internal `WorkerProcProcessHostEntrypointRuntime`.

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
unix when the platform is not Windows and unix domain sockets are supported
tcp otherwise
```

Control-transport selection is independent from the resolved OS process driver.

Raw socket paths and raw TCP endpoints are not public diagnostics.

Endpoint identity may be exposed publicly only through `endpoint_hash`.

Worker OS process-driver ids are not Kernel runtime-driver ids.

```text
worker.driver
  -> pcntl | proc
  -> internal OS child-process adapter

worker.task_type
  -> queue | http
  -> Kernel runtime-driver contribution
```

`RuntimeDriverResolver` does not select `pcntl` or `proc`.

## Process-child artifact-only boot

Worker children created through both process-driver paths enter a fresh PHP runtime image before Worker runtime boot.

Both process drivers are strict adapters over the mandatory guardian. The guardian forks/execs PCNTL workers; for proc it delegates raw `proc_open()` ownership to the nested process host.

For every spawn, the proc process host rotates its authenticated guardian connection through a one-shot tokenized handoff. The current connection closes before `proc_open()` and the replacement connection opens only afterward. The descriptor-isolation sequence is identical on Windows and POSIX and does not rely on `ext-sockets` or `SOCK_CLOEXEC`; on Windows, the retained handoff listener separately requires `ext-sockets` and `SO_EXCLUSIVEADDRUSE` for exclusive address ownership.

Neither driver resolves `ApplicationWorker` from the supervisor container.

`WorkerServiceFactory::workerChildCommandBuilder(...)` derives one validated skeleton-root-relative artifact root from:

```text
RuntimePathContext::skeletonRoot()
RuntimePathContext::artifactRoot()
```

The absolute artifact root MUST be a strict descendant of the skeleton root.

An equal root or a path outside the skeleton fails deterministically.

`WorkerChildCommandBuilder` retains one skeleton-root-relative artifact root and builds the exact child argv vector for both drivers.

`PcntlWorkerProcessDriver` retains the normalized skeleton root, the package-owned launcher command, the command builder, readiness channel, and `WorkerProcessGuardianInterface`. It does not fork, signal, wait, or reap worker processes directly and does not receive `ContainerInterface` or `ApplicationWorker`.

`ProcWorkerProcessDriver` retains the normalized skeleton root, one normalized child command vector, the command builder, readiness channel, and `WorkerProcessGuardianInterface`.

It does not own:

- `WorkerStateStore`;
- `WorkerControlServer`;
- the guardian-owned generation fence;
- pool-wide child state;
- recycle policy;
- shutdown policy;
- raw `proc_open()` resources.

For the proc backend, raw `proc_open()` worker resources are owned by the ProcHost process through the package-internal `WorkerProcProcessHostEntrypointRuntime`; `bin/coretsia-worker-proc-host` is only the package-owned executable shell that launches that composition.

The canonical artifact argument is:

```text
--coretsia-worker-artifact-root=<relative-safe-path>
```

Each child also receives internal readiness arguments:

```text
--coretsia-worker-readiness-port=<1..65535>
--coretsia-worker-readiness-token=<64-lowercase-hex>
```

On Windows, the retained worker-readiness listener requires `ext-sockets` and `SO_EXCLUSIVEADDRUSE` for exclusive address ownership.

The child MUST reject individual artifact-path arguments:

```text
--coretsia-worker-module-manifest
--coretsia-worker-config
--coretsia-worker-container
```

The artifact-root argument MUST:

- be non-empty;
- be skeleton-root-relative;
- use `/` separators;
- contain no whitespace;
- contain no control bytes;
- contain no empty segments;
- contain no `.` or `..` segments;
- contain no stream-wrapper syntax;
- contain no absolute-path prefix;
- contain no `@`-prefixed segment.

The child uses its working directory as the normalized skeleton root and resolves the relative artifact root against it.

It creates:

```php
new ArtifactRuntimeInput(
    skeletonRoot: $skeletonRoot,
    artifactRoot: $artifactRoot,
);
```

The child then invokes:

```text
Coretsia\Kernel\Boot\ArtifactRuntimeBooter
```

The Kernel boot boundary:

1. locates `current`;
2. validates one complete immutable generation;
3. reads exact snapshots for all four generation files;
4. validates generation metadata and envelope fingerprints;
5. hydrates `ConfigRepositoryInterface`;
6. hydrates `ModulePlan`;
7. creates `RuntimePathContext`;
8. builds the compiled runtime container.

After the container is built, the child resolves:

```text
WorkerPoolSpec
WorkerRuntimeEntrypointGuard
ConfigRepositoryInterface
ModulePlan
ApplicationWorker
```

It validates that child arguments match `WorkerPoolSpec`, invokes the Worker runtime-entrypoint guard, resolves `ApplicationWorker`, emits the exact readiness frame, and only then enters the long-running task loop.

Every PCNTL and proc spawn performs a fresh artifact-only boot.

This includes replacement children created by max-request recycle.

A recycled process child:

```text
receives the artifact root
-> locates current
-> validates the selected generation
-> hydrates a new runtime container
-> resolves ApplicationWorker
-> publishes readiness
-> enters the task loop
```

A replacement process child MUST NOT inherit:

- the previous child’s selected generation;
- artifact file handles;
- previously read artifact bytes;
- the previous runtime container;
- previous readiness state;
- generation-local runtime state.

If `current` changes between child generations, the replacement boots the generation selected at replacement startup.

The child artifact-only boot path MUST NOT:

- accept individual artifact paths;
- run Bootstrap Phase A;
- run ConfigKernel Phase B;
- read source config files;
- discover modules;
- read Composer module metadata;
- execute source providers;
- compile a replacement container graph;
- calculate fingerprints;
- write or repair artifacts;
- scan `generations/` for a newest directory;
- fall back to another generation.

The child launcher emits no stdout or stderr diagnostics.

A boot failure exits with a non-zero process code.

The supervisor observes that exit and maps it to package-owned deterministic Worker failure semantics.

Raw paths, artifact payloads, generation identifiers, config values, readiness tokens, and nested throwable messages MUST NOT be exposed publicly.

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
    'start_timeout_ms' => 10000,
    'stop_timeout_ms' => 10000,
    'force_kill_timeout_ms' => 1000,
]
```

Important config rules:

- `worker.task_type=queue` by default.
- `worker.workers` must be a positive integer.
- `worker.max_requests` must be a positive integer.
- `worker.task_type` is `queue` or `http`.
- `worker.driver` is `auto`, `pcntl`, or `proc`.
- `worker.control.transport` is `auto`, `unix`, or `tcp`.
- `worker.tcp.port` must be an explicit TCP port from `1` to `65535`.
- `worker.tcp.host` must be exactly `127.0.0.1`.
- TCP port `0` is forbidden.
- `worker.start_timeout_ms` must be a positive bounded timeout.
- `worker.stop_timeout_ms` must be a positive bounded timeout and is the strict wall-clock budget of the cooperative child-shutdown phase.
- `worker.force_kill_timeout_ms` must be a positive bounded timeout and independently bounds both the terminate/reap phase and the kill/reap phase.
- `var/tmp/worker.lock` is the package-owned, non-configurable lifecycle anchor.
- `var/tmp/worker.lifecycle.json` is the package-owned private active-supervisor locator.
- `var/tmp/worker.lifecycle.json.tmp` is the fixed atomic-write temporary locator path.
- configurable socket, state, state-temp, and stop-signal paths must not overlap each other or canonical lifecycle artifacts.
- configurable runtime paths must be skeleton-root-relative.
- runtime paths must not be absolute.
- runtime paths must not contain `..`, `skeleton/`, backslashes, whitespace, control characters, `://`, or segments beginning with `@`.

`ConfigKernel` Phase B validation rules enforce the Worker-specific `skeleton/` and `@` constraints through `forbiddenPrefixes` and `forbiddenSegmentPrefixes`. `WorkerPoolSpec` repeats the same checks as runtime defense in depth.

`worker.task_type` is Worker-owned runtime input.

It is normalized by `WorkerPoolSpec`.

It is mapped to Kernel runtime-driver contributions by the package-local `WorkerRuntimeDriverContributions` mapper:

```text
queue -> bg.worker_queue
http  -> http.worker
```

Invalid or missing `worker.task_type` is a Worker-owned lifecycle-validation failure, not a Kernel runtime-driver invalid-config failure.

## Lifecycle discovery artifacts

Three separate runtime concepts are intentionally preserved:

```text
WorkerPoolSpec = desired configuration for creating a new pool
WorkerPoolState = redacted diagnostic snapshot of an active pool
WorkerLifecycleLocator = private endpoint, control credential, and stop deadlines of the active supervisor
```

Canonical package-owned paths are:

```text
var/tmp/worker.lock
var/tmp/worker.lifecycle.json
var/tmp/worker.lifecycle.json.tmp
```

The lock is the worker-generation ownership and fencing authority. A free lock means no Coretsia-owned active or recovering worker generation exists, regardless of stale state or locator files. A held lock means a worker generation is active or recovering; it does not by itself prove that the foreground supervisor is reachable. A held lock with a missing, unreadable, malformed, oversized, symlinked, or schema-invalid locator is a deterministic communication failure.

The locator has an exact version-`1` schema and contains the active control transport, its private address, the supervisor-instance control credential, and the active stop deadlines. On POSIX its exclusive temporary file is created under `umask(0177)`, verified as mode `0600` before credential bytes are written, and published atomically. On POSIX, reads reject effective permission bits other than `0600`. The locator is never rendered by CLI commands, copied into `worker.state.json`, or included in logs and exception messages.

The supervisor publishes the locator only after the listener, signal handling, and `starting` state are ready, but before child spawn. During shutdown it deletes the locator before requesting terminal guardian release; the guardian releases the generation fence after all worker resources are closed.

## Control-channel authentication

Every `status`, `health`, and `stop` request includes the 256-bit credential of the active supervisor instance. The credential is encoded as exactly 64 lowercase hexadecimal characters and compared through `hash_equals()` before a control session is created.

The credential rotates on supervisor restart and remains stable during child spawn and recycle. Missing, malformed, stale, or incorrect credentials are rejected by silently closing the connection. Responses never echo the credential.

The credential exists only in supervisor memory, the active control server, the private lifecycle locator, and a private request frame. It is absent from public state, endpoint hashes, logs, spans, metrics, CLI output, exceptions, child argv, and child environment.

TCP control binds exactly to `127.0.0.1`; no remote or unsafe non-loopback opt-in is provided. Unix control sockets are created under restrictive `umask(0177)` and verified as mode `0600`. On Windows, deployment must restrict skeleton and runtime directory ACLs to the application service account and authorized administrators.

This credential is not an isolation boundary against arbitrary processes running under the same compromised operating-system account.

The normative Worker control-protocol and security decisions are recorded in ADR-0017.

## Worker commands

This package provides command classes for:

```text
worker:start
worker:stop
worker:status
worker:health
```

The command classes implement:

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

Full `coretsia worker:*` binary dispatch through container-backed CLI tag discovery remains owned by `platform/cli`.

### `worker:start`

Starts one foreground persistent supervisor.

The strict startup order is:

```text
WorkerStartCommand
  -> WorkerServiceFactory::workerPoolSpec(...)
  -> WorkerPoolSpec
  -> WorkerRuntimeEntrypointGuard::assertEntrypointAllowed(...)
       -> WorkerRuntimeDriverContributions::fromSpec(...) [internal]
       -> RuntimeDriverResolver::resolve(...)
  -> WorkerSupervisorResolverInterface::resolve()
  -> WorkerSupervisorInterface::run(...)
```

`WorkerPoolSpec` is built before the Worker-owned runtime-entrypoint boundary is invoked.

The supervisor remains unresolved until runtime-entrypoint validation succeeds.

The command emits one startup summary only after:

```text
every configured child is ready
AND pool status == running
```

The command process then remains blocked in the foreground until shutdown completes.

### `worker:stop`

Requests shutdown through `WorkerControlClientInterface`.

The command:

1. probes the canonical lifecycle-lock authority;
2. reads the private lifecycle locator;
3. connects to the active endpoint from that locator;
4. authenticates with the active supervisor-instance control credential;
5. uses the active supervisor's locator-published stop deadlines;
6. sends one `stop` request;
7. waits while the supervisor performs cooperative, graceful, and forced shutdown;
8. reports success only after the terminal `stopped` response.

Lifecycle commands do not resolve `WorkerPoolSpec` and do not use current worker configuration to address an active supervisor.

The command MUST NOT:

- resolve `WorkerPoolSpec` from current config;
- write the stop signal directly;
- create a control listener;
- read diagnostic state as liveness authority;
- own child processes;
- request terminal guardian release; the guardian releases the generation fence last.

### `worker:status`

Requests current in-memory state through `WorkerControlClientInterface`.

It probes the canonical lifecycle lock, reads the private locator, and connects to the endpoint of the active supervisor. It does not resolve current worker configuration.

It MUST NOT infer running state from `worker.state.json`.

### `worker:health`

Requests the live health projection through `WorkerControlClientInterface`.

It uses the same canonical-lock and private-locator discovery flow as status and stop, without resolving `WorkerPoolSpec` from current config.

Health is true only when:

```text
pool status == running
AND ready_worker_count == worker_count
AND no terminal child failure is pending
```

The command exits non-zero for an unhealthy live pool.

### Successful command summaries

Successful command summaries may expose only bounded safe fields:

```text
status
pool_status
pid
worker_count
ready_worker_count
healthy
reason
driver
control_transport
endpoint_hash
```

`pid` is the persistent supervisor PID.

Child PIDs are not public command-summary fields.

Raw socket paths, raw TCP endpoints, config values, payloads, headers, tokens, readiness tokens, absolute paths, and throwable messages MUST NOT be exposed.

## Runtime-driver resolution boundary

Kernel runtime-driver matrix/config policy and Worker owner prerequisites are separate failure domains.

The public Kernel matrix boundary is:

```text
Coretsia\Kernel\Runtime\Driver\RuntimeDriverResolver
```

The public Kernel contribution handoff object is:

```text
Coretsia\Kernel\Runtime\Driver\RuntimeDriverContributions
```

Worker runtime callers use the Worker-owned entrypoint boundary:

```text
Coretsia\Platform\Worker\Runtime\WorkerRuntimeEntrypointGuard
```

The following runtime paths use it:

```text
WorkerStartCommand
bin/coretsia-worker
```

They call:

```text
WorkerRuntimeEntrypointGuard::assertEntrypointAllowed(...)
```

with:

```text
ConfigRepositoryInterface
ModulePlan
WorkerPoolSpec
```

`WorkerRuntimeEntrypointGuard` owns:

- the `platform.worker` ModulePlan participation check;
- delegation to the package-internal `WorkerRuntimeDriverContributions::fromSpec(...)` mapper;
- construction of explicit Kernel `RuntimeDriverContributions`;
- delegation of canonical matrix validation to `RuntimeDriverResolver`.

Worker callers MUST NOT:

- import `WorkerRuntimeDriverContributions` from command/child surfaces;
- call `WorkerRuntimeDriverContributions::fromSpec(...)` directly;
- bypass the Worker module precondition;
- independently resolve or duplicate the Kernel runtime-driver matrix.

The shipped `bin/coretsia-worker` executable MUST NOT import classes from:

```text
Coretsia\Platform\Worker\Internal\*
```

The Worker package owns:

```text
worker.task_type
```

and maps:

```text
worker.task_type=queue -> bg.worker_queue
worker.task_type=http  -> http.worker
```

This mapping is independent from Worker OS process-driver selection:

```text
worker.driver=pcntl -> WorkerProcessDriverResolverInterface -> PcntlWorkerProcessDriver
worker.driver=proc  -> WorkerProcessDriverResolverInterface -> ProcWorkerProcessDriver
```

`pcntl` and `proc` MUST NOT enter `RuntimeDriverContributions`.

`RuntimeDriverResolver` does not select `pcntl` or `proc` and does not evaluate OS child-process capability.

Missing or invalid `worker.task_type` is Worker-owned lifecycle invalid state:

```text
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-invalid-state
```

Missing `platform.worker` is Worker-owned startup failure:

```text
CORETSIA_WORKER_START_FAILED: worker-module-not-enabled
```

Kernel runtime-driver matrix/config failures remain Kernel-owned:

```text
CORETSIA_RUNTIME_DRIVER_MATRIX_CONFLICT
CORETSIA_RUNTIME_DRIVER_MATRIX_INVALID_CONFIG
```

The Worker package MUST NOT reclassify those Kernel failures as Worker failures.

`http.worker` does not imply `platform.http` inside Kernel.

Any concrete HTTP task-source package/module prerequisite is owned and validated by the concrete HTTP task-source owner before readiness/execution.

`platform/worker` MUST NOT depend on `platform/http` merely to satisfy runtime-driver matrix resolution.

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

Supported task-source types are:

```text
queue
http
```

`platform/worker` does not ship a production source for either type.

Sources are contributed through:

```text
ReservedTags::WORKER_TASK_SOURCE
worker.task_source
```

with exact metadata:

```php
[
    'task_type' => 'queue', // or 'http'
]
```

For the selected type, zero matching sources fail child startup, exactly one source is used, and multiple matching sources are a deterministic configuration conflict. Priority is not an override mechanism.

Before readiness publication, the selected source must pass `assertReady(...)`. Real work is then acquired by `receive(...)` using transport-native blocking/event-loop waiting that remains cooperatively interruptible.

`receive()` returns `null` only for cooperative shutdown. It must not represent an empty queue or idle wake-up, and synthetic/no-op tasks are forbidden.

`worker.max_requests` counts real acquired task attempts. For a successful Kernel UoW, `WorkerTaskInterface::complete(result)` performs success settlement. For an application/Kernel failure, `WorkerTaskInterface::fail(original failure)` performs failure settlement. A `complete()` failure is a lifecycle settlement failure and must not trigger `fail()` automatically.

Queue adapters own broker receive, acknowledgement, retry/requeue/dead-letter behavior. HTTP runtime adapters own request receive, request/handler integration, response emission, and failure response behavior. Those adapters own their transport dependencies; `platform/worker` remains transport-neutral.

The canonical task-source contract is documented in `docs/ssot/worker-task-sources.md`.

## State files

`WorkerStateStore` owns deterministic diagnostic state I/O.

The default state path is:

```text
var/tmp/worker.state.json
```

There is no separate `worker.pid_path` config key.

The stored PID is the persistent supervisor PID.

The canonical state schema version is:

```text
1
```

The exact persisted fields are:

```text
version
pid
status
worker_count
ready_worker_count
driver_requested
driver
control_transport_requested
control_transport
endpoint_hash
```

The persistent status vocabulary is:

```text
starting
running
stopping
```

`stopped` is not persisted.

It is returned only as a terminal live control result after shutdown cleanup.

The state file is diagnostic only.

It is not liveness authority.

The liveness rules are:

```text
lifecycle lock free
  -> no Coretsia-owned active or recovering worker generation exists
  -> pool is not running

lifecycle lock held + valid private locator + reachable authenticated control endpoint
  -> live supervisor determines starting, running, or stopping state

lifecycle lock held + missing, unreadable, malformed, oversized, symlinked, or schema-invalid private locator
  -> communication failure

lifecycle lock held + unavailable or unauthenticated control endpoint
  -> communication failure
```

A stale state file with a free lifecycle lock does not mean the pool is running.

State publication uses deterministic JSON and atomic temp-file plus rename semantics.

After complete successful shutdown, the state file is deleted.

The state file MUST NOT contain:

- timestamps;
- environment values;
- raw socket paths;
- raw TCP hosts or ports;
- absolute paths;
- child PIDs;
- task payloads;
- HTTP headers;
- cookies;
- Authorization values;
- tokens;
- raw endpoint identifiers;
- exception messages;
- stack traces.

## Control channel

The supervisor-owned control layer is split into:

```text
WorkerControlTransport
WorkerControlProtocol
WorkerControlServer
WorkerControlClient
WorkerControlClientInterface
```

`WorkerControlTransport` owns:

- deterministic address derivation;
- listen;
- connect;
- timeout-aware accept;
- bounded frame reads and writes;
- connection closure;
- Unix socket cleanup.

`WorkerControlProtocol` owns exact versioned request and response schemas.

`WorkerControlServer` owns the live supervisor listener and typed control sessions.

`WorkerControlClient` owns lifecycle-lock probing, private locator resolution, endpoint-consistency validation, and live request-response behavior.

Supported control transports are:

```text
unix
tcp
```

The control protocol supports exactly:

```text
status
health
stop
```

The control protocol MUST NOT contain:

```text
start
```

Pool startup belongs only to the foreground `worker:start` command.

The protocol is:

- versioned;
- newline framed;
- deterministic JSON;
- bounded to one frame;
- exact-key;
- strict about unknown keys;
- strict about unsupported versions;
- payload-free.

A stop request remains pending until:

- every child has exited;
- every child has been reaped;
- every child resource is closed;
- diagnostic state is deleted;
- the cooperative stop signal is cleared;
- the control listener is closed;
- the Unix socket is removed when applicable;
- the private lifecycle locator is deleted;
- terminal guardian release has completed;
- the canonical generation fence is released.

Only then may the server return terminal:

```text
stopped
```

A disconnected status or health client MUST NOT terminate the supervisor.

The control channel MUST NOT transport task payloads.

Control communication failures map to deterministic `WorkerCommunicationFailedException`.

Public diagnostics MUST NOT expose raw socket paths, socket basenames, raw TCP hosts, raw TCP ports, raw endpoint strings, payloads, headers, tokens, readiness tokens, or throwable messages.

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

The currently emitted bounded `status` values are:

```text
start_success
start_failure
stop_success
stop_failure
status_success
status_failure
```

The reserved recycle values are:

```text
recycle_success
recycle_failure
```

Reserved values MUST NOT be emitted until the corresponding metric path is implemented and covered by deterministic tests.

Worker process status values MUST NOT encode:

- worker index;
- child generation;
- PID;
- signal;
- exit code;
- error reason.

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
worker_index
child_generation
exit_code
signal
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
WorkerLifecycleFailedException
WorkerForkFailedException
WorkerAlreadyRunningException
WorkerCommunicationFailedException
WorkerNotRunningException
```

Public worker exception messages have the canonical form:

```text
CORETSIA_WORKER_*: worker-reason-token
```

Examples:

```text
CORETSIA_WORKER_START_FAILED: worker-start-failed
CORETSIA_WORKER_START_FAILED: worker-task-source-missing
CORETSIA_WORKER_START_FAILED: worker-task-source-ambiguous
CORETSIA_WORKER_START_FAILED: worker-task-source-invalid
CORETSIA_WORKER_START_FAILED: worker-task-source-unresolvable
CORETSIA_WORKER_START_FAILED: worker-task-source-not-ready
CORETSIA_WORKER_START_FAILED: worker-readiness-timeout
CORETSIA_WORKER_START_FAILED: worker-readiness-invalid
CORETSIA_WORKER_START_FAILED: worker-child-start-failed
CORETSIA_WORKER_START_FAILED: worker-signal-handling-unavailable
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-lifecycle-failed
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-invalid-state
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-task-source-terminated
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-task-source-receive-failed
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-task-settlement-failed
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-child-exited
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-shutdown-failed
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-runtime-cleanup-failed
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-lifecycle-lock-failed
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-lifecycle-locator-failed
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-process-host-failed
CORETSIA_WORKER_FORK_FAILED: worker-fork-failed
CORETSIA_WORKER_ALREADY_RUNNING: worker-already-running
CORETSIA_WORKER_COMMUNICATION_FAILED: worker-communication-failed
CORETSIA_WORKER_NOT_RUNNING: worker-not-running
```

Worker exception messages MUST NOT include previous throwable messages, stack traces, absolute paths, raw socket paths, raw TCP endpoints, raw config values, payload fragments, headers, tokens, process command lines, or environment data.

`WorkerStartFailedException` is limited to startup validation, task-source selection/readiness, child-process creation, and signal bootstrap.

`WorkerLifecycleFailedException` owns runtime-wide Worker failures, including task-source receive/termination/settlement failures, invalid lifecycle state, unexpected child exit, shutdown, runtime cleanup, lifecycle-lock, lifecycle-locator, process-guardian, and proc-host failures.

`worker:start`, `worker:status`, `worker:health`, and `worker:stop` preserve the error code and reason of concrete `WorkerException` instances. Unknown throwables are mapped to command-specific safe catch-all errors.

Runtime-driver matrix/config failures remain Kernel-owned `RuntimeDriverResolver` failures.

They must not be reclassified as worker exceptions.

Worker-owned task type validation failures are not runtime-driver matrix failures.

Missing or invalid `worker.task_type` is surfaced as:

```text
CORETSIA_WORKER_LIFECYCLE_FAILED: worker-invalid-state
```

after Worker-owned normalization fails.

Kernel runtime-driver failures are surfaced unchanged only after Worker has produced explicit `RuntimeDriverContributions`.

## Security / Redaction

The worker package treats the following values as unsafe for public diagnostics:

- raw socket paths;
- raw TCP hosts;
- raw TCP ports;
- raw endpoint identifiers;
- raw lifecycle locator JSON;
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
pool_status
pid
worker_count
ready_worker_count
healthy
reason
driver
control_transport
endpoint_hash
operation
outcome
duration_ms
```

Endpoint identity may be represented publicly only as a deterministic hash.

The private lifecycle locator, `socket_path`, `tcp_host`, and `tcp_port` MUST NOT be emitted through logs, spans, metrics, CLI output, state snapshots, or exception messages.

`reason` is allowed only when it is a bounded package-owned health or error token.

Arbitrary throwable messages and dynamically generated reason values remain forbidden.

The public `pid` is the persistent supervisor PID.

Child PIDs, child generations, readiness endpoints, and readiness tokens are not public summary fields.

## Internal seams

The following interfaces are package-internal:

```text
Coretsia\Platform\Worker\Internal\WorkerSupervisorInterface
Coretsia\Platform\Worker\Internal\WorkerSupervisorResolverInterface
Coretsia\Platform\Worker\Internal\WorkerProcessCapabilities
Coretsia\Platform\Worker\Internal\WorkerProcessDriverInterface
Coretsia\Platform\Worker\Internal\WorkerProcessDriverResolverInterface
Coretsia\Platform\Worker\Internal\WorkerProcessGuardianInterface
Coretsia\Platform\Worker\Internal\WorkerControlClientInterface
```

The following helper is also package-internal:

```text
Coretsia\Platform\Worker\Internal\WorkerRuntimeDriverContributions
```

It maps Worker-owned task type to the public Kernel `RuntimeDriverContributions` handoff object.

It does not select the Worker OS process driver.

These interfaces and helpers:

- are not public package APIs;
- are not application extension points;
- MUST NOT be moved to `core/contracts`;
- MUST NOT be exported through Composer `extra` metadata as public API;
- MUST NOT be documented as stable third-party plugin boundaries.

The process-driver seam is limited to one-child OS operations.

The supervisor seam owns pool-wide lifecycle orchestration.

The guardian seam owns worker-generation process containment and canonical generation fencing.

The control-client seam owns live command communication.

The public Worker task-source SPI is intentionally not package-internal; it is owned by `core/contracts`, while `platform/worker` owns source selection and orchestration.

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
- external service-manager configuration;
- deployment restart policy;
- process-group, job-object, or cgroup policy;
- RoadRunner integration;
- Swoole integration;
- FrankenPHP integration;
- public worker plugin APIs;
- container artifact schema;
- artifact-generation publication;
- artifact-generation validation policy;
- compiled-container payload construction;
- config merge implementation;
- config validation implementation;
- production observability exporter configuration;
- automatic degraded-capacity child restart policy;
- rolling in-process pool replacement;
- public supervisor plugin APIs;
- public control-protocol extension APIs.

## References

- [Worker Architecture](https://github.com/coretsia/monorepo/tree/main/docs/architecture/worker.md)
- [Runtime Driver Resolution Architecture](https://github.com/coretsia/monorepo/tree/main/docs/architecture/runtime-driver-resolution.md)
- [ADR-0017: Persistent worker supervisor and application worker](https://github.com/coretsia/monorepo/tree/main/docs/adr/ADR-0017-persistent-worker-supervisor-application-worker.md)
- [Config Roots Registry](https://github.com/coretsia/monorepo/tree/main/docs/ssot/config-roots.md)
- [Observability SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/observability.md)
- [Worker Task Sources SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/worker-task-sources.md)
- [Runtime Drivers SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/runtime-drivers.md)
- [Runtime Container Definitions SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/runtime-container-definitions.md)
- [Process-Exec Descriptor Safety SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/process-exec-descriptor-safety.md)
- [Worker Process Bootstrap SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/worker-process-bootstrap.md)
- [UnitOfWork and Reset Contracts SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/uow-and-reset-contracts.md)
- [Artifact Generations SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/artifact-generations.md)
- [Compiled Container SSoT](https://github.com/coretsia/monorepo/tree/main/docs/ssot/compiled-container.md)
- [ADR-0029: Kernel compiled container artifact](https://github.com/coretsia/monorepo/tree/main/docs/adr/ADR-0029-kernel-container-compile-artifact.md)
- [ADR-0031: Atomic Artifact Generations](https://github.com/coretsia/monorepo/tree/main/docs/adr/ADR-0031-atomic-artifact-generations.md)
- [ADR-0032: Process-Exec Descriptor Safety](https://github.com/coretsia/monorepo/tree/main/docs/adr/ADR-0032-process-exec-descriptor-safety.md)
- [Worker package source](https://github.com/coretsia/monorepo/tree/main/framework/packages/platform/worker)
