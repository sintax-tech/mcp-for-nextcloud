<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Notes migration compatibility fixtures

Captured before production extraction from `d51e4f4` (production Notes source is
identical to planning baseline `c4f31fe`). These files are reviewed compatibility
contracts; do not regenerate them from a replacement implementation to fix a test.

- `definitions.json`: seven raw definitions, published definitions and generated guide.
- `plans.json`: all four writes in English and Brazilian Portuguese, including
  missing categories, shared ownership and active editor locks, plus saved-plan text.
- `results.json`: seven direct results, client-safe errors, partial edit and resource
  envelope. The shared filesystem fixture generates modification timestamps on
  mutation; only `modified` is normalized to `1700000000`. IDs/ETags are deterministic.

Existing detailed privacy, category, rename, lock, language and resource cases remain
active alongside the snapshots. `NotesMigrationCompatibilityTest` compares against
these committed files and asserts that previews leave the complete tree unchanged.

During shared MCP Phase 1 (execution baseline `2f80fc8`), only `/missing/type`
and `/too_large/type` in results.json were updated to the final ToolFailure PHP
namespace. Original capture: `d51e4f4`. No behavioral value was recaptured; the
comparison remains exact. The execution report records hashes and the phase commit.
