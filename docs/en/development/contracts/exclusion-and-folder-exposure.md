<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Exclusions, references and folder exposure

This contract map retains the behavior documented by upstream's
`tests/Unit/Contract/EXCLUSION-MATRIX.md` and `FOLDER-EXPOSURE.md` at
`0911f9123a661968e707fc4c391adcf9d37c3e84`, with the current capability owners.
Keep the tests and this map aligned when changing visibility or file operations.

## Exclusion and reference contracts

The following class names identify executable tests under `mcp/tests/`. Doubles
establish local contracts; native Integration tests exercise host behavior for
an explicitly recorded candidate and environment. See [testing](../testing.md)
for the separate commands and evidence limits.

| Contract | Final executable owners | Boundary |
| --- | --- | --- |
| V1: excluded/missing response equality | `Unit/Contract/VisibilityContractTest`, `Modules/Files/Search/FilesSearchContentTest` | Whole serialized tool results, 23 inputs, direct/inherited tags; no extraction-engine certification. |
| V2: new descendants | `VisibilityContractTest`, native `Exclusion/InheritanceTest` | Multilevel files created through WebDAV/Files API/tools, including mounts. |
| V3: mutation and events | `Access/Visibility/VisibilityGuardTest`, native `Exclusion/MovesTest` | Move in/out, partial mutations and same-request tag/node invalidation. |
| V4: local share origin | Guard contracts, native `Exclusion/SharedOriginTest` | File/subfolder shares follow the owner's ancestor chain; recipient-side excluded folders also apply. |
| V5: mount aliases | `VisibilityGuardMountBoundaryTest`, native `Exclusion/MountsTest` and `PositiveControlsTest` | Home, groupfolders and local external mounts remain usable when allowed; distinct storage IDs do not inherit each other's tags. |
| V6: federation | `VisibilityGuardTest` federated-share cases | Only local tags apply; remote owner configuration and production federation remain unqualified. |
| V7: writes and backup privacy | `VisibilityContractTest`, `FileBackupPrivacyTest`, `FolderExposureTest`, native `Exclusion/WritesTest` | Generic refusals precede mutation; backup tagging precedes content. |
| V8: resources | `Mcp/Resources/ResourceRegistryTest::testV8ExcludedAndMissingResourcesAreByteIdentical` plus both protocol eras | Files, folders and Notes; lists/templates disclose no user items. |
| V9: Talk attachments | `StructuredReferenceContractTest`, `OutputReferenceFilterTest`, native `TalkReferenceTest` and scope consumers | Hide inaccessible attachment IDs, preserve free text/type; excluded and missing are byte-identical. Attach/quote refuses without posting. |
| V10: DAV and structured references | `StructuredReferenceContractTest`, `OutputReferenceFilterTest`, `Modules/Calendar/EventAttachmentOutputTest`, Deck `CardFormatterTest` | Tasks filters protected ATTACH from output while retaining DAV content; every Calendar tool's plan/result/error omits raw ATTACH; Deck exposes counts only. |
| V11: logs | Logs module/privacy contracts | Redact messages, DAV tails and user agents under the documented switch; free text with hiding disabled is unchanged. |
| V12: generic failures | V1/V8 and `CheckoutControllerTest::testV1CheckoutExcludedAndMissingAreByteIdentical` | Whole HTTP status/body, generic MCP unexpected errors and class/code-only diagnostics. |
| V13: failures and aliases | `VisibilityGuardTest`, `TagsControllerTest` | Unreadable config, invalid ancestry, mapper/metadata failures and mounted-path-qualified cache; no valid empty selection after a failed read. |
| V14: freshness | Repeated-operation contracts, native `Exclusion/SessionsTest` and `AppConfigCacheWindowTest` | Reused Basic/Bearer credentials observe changes; independent native appconfig caches retain their measured TTL window. |

Files scope is enforced by `Access/ResourceScope/FilesReferenceScope` through
the `Platform/FileAccess/References/FileReferenceScope` contract and all
Files/Calendar/Tasks consumers. There is no empty production scope adapter or deferred
activation stage. The filter has no HTTP client. Local/foreign Nextcloud-shaped
URLs, aliases, public folder links, Calendar file-ID/filename parameters and nested
alarms retain their explicit filter contracts; ordinary external URLs and free
text are not a general data-loss-prevention service. Runtime catalogs include
de/es/pt_BR with English source fallback.

## D7: folder exposure

After account/operation admission and resource scope, the order is **node
visibility (D6), folder-exposure gate (D7), recursive helpers, mutation**. Hidden
nodes or unreadable/invalid exclusions receive a generic missing-resource refusal.
A visible folder with exclusions configured receives the folder-specific refusal
before subtree enumeration. The refusal directs users to the native Files UI.

| Operation | Final gate owner | Work protected |
| --- | --- | --- |
| Copy plan/confirm | `Modules/Files/Copying` and `Modules/Files/Access/FolderExposureGate` | Measuring, destination inspection and copy. |
| Move plan/confirm | `Modules/Files/Moving` and `Modules/Files/Access/FolderExposureGate` | Shared notices, descendant lock inspection and write. |
| Batch plan/confirm | `Modules/Files/Batches` | Whole-batch folder check before per-item locks, directory planning/creation or mutation. |
| Undo plan/confirm | `Modules/Files/Undo` | Batch identity/lifetime checks first, then gate before conflicts, empty-folder scans or recreation; recheck per item before writing. |
| Share/link creation/update | `Modules/Files/Sharing/ShareWriter` | Node visibility precedes policy getters and every share-manager query/write. |
| Share listing | `Modules/Files/Sharing/ShareLister` and `ShareFormatter` | Folder links use `url: null` without reading a token; visible file links retain URLs; non-link shares omit the URL key. |

A same-parent rename requires matching positive canonical IDs of actual parents;
an unresolved or planned virtual parent is insufficient. Allowed rename/batch
rename/undo still enforce descendant locks, including locks appearing after a plan.
Deleting a folder share remains possible. Listing does not revoke or modify shares.
`Modules/Files/Access/FolderExposureTest` instruments subtree listing, locks, policy
queries, share reads/writes and mutation, asserting zero forbidden downstream work.
Native `Exclusion/WritesTest` exercises the real share manager and Files operations.

Creation can succeed at a genuinely missing destination and fail at an excluded
one: a generic refusal does not eliminate that residual existence distinction.
Production federation, advanced groupfolder ACLs, real-client UX and operator
backup procedures remain separate gates. Passing unit contracts does not close those separate acceptance gates.
