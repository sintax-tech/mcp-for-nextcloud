<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Integration suite

The complete suite runs on [GitHub Actions](../../../.github/workflows/integration.yml) during release validation. Local runs through `ci/run-local.sh` are only for explicitly scoped, filtered diagnostics (below); do not run unfiltered cells or the full matrix locally. See the [contribution guide](../../../CONTRIBUTING.md) and [validation policy](../../../docs/en/testing.md#validation-policy). The active 1.4.0 matrix covers NC34/MariaDB and NC35/MariaDB, PostgreSQL and SQLite, each fresh and upgrade (eight cells). Existing production support paths and the declared installation range are not new test coverage.

For either execution path, `ci/harness.sh` starts a disposable official Nextcloud container and database, pinned by digest, installs or
upgrades the app through the server and runs this suite inside the container with `phpunit.xml` here. Nothing is ever
installed on the host outside a container; `vendor/bin/phpunit` in `mcp/` runs the unit suite only, which excludes this
folder.

## Running a cell on a Mac (`ci/run-local.sh`)

Needs `brew install colima docker` (Colima, not Docker Desktop), plus the PHP and Composer the packaging script uses.

    MCP_IT_PHPUNIT_ARGS='--filter SomeTest' sh mcp/tests/Integration/ci/run-local.sh <nc 34|35> <mariadb|pgsql|sqlite> <fresh|upgrade> [--prune-images]

Replace `SomeTest` with the relevant test class or method. The placeholders describe the entrypoint syntax; choose a supported cell from the workflow. The filter is required by the local validation policy, even though the harness can technically run an unfiltered cell.

It runs the same steps as the workflow (packages, tools, start-db, start-nextcloud, install-app, fixtures, provision,
report, test, collect) for that one cell, with the image digests, the cell and the baseline tag read from
`.github/workflows/integration.yml`. Colima is started when needed and always left running because the default
profile is shared. On success, error and INT/TERM a trap removes only this run's containers, network, volumes and
baseline worktree. Images stay cached as files unless `--prune-images` is given. The pinned images are
multi-architecture and run natively as arm64 on Apple silicon; the script prints the architecture it got.

Several runs can be started from different places, also from the same checkout: each names its containers, network
and log directory with its own id and removes only those, packages the app from its own copy, and the Deck download
cache is written atomically. Up to `MCP_IT_SLOTS` runs (default 2) use the engine at once, each in a slot
(`~/.colima/mcp-it.lock`, `mcp-it.lock.2`, ...); the others wait in `~/.colima/mcp-it.queue` and get in in the order
they arrived (numbered tickets, nobody moves while a run is still choosing its number, a run is never overtaken by one
that arrived after it; tickets of runs that are gone are dropped, and the slot of an owner that is gone is claimed by
one run, never removed under another). A waiting run logs its position, the runs holding the slots and those ahead of
it. Colima is started under an engine switch by the first run that finds it down; leaving a run releases its slot
without stopping the shared engine. Each run of NC 35 + MariaDB takes about 1.5 GB of the engine's memory; the
Colima profile here (4 CPUs, 8 GiB) fits 2 slots with room to spare, and 3 at most; give the engine about 2 GiB per slot
plus 2 GiB before raising `MCP_IT_SLOTS` further (`MCP_IT_SLOTS=1` runs one at a time). The queue lives in
`ci/engine-queue.sh` and is proven without Colima by `ci/engine-queue-test.sh [repetitions]` (fake `colima`/`docker`, a
temporary `MCP_IT_LOCK_DIR`). A copy of `run-local.sh` from before the tickets or the slots still shares slot 1 safely,
but does not wait for the queue and may take slot 1 ahead of it.

**Never stop the shared Colima profile or remove another run's resources.** `ci/run-local.sh --shutdown` cleans
only interrupted runs recorded in this checkout's `.mcp-it-runs/*/owned-resources` manifests. It skips live owners,
uses exact container/network names and verifies anonymous-volume removal. A failed inspect is checked against a
responsive inventory; any failed removal or proof retains the manifest for retry. Exact volume names are saved in
`owned-volumes` before container removal, so an interrupted cleanup can still remove its remaining volumes.
It never scans global `mcp-it-*` names or stops Colima. `ci/shared-engine-test.sh` tests a sandboxed copy of the entrypoint, preserving real run manifests;
`ci/cleanup-ownership-test.sh` covers interrupted runs and retry after seven failure modes.
`php ci/local-artifact-root-test.php` proves that generated artifacts stay outside the app's recursive source readers.

Diagnostics: mode `baseline` installs the released baseline (the tag in the workflow) and never upgrades it, so a scenario can
be run against the published code; `MCP_IT_PHPUNIT_ARGS="--filter SomeTest"` limits the suite to one test. They were built
to find why a groupfolder version restore was refused (`Exclusion/MountsTest` now holds it as a regression, see
`EXCLUSION-MATRIX.md`).

Logs and artifacts (`run.log` is the terminal output, `resources.log` the memory samples, `mcp-it/artifacts/` the junit,
Nextcloud and database logs, `observations.txt` and `provision.log`) land in
`.mcp-it-runs/<cell>-<timestamp>-<pid>/` at the repository root, which is git-ignored. The exit status is the suite's.
A cell takes about 1.5 to 3 minutes once the images are pulled and the engine (a Linux VM with 4 CPUs and 8 GiB) uses
up to roughly 2 to 3 GiB of host memory while it runs.

What the suite gives later tests:

- `IntegrationTestCase::openIndependentConnection()`: a second real session to the instance's database (the same
  SQLite file, or the same MariaDB/PostgreSQL database), next to the official connection.
