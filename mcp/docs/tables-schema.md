# Tables structure and sharing

Tables structure writes use the independent `tables.schema` grant. Sharing and revocation use `tables.share`. Both are denied by default. Row Read/Full presets do not grant them, and existing explicit grants remain independent. No administration layout or Forms warning is changed by this feature.

The six existing Tables tools keep their behavior and order. Eight tools are added: `tables_create_table`, `tables_update_table`, `tables_create_column`, `tables_update_column`, `tables_create_view`, `tables_update_view`, `tables_share_node`, and `tables_unshare_node`.

Every new write first returns a complete plan. Show that plan and obtain explicit agreement before repeating the same intent with `confirm: true` and its `plan_state`. A table owned by another user also requires `confirm_shared: true`. The value is stateless; no approval is stored. Grants, the authenticated session, native read/manage permission, parent bindings, application availability and API compatibility are checked again. A changed plan does not write.

## Supported native APIs

Structure/sharing compatibility is separate from the existing reading and row-writing compatibility. The initial evidence-bounded ranges are Tables 1.0.10 up to but excluding 1.0.11 on Nextcloud 32; 2.2.2 up to but excluding 2.2.3 on Nextcloud 33–35; and 2.3.1 up to but excluding 2.3.3 on Nextcloud 33–35. Introspection must also match every consumed native signature and field. There is no promise of structure writing on every Tables 1.0.0+ installation.

Official stable tags 1.0.0–1.0.10, 2.0.0–2.0.1, 2.1.0–2.1.1, 2.2.0–2.2.2, 2.3.0–2.3.2 and 2.4.0 were compared by significant-token method signatures and bodies. The consumed API of 2.3.1 matches the tested source pin 2.3.2. The other intermediate releases differ; 2.4.0 is not enabled. No stable 1.0.11 or 2.3.3 tag existed in the queried list. Version ranges are extended only with evidence. The 1.0.0 array-view/positional-share and 2.0.0 positional-share adapters are retained for source contracts but remain disabled pending supplementary integration proof.

View input is an array in 1.0.0 and a DTO from 1.0.10. Empty filter/sort/column lists must construct actual empty DTO collections; the native helper would drop them. The share API changes from positional to DTO in 1.0.10, back to positional with extras in 2.0.0, and to DTO again in 2.2.2. Those details stay inside Integration.

## Structure constraints

Only the `custom` table template is used. Creation makes one entity, with no hidden schema import, extra rows or nested creation. Existing resources require native manage, independently of the MCP read grant. Federated resources are refused. There is no deletion of tables, columns or views, owner transfer, public link, password or context operation.

Column types are text, number, selection, datetime and usergroup. Type/subtype changes and editing an existing relation column are refused. Options are a closed list of unique `{name,value}` entries, validated against the type. Native technical names, UUIDs and opaque settings are never accepted from the client. Existing native settings are preserved internally.

Column updates fuse the fresh snapshot with the requested patch before validating the complete DTO. Fields omitted by the caller are written again because native setters would otherwise clear them. This also means a concurrent native edit can be overwritten between validation and the native update; there is no cross-service CAS guarantee. Selection IDs/order are preserved; removal/reindexing is refused. Native option UUIDs remain server-managed. Team defaults require Circles enabled for the caller and the same actor-bound CircleHelper::circleExists check used by the native row validator; unproved older families stay closed. Existing native options are preserved without applying the client-input domain again. Native text-regex interpretation is not proven by the pinned sources, so changing that field on ordinary text columns is refused. Link provider settings use the proven comma-separated `url`, `files`, `contacts` format, bounded to 512 bytes. No client pattern is run against rows.

View patches replace the explicit complete columns/filter/sort lists. Rules within a group use AND; groups use OR. Values and operators use the existing pure filter domain, not a row-writing helper. IDs must belong to the authorized table/view. Relation references can only be preserved without editing; relation predicates and sort lists cannot be rewritten. Where the native DTO drops description `""` or `"0"`, preparation refuses it. The 2.3.2 source family supports description `"0"`. Changing a view can hide rows without deleting them.

## Sharing constraints

Only exact existing user/group IDs are accepted. Native manage permits delegating any explicit combination of read/create/update/delete/manage (bits 1/2/4/8/16). Manage and delete give recipients those capabilities in the native Tables UI, even though MCP exposes no schema deletion tool.

MCP additionally enforces disabled sharing, recipient existence, group sharing and group-member restrictions, including native excluded-group exceptions. An identical share is a no-op. Different permissions require separate confirmed revocation and creation; no implicit permission update occurs. Revocation binds the share ID to the exact node and permits only user/group shares. Public tokens, URLs, passwords and native UUIDs are never returned.

## Receipts and evidence

Every attempted mutation is followed by fresh authorized observation, including when the native call throws. New mapper/permission-reader instances prevent a previously cached entity from being used as evidence. Receipts distinguish observed requested state, a different or possibly partial state, and an unverified state. An existing share after failed revocation is not reported as revoked. Losing access during readback yields no stale private metadata.

There is no retry, automatic rollback, compensating SQL, backup or guaranteed undo. Repeating a creation can duplicate it. Native services remain the only mutation path.

`tests/fixtures/nextcloud-api/tables/schema-api.json` records official source-archive URLs/SHA256, per-file SHA256, consumed method fingerprints and the original DTO/model sources used by isolated focal tests. Re-extract with `php tests/fixtures/nextcloud-api/tables/generate-schema-api.php /path/to/pinned-archives`; downloads are an explicit developer step using the manifest URLs. No network access occurs during tests or discovery. Source models and unit doubles do not replace integration cells, which run in the release batch.
