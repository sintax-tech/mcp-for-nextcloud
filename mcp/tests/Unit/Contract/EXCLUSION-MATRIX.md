<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Exclusion contract matrix (issue #4, A1)

These are local contracts using OCP doubles and an in-memory tree. They do not
certify Nextcloud core behavior, storage mounts, authentication sessions or event
delivery. A4 provides that evidence in the private Actions repository; its verdict is the last section of this file.

For byte comparisons, keep the request identical and remove its excluded target
between calls. This preserves caller-supplied paths in batch summaries while
proving that the response cannot distinguish exclusion from absence. Creating a
missing destination can succeed; an excluded destination always refuses with
`CommonMessages::notFound()` before any mutation.

| ID | Local executable evidence | Remaining evidence / owner |
| --- | --- | --- |
| V1 | `VisibilityContractTest::testV1ExcludedAndMissingToolsAreByteIdentical` compares complete serialized results for 23 tool inputs, including explicit image batches, versions, shares, Notes and folder reads. `testEveryFileTouchingToolHidesTaggedNodeDirectlyAndViaAncestralFolder` covers direct and inherited tags, listings and writes. `FilesSearchContentTest::testVisibilityGuardFiltersHiddenFileFromFtsAndNameSearch` covers name/full text filtering before snippets. | Real OCR/core extraction behavior is not certified by doubles. |
| V2 | `VisibilityContractTest::testV14RepeatedOperationsReflectTagAndMoveChanges` checks a new multilevel descendant without a direct tag. Existing create/mkdir/Notes contracts refuse creation inside an excluded folder without effects. | A4: actual externally created descendants and native core inheritance. |
| V3 | Repeated-operation contract plus `VisibilityGuardTest` move-in/move-out event tests, partial-mutation invalidation and `testV14TagEventsInvalidateTheSameGuard`. | A4: actual Files/WebDAV mutations and event delivery. |
| V4 | Existing owner-tree guard fixtures cover direct/immediate/high ancestors, multiple owner aliases and failed owner resolution. They are supporting contracts only. | A4: real local file and subfolder shares; no real coverage claimed here. |
| V5 | Mounted-path alias regression in `VisibilityGuardTest::testV13VisibilityCacheIsQualifiedByMountedPath`; existing external/team-folder fixtures check local tags. | A4: provision groupfolders and local files_external; prove narrow mounts and aliases. Gate remains unproven locally. |
| V6 | `VisibilityGuardTest::testFederatedShareStaysVisibleWithoutLocalOwnerFolder` and `testFederatedShareStillHonorsLocalHiddenAncestor`. Remote owner tags are never inferred from a cloud ID; only locally visible tags apply. | Remote server exclusion configuration is outside this connector's authority. |
| V7 | `testV7HiddenDestinationAndResolutionFailureUseGenericRefusal`, existing Files copy/move/batch/create/mkdir/undo, Notes and version tests; `FileBackupPrivacyTest` verifies tag assignment before backup content and no content on tag failure. | D7 folder classification, zero enumeration and folder-share URL suppression belong to A3. Actual core writes/events belong to A4. |
| V8 | `ResourceRegistryTest::testV8ExcludedAndMissingResourcesAreByteIdentical` compares file/folder/Notes failures; existing list/templates tests expose no user items. Both protocol eras retain existing hidden-resource tests. | None for the local resource contract. |
| V9 | Direct attach/quote refusals (plan and confirm) remain in `VisibilityContractTest` and Talk tests. `StructuredReferenceContractTest::testV9*` proves `talk_read_messages` reports `attachmentId: null` for a room share of a file excluded directly or by an ancestor, of a missing share and of a file only outside the Files scope (scope double), with the message text and type unchanged and excluded byte-identical to missing; without exclusion or scope the id passes as before. `OutputReferenceFilterTest` covers share of another conversation, non-room share and unreadable files. | A4: real room shares (phase 1, tag). B5 wires the real Files scope into the filter and into the attach/quote refusals; B8 proves phase 2 for real. |
| V10 | `StructuredReferenceContractTest::testV10*`: `tasks_read_task`, plans without confirm of edit/complete/delete and confirmed edit/complete results show only allowed or external ATTACH, for a tag exclusion and for a file only outside the scope; `tasks_list_tasks` carries no raw calendar; before/after lose the same ATTACH so the plan shows no removal; the DAV object read back after an edit or a completion keeps every ATTACH. `OutputReferenceFilterTest` covers each supported local URL shape (/f/{id}, /apps/files/files/{id}, ?fileid=, /s/{token}, /remote.php/dav/files/{uid}/…, /remote.php/webdav/…, web root, /index.php, relative, wildcard trusted domains, ?dir=), the same shapes on any host (loopback, IP, alias under another web root; a node of the user refused there is removed; a node the user lacks, a shape naming no checkable node or a path inside a share unknown to this server stays), unresolvable local URLs removed, external URLs without a Nextcloud shape and inline ATTACH kept, a public folder link removed while an exclusion is configured or unreadable (D7), the Calendar app's `X-NC-FILE-ID` (fail-closed) and `FILENAME` parameters (refused → ATTACH removed, absent from the tree → parameter dropped), every alias of an id having to pass, nested VALARM, invalid configuration and that the filter holds no HTTP client. `testA6*` repeat the folder link, the hosts and the Calendar parameters through `tasks_read_task`. `EventAttachmentOutputTest` proves no Calendar tool (every one, plan and confirmed, plus an error) carries ATTACH or a file URL, and that an update keeps the event ATTACH. `CardFormatterTest::testCardReportsOnlyTheAttachmentCount` covers Deck. | Real CalDAV objects and real trusted_domains are exercised by A4. |
| V11 | `LogsModuleTest` covers hidden messages, DAV URL tails, user agents and the disabled switch; `LogRedactorTest` covers field sanitization. | Free text with hiding disabled keeps its documented behavior. |
| V12 | V1/V8 comparisons; `CheckoutControllerTest::testV1CheckoutExcludedAndMissingAreByteIdentical` compares HTTP status and serialized body for download and upload. Existing `ToolRegistryTest` unexpected-failure tests assert generic client errors and class/code-only app logs. | D7 refusal is A3. Missing and excluded checkout targets use the common resource refusal; invalid credentials retain their existing refusals. |
| V13 | New guard tests cover unreadable config with retry, invalid/cyclic ancestry including backup tagging, excluded root preflight, late metadata failure without a positive cache entry, and mounted alias cache isolation. Existing tests cover mapper retry, malformed configuration and failed settings saves preserving prior protection. | Native core resolution and storage failures are additionally exercised by A4. |
| V14 | `testV14RepeatedOperationsReflectTagAndMoveChanges` models request boundaries with cache invalidation; the event test exercises assignment/unassignment on the same guard instance. No credential is revoked. | A4: actual existing Bearer and Basic sessions, tag changes and moves across requests. Local tests do not claim authentication-session certification. |

A2 adds `OutputReferenceFilter` with a Files scope step (`FileReferenceScope`), empty until B5, which registers
`FilesReferenceScope`, the user's Files resource scope of the operation. It changes no schema: `attachmentId` was already nullable in
`talk_read_messages`, and a task's `icalendar` stays a string. With no exclusion and no scope nothing is resolved
and every reference passes untouched.

A1 changes no tool response shape, schema or argument list. It standardizes missing
image-batch reasons and excluded destination messages with existing translated
`CommonMessages::notFound()` and refuses unresolved visibility checks. No new
translation keys are introduced.

A4 must also include real positive controls: an unexcluded file in the user's
home, an internal share, a groupfolder and local external storage must remain
visible. The guard requires ancestry terminating in `IRootFolder`; these controls
must fail CI if a real mount violates that assumption and hides every file.

The implemented A3 gate, its D6 precedence, URL nullability and translation fallback
are specified in [the D7 contract map](FOLDER-EXPOSURE.md) and `FolderExposureTest`.

## A4 verdict: real-server evidence (issue #4)

Run [38783918353](https://github.com/sintax-tech/mcp-for-nextcloud-dev/actions/runs/38783918353) of the private `dev`
repository, commit c51af34, is green in all twelve cells: NC 32, 33, 34 and 35 on MariaDB, NC 35 also on PostgreSQL and
SQLite, each fresh and upgraded from v1.0.8 (86 tests per cell, 3 skipped only where the PostgreSQL-only smoke tests do not
apply). The scenarios live in `tests/Integration/Exclusion/` and are described in `tests/Integration/README.md`. The tools
are called over HTTP with a real app password (Basic) and a real OAuth access token (Bearer); files change through WebDAV
and the server's Files API; groupfolders, three local external mounts, Talk and Notes are provisioned in every cell.

| ID | Proven for real | Test |
| --- | --- | --- |
| V2 | A file created from outside the tools (WebDAV, three folder levels; Files API, five levels) inside an excluded folder is hidden without a tag of its own; a file created through the tools is hidden once an ancestor is tagged. Home, groupfolder, external storage and its alias. Tree, list and name search leave it out. | `InheritanceTest` |
| V3 | WebDAV moves into and out of an excluded folder, of a file and of a whole folder, and a tagged folder moved elsewhere, are reflected by the next call; a tool move followed by a WebDAV move too; one guard instance follows real NodeRenamed and tag events inside one request. Home, groupfolder, external storage. | `MovesTest` |
| V4 | A real share of a file or of a subfolder to another user is judged by the owner's chain: tag on the file, on the immediate parent, on a high ancestor, on the shared folder, on a subfolder or file inside it; visible again once the tag is removed. | `SharedOriginTest` |
| V5 | Groupfolder: tag on the mount root and on a subfolder; a subfolder shared out of the groupfolder stays protected by its ancestors. Local external storage: a tag inside it hides the file through the wide mount and through a second mount of the same directory (alias); a tag on the mount root hides both; a narrow mount honors tags inside it and on its own root. | `MountsTest` |
| V7 | Create, mkdir (also with intermediate folders), upload, copy, move, batch (also with a folder to create), undo, version restore, Notes create and move with an excluded source or destination are refused, the plan included, and the real tree shows no effect. D7 against the real share manager, copy and move: folder share and link refused with zero shares created; file share works; folder copy refused and file copy works; folder move to another directory and a batch with it refused; rename in the same parent allowed; link of a folder listed with `url: null`, of a file with its url, nothing revoked; without an exclusion folders behave as before; an exclusion set between plan and confirmation stops the copy. | `WritesTest` |
| V9, phase 1 | A real Talk conversation with a real room share: `attachmentId` is withheld for every participant when the file is excluded by its own tag or by an ancestor and returns when the tag is removed; attaching or quoting an excluded file reads like one that does not exist and posts nothing. **Phase 2 (file only outside the Files scope, no tag) is not claimed here: B8.** | `TalkReferenceTest` |
| V14 | One Basic login and one Bearer login, each reused for every call of the scenario: a tag assigned or removed, the tag leaving and returning to the exclusion list, a WebDAV move, a tagged ancestor, always reflected by the next call; the login is never revoked. | `SessionsTest` |
| Positive controls (A1 review F4) | With an exclusion configured and nothing tagged, a plain file stays readable and listed in the home, in a groupfolder, in the external storage, its alias and the narrow mount, in a file and in a folder shared to another user, and in a groupfolder file shared to another user. | `PositiveControlsTest` |

**What the controls found.** The first runs failed these controls in every cell: with any tag configured, files in a local
external storage and files shared out of a groupfolder were refused. In a fresh process the folder above the root of any
mount came back with the id of the mounted storage instead of its own, so the guard's walk saw an id it could not use and
failed closed. The fix is in the guard (c51af34, "Resolve the folder above a mount root by its path"): the parent of a
mount root is resolved again by its path. The same scenarios, unchanged, turned green.

**Limits that stay.**

- A narrower mount of a subdirectory (here `/mcp-it-ext/Open` next to the mount of `/mcp-it-ext`) is a separate storage in
  Nextcloud with file ids of its own. A tag placed through the wide mount names other ids, so the file stays readable
  through the narrow mount (recorded in the cell's `observations.txt`, not asserted as expected). A tag has to be placed
  where the file is read; an administrator who mounts the same directory twice with different roots has two resources.
  Two mounts of the *same* directory share their ids and are protected together (alias).
- **Stale window of the exclusion list (measured, F5).** Core keeps the app config in the web server's local cache
  (APCu) for 3 seconds (`AppConfig::LOCAL_CACHE_TTL`, identical in NC 32, 33, 34 and 35) and drops it only for writes made
  through that same cache. A list written by another process (an `occ config:app:set` of `hidden_system_tags`) or by
  another web node is therefore served stale by a node that loaded its cache before the write, for at most the TTL. With
  `cache_app_config` on, `AppConfigCacheWindowTest` warmed the web server, wrote the list from the CLI and read at once:
  in every one of the eleven cells measured (NC 32 to 35, MariaDB, PostgreSQL, SQLite, fresh and upgraded) the first read
  still served the old list and the exclusion took effect after 2.66 s to 3.94 s (the test bounds it at 6 s). So the
  window exists, is bounded by the 3-second TTL plus the time of one request, and a tag *added* to the exclusion
  list may leave its files readable on a node for up to about 3 to 4 seconds. A write made through the web server's own
  cache (the admin screen on the same node) clears that node's cache at once. The rest of the suite runs with the cache
  off (`harness.sh provision`), because it writes the settings from the CLI and reads them back immediately.
  Whether that window is acceptable, or the guard should read the list without the local cache, is a product decision.
- V6 (federated shares) stays documental plus contract; V8, V10, V11, V12 and V13 are covered by the local contracts above
  and were not repeated against the real server here (V10 real CalDAV objects with `ATTACH` are not part of A4's list).
- Groupfolders are provisioned without advanced permissions (ACL rules); native groupfolder ACLs are not exercised.

## A4 review adjustments (post-review round)

After the review of the A4 proof, with the guard follow-up 83489c4 (backups and the folder gate resolve the real parent
of a mount root):

- **Whole-result comparison (F4).** Every "answers like a path that does not exist" check now compares the HTTP status and
  the whole `result` (content, `structuredContent`, `_meta`, `isError`), not only the text blocks (`ToolReply::fingerprint()`).
  Creating into a destination that does not exist, which the contract allows to succeed or to answer with its own hint, is
  compared with the plan of the same tool for a missing folder; the generic refusal prefix is asserted for the rest.
  Run 79a413b (12 cells, partly cancelled) passed every such comparison.
- **Edit, replace and restore inside mounts.** With an exclusion configured and nothing tagged, `files_edit` and
  `files_replace` work in a groupfolder, in external storage, in its alias and in the narrow mount, and leave the backup
  of the previous content (`MountsTest`, passed in run 79a413b). Version restore of a groupfolder file: the first
  attempt answered "Resource locked by another operation; try again" in 7 of the 11 cells that ran; the test now retries
  as a client would. Whether local external storage keeps versions was recorded only in a truncated observation, so version restore there is
  not claimed.
- **A share moved by its recipient into a tagged folder of their own** is hidden: proven for a folder share
  (`SharedOriginTest`, run 79a413b, every cell); the same test for a file share only failed on the test's own share
  permissions (a file share cannot carry create or delete) and was corrected.
- **A received folder moved into another received share** is refused without effect: the D7 gate stands in front of
  it (unit-tested) and core also refuses to rename a share mount point across storages. The connector itself no longer
  refuses cross-storage moves (`files_move`/`files_move_batch` allow them since the storage boundary was lifted, and the
  receipt reports the changed id); the test accepts either refusing answer and records which one came.

**Re-proven locally after the last edits (final status).** The GitHub Actions minutes ran out, so the whole suite was
run with `mcp/tests/Integration/ci/run-local.sh` (Colima on an Apple-silicon Mac, native arm64 images with the same pinned
digests), HEAD 5c9686c. Every cell below passed in full (96 tests, no failure):

| Cell | Result | Assertions | Skipped |
| --- | --- | --- | --- |
| NC 35, PostgreSQL, fresh | OK | 1226 | 1 |
| NC 35, PostgreSQL, upgrade | OK | 1226 | 1 |
| NC 35, MariaDB, fresh | OK | 1203 | 4 |
| NC 35, SQLite, fresh | OK | 1208 | 4 |
| NC 34, MariaDB, fresh | OK | 1203 | 4 |
| NC 33, MariaDB, fresh | OK | 1204 | 3 |
| NC 32, MariaDB, fresh | OK | 1204 | 3 |
| NC 32, MariaDB, upgrade | OK | 1204 | 3 |

The skips are the three PostgreSQL-only smoke tests (every cell but PostgreSQL) and, where marked below, the groupfolder
version restore. Not run in this pass: the upgrade mode of NC 33 and NC 34, and of NC 35 on MariaDB and SQLite.

What this adds to the real proof:

- The file share moved by its recipient into a tagged folder of their own is hidden (folder share and file share), and the
  received folder moved into another received share is refused without effect, in every cell above.
- A received share's mount point cannot be renamed or moved by `files_move`: core answers "Moving across different
  storages is not supported in this version", with or without an exclusion, so the D7 gate is not what answers there. A
  folder of the recipient's own is renamed in place with an exclusion configured.
- **Stale window of the exclusion list**, measured again with the cache on: 2.82 s to 3.86 s in these seven cells, on top of
  the 2.66 s to 3.94 s of the GitHub run (the core TTL is 3 s).
- **Version restore of a groupfolder file** (not an exclusion matter, diagnosed with a temporary diagnostic test): it was
  refused with "Resource locked by another operation; try again" where the `files_lock` app is installed (the NC 34 and
  NC 35 images; not NC 32 and NC 33), also with the released v1.0.8 code and with no exclusion configured. There the tool
  wrote the version over the file with `putContent` while the version stream it had read was still open
  (`VersionTools::writeVersion`), and the lock the write needs cannot be taken while that stream is open. Fixed by
  copying the version first and closing its stream before the write; `MountsTest` now restores a groupfolder version at the
  first attempt on every cell, with no skip. Without `files_lock` the tool calls the version manager's `rollback`, which
  always worked.
