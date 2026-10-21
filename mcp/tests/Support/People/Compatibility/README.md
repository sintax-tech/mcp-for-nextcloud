<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# People compatibility fixtures

Captured before extraction at `43c00d0` (production matches planning baseline
`9afd64c`). Do not regenerate these contracts from the replacement to pass a test.

- `definitions.json`: exact raw/published definition, generated guide, and module
  order by short class name, allowing namespace migration without changing order.
- `results.json`: user-only, mixed exact/partial, capped and empty results, invalid
  UTF-8 substitution and English/Portuguese/Spanish native-failure envelopes.

All data is synthetic and deterministic; no result field is normalized. The native
has-more flag is deliberately ignored as before. Detailed boundary tests complement
these snapshots, and each test resets Translator after execution.
