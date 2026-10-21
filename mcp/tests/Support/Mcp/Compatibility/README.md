<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Shared MCP contract captures

Captured from unchanged production at `2f80fc8` on PHP 8.4.26. All identities,
content and failures are synthetic. `SharedMcpMigrationCompatibilityTest` compares
complete bytes; it never writes snapshots. English, Brazilian Portuguese and
Spanish publication includes all nine catalogs, built-ins, guides, resources and
prompts under default, denied, full and alternative link grants. Protocol captures
include both eras and malformed envelopes; results include read, preview, write,
changed-plan and failure envelopes. JSON object/array distinctions are retained.
Invalid UTF-8 is asserted in PHP rather than normalized in input fixtures.

The dynamic tests and original ToolRegistry, protocol, PlanState, SharedWriteGuard,
VisibilityGuard and LockAwareWrite suites additionally exercise audit failure,
role/app changes, confirmations, ETags, native events, partial tag mutations,
share aliases, backup privacy and lock rechecks. Their original expanded cases
are reconciled in the execution report. These existing security tests remain
mandatory gates; snapshots do not replace them.

Do not regenerate these files after extraction. The Notes migration separately
approved two internal exception identities in its older captures.

The 2026-10-17 local closure corrects one public Deck guide sentence in six
`publication.json` entries (English guide text under the three locales and the two
applicable grant contexts). It now describes the already-supported movement between
lists and boards. Only those seven string values across `publication.json` and the older
`tests/fixtures/deck-definitions.json` capture were edited by hand; schemas, plans,
protocol and result captures are unchanged. The guide contract first failed on the
revised expectation; both complete contract comparisons pass after the correction.
The fixture changes and their source revisions remain traceable in Git history.

The 2026-10-20 upstream 1.1.1 integration advances only the 29
`io.modelcontextprotocol/serverInfo.version` values in `protocol.json` from
`1.1.0` to `1.1.1`, matching the new manifest and protocol metadata. Every other
fixture byte remains unchanged. The full frozen-contract test rejected the old
values before this explicit release-version update; the focused protocol and
shared-contract suite then passed 24 tests / 143 assertions. The captures were
not regenerated or normalized.

The 2026-10-21 upstream 1.1.2 integration advances the same 29 server-version
values from `1.1.1` to `1.1.2`. No other bytes in the four compatibility captures
change. Guests restrictions are exercised by behavioral unit and native tests,
not by regenerating the non-guest captures.
