<!--
SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Recipient API fixture provenance

`recipient-sources.json` pins official Deck release archives (the minimum supported tag for each historical fixture), the bundled Circles tags, and the core QBMapper source from each fixture's original server tag. URLs, versions and SHA256 values are recorded per major. Circles uses the official repository's tag archive because bundled Teams has no separate release asset for these tags. The historical Circles v31.0.0 tag identifies itself as 31.0.0-dev.0 in appinfo; that exact manifest is recorded and verified separately, without changing the tag or hash. NC31 remains a historical compatibility fixture; the supported integration matrix is NC32–35.

Fetch without executing PHP on the host:

```sh
python3 mcp/tests/fixtures/nextcloud-api/fetch-recipients.py .review/dk4r-api-sources
```

Inside the disposable integration container, through `run-local.sh`, run for each major 31–35:

```sh
php /mcp-it-src/mcp/tests/fixtures/nextcloud-api/generate-recipients.php 32 /mcp-it-src/.review/dk4r-api-sources /tmp/generated-recipient-fixtures
```

Create the output directory first. The optional fourth argument defaults to the fixture directory; the harness mounts the checkout read-only, so its diagnostic test writes to `/tmp` and collects the exact generated PHP through observations. The generator verifies all input hashes and app manifest versions, extracts into a disposable temporary tree, uses the existing `SourceParser`, preserves other surfaces and cleans the tree in `finally`. Repeating it produces identical files. No native class is loaded and no surface is handwritten.

Full `generate.php` also understands Circles in the supplied complete tree and verifies its manifest version. It keeps the original server/Deck/Talk source tuple and adds `recipientSources` provenance. Runtime absence of Circles with a circle ACL is an explicit refusal in `DeckRecipientDirectory`; absence without a circle ACL does not resolve optional Circles services.