- `Support\EventProbe`: listeners on the real event dispatcher, with the caller's transaction state at dispatch.
- `Support\PostgresQueryLog`: the statements each PostgreSQL session ran between two markers, in the PostgreSQL cell.
- Fixture users and groups (`Fixtures`), created by the harness.

## Calendar over the real endpoint

`ci/harness.sh provision` installs the Calendar release pinned per Nextcloud major (`v6.6.2`, whose `info.xml`
declares `min-version 32` and `max-version 35`, so one build serves the whole matrix) next to groupfolders, external
storage, Talk and Notes; a part that cannot be installed is recorded in `MCP_IT_PROVISION_FAILURES` and reported by
`Exclusion/ProvisioningTest`, never swallowed.

`Calendar/CalendarToolsTest.php` calls `calendar_list_calendars` over the MCP endpoint with a real app password and
checks the answer is the list of the caller's own calendars. The personal calendar it expects is the server's own
provisioning on the first login, driven by the event the dav app listens to (the fixture users never log in through
the web UI), so what the tool reads was written by the server, not by the test. The Calendar app is optional: a cell
that could not provision it skips the test with the reason, and the provisioning failure stays visible through the
env var.

## Limit of the smoke tests

`Smoke/PostgresQueryLogTest` brackets a test closure registered at priority 500 with marker listeners at 501 and 499.
It proves that the log captures a session's statements and that an empty window is trustworthy; it does **not**
prove that a listener of the app runs no query inside the caller's transaction (contracts §3.3.6, validation
T19/F5). The app's listeners are registered at the default priority 0, which they share with core and other apps'
listeners that do run queries. Tickets 06/07 must delimit the app's real handler without changing its registration
or order, and also assert the caller's next query and its commit/rollback.

## Transactional policy coverage (T1/T2)

`Policy/PolicyTransactionTest.php` binds the production writer and official `IConfig`, `IUserConfig`, and
`IAppConfig` to the server connection. It checks that core config and OAuth storage actually hold that same
connection. A second real session observes config through test-only clones of core config services with their
connection rebound; app tables are read through production mappers. The tests issue no SQL against core tables.

Failures after user set, user delete, app set, group write, and revision CAS must leave all values and counters
unchanged. Each stage is observed from the independent session before commit, and the original config services
are reused after rollback. The success case must make every write visible together. Native migration seed
reexecution must preserve existing counters, and a second writer must reject a stale revision.

The policy outbox is one more injection point: an event the command emits is written after the revision CAS, in the
same transaction, and must be invisible to the second session until the commit.

## Administrative commands and undo (T3/T4)

`Policy/AdminCommandsTest.php` runs the commands of the admin routes through the production writer. A command over
two users and four cells, whose last cell fails after every config write ran, must leave each raw value, the
revision and the event table exactly as another session saw them before. A receipt's undo must bring back absence
where a key was absent and the old value elsewhere, seen from another session, and a second undo of the same
receipt must be refused without touching anything. Route mapping (428/409/410/503) is covered by the unit suite.

## Authorization schema (T12/T18)

`Policy/AuthStateTest.php` checks the B2b tables and the OAuth generation columns on the real database of each cell,
and that generations, recheck causes and group lifecycles written by the composition mappers commit or roll back with
the policy writer. In the upgrade cells, `ci/legacy-credentials.php` runs while the baseline release is still
installed and issues a code, a rotated grant (with its spent record) and a second grant through that release's own
store; `OAuth/LegacyCredentialsTest.php` then checks after `occ upgrade` that every row, id and hash is unchanged,
that they carry epoch 0 and generation 1, and that they still authenticate, exchange, rotate and detect the replay.
It is skipped in fresh cells. These tests do not certify how later tickets use the columns, nor the full T19 matrix.

