<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Documentation authoring

English is canonical. Keep one owner for each procedure: root and app READMEs are
entry points, operational guides describe tasks, references describe contracts,
and development guides describe contribution. Use English filenames and comments.
Preserve original copyright when extracting text; add contributor attribution for
changes. Keep legal notices at their existing repository and app locations.

Use relative links and meaningful headings, checking the fragment in the target
language. Operational pages link back to their operational index and may only link
locally to other packaged pages or app legal files. The full locale index and root
README additionally link to development guides. Keep important public anchors as
useful navigation, not empty redirect files. Share images under `docs/assets/`.

`docs/documentation.json` owns locales and the explicit operational package
allowlist. Generated package copies are build output, never another editable manual.
Keep durable usage, architecture and contribution guidance in the repository.
Campaign plans, host-specific provisioning notes, raw evidence and PR drafts belong
in external working records or the pull request, not in the maintained manual.
Keep executable test fixtures with their tests and retain their provenance.

Run `composer docs:check`, `composer docs:translations` and `composer test:docs`
from `mcp/` (or `composer --working-dir=mcp ...` at the root). They have fixed scopes,
ignore forwarded Composer arguments and are included in `composer check`.
Markdown lint checks reversed/empty links and undefined references (MD011/MD042/MD052);
parsed link, image, HTML, anchor and source-provenance checks run offline. Network
availability is not a deterministic quality gate. New non-ignored Markdown is checked.

The inputs/manifest live in `mcp/scripts/docs/sources.mjs`; parsing, repository
validation, translations and packaging each have a separate module in that folder.
Use `mcp/tests/documentation/*.test.mjs` for behavior tests, with invalid Markdown
stored as strings and temporary Git fixtures under ignored `mcp/build/`.

Development-only Markdown dependencies are locked. The KaTeX override to 0.18.2
fixes GHSA-238p-pmpm-9mq7 inherited through markdownlint's math extension; no math
renderer or Node dependency ships in the app. Reassess the override on dependency
updates rather than forcing an audit downgrade.
See [translation maintenance](translations.md) for provenance and human review.
