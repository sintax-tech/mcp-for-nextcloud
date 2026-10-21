<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Static analysis

Install the committed development dependencies with Composer >=2.8. From the
repository root run:

```sh
composer --working-dir=mcp analyse
```

`analyse:lib` checks all `mcp/lib/` at PHPStan level 0, including new files.
`analyse:core` checks the explicit paths in [core.neon](../../../mcp/phpstan/core.neon)
at level 5: tool contracts, metadata/schema, validation/results, exception summaries
and timezone lookup. It also covers `DecimalCounter`, `PolicyLimits`,
`PolicyEventPayload`, the grant/eligibility/effective result values, `ScopeResolution`,
`ScopeActivation` and `CredentialGeneration`. Both gates must pass. This does not claim level-5 coverage of
all production code, nor analysis of test bodies, scripts or templates.

The target language version is PHP 8.2 regardless of the local interpreter.
`php -l` still uses the installed interpreter; CI runs syntax checks on PHP 8.2.
Composer commands explicitly select committed configurations and discard additional
arguments: `analyse:core -- --level 0` still runs level 5. For temporary diagnostics,
invoke PHPStan directly with a disposable config; that is not a verification result
for the canonical gates. Do not commit a local override or suppression baseline.

## Understand a diagnostic before changing code

Classify missing host symbols separately from source defects and inaccurate PHPDoc.
Correct annotations from actual callers and tests, including invalid inputs a validator
is responsible for rejecting. Preserve defensive checks. Do not add casts, assertions,
inline ignores, permissive fake methods, excluded paths or reduced levels to get green.
A genuine behavior defect needs a separately reviewed correction, with a failing
behavior test. Keep the gate failing until it is resolved.

PHPStan is development-only and pinned by `mcp/composer.lock`. Analysis needs no live
Nextcloud, credentials or network after dependencies are installed. The two caches
live under `mcp/.cache/phpstan/`; they are disposable and excluded from Git/packages.
Analysis workers are limited to two, each with a 1G memory limit.

## Host reference and maintenance

OCP declarations are discovered from the installed stable33 reference. Missing
DAV/Deck classes are modeled by [analysis-only declarations](../../../mcp/phpstan/README.md)
with upstream revisions and hashes. They are scanned, never executed/autoloaded;
PHPUnit doubles do not define the analysis environment. Never replace the actual
host implementation with these files.

When updating the host reference, reconcile signatures with upstream source and run
`composer --working-dir=mcp test:api` as well as analysis. API snapshots cover the
supported Nextcloud versions and retained compatibility references; stable33 alone
cannot prove Nextcloud 32–35 compatibility or dynamic Talk calls. Real-host checks
remain necessary. Expanding level 5 and introducing stronger levels are separate
changes; preserve existing coverage and record added scope explicitly.