## Upgrade snapshot without groups (T12, T13 first part)

In the upgrade cells, `ci/legacy-policy.php seed` runs right after `ci/legacy-credentials.php`, with the baseline
still installed: it writes the synthetic snapshot of `Compat/SnapshotProbe` through the baseline's own `GrantPolicy`
setters (nine users, one per eligible × connected pair over '1', '0' and absent; every CATALOG operation stored as
'1', '0' and absent three times each), issues one access token per user through the baseline's store, and records
the stored bytes (value, type, lazy flag, flags of every key, read through the config services) and every answer of
that release under each state of the service switch. The fixture step then creates those nine accounts.

`Compat/UpgradeSnapshotTest`, in the `compat` suite that runs before every other one, checks after `occ upgrade`:
the bytes and keys are unchanged, also after every migration of the app runs again (the core `MigrationService::executeStep`, what
`occ migrations:execute` runs) and the seed step with it; no policy key was materialized in the app config; the global epoch is 0, no user was cut and every
baseline token keeps its row with epoch 0 and generation 1; the branch's answers equal the baseline's; the baseline's
own `GrantPolicy`, loaded by `ci/legacy-policy.php read` from the copy the upgrade moved aside
(`MCP_IT_BASELINE_APP`), reads the same bytes into the same answers (the script refuses any class of the app the
reading loaded from elsewhere, and any Service, OAuth or Db class the server's boot loaded from the branch first); and each baseline access token authenticates, and its refresh token rotates, exactly
when the baseline's `canConnect` allowed it, while with the service off or absent none does. The seed records the
app version it ran under, which must be the baseline's. The tests rely on declaration order (`executionOrder="default"`
in `phpunit.xml`): the last one cuts the snapshot's users. Skipped in fresh cells.

That 1.0.8 can still read the preferences is a property of the data, not a statement that going back to 1.0.8 is
safe: it is not, even before groups are adopted, because 1.0.8 knows neither the epoch nor the cuts that revoke
credentials in 1.1.0 (decisions of 1.1.0). The coordinated recovery rehearsal of T13 (backup of installation and
database, service closed) belongs to the release gate and is not part of this suite.

## Revocation by generation and epoch (T7/T11 OAuth part)

`OAuth/GenerationCutTest.php` runs the production token service, writer and account listener on an account it
creates and deletes. A code carries the user's next generation, its grant and the spent record of a rotation keep
it, and a second consent leaves the other sessions working. A disconnect, an account disabled in Nextcloud and the
service switched off revoke by a cut or by the global epoch, as another session reads them; the cleanup after the
commit removes the revoked rows, and repeating it never removes a credential issued after the cut. A consent whose
code fails to store leaves the official `connected` value, the generation and the revision exactly as they were. The
daily cleanup job must be registered by the app (install and upgrade) and must not remove a valid credential. The
global epoch is proven by `Last/GlobalEpochTest.php`, in the `last` suite of `phpunit.xml`, which runs after every
other test because its cleanup removes every credential of the old epoch, the baseline ones of an upgrade cell
included. It runs in every cell; in the upgrade cells it also covers a row that takes (epoch 0, generation 1) from the
defaults the upgrade ALTER added. The epoch is put back afterwards.
Recheck causes are not covered here: they belong to ticket 06.

## Recheck causes, deferred work and the issuance mutex (T5, T7, T8, T19)

`OAuth/UserCausesTest.php` (ticket 06) deletes accounts and removes members through the core itself, so the events
arrive in core's order, with the duplicate pairs `User::delete` dispatches around `Group::removeUser`, to the app's
listener as registered (default priority). It checks that one deletion records one account cause, cuts once (one
revision) and consumes the cause; that a failure of each one of the app's handlers, injected by renaming the app's
recheck table only while core dispatches that event (probe listeners at priority 1 and -1 around it), never stops the
deletion and the credential still dies (reconciler or observed use); that a lost After of a deletion is consumed by
the next consent of a recreated account, whose code and grant then survive the late After; that a removal with its
After cuts in the same request, one without it (another listener throws first) is revoked by the next pass even when
the user is added back (Q1), and one that keeps eligibility through another source touches nothing.

