<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Testing

Read the [contribution guide](../../CONTRIBUTING.md) for the development philosophy and access requirements. Use focused tests while developing and the required gates for the final committed change. Keep the command, PHP configuration, revision, exit status and evidence together. A test count alone is not a behavioral comparison.

When moving or splitting code, reconfirm source-reader paths and move certificates against the affected revisions. The commands below refer to the checked-in harness; integration image pins and the chosen execution matrix belong to the workflow and dated validation record.

## Validation policy

For code changes, both author and reviewer run the focused and affected tests, all of `tests/Architecture`, `tests/Unit/Scripts/Dev/MapCoverageTest.php`, all of `tests/Unit/Contract`, and REUSE on a clean copy as described in [Packaging](packaging.md#licenses-and-attribution). The complete unit and integration suites run in the existing GitHub Actions release validation, once on the authorized release-branch push; do not repeat them locally for each patch. Local native integration is limited to an explicitly scoped diagnostic, not an unfiltered cell or the full matrix.

New tests belong in the existing `tests.yml` partitions and `integration.yml` cells, without a parallel workflow, runner or PHPUnit configuration. Use `scripts/ci/phpunit.php prepare <report-directory>` from `mcp/` to inspect the unit inventory; the existing CI verify step proves each inventoried identity executed exactly once. Changes to integration tests, fixtures or doubles require renewed integration validation in the existing CI. Markdown-only changes need a content and link review, without application tests or CI.

## Prerequisites

From the repository root:

```sh
cd mcp
composer install
```

Use a PHP version supported by `appinfo/info.xml` and the dependencies in `composer.lock`. The extensions and PHP matrix used by CI are in [tests.yml](../../.github/workflows/tests.yml). The local unit bootstrap supplies OCP test support; it does not install Nextcloud. Do not run these unit fixtures against a live user's installation.

Before collecting evidence, record the revision and any uncommitted changes; final release evidence must identify the committed content. Check that the app's source directories contain no hidden ignored code. From the repository root, review every entry in:

```sh
git status --short --ignored mcp/lib mcp/src mcp/templates mcp/appinfo mcp/css mcp/js mcp/img mcp/l10n
```

## Unit and contract suites

The following commands run from `mcp/`: the first is an example focused test, and the second is the required Contract gate. They do not run the complete unit suite.

```sh
php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result tests/Unit/Tools/Files/FilesPlanTest.php
php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result tests/Unit/Contract
```

`phpunit.xml` excludes `tests/Integration`. CI also sets upload/post limits; keep the exact configuration with the result because those limits can change upload-plan expectations. A native run and a pinned container run are not interchangeable environment baselines. Do not silently change settings to conceal a failure.

The known environment-failure identities are recorded in [`environment-failures.txt`](../../mcp/tests/Unit/Contract/baseline/environment-failures.txt). Skips and their environment constraints have separate records under [`baseline/`](../../mcp/tests/Unit/Contract/baseline), including `environment-skips.tsv`. Compare the exact identities and reasons against the dated execution record, not only their count. New errors, missing baseline failures and unexpected skips require investigation; do not reclassify a regression as environment noise.

## Golden and effect contracts

[`GoldenContractTest`](../../mcp/tests/Unit/Contract/GoldenContractTest.php) and the other contract tests use [`golden/`](../../mcp/tests/Unit/Contract/golden) for discovery, schemas, guides, routes, names and response contracts. [`EffectContracts/`](../../mcp/tests/Unit/Contract/EffectContracts) uses [`effects/`](../../mcp/tests/Unit/Contract/effects) to check ordered writes and refusals with doubles.

A pure restructuring must leave these observable contracts unchanged. Retarget a moved source reader exactly, preserving the assertion and its negative controls. Do not regenerate expected snapshots merely to accept a new result. A separately approved feature may require a narrowly reviewed golden delta; that is different from a move.

## Architecture and DI

From `mcp/`:

```sh
php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result tests/Architecture
php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result tests/Unit/Scripts/Dev/MapCoverageTest.php
php -d memory_limit=1G scripts/dev/map_check.php
```

The architecture suite covers catalog/delegation contracts, module/layer/native-app boundaries, DI cycles, missing ports, size ceilings, style and packaged asset boundaries. `map_check.php` validates the live FQCN map and size inventory. Neither is a substitute for running the actual tool.

Keep the FQCN map and retained size ceilings consistent with the move. [`boundary-exceptions.json`](../../mcp/tests/Architecture/boundary-exceptions.json) has no live exceptions, and the size inventory has no transitional entries. Historical checker fixtures and frozen baselines are controls, not permission to restore an expired exception. Do not add broad exemptions or raise a retained ceiling to make a split pass. A structural dependency call found in tokens does not establish that an enclosed callback is reached.

## Move equivalence

Keep a pure move separate from extraction or behavior changes. From `mcp/`, compare the committed before/after revisions of the move only:

```sh
php scripts/dev/move_equivalence.php --ticket=RE-XX OLD_MOVE_PARENT NEW_MOVE_COMMIT
```

Replace all three placeholders with the reviewed ticket and actual revisions. The comparator reads Git objects and the destination revision's [`fqcn-map.json`](../../mcp/scripts/dev/fqcn-map.json), not uncommitted files. It normalizes the permitted class/path rewrites and rejects unrelated executable changes. It deliberately ignores comments, PHPDoc, import ordering and whitespace; review those separately.

Location-sensitive dynamic sites can require a ticket-specific frozen certificate. Its reviewed base, exact move pairs, source anchors and external seal must match that move. Activation is checked before certificate JSON is read. A certificate from an earlier ticket cannot authorize a later split; do not weaken the generic refusal or regenerate a seal automatically. Caller-location independence, source/caller closure and negative controls need the reviewed per-site evidence. The frozen proof applies to its move commit; subsequent extraction still needs comparator coverage where applicable and unchanged behavioral/effect contracts.

## Test identity inventory

The inventory checks that baseline test methods survive moves. These commands run from `mcp/`; substitute real saved baseline/current reports:

```sh
php scripts/dev/test_inventory.php snapshot > /tmp/mcp-test-identities.json
php scripts/dev/test_inventory.php compare /path/to/baseline-identities.json
php scripts/dev/test_inventory.php failures /path/to/baseline-junit.xml /path/to/current-junit.xml
```

A baseline identity may match through the reviewed FQCN/test map, not an arbitrary class with the same short name. Test inventory and JUnit failure comparison serve different purposes; keep both when the change requires them.

## Integration matrix

Read the [integration guide](../../mcp/tests/Integration/README.md) and [integration workflow](../../.github/workflows/integration.yml) for the current cells, native-app versions, image digests, upgrade baseline and artifacts. Use a disposable installation, never a user's instance.

The active 1.4.0 matrix has eight cells: NC34/MariaDB and NC35/MariaDB, PostgreSQL and SQLite, each in fresh and upgrade mode. New coverage is NC34/35 only; the unchanged installation range in `info.xml` does not require active NC32/33 cells.

For an explicitly scoped diagnostic on a Mac, the existing entrypoint runs from the repository root. This example selects only CalendarToolsTest:

```sh
MCP_IT_PHPUNIT_ARGS='--filter CalendarToolsTest' sh mcp/tests/Integration/ci/run-local.sh 34 mariadb fresh
```

A filtered diagnostic is not full-cell or matrix evidence and does not replace release CI. Keep its filter, selected cell and limitations with the result; do not remove the filter to run the complete suite locally.

The entrypoint manages the shared Colima engine, queue and per-run resources. Coordinate resource slots, preserve other runs and do not stop the shared profile or prune global resources. The harness collects JUnit and Nextcloud/database logs. Real integration covers native permissions, mount behavior, version APIs, transactions and upgrade state that mocks cannot establish.

## Limits of the evidence

Frontend tests live under [`tests/Frontend`](../../mcp/tests/Frontend). Package-dependent checks need the built archive via `MCP_PACKAGE`; otherwise they can skip. See [Packaging](packaging.md) for the build and verification steps before interpreting those results.

No single green command proves compatibility on every backend or client. Keep exclusions, unavailable provisions, skips and known environmental failures visible. Source/API contract checks, golden output, ordered effects and real endpoint tests support distinct claims; report only what the executed checks establish.
