<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Start with the [development setup](docs/en/development/setup.md), then use the
[testing guide](docs/en/development/testing.md) for focused checks and full verification.
The [architecture specification](docs/en/development/architecture-specification.md)
and [tool-authoring guide](docs/en/development/adding-a-tool.md) explain ownership,
registration and the contracts that changes must preserve.

Write code, identifiers, comments, PHPDoc and canonical documentation in English.
Preserve localized output and meaningful multilingual fixtures. Update English
guides with the change; reviewed translations can follow independently. Link only
to translations that exist and label English fallbacks explicitly.

Run `composer --working-dir=mcp check` before submitting a change. This includes
syntax, both static-analysis scopes, all tests and a verified production package.
REUSE compliance and the supported PHP matrix also run in CI. Keep dependency
updates deliberate and preserve original attribution. Do not weaken a failing
check or regenerate compatibility captures merely to make a refactor pass.

New tooling requires no devbox installation. Environment-specific task catalogs
may delegate to these Composer commands; keep their metadata outside the repository.

See [documentation authoring](docs/en/development/documentation.md) and
[translation maintenance](docs/en/development/translations.md) for documentation contributions.