The F5 case delimits the app's real handler without changing its registration: inside the caller's transaction on
the official connection, the test calls the registered listener instance itself between two statement-log markers
(PostgreSQL cell: the window must be empty), then lets core remove the member with its real dispatch (no statement of
that window may touch an app table or fail), runs the caller's next query and commits or rolls back. The deferred fact
is reconciled at the next safe point without a date: committed and no longer eligible, cut; rolled back and still
eligible, nothing cut. The same is done for an account deletion (both deletion handlers called between markers, then
`User::delete` itself, committed, reconciled later: account gone, cut) and for an account disable (`setEnabled(false)`,
rolled back; inside a transaction the disable is left to the next use, which refuses the disabled account). Those
cases read the effect on the user's generations; group eligibility over consent and Bearer is proven by
`OAuth/GroupConsumersTest` (ticket 09). Limit: the listener instance and its dependency graph are built before the
caller's transaction opens (the dispatcher resolves the service on its first event in the process); none of their
constructors runs SQL, but that construction is not itself bracketed by the log.

A deletion whose Before could not store its cause is reconciled by its After without a date, so a recreated account
never inherits the old token (review F1 of ticket 06).

Two pending proofs of earlier tickets run here too: a `record()` of one cause interleaved by another session between
its read and its insert (an adapter on the first session runs the other session's record at its second query
builder), and a real lock wait on the issuance mutex held by another session, with the engine's lock timeout
shortened for the test session (MariaDB `innodb_lock_wait_timeout`, PostgreSQL `lock_timeout`, SQLite
`busy_timeout`); the elapsed time is printed to stderr. A real deadlock (MariaDB 1213, PostgreSQL 40P01) is not
produced here.

## GID lifecycle (T6, T19 group part)

`OAuth/GroupLifecycleTest.php` (ticket 07) deletes and creates groups through the core's group manager and Database
backend, with the app's listener as registered. A deletion of a group whose policy grants eligibility must, between
core's Before and the backend delete (a probe right after the app's handler), show the lifecycle as `pending_delete`
and the cause of the holder who loses its last source, with neither the mutex nor the revision moved (the one
transition that runs without the mutex, see `GroupLifeMapper::markPendingDelete()`); after GroupDeletedEvent the rows
and the lifecycle are gone, the holder is cut once and the cause is consumed (two revisions: purge and cut). A holder
eligible through another source keeps its credential. A lost GroupDeletedEvent (another app throws first) leaves
`pending_delete` until the job, which purges a group that is gone; a deletion that failed (the Before alone) is
suspended by the job (`needs_confirm`, rows kept, eligibility through it unknown, nothing cut). Recreating the id
purges a residual policy saved before the creation began (policy_rev <= R_c), a policy saved between the creation's
Before and its event is kept, and a late GroupDeletedEvent carrying the old gen never removes a policy saved after the
recreation. Creating another group while one is being deleted is never refused.

The F5 case brackets the app's four group handlers between statement-log markers inside the caller's transaction
(PostgreSQL cell: empty window), then lets core delete the group with its real dispatch (no app table touched, no
failed statement), runs the next query and commits or rolls back; at the next safe point the policy is suspended
(`needs_confirm`) and never deleted, committed or not. Delete and recreate of the same id in one committed transaction
shows the accepted window of contracts §6.3 item 3 (i): another session still reads the old lifecycle as active until
the deferred processing, which then suspends it.

Declared residue (not a failure): if the reconciler job reads a group as existing while its deletion is in flight
(after the Before wrote `pending_delete`, before the backend delete), the lifecycle goes to `needs_confirm`, and the
GroupDeletedEvent that follows, with the Before's gen, purges nothing. The suspended policy of a group that no longer
exists grants nothing; a recreation with its signals purges it, and an administrator clears it by removing the group's
policy (admin UI, ticket 11).

These tests were written while the integration workflow was disabled (Actions minutes): they have not run yet.

## Group save (T5 F3, T6, T16 server part)

`OAuth/GroupGrantsTest.php` (ticket 08) previews and saves group policies through the container's service and writer,
over the core group manager and its Database backend, reading every result from a second session. A save makes the
lifecycle active with policy_rev equal to the revision it commits, and writes no user preference (connected and the
overrides stay as they were). A second backend that serves the same id makes the group untrusted: 422 at preview and at
save, nothing written. Replacing the only member by another one between preview and save keeps the count and is still
a 409. A withdrawal of eligibility with a new consent of the member between the external phase and the commit is
replayed once (H1 differs from H0) and cuts the new credential with the old one; a holder still eligible individually
keeps its session. "Confirm current policy" brings a suspended group back to active with a new gen and the same rows,
and a deletion's pre-event between the phases (pending_delete, no revision step) is seen under the mutex: 409.

