<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Packaging

The package is the installable `mcp` app, not a copy of the entire repository. Building and checking an archive does not sign, publish or install it.

## Package inputs and exclusions

[`package.sh`](../../mcp/scripts/package.sh) stages `appinfo`, `lib`, templates, the two technical READMEs, the root `CHANGELOG.md`, legal documents, Composer metadata and runtime asset directories. It installs production dependencies from the lock file into the stage and removes that staged lock file before archiving. The archive has one top-level `mcp/` directory.

The root READMEs, these `docs/en` guides, tests, development scripts, authored `src/`, development dependencies, credentials, logs and user data are not package inputs. Do not copy them into the app to repair a documentation link. The packaged technical READMEs link to the development guides by explicit repository URLs; the repository's current documentation may describe a newer tree than an older installed release.

Production `vendor/` is checked against an explicit allowlist; the local development `vendor/` is not copied wholesale. PHP source and UI bundle files must already be tracked. Check ignored source files as described in [Testing](testing.md#prerequisites).

## Build committed assets

Authored JavaScript lives under `mcp/src/`. [`build_assets.php`](../../mcp/scripts/build_assets.php) uses the checked-in asset manifests and builder classes under `mcp/scripts/`; it does not infer an arbitrary bundle from whichever files happen to be present. The generated `mcp/js/` bundles are committed. The package step refreshes them and the verifier checks the archived bytes against the source build.

From the repository root, a deliberate asset rebuild is:

```sh
php mcp/scripts/build_assets.php
```

Review and commit an intended bundle delta with its source change before final evidence. Documentation-only work should not introduce a bundle delta. The verifier also rejects orphan source scripts and missing required manifests.

## Build and verify the archive

Requires PHP, Composer and tar. From the repository root:

```sh
sh mcp/scripts/package.sh
```

The script reads the version from `mcp/appinfo/info.xml`, writes `mcp/build/mcp-<version>.tar.gz` and invokes the package verifier itself. To inspect that archive again, run from `mcp/` and substitute the actual version:

```sh
php scripts/check_package.php build/mcp-VERSION.tar.gz templates lib
```

[`check_package.php`](../../mcp/scripts/check_package.php) refuses extra top-level entries, development dependencies, missing legal files, development-only entries, duplicate file members, source JavaScript and missing or mismatched runtime assets. It checks the scripts/styles referenced by PHP and the required manifests. Its own tar reader detects AppleDouble, `.DS_Store`, pax/xattr metadata and truncated/corrupt archives that a platform tar listing can hide. `package.sh` deletes the archive when verification fails.

The gate does not check Markdown links or replace installation testing. Inspect the packaged READMEs separately; root-relative development-guide links would not resolve inside an installed archive.

When running frontend package checks, provide the generated archive. From the repository root, replacing `VERSION`:

```sh
MCP_PACKAGE="$PWD/mcp/build/mcp-VERSION.tar.gz" node --test mcp/tests/Frontend/*.test.cjs
```

Record skips as well as failures. Package-dependent tests without `MCP_PACKAGE` are not equivalent evidence.

## Licenses and attribution

Preserve `LICENSE`, `NOTICE`, `THIRD-PARTY-NOTICES.md`, the license texts under `LICENSES/` and the bundled dependency notices. The app is AGPL-3.0-or-later with the additional attribution term in NOTICE; architecture credits do not replace that term or change dependencies' licenses.

New repository documentation uses SPDX headers and the explicit Markdown annotation in [`REUSE.toml`](../../REUSE.toml). If the REUSE tool is installed, run `reuse lint` from the repository root; CI also has a REUSE check in [package.yml](../../.github/workflows/package.yml). Do not install tools or alter licenses merely to suppress a diagnostic without reviewing its cause.

## Installation and upgrade boundaries

A valid archive is not proof of a successful installation, migration or upgrade. Use the disposable [integration harness](../../mcp/tests/Integration/README.md) for those checks. The [user README](../../README.md) describes supported installation and incident recovery procedures. Internal path changes do not justify a code-only downgrade over a newer database or a restore from mismatched backups.

## Release handoff

Release preparation sets the final version/date, consolidates the CHANGELOG, updates the user and technical READMEs and aligns store summaries/descriptions in all supported languages before the final build. Final notes, signing, tags, assets, pushes and store publication require their own release authorization. Keep signing material out of the repository and build logs.

Save the commit, package path, hash, verifier output and relevant integration evidence for that final build. A verification-only CI workflow or a locally produced tar is not an automatic publication step.
