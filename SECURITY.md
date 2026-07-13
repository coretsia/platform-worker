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

# Security Policy

## Status

Coretsia Framework is in active development and DOES NOT provide a stable production release or long-term-supported release line yet.

Until the first stable public release is published, security handling is performed on a best-effort basis for the default development branch.

For implementation status and roadmap planning, see:

- [Roadmap](docs/roadmap/ROADMAP.md)

## Supported Versions

At the moment, Coretsia DOES NOT provide long-term-supported stable release lines.

| Version / line                                 | Supported | Notes                                                  |
|------------------------------------------------|-----------|--------------------------------------------------------|
| Default branch (`main`)                        | Yes*      | Best-effort fixes during active development            |
| Tagged 0.x development snapshots               | No        | Development milestones, not stable support lines       |
| Any older commit / fork / unpublished snapshot | No        | Upgrade to the current default branch before reporting |

\* "Supported" here means vulnerability reports are reviewed and may be fixed in the active development branch. It DOES NOT mean a stable security SLA is available.

## Reporting a Vulnerability

Please DO NOT open public GitHub issues for suspected security vulnerabilities.

Report vulnerabilities privately to:

- team@coretsia.dev

Include, when possible:

- affected package(s) or path(s)
- exact version, tag, or commit hash
- PHP version and environment details
- minimal reproduction steps
- impact assessment
- any proof-of-concept or logs that help reproduce safely

## What to Expect

For valid reports, the project will generally try to:

1. acknowledge receipt;
2. reproduce and assess severity;
3. prepare a fix on the active development branch;
4. publish the fix in the appropriate public commit or release note when ready.

Response and remediation timing is not guaranteed at this stage of the project.

## Scope Guidance

Security reports are especially helpful for issues involving:

- unsafe file/path handling
- secret leakage or redaction bypasses
- authentication / authorization flaws
- request handling vulnerabilities
- dependency or supply-chain risks in shipped runtime/tooling code
- sandbox or boundary violations that break documented invariants

The following are usually out of scope unless they lead to a concrete exploitable weakness:

- purely theoretical concerns without a reproducible impact
- issues in unsupported historical snapshots
- local environment misconfiguration outside the repository defaults
- feature requests or general hardening suggestions without a vulnerability

## Disclosure

Please allow time for investigation and remediation before any public disclosure.

Once the project has stable release lines, this policy can be expanded with version-specific support windows, coordinated disclosure timelines, and security advisory procedures.