After the review of ticket 08: a new member who joins and consents after the save's enumeration replays the attempt
(new holder in H1) and ends in a 409 (member set changed); the next save cuts it, and after a regrant its old token,
refresh and unused code stay rejected. A holder kept by a second group whose deletion pre-event lands between the
phases makes the withdrawal unavailable (503, eligibility without the first group unknown, nothing written); after
the real deletion the withdrawal cuts it. With two real sessions (MariaDB, PostgreSQL; SQLite skips, it has no such
window), a pre-event committed by the other session after the read under the mutex makes the conditional lifecycle
write touch no row: replay, 409, the other session's pending_delete intact.

Not covered here (unit only, `GroupGrantsWriterTest::testADeletionBeforeThatLosesItsTransitionToAWithdrawalJudgesTheHoldersAgain`):
a withdrawal committing between a deletion Before's judgement of the holders and its pending_delete transition, which
then fails (the save renewed the gen) and makes the Before judge the holders again. The real server offers no point
between those two steps of the app's own handler; the order is reproduced with the production listener, writer,
cause processor and mappers.

These tests were written while the integration workflow was disabled (Actions minutes, until 1 November): they have
not run yet.

## Resource scope schema (issue #4, B1)

`Policy/ResourceScopeTest.php` checks that `mcp_scope_modes` and `mcp_scope_items` exist and hold no row after the
fresh install and after the upgrade from the baseline release, so every user stays unrestricted; that the BIGINT
`policy_rev` holds the largest counter; and that scopes written by `ResourceScopeMapper` commit or roll back with the
policy writer, seen from a second session, with ids never case-folded. The revision these tests store is arbitrary:
which revision the scope writer records is B2's contract, not the schema's.

## Resource scope writer (issue #4, B2)

`Policy/ResourceScopeWriterTest.php` checks that the container wires the scope mapper, the native enumerator and the
activation barrier into the policy writer and the matrix, and that the container's writer saves `setResourceScope`
for every module, whose barriers B5 and B6 opened (the refusal behind a closed barrier is a unit contract). Behind an
open barrier (built by hand, with a stand-in enumerator, since native folders and calendars are not
provisioned here) the saved scope carries `policy_rev` = the committed revision, another session reads the mode and
its items through the one-statement join and the grouped count, no epoch moves, and undo restores the absence of the
mode. The real Files and CalDAV checks are covered by `FilesScopeTest`, `CalendarScopeTest` and `ScopePanelTest` (B5–B7).

The candidate listing of Files uses the native API on purpose (contracts F4), with its native side effects, which
these tests neither suppress nor certify: `getUserFolder()` creates the home of a user who never signed in, and
`getDirectoryListing()` (`View::getDirectoryContent` in stable32–35) may create the missing parent folder of a nested
mount (R4 of the plan review) and scans uncached sub-storages. It also materializes every child of the listed folder
before the folder filter and the page of 50, so there is no width cap yet; measuring a folder with thousands of
children belongs to B7, and if that fails the mitigation is a recoverable refusal above a fixed width, not a
cache-based listing (which would lose the mounts and diverge from the native view).

## Files scope (issue #4, B5)

`Policy/FilesScopeTest.php` creates folders in alice's tree and saves her Files scope with the container's writer,
which checks each folder against her native tree; the tools, `nc://files` and a checkout link are then used over HTTP
through the MCP endpoint, which opens alice's policy operation. "/" lists the selected folders at their real paths;
a file, a folder or a resource outside the scope answers exactly like a missing one, and a download link issued before
the scope is refused (S2). `files_mkdir` creates a level inside a nested selected folder without its ancestors becoming
listable, and a destination above the boundary or outside the scope is refused with no folder created; a file moved
into the scope outside MCP is reachable on the next call (S5). Restricted to nothing reaches nothing (S6). Shares
across users (S4) and V9 phase 2 are covered by the B8 proof below.

## Real resource scope proof (issue #4, B8)

This proof is included in the existing NC34/35 integration cells on GitHub Actions. A focused local diagnostic can use `MCP_IT_PHPUNIT_ARGS='--filter ScopeRealProofTest' sh mcp/tests/Integration/ci/run-local.sh 35 pgsql fresh`; it does not replace CI. The earlier NC32 proof is historical, not an active 1.4.0 cell.
`Policy/ScopeRealProofTest.php` adds only the real scenarios missing from the existing Files, Calendar and panel tests:

