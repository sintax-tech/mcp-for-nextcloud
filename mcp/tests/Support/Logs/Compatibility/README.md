<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Logs compatibility fixtures

Captured from unchanged production at `57bec7b`, before the Logs migration, using
LogsMigrationCompatibilityTest::capture and the synthetic nextcloud-log fixture.
Do not regenerate these files to accommodate implementation changes.

Definitions freeze raw/public metadata, full guide and module order by short class
name. Results freeze visible/hidden list and analysis, filtered/empty windows,
paging and safe failures in English, Brazilian Portuguese and Spanish. Audit freezes
event parameters, app records and ordering for success, denial, invalid and read_error.
No output fields are normalized. Invalid UTF-8 direct filters are asserted separately
in PHP because JSON capture cannot preserve their original invalid bytes.

All 77 original Logs methods remain required. Existing scanner, redactor and analyzer
suites cover growing/out-of-order windows, malformed rows, budgets, IPv6, hostile
labels, route folding and signature ties. Added contract tests cover state changes,
audit failures, exception/validation boundaries, encoding and publication stability.
