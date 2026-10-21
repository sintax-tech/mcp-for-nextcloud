<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# DAV migration compatibility fixtures

`definitions.json` was captured before extraction from commit `ddfa06c`.
`plans.json` was captured from an isolated archive of that same commit during
final verification, never from the replacement implementation. The independent
baseline and migrated captures compared byte for byte before the fixture was added.

Plans cover all twelve writes plus their exact English Markdown and four
representative result/error envelopes. They use the fixed Calendar, Contacts and
Tasks feature fixtures. Only the random UID of a newly created contact is replaced
with `<generated-contact-uid>`, consistently across its fields and vCard bytes.
Calendar and Tasks creation plans already omit provisional identity. No timestamps,
ETags, messages, field order or other payload values are normalized.

Detailed feature tests retain multilingual, privacy, failure precedence, partial
write, acknowledgement and non-mutation assertions. Do not regenerate these
fixtures from changed implementation just to make a mismatch pass.