| Scenario | Real proof |
| --- | --- |
| S4 / S5 | A native user share, the recipient's independent scope and native WebDAV read, then WebDAV MOVE into, out of and back into the selected folder. The same MCP clients observe each change on their next HTTP call. Selecting the owner-only folder id for the recipient throws `ScopeItemsInaccessible`, preserving the revision and the recipient's saved scope. |
| S9 | The same Basic and OAuth credentials read before and after replacing the selected folder; both apply the current scope, remain authenticated and leave OAuth generations and the global auth epoch unchanged. |
| S12 | `Policy/ScopeCleanupEventsTest.php`: on-time real deletion removes old rows; actual account recreation before the late handler preserves newer rows; both core deletion events inside a caller's rolled-back transaction keep scopes; committed deferred deletion, diagnostic job, actual same-UID recreation and MCP reads keep the old restriction. The job counts absent accounts and never removes scopes. |
| V9 phase 2 | A real Talk room share, no exclusion tags configured, native ACL allowing the participant and only Files scope blocking it. The attachment message stays with its type and explicit null attachmentId, without its filename. Quote draft and confirmation match an absent attachment and publish nothing. Reply draft and confirmation quoting the same attachment are also called before restricting scope: their formats remain identical afterward (apart from the new reply message id). Read envelopes omit filenames and reply results/plans omit attachmentId structurally, even with open scope; the effective scope checks are the read attachmentId becoming null and the quote refusal. |
| D6 | Full HTTP status/result comparisons against the same tool's nonexistent resource, including Files reads and Talk quote plans and confirmations. |

S12 outside-transaction failure (Before without After because deletion failed) is covered only by unit contract,
not by this real proof. It is safe by construction: scope removal requires the After event. The real failure case
here is the caller transaction rollback with both native events delivered. Rollback fixtures are deleted through
native OCS HTTP in teardown, with a subsequent 404 check, independently of the CLI user cache.

Existing coverage is reused: `FilesScopeTest::testS2S5TheToolsReachOnlyTheSelectedFoldersAndCreateNothingOutside`
proves nested destinations, refusals without partial writes, checkout and resource reads; `CalendarScopeTest` proves
calendar/task scope and native share identity; `ScopePanelTest` proves the native picker, saved scope, personal view
and server-side admin gate. `Exclusion/TalkReferenceTest` remains V9 phase 1 (tags). S14 headless UI belongs to ticket 14.

## Calendar and tasks scope (issue #4, B6)

