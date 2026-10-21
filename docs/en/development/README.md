<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Development guide

MCP for Nextcloud is a native Nextcloud app. Start with [setup](setup.md), then
run the commands in [testing](testing.md). Code, identifiers, comments and technical
source documentation use English; user-facing messages use Nextcloud localization.

| Task | Reference |
| --- | --- |
| Understand ownership and dependencies | [Architecture](architecture-specification.md) |
| Add or change an MCP capability | [Adding a tool](adding-a-tool.md) |
| Change administration or personal settings | [Administration JavaScript](admin-javascript.md) |
| Understand analyzer scope and host doubles | [Static analysis](static-analysis.md) |
| Build the installable artifact | [Packaging](packaging.md) |
| Edit guides or translations | [Documentation](documentation.md), [translations](translations.md) |
| Change file visibility rules | [Exclusion and folder exposure contract](contracts/exclusion-and-folder-exposure.md) |

Use [operational guides](../guides/README.md) for installation and administration.
Keep test fixtures with their tests and preserve their attribution and source
history. Record candidate-specific validation, limitations and review requests in
the pull request; the maintained guides describe reproducible workflows.
