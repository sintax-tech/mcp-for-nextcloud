<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Contributing to MCP for Cloud

[English](CONTRIBUTING.md) · [Português (Brasil)](CONTRIBUTING.pt-BR.md) · [Español](CONTRIBUTING.es.md) · [Deutsch](CONTRIBUTING.de.md)

Thank you for contributing. Bug fixes, translations, documentation and new capabilities are welcome. This guide explains the product decisions behind our code so you can assess an idea before investing in a large implementation.

## What we are building

MCP for Cloud is a native Nextcloud PHP app that lets an authenticated person use Nextcloud through an MCP client. It runs inside their instance, under their identity and permissions. Our priority is useful access to existing apps with clear controls, predictable effects and an installation that works through the Nextcloud interface.

## Our development philosophy

### Keep installation simple and the implementation native

The distributed app must work without mandatory terminal commands, manual SQL, configuration-file edits or a separate service. Optional diagnostics must remain optional. Use Nextcloud migrations and settings for setup and configuration.

Use native PHP and the Nextcloud APIs. Keep production dependencies small and justified. The frontend uses plain JavaScript, with bundles built by the existing PHP asset builder. Do not introduce a Node/npm build or test prerequisite, another framework or an external service without prior agreement. Composer is used in development and packaging; users install a package containing its runtime dependencies.

### Respect both MCP policy and native permissions

Every operation runs as the authenticated actor and must satisfy the applicable MCP grants, group restrictions, Guests rules, resource scope, visibility checks and native ACLs. A grant does not replace a native permission. An unavailable policy or service must produce a controlled refusal rather than broader access.

Preserve saved access decisions during upgrades. New writes and newly introduced sensitive reads start denied. Some established read grants are allowed by default; this is not permission to expose every new read automatically. A new operation, or an expansion of an existing grant, needs an explicit access review. For example, permission to edit a card does not automatically establish permission to delete a board's labels.

### Make writes understandable before executing them

An unconfirmed write returns a plan describing the target, changes, people affected and recovery limits. It must not perform the requested write or create its backup, folder or checkout token in advance. Execution requires `confirm: true`, plus `confirm_shared` and `plan_state` where the workflow requires them. Revalidate access, identity and relevant state before the effect.

Confirmation is conversational: the server cannot prove a person said yes. `plan_state` binds a call to the state shown in a plan; it does not grant permission or represent a stored approval. Preserve each workflow's concurrency checks. Document native bootstrap effects where they exist; do not promise that every preview is free of all platform effects.

### Preserve native behavior and protect data

Use the supported native service or DAV pipeline for the operation, preserving the required permission checks, events, versions, trash and scheduling behavior. Do not modify another app's tables or bypass its workflow to make a feature appear to work. Persistence owned by MCP still uses Nextcloud's database and migration facilities.

Do not delete user files as cleanup, rollback or retention. File deletion is an explicit, restricted workflow through `files_delete`; `notes_delete` has its own rules. Keep the narrow existing empty-folder undo exception narrow. Never silently prune backups or versions, and never describe a native trash attempt as guaranteed recovery.

A read should not mark messages read, expire status or perform maintenance unexpectedly. Inspect the native call's actual effects. If a safe path is unavailable, refuse the capability and explain the limitation instead of adding a bypass.

### Carry privacy across apps and report uncertainty

Links, attachments, search results and references must respect the protections of the resource they expose. Moving through another module must not reveal a hidden resource, its name or its existence. Return only the data needed for the operation, and keep secrets and resource contents out of diagnostics.

Keep scans and responses bounded; make partial results and continuation explicit. Distinguish observed success, partial completion and an uncertain result. Do not promise atomicity, invitation delivery or recovery that the native API cannot establish. Do not add automatic retries or destructive compensation after a possible write without a separately reviewed design.

### Keep changes focused and controls familiar

Use focused classes and the existing module boundaries. Keep tool names, schemas, ordering and persisted identities stable unless the change explicitly requires a reviewed contract update. Retained legacy code and test exceptions are not templates for new code.

Preserve the permission screen's layout, its three states distinguishable without color, explicit bulk changes, counts and Undo. A visual redesign needs separate agreement.

## Before a substantial contribution

Describe the user problem, expected behavior, target branch, grants, native API and supported versions. Explain shared or destructive effects and failure cases. Align a substantial feature, architectural change, new dependency or access expansion with the maintainers before implementing it. Small fixes and documentation improvements can go directly to a focused PR.

Compatibility belongs to the target release. Published 1.3.5 documents NC32–35. For work targeting 1.4.0, new guarantees and tests cover NC34/35 only; existing NC32/33 code and the declared installation range remain. Do not add NC32/33-specific fallbacks or fixtures, or treat the installation range as a promise of new-feature coverage.

## Implementation and evidence

Read [Architecture](docs/en/architecture.md), [Adding a module or tool](docs/en/adding-a-module.md), [Testing](docs/en/testing.md) and [Packaging](docs/en/packaging.md). New and rewritten PHP uses tabs, `strict_types`, appropriate SPDX notices and complete PHPDoc. Preserve attribution. Code, comments, commit messages and source strings are in English; maintain equivalent supported translations for user-facing changes.

For code changes, run focused and affected tests, Architecture, MapCoverageTest, the Contract suite and REUSE on a clean copy. New tests must enter the existing CI inventory and partitions; native integration coverage belongs in the existing integration cells. Mocks alone do not prove native behavior. The complete suites run in the existing GitHub release validation, rather than being repeated locally for each patch. Markdown-only changes need a content and link review, not application tests or CI.

In the PR, state what changed, the access and compatibility impact, commands and results, and what was not tested. Review golden changes deliberately; do not regenerate expectations to conceal regressions. Native integration changes require renewed integration validation in CI.

## Review, credit and release

A working implementation can still require changes to fit these principles. Small, suitable contributions normally enter through a regular merge preserving authorship; maintainer adjustments remain separate. Large contributions or those needing a different design may be adapted before integration, with the original contribution credited and our changes distinguished.

A merged contribution and a published release are separate steps. Preserve the project's license and attribution notices. See the [license](LICENSES/AGPL-3.0-or-later.txt), [NOTICE](NOTICE) and [technical reference](mcp/README.md).