`Policy/CalendarScopeTest.php` creates calendars, task lists and a share of bob's in the container's DAV app (the
share and its withdrawal go through bob's own CalDAV request) and saves the scope with the container's writer, which
checks each calendar against alice's native calendars. With the calendar scope set, only its calendars reach the
calendar tools, and a calendar outside it answers a read, a plan and a confirmed write exactly as a missing calendar
does, with nothing written (S7). The tasks scope is independent: one calendar with VEVENT and VTODO stays a calendar
while leaving the task tools, which are called over HTTP through the MCP endpoint. The shared calendar is kept by the
owner's calendar id through a rename, a withdrawal and a new share, and its recreation under the same URI gets a new
id that the old scope does not reach (S15). The calendar tools need the Calendar app, which the harness does not
install, so they run through the container's `CalendarModule` inside one policy operation, as the endpoint runs them.

## Access inspector (ticket 13; issue #4 A5, B6)

`Exclusion/InspectorTest.php` checks the inspector against what the tools really do: each reason it gives for a path
(direct tag, tagged folder named by its path, share origin for the recipient, unreadable configuration) comes with
`files_read` refusing that path exactly like a missing one, and a path it reports as reached is read; the effective
access of `notes.delete` and `files.create` is whether the endpoint's `tools/list` holds `notes_delete` and
`files_mkdir`, and with the service off every operation is blocked by the service and the endpoint admits nothing; the
container's inspector explains a task list through its scope; and a non-administrator gets a refusal before anything is
inspected on each route.

## Resource scope panel (issue #4, B7)

`Policy/ScopePanelTest.php` runs what the admin panel asks and saves through the scope, matrix and undo controllers
built from the container's services, signed in as the administrator: show, candidates, save, a stale save refused with
409 and nothing written, the matrix cell and the personal page (signed in as alice) reading the saved scope, and undo
through the receipt. An unreadable scope (an inconsistent row on the real database) is `unavailable` in every scope
cell of the matrix, which still answers. Files is browsable and its scope is saved, now that B5 opened its barrier.
Over HTTP,
a non-administrator session gets the framework's 403 on every scope route. The panel's own requests (URLs, requesttoken
header, bodies) are covered by the node tests. The test also measures the folder picker over a folder of 1000
subfolders (F3 of the B2 review) and records it in `observations.txt`.

## Exclusion proof (issue #4, A4)

`Exclusion/` runs the real scenarios of the exclusion by tags (V2, V3, V4, V5, V7, V9 phase 1, V14) and the positive
controls; `Unit/Contract/EXCLUSION-MATRIX.md` holds the verdict and the limits.

- **Provisioning** (`harness.sh provision`, after the fixtures): groupfolders (`mcp-it-gf`, group `mcp-it-staff`), Talk
  and Notes from the project release repositories, pinned per Nextcloud major by tag and SHA-256, and three global local
  external mounts for both fixture users: `mcp-it-ext-wide` (`/mcp-it-ext`), `mcp-it-ext-alias` (the same directory) and
  `mcp-it-ext-narrow` (`/mcp-it-ext/Open`). Each part is attempted on its own; a failure is recorded and
  `ProvisioningTest` fails by name, with `provision.log` in the cell's artifact. Nothing is left silently uncovered.
- **Real logins**: an app password (HTTP Basic, created through the core token provider) and an OAuth access token from the
  app's own `TokenService` (Bearer, PKCE), used over HTTP against the container's Apache. Files change from outside the
  tools through WebDAV and the server's Files API; tags, shares and links through the core services.
- **`cache_app_config` is off** in the cell, except inside `AppConfigCacheWindowTest`: core serves the app config from the
  web server's local cache for 3 seconds and the CLI that writes the settings cannot invalidate it. That test turns the
  cache on and measures the stale window of the exclusion list (2.66 s to 3.94 s in the eleven cells measured); the result
  and its meaning are in `EXCLUSION-MATRIX.md`.
- **Diagnostics**: `DiagnosticsTest` records, in `observations.txt` of the artifact, how each kind of mount looks to the guard
  (including the ancestor walk in a fresh process, `Support/chain-probe.php`) and the answers of the tools for it. Behavior
  that is documented rather than asserted (the narrow mount of a subdirectory is a separate storage) is recorded there too.
- Not covered by the Exclusion directory: V6 (federated), groupfolder ACL rules and S-scenarios of part B. V9 phase 2 is covered by Policy/ScopeRealProofTest (B8).

## Native Deck fixtures (DK-6a)

Normal provisioning also installs Deck, without bypassing any other optional app. `pin_releases` pins
NC34/35 to Deck1.18.5/1.19.0 and the SHA-256 of each release archive. Before enabling it,
`ci/check-deck-manifest.php` checks the app id, version and exact Nextcloud major bounds. `runtime.md` records
the installed version, expected pin and checksum. A missing/failed app fails the `deck` group, never skips it.

    MCP_IT_PHPUNIT_ARGS='--group deck' sh mcp/tests/Integration/ci/run-local.sh 35 mariadb fresh

The `Deck/DeckFixture` actor helper starts a fresh PHP request for each UID/action, avoiding cached Deck services
bound to a previous UID. Its four dedicated accounts (owner, reader, editor, outsider) have random throwaway
passwords. Boards/cards in different ACL domains, files, a group and a native circle are disposable fixtures.
`Deck/NativeDeckTest` asserts the native label delta, foreign-label rejection, native dependency availability and
semantics, and file share/detach preservation. It deliberately records the native acceptance of cycles and self
relations separately from the MCP-layer refusals. The earlier NC32/33 API-absence checks are historical; active 1.4.0 coverage uses NC34/35.
These tests call native services; they do not implement or promise new MCP tools.

Local runs cache only the Deck archive under `.mcp-it-runs/deck-downloads/` at the repository root, keyed by tag and SHA, verifying its
checksum even on a hit. Remove that directory for a cold download. `artifacts/deck-cost.tsv` separates download
and checksum time from extraction/enablement, in milliseconds; `resources.log` samples the engine/containers.
For comparable whole-cell timing, run the same existing tests with Deck enabled/disabled:

    MCP_IT_WITH_DECK=1 MCP_IT_PHPUNIT_ARGS='--filter ProvisioningTest --exclude-group deck' sh mcp/tests/Integration/ci/run-local.sh 35 mariadb fresh
    MCP_IT_WITH_DECK=0 MCP_IT_PHPUNIT_ARGS='--filter ProvisioningTest --exclude-group deck' sh mcp/tests/Integration/ci/run-local.sh 35 mariadb fresh

`MCP_IT_WITH_DECK=0` exists for the explicit cost comparison, not as a passing Deck configuration: selecting the
`deck` group with it fails, which also exercises log collection and cleanup after failure. Normal runs default to
Deck enabled. Do not substitute the old interactive spike mode or fixed passwords.

The harness retains the historical `MCP_IT_RUN_UNIT` switch, but it runs the complete unit suite and is not part of the current local validation policy. Do not use it for local patch verification. The full unit suite runs in the existing GitHub CI partitions; unit and integration bootstraps must remain separate.

Existing frontend checks need no Nextcloud service: `node --test mcp/tests/Frontend/*.test.cjs` is optional verification, not a required build or test prerequisite. The PHP asset builder and PHPUnit entrypoints remain authoritative. Fresh/upgrade and database selection are unchanged. `ci/run-local.sh --shutdown` cleans interrupted runs recorded by this checkout and preserves live runs.
The default Colima profile is shared and is always left running. Artifacts and cached downloads stay outside
`mcp/`, so recursive unit-test source readers cannot ingest run output; cached archives/images are files, not running services.

### Deck issues 26 and 27

`AssignmentLifecycleTest` creates isolated regular users, tests assignment over real MCP Bearer and cookie/CSRF requests to CardController, reverses the actors and checks fresh native persistence. Its separate disposable notification delivery app uses the real IManager; the induced failure records actual defer/flush phases: the MCP route fails inline on the direct call, retries with delivery deferred, stores the assignment and reports the delivery error afterwards, while Deck's web route still fails inline. Devices are counted without storing endpoints/keys. No logs or evidence are requested from the reporter.

`AssignmentPushTest` registers a push device for the recipient through the Notifications app's own OCS routes: a proxy device pointing at a throwaway `php -S` proxy inside the container (`push-proxy.php`) that answers 200 or 500, a proxy device pointing at a closed port, and, where the Notifications app has it, an activated web push subscription with an unreachable https endpoint. `allow_local_remote_servers` and the Notifications `webpush_enabled` switch are turned on only inside the test container and restored on close. For each case it assigns through Deck's own `assignUser()` in a fresh process with no MCP code (`push-actor.php`, the 1.2.5 path), through the Deck web route and through `deck_edit_card`. The probe app's notifier can fail while Deck notifications are prepared, which makes the real Notifications push code raise inside `assignUser()` for a recipient with a device and not for one without, the pattern reported in issue #26. Network and HTTP errors of the push service are handled by the Notifications app and never reach Deck. A batch case assigns, in one call, a recipient whose push fails to prepare and then a healthy one, each device on its own proxy path: both must be assigned, the healthy path must receive a push and the failing recipient must keep a single notification. `AssignmentLifecycleTest` expects the MCP route to try the direct call first and then retry under `defer()`/`flush()`.

`CardStatusPersistenceTest` verifies both status setters, confirmation, stale fingerprints, native completion-column behavior, no-op and UTC persistence. The oracle is the container’s UTC clock and the raw done column read in a new process; account timezones only verify serialization. The earlier NC32/MariaDB proof is historical. Active 1.4.0 coverage runs in the NC34/35 CI matrix; any local diagnostic must select this test with `MCP_IT_PHPUNIT_ARGS` and preserve its limited evidence and owned-resource cleanup.

The issue #26 automation control is a real local Database account whose random creation password is discarded, absent from the browser-login fixture. It is assigned/unassigned as a recipient and also writes through an OAuth Bearer credential only; no interactive login is performed. The test records its actual backend and checks last-login zero before issuing the credential. This models credential-only automation usage, not a specialized service-account backend or the reporter’s unspecified account provisioning. That backend distinction remains outside the proof.

CardStatusPersistenceTest also rereads the persisted native activity stream in a new process for the other board member after each transition. Both pinned Deck versions must emit exactly one card_update_done, card_update_archive, card_update_unarchive and card_update_undone for the corresponding confirmed call, with the actual actor/card/affected account. A no-op must add no activity. This is a read-only DB oracle of native publication, not a claim of push delivery.

### Additional Tables 2.4.0 source pin

The default Tables pins stay unchanged. To exercise the independently supported 2.4.0 profile, download the official `tables-v2.4.0.tar.gz` release and run a separate NC35 cell (after obtaining the shared engine slot):

```sh
MCP_IT_WITH_TABLES=1 MCP_IT_TABLES_ARCHIVE=/absolute/path/tables-v2.4.0.tar.gz MCP_IT_PHPUNIT_ARGS='--filter McpTables' sh mcp/tests/Integration/ci/run-local.sh 35 mariadb fresh
```

The archive is copied to the disposable harness tools directory and must match SHA256 `47847b64ee65a58afd2a8ac3f943c53128a927a1b321ed94c4a322daa6b5eb99` before extraction. In the active NC34/35 harness, NC34 refuses this opt-in and the additional runtime proof deliberately uses NC35. The existing app profile for NC33–35 is unchanged; that does not reintroduce NC33 test coverage. `test-env` reports 2.4.0, so the same discovery/read/row/schema/share scenarios run with the correct independent parser expectation. The schema HTTP scenario additionally configures gallery/card sources through native Tables, refuses source-removing MCP column patches without any database change, and confirms an allowed rename while preserving layout/cards in native readback. No user-instance data is used.
