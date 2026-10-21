# Changelog

All notable changes to the native Nextcloud app (`mcp/`). Versions follow `mcp/appinfo/info.xml`.

## Unreleased

### Changed

- Organize Files, Deck, Talk, Calendar, Contacts, Tasks, Notes, People and Logs by capability, with explicit shared MCP, access, OAuth and platform owners. Public tool names, routes and native integration contracts are preserved; internal PHP namespaces change. See the [architecture guide](docs/en/development/architecture-specification.md).
- Split administration and personal JavaScript into responsibility-focused source modules and two generated production bundles, with lifecycle and revision-aware recovery tests.
- Unify contributor commands through Composer, including PHP syntax and static analysis, browser tests, native integration cells and production package verification. See [testing](docs/en/development/testing.md).
- Maintain canonical English documentation with offline operational guides, translation provenance and Spanish, Catalan, Brazilian Portuguese and European Portuguese drafts. Human language review remains pending.

### Security

- Apply Guests hidden-storage checks to structured file references as well as Files operations, including Talk attachment publication and task attachment output. This closes a direct-path lookup gap identified while integrating upstream 1.1.2.

The release version for this architectural contribution is not assigned. Source builds require the locked frontend toolchain; installed packages do not require Node.js. See [setup](docs/en/development/setup.md) and [packaging](docs/en/development/packaging.md).

## 1.1.2

Security release for servers with the **Guests** app. No database change; updating from 1.1.1 needs no step and changes nothing for accounts that are not guests.

### Security

- Guests: the restrictions of the Guests app now apply to a guest on **every MCP request**, with an app password and with OAuth. Guests sets them up only when the session already holds the user when it starts, or on the core login event without its late part; an app password logs in after that and an OAuth token is checked inside the app, so before this release a guest could keep using MCP after this app was taken off the apps allowed to guests, see external storage Guests hides, and share outside the guest's groups although "hide other accounts" forces sharing only within them. All three are now enforced, and the endpoint answers 403 to a guest Guests does not allow here.
- Guests: the tools, resources (`nc://notes`) and Talk references of a module whose app is not allowed to the guest (Calendar, Notes, Talk, Deck, Contacts, file versions) are left out of `tools/list` and refused. Files and Tasks use apps Guests always allows (`files`, `dav`) and stay available.
- Guests: **checkout links** (`files_checkout`, `files_upload`) carry no session; they now apply the Guests restrictions to the link's owner before any file is reached, and a link of a guest Guests no longer allows answers like an unknown link, without being spent. With Guests older than 4.8.0, which can only restrict the session user, a guest's checkout link is refused.
- Guests: on Nextcloud 35 the core mounts external storage by path without the Guests mount filter; the Files tools, `nc://files` and checkout links now refuse a file on external storage that Guests hides from the guest.
- When the state of Guests cannot be read, the request or the module is refused.

## 1.1.1

Fix release for the admin screens: group grants, resource scope and the permission matrix. No database change; updating from 1.1.0 needs no step.

### Fixed

- admin: *Group grants* and *Resource scope* no longer report a save as done when the server's reply cannot be read ([#11](https://github.com/sintax-tech/mcp-for-nextcloud/issues/11)). When a save came back with a 2xx status but a body that is not JSON (for example an HTML page from a proxy, or a session that expired), the screen showed "Saved" or "Change undone" although nothing was confirmed. It now says the result **could not be confirmed** and reads the saved state again, so what is on screen is what the server holds.
- admin: the **permission matrix** of *Access and permissions* has the same fix ([#11](https://github.com/sintax-tech/mcp-for-nextcloud/issues/11)). A change of a cell, a bulk action, **Undo** or the service's **Can connect** switch whose reply was a 2xx with a body that is not JSON was handled as a plain failure and could leave the screen different from what the server holds. The matrix now says the result **could not be confirmed**, without any "Saved" or "Change undone", and reloads the saved values once. The write may already have happened: nothing is repeated automatically, and if the reload also fails the screen shows the load error with **Retry**.
- About #11: the reported loss of the *Can connect* setting after a reload was **not reproduced** on Nextcloud 34 and 35 (MariaDB and PostgreSQL, API and browser). `allowed_groups` is not a setting of this app (`occ config:app:set mcp allowed_groups` has no effect); group permissions are set in *Access and permissions → Group grants*. This release fixes the false "Saved" messages and the stale matrix, and does not claim to resolve every case of the report. If it still happens, the first thing to check is the reply to the save request (browser Network tab) and the Nextcloud log.

## 1.1.0

Priority of this release: stability and security. When the app cannot be sure about an access, it refuses it (or asks for a new sign-in) instead of guessing. The upgrade from 1.0.8 is automatic: the new tables are created by the app, nothing has to be run by hand, and the credentials, permissions and connections of 1.0.8 keep working.

### Added

- admin: **permissions by group** ([#3](https://github.com/sintax-tech/mcp-for-nextcloud/issues/3)). In *Access and permissions → Group grants* the administrator grants *Can connect* and the operations of each module to native Nextcloud groups. The groups appear in a list beside the open group, with its own scroll, one line per group and a filter **All | With grants | Blocked groups** (on a phone, a picker opens the list); the list is searchable (50 per page) and a group that cannot grant shows a lock; the editor shows the members and the individual choices of each group; before saving, a preview lists the permissions added and removed, the current members and the users who may be signed out (an estimate, not the exact access of each member). Groups only grant: they never deny or narrow. A user's own choice (Allow or Deny) always wins over the groups; without one, the user receives a permission if any of their groups grants it, and otherwise the default applies (reading on, except the server log; writing and *Can connect* off). Removing *Can connect* from a group signs out only the members who lose eligibility because of it.
- admin: only groups kept by Nextcloud's own database backend can grant. Groups that come from LDAP, SAML, another app or several sources appear disabled, with the reason; their members receive permissions one by one in the matrix. Why: the app must know for certain when a group is deleted or created again, and an outside source can recreate a group with the same name without Nextcloud noticing, so the new group would inherit the old group's permissions.
- admin: **group lifecycle.** The app follows every group creation and deletion that Nextcloud reports. A group whose state became uncertain (a creation or deletion that was rolled back or seen inside another transaction, or one that lost its single native backend) is *awaiting confirmation*: it grants nothing and keeps its saved policy until an administrator clicks **Confirm current policy**; nothing is erased on its own. A group in that state only blocks a user (503, "try again") when the user's access depended on it alone; a tool whose permission is unknown is left out of the tool list instead of breaking the list. Deleting a group suspends it first, then records who holds credentials, and only then lets the cleanup run; if anything fails in between, the group stays suspended and nothing is erased. A member who leaves a group is recorded even if the user has an individual Allow.
- admin: **back to the groups' inheritance.** In *Permissions*, *Inherit* in a cell's window, *Give everything back to inheritance…* in a row's actions menu and the same action of the selection bar remove an individual choice so that the groups apply again. A preview shows, cell by cell, the result before and after (an individual choice, the granting groups, the default or *Unknown*), how many results change, stay or are unknown, and warns that users who lose *Can connect* are signed out and that undoing does not sign them back in. The reset is tied to the version of the table the preview was read at; if anything changed since, it is refused and the table reloads. The interface offers **Undo** for 10 seconds while the control is available; the timer waits while the pointer or the keyboard focus is on the notice. Undo is still subject to the saved revision and to the expiry of the server's receipt. At most 50 users and 2,500 cells per reset. A reset that would remove *Can connect* is refused while the groups cannot be read, and one that leaves the user eligible through a group signs no one out ([#3](https://github.com/sintax-tech/mcp-for-nextcloud/issues/3), [#2](https://github.com/sintax-tech/mcp-for-nextcloud/issues/2)).
- admin: **access inspector** in a side drawer of *Access and permissions* (from a user's row menu or from *Inspect a person…*; administrators only, read only). For a user it shows every operation as *Allowed*, *Blocked* or *Unknown*, the first check that blocks it (account, service, eligibility, connection, app, server log, permission or resource restriction) and where the permission comes from (the user or which groups); it also says why a path in the user's files, a calendar or a task list is reached or not. For a path excluded by tag it names the reason and the excluded folder, only to the administrator; the tools keep answering "not found". If a check cannot be confirmed, the answer is *Unknown*, never *Allowed*. Each question is written to the Nextcloud log at *info* level, without paths or names, so it appears only when the log level is 1 or lower: it is not a guaranteed audit trail ([#3](https://github.com/sintax-tech/mcp-for-nextcloud/issues/3)).
- admin: **restriction by resource** ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)). The administrator can limit a user to chosen folders (Files), calendars (Calendar) or task lists (Tasks), in a side drawer of *Access and permissions* (from a user's row menu or from *Restrict the resources of a person…*; one user at a time, choosing "All resources" or "Only selected" per module). Up to 200 resources per user and module. It only narrows: it never grants, it is set per user (not per group) and only by administrators, and the user sees their own restriction on the personal settings page. A calendar, a task list, a folder or a file outside the restriction is answered "not found" and disappears from lists, exactly like one that does not exist; if the restriction cannot be read, the tools answer 503 instead of guessing. Calendar events and tasks are restricted separately, even when one calendar holds both. After the access and operation permission checks, the resource restriction is checked first, then exclusion by tag, then the Nextcloud permissions; a restriction that cannot be read answers 503 before any item is looked up. Changing a restriction does **not** sign the user out: it applies from the next request. Notes, Contacts, Deck and Talk have no restriction in 1.1.0. Changes and queries are written to the Nextcloud log at *info* level, without resource names.
- oauth, admin: **OAuth clients registered by the administrator** (self-hosted MCP harnesses that take a manually configured `client_id` and return to their own domain), by @Zegorax in [#8](https://github.com/sintax-tech/mcp-for-nextcloud/pull/8) ([#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)). *OAuth clients* has a new **Self-hosted clients (custom redirect URIs)** list: one client per harness, with a name (shown on the consent screen and in the connection lists), an **Enabled** setting and 1 to 20 exact redirect URIs (absolute `https`; `http` only on loopback; no user name, `*` or fragment). None by default, at most 20; each client gets its own `client_id` (`nextcloud-mcp-custom-` plus 12 hex characters). The consent screen says the administrator added the client and names the host it returns to. PKCE S256 is required as for every client.
- oauth: the redirect must match one of the client's URIs **exactly, port included, also on `127.0.0.1`, `localhost` and `[::1]`**; the free-port rule for loopback stays only for the native client and for Claude/ChatGPT. Turning a client off, deleting it or removing one of its URIs disconnects that client at once and never touches the user's other clients; one change is all or nothing. A consent screen opened before the change is checked again and cannot complete afterwards. An administration page that is out of date gets 409 and reloads, so it cannot turn a client back on or bring a removed URI back. Renaming a client, adding a URI or enabling it signs no one out. App passwords (HTTP Basic) are independent of every OAuth client.
- l10n: German translation (`de`) of the whole interface, of the messages shown by the tools and of every string new in 1.1.0 (the group editor, the reset and the inspector, the resource restriction, the self-hosted clients). Informal register ("du"), the Nextcloud default. German translation by @matt-ek ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)).

### Changed

- admin: **the *Access and permissions* tab was redesigned** so it stays usable with many users and groups; no permission rule and no address of the API changed. The matrix shows one **level per module** (*None*, *Read*, *Edit*, *Full*) instead of a box per operation: choosing a cell opens a small window with the levels, what each one allows and what the person would inherit, and the **Operations** view keeps the box per operation for fine adjustments (a set of operations that is no level shows as *Custom*). Only real **exceptions** are marked: a value set for the person that differs from what they inherit; a value that comes from a group has a quieter group icon, and a value that only repeats the inheritance has no mark. **With exceptions** filters the people on the page, and a notice offers to review the individual values that repeat what the person already inherits (they change nothing today, but keep later group changes from reaching that person). Each row has one **actions menu** (inspect access, restrict resources, give everything back to inheritance, copy the user ID), and a **selection bar** replaces the *All* menus of the headers: with people selected, set a module's level or *Can connect* for all of them, or give their values back to inheritance, after a confirmation that counts who changes. The user and *Can connect* columns stay fixed when scrolling sideways, the keyboard focus is always visible, the theme color is no longer used for states, and the page fits a phone. The group editor uses the same levels and a **Review and save** bar. *Undo* stays 10 seconds.
- admin, files: while at least one exclusion tag is configured (or the setting cannot be read), the tools no longer **share**, **create a public link for**, **copy** or **move to another parent folder** any **folder**, and `files_list_shares` shows no link address for folder links. A visible folder gets a clear refusal saying to do this in the Nextcloud Files interface; an excluded folder still answers "not found". Single files, renaming a folder in place and removing a share keep working. Why: Nextcloud cannot guarantee that everything inside a folder is visible to the app, so sharing a folder could expose a protected item ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)).
- oauth: the state of the OAuth clients (the native client's switch and the self-hosted clients) now lives in the app's own table instead of the app configuration, whose cache could serve an older value after a withdrawal; every decision to admit a client reads it from the database. The upgrade imports the 1.0.8 switch of the native client by itself.

### Fixed

- admin: found while testing the page in a browser: a save of the OAuth clients or of the server log groups made the next save of another block of the same page fail with a conflict, and drafts typed in a block were lost when the page's revision moved. The page now keeps the drafts of its own window across its own saves, and announces a save of those blocks only when the request itself changed something; a change made by another administrator is still refused with 409.
- files: restoring a file version in a team folder failed with "Resource locked by another operation" when the files_lock app is installed (Nextcloud 34 and 35). The tool wrote the version over the file while the stream it had read from the version was still open; it now copies the version first, closes its stream and then writes the copy, with the same lock checks and the same backup as before. There is no 1.0.9.
- calendar, tasks: reading one calendar or task object by identifier (for example `tasks_read_task`) failed with "Unexpected error" on Nextcloud 32, because the app used a database function that exists only from Nextcloud 33. Present since 0.10.0.
- files: with an exclusion tag configured, files on external storage and files shared out of a team folder were refused, because Nextcloud reports the folder above a mount with another storage's id. Nothing was exposed; legitimate access was refused. The app now resolves that folder by its path ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)).

### Security

- oauth: the native client that already existed in 1.0.x had its on/off switch read from the app configuration cache, whose value could be older than the administrator's change, so it could still be admitted after the administrator turned it off. It now follows the same rule as the self-hosted clients: the switch is read from the database and turning it off disconnects it at once ([#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).
- oauth: the integration of the self-hosted clients closed three gaps found in review of the first version of [#8](https://github.com/sintax-tech/mcp-for-nextcloud/pull/8): a consent screen opened before a client was turned off, a save from an out-of-date admin page, and a code exchange racing with the change.
- exclusion by tag ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)): the answer for an excluded item is now the same as for a missing one in every tool and in the checkout link. Gaps closed: image view, folder creation, copy, move, create and upload answered differently for hidden items; an unreadable tag setting produced an error instead of a safe refusal; an incomplete or circular folder chain ended as "allowed"; the visibility cache was keyed by file instead of by path and could be written before the last check; the write preflight skipped the root when intermediate folders were missing.
- talk, tasks: **references to excluded files** are filtered. `talk_attach_file` and `talk_quote_file` check the file as the caller sees it and treat an excluded file as not found; `talk_read_messages` returns no attachment for an excluded one. In Tasks, any `ATTACH` that points to an excluded file through a Nextcloud address (on any host, IP or alias) or through the Calendar app's file parameters, and public folder links while tags are active, are removed from what the tools show; the stored task is not changed. The same filter applies to files outside a user's Files restriction ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)).
- admin, oauth: policy changes are saved one at a time and cut OAuth credentials by user generations and a global epoch, never running SQL inside a transaction opened by Nextcloud. When it is not possible to tell whether a member still has another source of access, removing a group's permission is refused with nothing changed, and the administrator tries again.
- admin: **deleted accounts.** The restriction by resource of a deleted account is removed when the deletion events are processed outside another transaction and the app captured the policy revision before the deletion; only restrictions saved at or before that revision are removed, so a later one is kept. If an event is lost, either event arrives inside another transaction, or the revision or the cleanup cannot be completed, the restriction stays. The periodic job only counts such accounts, never removes them: proving an account is gone is not in the app's hands, and an orphaned restriction is harmless (an account recreated with the same user name stays restricted instead of regaining access to everything).

### Compatibility and recovery

- Upgrade from 1.0.8 is certified: the new tables are created automatically and the credentials issued by 1.0.8 keep working. No terminal step.
- **Going back to 1.0.8 is not supported**, even before groups or restrictions are used: 1.1.0 revokes credentials logically and removes them later, which 1.0.8 does not know, so a revoked credential could work again, and restricted users would regain access to every resource. The preferred way out of a problem is to update to a corrected 1.1.x release. Only if you must go back: restore the **whole** installation (code, configuration, database and data) from the same backup taken before 1.1.0, in maintenance mode, then switch the MCP service off in the administration (it removes every OAuth connection), have each MCP user revoke their app passwords in *Personal settings → Security*, keep it off for at least 15 minutes so issued checkout links expire, switch it on and reapply what changed after the backup. Never put only the 1.0.8 code over the 1.1.0 database.

### Known limitations

- Groups, delivered events: if a group is deleted and created again with the same name and the notice is lost (the PHP process ends before the follow-up, or both happen inside one transaction), the new group may inherit the old policy for that window. Nextcloud does not reserve the backend name `Database`, so a backend that reports it is treated as the native one. Long-running command-line processes can see stale state while they live. A change in a user's eligibility seen inside another caller's transaction does not cut credentials when the user is still eligible at processing time: the app cannot write inside that transaction, and cutting would also drop new, legitimate connections.
- Groups: if the suspension of a deleted group loses the race three times, a connection made in that interval is valid again only after an administrator confirms the group's policy.
- Individual choices: there is no direct control to save an Allow over an Allow that is only inherited; uncheck (Deny) and check again (Allow), but not for *Can connect*, because denying it signs the user out.
- Exclusion by tag: it covers this connector only (not other AI tools, the Nextcloud web interface or manual uploads) and does not show in the Files interface. Federated shares are checked against this server's tags only. Mounting the same external directory twice with different roots creates two storages: tag where the file is read. Names written into server log messages are hidden only while *Hide the text of log messages* is on; free-text mentions of a file name in Talk messages, event and task descriptions or Deck cards are not redacted. A change to the exclusion tags made outside the admin page (another web node, or the command line) can take about 3 seconds to apply.
- Restriction by resource: Files, Calendar and Tasks only, per user only. A selected folder's root cannot be renamed through MCP. Backups made by the app (`/MCP backups`) are written without the restriction check.
- Deleted accounts: receiving the deletion notice is not enough to remove the account's restriction; both events must arrive outside another transaction and the revision must have been captured. Otherwise the restriction stays, which only narrows: an account recreated with the same user name remains restricted.
- Cost on large installations: deleting a group and taking *Can connect* from a group cost time proportional to the number of users who have already connected an MCP client, not to the size of the group: with about 1,000 of them it takes roughly 9 to 19 seconds, and opening a group of 5,000 members takes 1.5 to 2 seconds. An optimization is planned for 1.1.1.
- Inspector and restriction: their audit lines are recorded only with the log level at *info* (1) or lower.

Thanks to @matt-ek for the German translation ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) and to @Zegorax for the OAuth clients registered by the administrator ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/pull/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).

## 1.0.8

### Changed

- appinfo, admin: the app is now called "MCP for Cloud" (it was "MCP for Nextcloud"), because the Nextcloud App Store rules do not allow the Nextcloud name in the name of an app. The new name appears in the store listing, in the administration and personal settings menus, on both settings pages, in the READMEs and in the `NOTICE` file; the store description keeps saying that the app is a Model Context Protocol server for your Nextcloud (en, pt-BR, es). The app ID (`mcp`), the settings addresses, the endpoints, the saved settings and the tables are unchanged, and so are the installations, the permissions and the connections of the clients: nothing has to be done.
- mcp: the server name that MCP clients show (`serverInfo.name`) is now `mcp-for-cloud` (it was `nextcloud-mcp`); the OAuth resource name, the Basic authentication realm and the product identifier written in the calendar events created by the app (`PRODID`) carry the new name as well. The identifier of the built-in native client (`nextcloud-mcp-native`) is kept, so the connections already made with it keep working.

## 1.0.7

### Added

- tasks: task categories (the VTODO `CATEGORIES` property, shown as categories in the Tasks app). `tasks_read_task` and `tasks_list_tasks` return a structured `categories` list, merged from every `CATEGORIES` line of the task, with escaped commas read back intact; names are trimmed and a name repeated with a different case (including Greek "Σ", "σ" and "ς") is listed once, with its first spelling. `tasks_read_task` also returns the raw `icalendar`. A task without categories returns `[]` ([#6](https://github.com/sintax-tech/mcp-for-nextcloud/issues/6)).
- tasks: `tasks_create_task` and `tasks_edit_task` accept `categories`, a list of up to 50 texts of 1 to 255 characters after trimming, with no control characters; an invalid value is refused before anything is written. On an edit, omitting `categories` leaves the existing lines untouched, `[]` removes them all and a list replaces them all with a single line (a comma inside a name is escaped and reads back intact). Recurring tasks and tasks with participants are still refused ([#6](https://github.com/sintax-tech/mcp-for-nextcloud/issues/6)).
- tasks: `tasks_list_tasks` accepts `category` and lists only the tasks with that category, compared exactly but ignoring case; it works together with `include_completed`, and `offset`/`limit` page over the matching tasks only. Private and confidential tasks of another owner stay hidden, and so do their categories ([#6](https://github.com/sintax-tech/mcp-for-nextcloud/issues/6)).
- tasks: the confirmation plan shows the categories before and after a change, and "empty" when they are cleared. Each name is written in quotes, with doubled spaces and invisible or control characters shown as visible escapes, so that "A, B" and "A" + "B" never read alike and no change is hidden ([#6](https://github.com/sintax-tech/mcp-for-nextcloud/issues/6)).
- admin: the settings page is split into five tabs — Overview, Access and permissions, Clients and connections, Data protection and Advanced — reachable by keyboard and by address (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`), so a link opens the tab it names. When the saved exclusion setting cannot be read, the Overview tab says so and points to Data protection, where it is repaired ([#2](https://github.com/sintax-tech/mcp-for-nextcloud/issues/2)).
- admin: the groups that may read the server log and the exclusion tags are chosen in a searchable list with a summary, instead of a long list of checkboxes; the log privacy option has its own "Log privacy" block with a "Learn more" text ([#2](https://github.com/sintax-tech/mcp-for-nextcloud/issues/2)).
- admin: the permission matrix can show one app at a time ("Show apps", kept in the address, e.g. `#access?app=files`), keeps the column of names fixed while scrolling sideways and starts with "Only users who can connect". A legend explains the states ([#2](https://github.com/sintax-tech/mcp-for-nextcloud/issues/2)).
- admin: permission cells show Allowed, Denied or Unavailable. A disabled account or a user with "Can connect" off has one notice across the app-permission columns. For other users, an unavailable app or log role is marked with ⊘ in the affected cells, with the reason on hover and for screen readers ([#2](https://github.com/sintax-tech/mcp-for-nextcloud/issues/2)).
- admin: the "All" menu asks for confirmation that says what it will change: how many users change, how many are left out because the permission is unavailable to them and how many already have the value. Nothing is sent when nothing would change. After a change, "Undo" restores the previous values for 10 seconds (the timer pauses while the pointer or the keyboard focus is on it); one change or undo runs at a time ([#2](https://github.com/sintax-tech/mcp-for-nextcloud/issues/2)).

### Changed

- admin: the "Hidden files & tags" block is now "Excluded from MCP access" and says what it really does. Files and folders with a selected tag, and everything inside them, are hidden from the tools of this connector only; other AI tools or connectors that read the same Nextcloud are not covered. An invisible tag can be assigned or removed only by administrators; a restricted tag also by the groups the administrators allow; any tag that is not selected is an ordinary label. The server log tools respect the exclusion only while "Hide the text of log messages" is on. The README, the technical README and the store description (en, pt-BR, es) use the same wording ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)).
- admin: "All" applies the permission to the users that change on the current page; users for whom the permission is unavailable are no longer written (their effective access was already denied).
- admin: the help of "Server log" says that the log shows file names, addresses and accounts of every user, and that the groups chosen there may read it besides the administrators.
- appinfo: the store description (en, pt-BR, es) mentions the categories of the Tasks tools.

### Known limitations

- "All" applies to the page shown (50 users); to change more users, go through the pages or use the filters.
- "Undo" writes the previous values back: if another administrator, or the same one in another window, changed the same permission in the meantime, that change is overwritten (last write wins).
- Undoing a "Denied" on "Can connect" restores the permission but not the sign-ins that denying it revoked: the user signs in again in the client.
- Categories are compared by their characters: the same text typed with a precomposed and with a combining accent (NFC and NFD) counts as two categories, and a few invisible characters outside the control characters (for example a zero-width space) are accepted in a name.
- The Nextcloud Files interface does not show which files are excluded from MCP access; the exclusion itself is unchanged.

## 1.0.6

### Fixed

- files, notes: with any hidden system tag configured, every file and folder outside `/MCP backups` was reported as not found (since 0.8.0). The backup check of `VisibilityGuard` walked up the parents past the user folder to Nextcloud's root, whose `getParent()` throws by design, and the fail-closed handling read that as "hidden". The walk now stops at the root, so ordinary files are visible again, and a backup of a hidden original stays hidden. Thanks to @NightDragon1 (#5).

### Security

- visibility: after a report by @matt-ek (#4) that motivated the audit, the tag-based hiding was checked on every access path of this connector and the gaps were closed; each item below states the corrected behavior.
- files: `files_undo_batch` refuses a batch whose undo would touch a hidden item — moving it back into view or removing a folder that became hidden — instead of executing it and publishing the paths.
- talk: `talk_quote_file` refuses to quote an attachment whose shared node cannot be resolved and checked, instead of letting the quote through.
- notes: creating or moving a note into a category whose path crosses a hidden folder is refused before any intermediate category folder is created.
- resources: reading an `nc://files` URI of a hidden folder answers exactly like one that does not exist, without revealing that it exists or that it is a folder.
- visibility: a hidden-tags configuration that cannot be validated — the tag manager failing while the list is saved, or a saved value that cannot be read or decoded — now keeps the protection and refuses access instead of silently turning it off; the admin page explains the state and offers the repair.
- visibility: an item reached through a local share inherits the hiding of its owner's source folder chain; team folders, external storage and federated shares are checked along their mounted path.
- visibility: a visibility decision is no longer reused after a move, creation or copy: the item is answered with its current state, not a cached one.
- files, notes: a path segment named "0" is no longer discarded, so a file or folder with that name is resolved — and hidden — like any other.

### Added

- logs, admin: the new administration option "Hide the text of log messages" is on by default. While hidden tags are configured, the log tools hide the text of every message and exception, the user agent and any request method outside the known HTTP and WebDAV verbs, reduce each URL to its fixed route prefix and refuse the `contains` filter, which would match the hidden text. Turned off, the logs come back in full — the option exists because IT teams read exactly those details to diagnose problems.

### Changed

- logs: the app's own error log now records only the class and the code of an unexpected exception, never its message, on every installation: a message can carry the names and paths of the user's files in any form, so this privacy trade-off gives that detail up for good.
- admin: saving an empty hidden-tags list — by the repair button or by unchecking the last tag — asks for confirmation before it turns the protection off.
- docs: the technical README of the app is now available in English (`mcp/README.md`) and Portuguese (`mcp/README.pt-BR.md`).
- ci: GitHub Actions runs the test suite on PHP 8.2–8.5, checks the package and the REUSE compliance on every push and pull request; it never publishes anything.
- appinfo: the store description (en, pt-BR, es) mentions the option that hides the text of log messages while hidden tags are configured.

### Known limitations

- The hiding protects this MCP connector; other connectors or AI tools that read the same Nextcloud are not covered by it.
- A federated share is checked only against the tags of the local installation: the tags and the policy of the remote server cannot be read from here, so the remote side must keep what it may not expose from being shared.
- The log option hides the text of messages; a bare name that another application writes into a structured field of a log entry is not treated.
- The end-to-end verification of the hiding with real shares (local shares, team folders, external storage, federated) runs on a real Nextcloud test instance, beyond the unit suite.

## 1.0.5

### Added

- licensing: the repository follows REUSE 3.3. Every source file carries `SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>` and `SPDX-License-Identifier: AGPL-3.0-or-later`; files that cannot carry a header (JSON, images, fixtures, lockfiles) are covered by `REUSE.toml`. The full license texts are in `LICENSES/` and `mcp/LICENSES/` (AGPL-3.0-or-later for the app; BSD-3-Clause, GPL-3.0-or-later, LGPL-3.0-or-later, MIT and Unicode-3.0 for bundled third-party code), and `mcp/LICENSE` is shipped in the package. `LicensingTest` fails on a file of `lib/` without its SPDX header.
- licensing: `NOTICE` (root and `mcp/`) states the copyright and an additional term under section 7(b) of the GNU AGPLv3: whoever conveys the program or a work based on it, or offers it over a network, keeps the author attribution "Developed by Sintax — https://sintax.tech" in the administration page and in the user documentation, and the copyright and NOTICE files. It does not restrict use, modification or commercial distribution beyond that attribution, and does not apply to the third-party dependencies.
- licensing: `THIRD-PARTY-NOTICES.md` (root and `mcp/`) lists the software shipped in the production package, from `composer.lock`, with version, SPDX license and upstream copyright: smalot/pdfparser (LGPL-3.0-or-later, with the TCPDF-derived parser code and the cups-filters glyph mapping it embeds), symfony/polyfill-mbstring (MIT, with the Unicode-3.0 data tables) and the Composer autoloader (MIT).
- admin: the administration page shows "Developed by Sintax" with a link to https://sintax.tech in its footer, in en, pt-BR and es. The READMEs end with an "About Sintax" section.
- build: `scripts/check_package.php`, run by `scripts/package.sh`, refuses a package without `LICENSE`, `NOTICE`, `THIRD-PARTY-NOTICES.md`, the `LICENSES/` texts and the license files of the bundled dependencies.

### Changed

- appinfo: the store description (en, pt-BR, es) no longer promises a Nextcloud version for every edit: every edit keeps a backup, and Nextcloud versioning applies as usual.

### Fixed

- files, notes: a file locked by `files_lock` (bundled with Nextcloud 35, an app before) is no longer a generic error. A file open in Text (also through Notes in rich mode) or Nextcloud Office, locked by another person or by a WebDAV client is refused with a message in en, pt-BR and es that names the app or the person, says since when and until when the lock lasts when that is known, and what to do. A person is named by display name only, and not at all when that name is just the uid; a lock token never appears.
- files, notes: the locks are read through `ILockManager` (`isLockProviderAvailable` + `getLocks`) before the shared-write confirmation and the backup, and again right before every write: each `putContent`, move, rename and delete of `notes_edit`, `notes_move`, `notes_delete`, `files_edit`, `files_replace`, `files_version_restore`, `files_move`, every item of `files_move_batch` and `files_undo_batch`, the upload of `files_checkout`, the content of a new file of `files_create` or `files_upload` and the backup copy written before an edit, and each of the two writes of a `notes_edit` that changes content and title (a rename refused after the content was saved says so). The check refuses the locks the lock provider reports, and its answer may come from the provider's per-request cache (see Known limitations). Manual locks of other people and app locks are also refused by the storage wrapper of `files_lock` itself, at the write, except in the case of Text described there. A WebDAV token lock is refused only by this check, since that wrapper never enforces token locks outside DAV. A delete or a Markdown rename refused here never reaches the hooks of Text that reset or unlock the open document. A refusal the storage still raises (`ManuallyLockedException`), also in `files_copy`, `files_create` and `files_upload`, is caught and explained, naming the backup copy when one was already taken; when its lock can no longer be matched by token, the holder is reported as unidentified instead of guessed.
- files, notes: in doubt the write is refused. A lock provider that cannot answer refuses the write ("could not check whether the file is locked"), instead of letting it through. A folder that moves (`files_move`, `files_move_batch`, `files_undo_batch`) is checked with everything the user can reach inside it, up to 5,000 items: a locked file inside refuses the move, named unless the user cannot see it (then the plan and the refusal carry no holder, type or date of it), and a larger folder is refused as soon as a listing exceeds what is left of that budget, without reading the locks of its entries (each listing is still read whole, see Known limitations).
- files, notes: the plan of a write (`confirm` missing) warns about a lock (`lock` plus a `warnings` entry of type `file_locked`) without refusing the plan. In `files_move_batch` a locked item is listed under `denied`, so the batch is refused whole before any folder is created or anything is moved; in `files_undo_batch` it is a conflict.
- files, notes: lock semantics follow `files_lock` and never work around it. The user's own manual lock (`TYPE_USER`) lets the write through and keeps the lock; an app lock (`TYPE_APP`, Text or Office) and a WebDAV token lock (`TYPE_TOKEN`) that the provider reports refuse the write, even for their own user. The app never unlocks, never takes or reuses a lock token and never writes in the lock scope of Text or Office. Without a lock provider nothing changes.
- files: with a lock provider present, `files_version_restore` no longer calls the core rollback, which (`VersionManager::handleAppLocks`, Nextcloud 32 to 35) catches the refusal of a Text or Office lock and repeats the write inside that app's lock scope, over the open document. The version is read through `files_versions` and written through the file like an edit, which `files_lock` refuses when the file is locked. The write goes through the normal versioning of the file, as the versions backend decides it; what the app guarantees about the content it replaces is the backup in `/MCP backups`, taken before, as for every edit. The restored file shows the restore time as its modification time and the change as an edit instead of a restore. Without a lock provider the core rollback runs as before.
- checkout: the upload route of `files_checkout` answers **423** for a locked file instead of 500. A lock present before the link is spent leaves the link usable; a lock met afterwards names the backup copy and asks for a new checkout. That route has no user session, so the user's own manual lock refuses there, as `files_lock` does; the plan and the issue of a checkout are checked the same way and point to `files_edit`. The create link of `files_upload` maps a lock to 423 by the failure type instead of comparing translated messages.
- files, contacts: a backup folder locked by `files_lock` is reported as a lock (423 on the checkout upload) instead of a generic backup failure, in `/MCP backups` and in the contact backups.
- logs: a lock refusal is an expected condition, not an error: the app logs it at debug level only, without the path or the token, and `ToolRegistry` no longer reports a `LockedException` from any module as `MCP tool failed`.

### Known limitations

- The app refuses the locks the lock provider reports when it checks, and that answer may come from the per-request cache of `files_lock` (below), so a lock taken after the first lookup of a file in a request may not be seen by the app.
- Manual locks of other people and app locks (Text, Office) are also enforced by the storage wrapper of `files_lock`, which reads the lock afresh at the write; a lock of those types missed by the app's check is then refused by the storage. Except for a delete, or a rename to or from Markdown, of a document of Text: Text's own listener for that event (`BeforeNodeDeletedListener`, `BeforeNodeRenamedListener`) runs before the storage and resets the document (`resetDocument(..., true)`), which releases its app lock; an app lock of Text taken after the app's lookup is released that way before the storage looks at it, and does not stop the delete or rename.
- A WebDAV token lock (desktop client, office apps over WebDAV) is not: Nextcloud enforces token locks only on WebDAV requests, and the storage wrapper of `files_lock` ignores them. The app refuses them by its own check, which is not atomic with the write. Besides, `getLocks()` is answered from the per-request cache of `files_lock`, negative results included, so the check repeated right before a write does not see a token lock taken after the first lookup of that file in the same request: the window runs from that first lookup to the write. There is no guarantee that a write never lands under a token lock.
- The app does not take a lock of its own to close that window: `files_lock` 32 to 35 has no atomic acquisition (a separate lookup and insert, `file_id` without a unique index), and a lock of the app would conflict with the user's own manual lock, be renewed across requests and could be left behind by a process that dies. A real guarantee needs support in `files_lock` or the core.
- A token lock that `files_lock` reads from a remote server (federated shares) may be reported as absent when that lookup fails; the app cannot tell that apart from no lock.
- The 5,000-item ceiling of a folder that moves bounds the lock lookups and the queue, not the reading of each listing: `Folder::getDirectoryListing()` has no limit in Nextcloud 32 to 35, so each folder's listing is read whole before it is compared with what is left of the budget. `Folder::search()` takes a limit but queries the file cache, not the listing, so it is not used as a count.
- Without a lock provider, `files_version_restore` keeps using the core rollback, whose retry inside an editor's lock scope cannot happen without `files_lock` either.

## 1.0.4

### Changed

- appinfo: the store description (en, pt-BR, es) lists the server log module added in 1.0.2.

## 1.0.3

### Changed

- compat: the app now declares Nextcloud 32–35 (it declared only 33). `NextcloudApiContractTest` gained fixtures of v34.0.0 with Deck 1.18.0 and Talk 24.0.0 and of v35.0.0 with Deck 1.19.0 and Talk 25.0.0, and now also fails on a method a newer major removed, not only on one an older major lacks. `CalDavBackendContractTest` covers the DAV backends of 34 and 35. PHP stays 8.2 or later: 32 and 33 still run on it, and 35 requires 8.3 by itself.
- contacts: the embedded CardDAV server no longer touches the private `\OC` class: the web root comes from `IURLGenerator::getWebroot()` and the server container handed to the DAV `PluginManager` from `Server::get(ContainerInterface::class)`. The contract test refuses any use of `\OC` in `lib/`.

### Fixed

- deck: editing a card (title, description or due date) on Deck 1.18 and 1.19 (Nextcloud 34 and 35) cleared its start date, and on 1.18 its colour, because `CardService::update()` writes both fields when they are left out. The current values are now sent back, in the form each release expects.
- talk: reading the history of a conversation whose lobby is on no longer works for a participant who cannot bypass the lobby, as in Talk itself (`#[RequireModeratorOrNoLobby]`); an expired lobby timer is honoured first.
- talk: on Talk 24 (Nextcloud 34) and later, writing in a webinar whose lobby timer has passed was refused, because `Room::getLobbyState()` no longer opens an expired lobby by itself. The module now calls `RoomService::validateLobbyTimer()` first, as Talk's own controllers do; a failing check keeps the lobby closed.
- admin: an error on the admin and personal pages no longer depends on `OC.Notification`, removed in Nextcloud 34. It still goes through `OCP.Toast`, and without it to the connections list's live region or a browser dialog instead of being lost. A test keeps `js/` and `templates/` free of the front-end globals 34 and 35 removed.

## 1.0.2

### Added

- logs: new read-only module for the IT staff, `logs_list` and `logs_analyze`, over the Nextcloud server log through the core's own reader (`ILogFactory` + `IFileBased::getEntries`): no file opened by the app, no shell, no `occ`. `logs_list` filters by minimum level, app, user, time window (ISO 8601), plain text and request id, 1 to 100 entries per page; `logs_analyze` gives totals by level, app and user, the 20 most frequent message signatures with first and last occurrence, the top URL paths and user agents and a histogram by hour. Each answer makes a single `getEntries` call of at most 5,000 entries from the end of the current file and never reads deeper than the last 20,000 eligible entries (at or above the log level; the core walks the file from its end through every line and has no budget of its own); `since` skips older entries without ending the search, since the file is not in time order. It answers `scanned`, `truncated`, `budgetReached`, `oldestScanned` and `nextOffset`. The rotated `nextcloud.log.1` is not read.
- logs: double gate. Only a Nextcloud administrator or a member of a group listed in the new **Server log** block of the admin page sees the tools at all (no group listed = administrators only), and the per-user grant `logs.read` starts **off**, unlike every other read. With `log_type` set to syslog, errorlog or systemd the tools are hidden and the admin page says why.
- logs: every answer is wrapped as untrusted data (`untrusted_log_data: true`, after a fixed warning), with control and invisible characters removed, IPs masked (IPv4 `a.b.x.x`, IPv6 /48), messages cut at 2,000 characters and stack traces reduced to ten frames without arguments. Every call raises the core's `CriticalActionPerformedEvent` (written by admin_audit when enabled) and a line in the app log, with its outcome: a read (`success`) is recorded as a read, and a call denied by the role or the grant, refused for its arguments or failed (`denied`, `invalid`, `read_error`) under a message of its own.

### Changed

- logs: role groups now live in the app's own `mcp_logs_groups` table, created by a packaged native migration and read through `QBMapper`. The first read imports existing `logs_groups` from appconfig without overwriting a previously migrated state. Legacy key cleanup retries on subsequent reads if it fails, without blocking reads or reimporting stale groups. GET returns `version` and PUT requires it; an atomic version increment with compare-and-swap rejects stale writes with 409. `ILockingProvider` remains an extra layer: with `filelocking.enabled=false` it becomes Noop, but the database CAS still protects revocations.
- logs: IPv6 CIDRs are reduced to the canonical /48, including labeled addresses and invalid prefix lengths. Redaction consumes labels of any length in a linear pass (possessive matching without restarting inside a label) and bounds processing to 16 KiB per field, discarding a token crossing that boundary before masking and applying the final 2,000-character message limit. Audit fallback logger failures preserve refusals and withhold successful data.
- grants: the default of a grant is now per module and operation (`GrantPolicy::defaultGranted`); every existing module keeps read on and the rest off.

### Fixed

- files: `files_image_search` no longer fails on Nextcloud 33: the search comparison and binary operator objects are now convertible to string (`__toString`), as the core search backend requires.

## 1.0.1

- appinfo: adds the `ai` store category.

## 1.0.0

First version published in the Nextcloud App Store, signed with the app's store certificate. No functional changes since 0.11.1.

## 0.11.1

### Fixed

- appinfo: `info.xml` has a single `<name>`, without `lang`. Nextcloud accepts only one; with the translated copies the `InfoParser` turned the name into an array and the admin app list showed it as raw JSON. The summary and the description keep their English, Brazilian Portuguese and Spanish versions.

### Changed

- build: the package check is `scripts/check_package.php`, replacing `scripts/check_package.py`, so packaging needs only `composer` and `php`. The rules are the same (`._*`/`.DS_Store` at any depth, PaxHeader entries and pax extended headers, a top level other than `mcp/`, a `vendor/` entry outside the runtime dependencies, an `addScript`/`addStyle` asset missing from the package); the archive is read by a minimal tar reader of its own because `PharData` hides the pax headers. It is stricter than the Python script: an archive that ends right after a pax header, a GNU long name or long link, a truncated or corrupt gzip stream, a negative or oversized base-256 size and a GNU long name over 64 KiB are refused, and metadata bodies are never held in memory beyond that limit. On every older package it was compared with, its report matched the Python one line by line.

### Removed

- the original Node.js stdio prototype at the repository root (`src/`, `tests/`, `package.json`, `tsconfig.json`, `vitest.config.ts`, `.env.example`), unused by the app and its packaging; it remains in the git history up to commit 27739f0 (tag v0.11.0).

## 0.11.0

### Added

- compat: the code runs on Nextcloud 31, 32 and 33; `info.xml` declares only Nextcloud 33 for now, so the store listing offers 33, and widening the range is a one-line change while the contracts below keep 31 and 32 ready. Every Nextcloud API it uses — `OCP`, the DAV app and the internal classes of Deck and Talk, with their methods, constants and the parameters of the untyped calls — is checked by `NextcloudApiContractTest` against the oldest release of each major (v31.0.0, v32.0.0, v33.0.0, with Deck 1.15.0/1.16.0/1.17.0 and Talk 21.1.4/22.0.0/23.0.0), from fixtures generated from their sources; an API a covered release lacks fails the suite unless it has a fallback. The CalDAV/CardDAV backend lists of `CalDavBackendContractTest` now cover the three majors, and a fixture of a release older than the declared range is kept: every declared major must be checked, extra older ones are allowed.
- compat: an optional app older than MCP supports counts as off, exactly as one that is not installed: its tools leave `tools/list`, a call is refused and the admin matrix hides its columns, keeping the saved permissions. The minimums are Talk 21.1.4 (the first Nextcloud 31 release whose `ChatManager::addSystemMessage()` takes the participant) and Deck 1.15.0. The admin page names the app, the installed version and the minimum, in English, Brazilian Portuguese and Spanish.
- appinfo: the store listing. Name "MCP for Nextcloud", summary and Markdown description in English, Brazilian Portuguese and Spanish (modules, safeguards, clients, requirements), author, website, Git repository, two screenshots from `screenshots/` at the repository root (outside the app package), the `integration` category next to `tools`, PHP 8.2 or later and Nextcloud 33 as the only declared release.

### Fixed

- compat (Nextcloud 31): the app no longer depends on what only exists from Nextcloud 32 on. The embedded CalDAV server is `InvitationResponseServer` there, the predecessor of `EmbeddedCalDavServer`, with the `IMipPlugin` added under `dav/sendInvitations` as 32 does, so invitations still go out by e-mail; the users flagged in the policy are listed through `IConfig::getUsersForUserValue` when there is no `IUserConfig` (the container passes null); and the availability of an app uses `isInstalled()` when there is no `IAppManager::isEnabledForAnyone()`.
- calendar: deleting an event with `send_invitations: false` no longer sends cancellations on Nextcloud 31, 32.0.0–32.0.6 and 33.0.0, whose Schedule plugin reads `x-nc-scheduling` on a change but not before an unbind. The dispatcher detaches the plugin's `beforeUnbind` from that one-off server, which is what later releases do when they see the header.
- deck: `deck_followup_cards` works with Deck 1.15 and 1.16 (Nextcloud 31 and 32), which have no `CardMapper::findAllForStacks()`: the cards are read stack by stack with `findAll()`, with the same filters and order.
- deck: a Deck conflict is mapped to its own message. The mapping named `OCA\Deck\ConflictException`, which does not exist in any Deck release; the class is `OCA\Deck\Exceptions\ConflictException`, a `StatusException`, so it was answered as "not allowed".

## 0.10.0

### Added

- files: three tools for the shares of the files and folders the user owns. `files_list_shares` lists the shares the user created — of one file or folder, or of all of them (50 per page, `offset` up to 1000) — with the type, the recipient, the `view`/`edit` permission, the expiry, whether a password exists and the link URL; the password itself is never returned, a hidden node counts as missing and a Talk attachment is listed as not removable. `files_share` shares an own file or folder with a person (`user:<uid>`) or a group (`group:<gid>`), or changes the share that already exists for that recipient (`permission` `view`/`edit`, `expires` in the account's timezone, `note`); the plan shows before → after, says when a web-made share loses its re-share right, and says "nothing to change" when the call would change nothing. The administrator's sharing rules are explained before writing and a refusal of the core becomes a translated message. With `with: "link"` the same tool creates or changes the public link of an own file or folder (one per file, view only); the server generates the password, through the password policy app or a 16-character secure random fallback, validates it against the policy before saving it, and creates it when `password: true` is passed or the administrator requires one. It is returned once, in the result of that execution, and never in the plan, the log or `files_list_shares`; the plan warns that anyone with the link can open the file, and the administrator's link rules (links off, maximum and default validity) are applied before writing. `files_unshare` removes a share the user created, named by the `shareId` of `files_list_shares` or by `path` plus `with`; the plan says who loses access (a person, every member of a group, or "the link will stop working") and that the file itself stays untouched. What is not the user's own — somebody else's share, a share of a file owned by somebody else, a hidden node, an unknown id — is answered as "not found" without revealing it; a Talk attachment is removed in Talk. Both writes are checked again when the call is confirmed, and each one requires the grant of the kind it acts on (`share` or `link`).
- files: two tools create a NEW file, under the existing `files.create` grant. `files_upload` is for any type generated locally (docx, xlsx, pdf, images): the plan names the file, the folder, the link lifetime and the upload limit of `files_checkout` (a declared `size` over it is refused before any link exists), and the confirmed call returns `uploadUrl`, a single-use link valid for 15 minutes bound to the user and the path (never a node id), with the exact `curl -sS -T file -X PUT -H "Content-Type: application/octet-stream"` command. The link is spent on the checkout upload route, which answers 201 with `path`, `size`, `etag` and `fileId`; an empty body creates an empty file, and a multipart body (400), a body over the limit (413), a name taken in the meantime (409) or a folder that went away (404) leave the link usable. `files_create` writes a small text file inline (`.md`, `.txt`, `.csv`, `.json`, `.html`, `.xml`, `.yaml`, `.yml`, up to 1 MB), and its plan quotes the beginning of the text. Neither ever overwrites a file with content: an existing name is refused in the plan, in the confirmation and when the bytes arrive, also when another client creates it with content in between (the file is created empty first, and a file that turns out not to be empty is left alone) or changes it between the creation and the write (it is read again right before the write: another id, ETag or size is a 409 that writes nothing). An empty body for a link whose plan declared a `size` above zero is refused (400) and the link stays usable. The folder must exist (a hidden one answers like a missing one), accept new files and, outside the personal folder, be confirmed with `confirm_shared`; names Nextcloud refuses (`IFilenameValidator`: reserved names, forbidden characters and extensions) are argument errors that never echo the value. The tool guide and the descriptions send a new file to `files_upload`/`files_create` and keep `files_checkout` for replacing an existing one. A new prompt, `create_nextcloud_file`, teaches that flow to anyone with `files.create`; the `edit_nextcloud_file_locally` prompt (`files.edit`) only adds it when `files.create` is granted too. The `files.create` column of the admin matrix now reads "Create folders, copies and new files".
- tools: a tool may list alternative grants (`grantAnyOf`); it is listed and callable with any of them, and checks the one each call needs.
- grants: the Files module gains two operations, `share` (people and groups) and `link` (public links), so an administrator can allow one without the other. Both are new columns of the admin matrix and both start denied.
- deck: a Deck can now be set up from scratch. `deck_create_board` creates a board owned by the user with its lists and first cards (up to 20 lists and 100 cards, optional colour, assignees limited to the user because nobody else has access to a new board) after one confirmation, and the plan shows the whole tree. If a step fails midway nothing is undone: the result lists what was created (`created`), what was not (`failed`, with a safe reason) and `warnings`, so a retry does not duplicate anything. `deck_create_stack` adds a list to a board the user manages, at the end or at a position, and asks `confirm_shared` on somebody else's board. Both use the existing `deck.create` grant.
- deck: `deck_delete_stack` and `deck_delete_board` delete a list or a board only when it is empty: any card, active or archived, refuses the call with the number of cards left (the plan already says so), and the count is taken again when the call is confirmed. Only the owner can delete a board; both go to the Deck trash and can be recovered there. Both use the existing `deck.delete` grant.

### Security

- files: the public upload route of `files_checkout` and `files_upload` refuses everything the headers and the token can refuse — a multipart body, an empty one, a `Content-Length` over the limit, an unknown link, a link of another kind or of another session, a spent or expired one — before a byte of the body is copied to the temporary folder, so a request without a valid link cannot fill the disk.
- files: a `files_upload` link is no longer burned by a refusal that only read: a folder that became shared, lost its create permission or can no longer be updated is answered (409 or 403) before the link is spent.
- files: a lock or a full quota while a new file is written keeps its own message (423 and 507 on the upload route) instead of a generic error, and any other failure is logged with the exception class only.
- files: `files_list_shares` without `path` lists only shares of files the user owns. A share the user created of a received file (a re-share made in another interface) no longer appears, with its link URL or recipient, and takes no place on a page.
- files: the confirmed `files_share` and `files_unshare` execute exactly what the plan showed. The plan carries `plan_state`, an opaque HMAC (with the instance secret) of the action, the share and its fields before and after, never a password, also cited in the line for the model at the end of the plan. The confirmed call gives it back: when the share appeared, changed or went away in the meantime (create → update, update → create, update → nothing, nothing → update, a share removed and made again), nothing is written and the answer is the new plan with "the share changed since the plan; check it again". A confirmed call without `plan_state` is an argument error. The helper (`PlanState`, `PlanChanged`) is generic, for other writes later, and the value is bound to the calling account.
- files: that `plan_state` also binds every argument that changes the effect: the node (id and path), the recipient (kind and id), and for `files_share` the permission, `expires`, `note` and `password` as the call gave them; for `files_unshare` the share id and type. A plan made for one person no longer confirms a share with another person, a group or another file when both would find the same state: the confirmed call writes nothing and answers with the new plan.
- files: an unexpected failure while the link password is validated (a policy listener throwing something other than a refusal, whose message may carry the candidate) or while the share is saved (an `Error` included) becomes the safe "password refused" or "share refused" message. The log keeps the exception class only, so the generated password never reaches it.
- people: a failure of the core search in `users_search` becomes the fixed message "the search for people failed; try again", and the log keeps the exception class only, so the search term never reaches the log.

### Fixed

- files: the checkout upload never writes a body the server could not keep (larger than its declared length, or a temporary file that could not be written): it answers 413 and the link stays usable, where it could have written an empty file.
- files: an undo of `files_move_batch` that stopped halfway (a lock or a permission that changed) no longer ends in a fatal error. It keeps only what did not go back, so the next undo finishes the batch; this was broken since 0.9.0.
- files: `files_move_batch` confirmed with `confirm_shared: true` moves the shared items too, in their place in the order, records them and can undo them. They used to be dropped without a word; without the confirmation the plan still asks for it.
- files: `files_move`, `files_copy` and `files_move_batch` refuse `/MCP backups` and what is inside it, as source and as destination, with the message of the other write tools. Moving or renaming that folder could expose the backup of a file that was hidden later.
- files: the folders of `files_move_batch` (`mkdirs`) go through the checks of `files_mkdir` in the plan and again before the first one is made. A folder is no longer created inside a hidden folder, and a name taken by a file, the backup folder or a folder without the create permission no longer leaves folders made and no batch to undo them; the plan names the folder that cannot be created and is not ok.
- files: `files_image_search` with a hidden `folder` answers "not found", like a folder that does not exist, instead of an empty list.
- files: `files_copy` of a folder with a hidden file or folder inside is refused before anything is copied. The copy gives every node a new id, the hidden tag stays with the original, and the copy of the hidden file was readable.
- files: the plan of `files_undo_batch` says a folder the batch created goes when the undo leaves it empty, as the run does; it used to say the folder stays.
- files: `files_tree` says `truncated` only when an entry was left out, not when `limit` is exactly the number of entries.
- deck: a Deck write no longer answers with an error once it may have been saved. Deck 1.17.5 writes first and only then runs activity, events and notifications, any of which may throw (a default label of a new board, a board event of a new list, a notification of a deleted card). After an exception of the write itself, every write tool (create, edit, move and delete a card, assign and unassign, create a board and a list, delete a list and a board) reads the real state again: a card of the user with the same title created in the last two minutes, the card or list in the trash, the fields and modification time of the edited card, the destination list of the moved card, the new board or list that did not exist before. When the write happened, the answer is a success with the warning "Saved; Deck reported an error afterwards (notification or activity)"; when it did not, the usual error; when the state cannot be read, a result that is not an error and says to read it with `deck_list_cards`, `deck_read_card`, `deck_list_stacks` or `deck_list_boards` before trying again. `deck_create_board` lists a step saved this way as created and one that cannot be confirmed as a warning, never in `failed`. A refusal before the write (permission, validation, session, the rules of the tools) stays an error, and a list or board whose delete could not be taken back after a card arrived is a partial result pointing to the Deck trash, not an error. The log keeps the exception class only.
- deck: that read-back no longer trusts the exception class. Deck's own `NoPermissionException` and `BadRequestException` are also followed by a read-back, because a listener of another app may throw them after Deck saved the data; a real refusal read back finds nothing and stays the usual error. Only the refusals this app raises itself before calling Deck (rules of the tools, session, conflicts, argument errors) skip the read-back.
- deck: an item found again only by likeness is never taken as proof. A card found by owner, title and time, or a list or board with the title that was not in the snapshot, is answered as `confirmed: "probable"` with its id and the warning "Probably created: check it before continuing", and nothing else is written on it: no assignment on the card, no card inside the list, no list or card inside the board. In `deck_create_board` the steps that depended on a probable board or list are reported in `failed` with the reason and the result is not `complete`. Exact reads by id (edit, move, delete, assign of a known card) are still confirmed.
- deck: `deck_delete_stack` and `deck_delete_board` no longer leave a card in the trash with the list or the board. The Deck deletes without looking at the cards, so a card created by another request between the count and the delete was lost with it. The cards are now counted again right after the delete; when one showed up, the board is brought back with Deck's own undo and the list is restored through the Deck update with `deletedAt: 0` (Deck has no undo for a list), and the call is refused as "received cards while it was being deleted; nothing was deleted".
- deck: when that restore throws (the list's `StackService::update()` with `deletedAt: 0`, or the board's `BoardService::deleteUndo()`), the item is read again before anything is claimed: Deck saves `deleted_at = 0` before its activity and events, so an item that is active again is answered like a delete that lost a card. If the item cannot be read at all, the answer is not an error and says to read it with `deck_list_stacks` or `deck_list_boards`.
- notes: `notes_edit` with `content` and a `title` already taken in the category no longer overwrites the note and then fails. Everything that can refuse — the size, the title that cleans to nothing, the title already used — is checked before the first write, and the plan refuses the same title, so nothing is written and the note keeps its content (Notes keeps no backup).
- notes: the plan of `notes_edit` compares the whole content to say what changes. An edit that only touches the part after the excerpt the plan shows was reported as "nothing changed"; it is now listed as a content change and the plan gives the size before and after.
- notes: `notes_create` asks for `confirm_shared` when the notes folder, or the nearest existing folder of the category, is a share, a team folder or an external storage, like the other writes of Notes; its plan lists the shared resource.
- talk: `talk_attach_file` refuses a blank or too long caption before it creates the share, where it used to create the share, post the card and report the caption as not sent.
- talk: `talk_read_messages` takes an attachment envelope only from the comments Talk writes itself (rich-object and system messages). A participant who typed JSON shaped like an envelope no longer hides their text or forges an attachment id.
- checkout: the links of `files_checkout` and `files_upload` are refused (403, the same answer as a revoked grant) when the account was disabled or removed after the link was issued, before the body is read and without spending the link.
- calendar: the CLASS of a private or confidential appointment of someone else is now read component by component, as the iCalendar defines it: the master and each `RECURRENCE-ID` override have their own. An override is treated by its own CLASS and, with none, takes the one of the master; `CLASS` in lower case counts as well. `calendar_list_events`, the collision warning of the plans and every other read of the calendar hid only what the master said, so a private occurrence of a public series, altered by its organiser, showed its title. A change to an object with a private or confidential component in someone else's calendar is refused as before, now also when only an override carries the CLASS.
- calendar: the collision warning of an all-day event looks at the day in the zone of the account. It searched the day in UTC, so an appointment of the evening before could be reported as overlapping and one of the late evening of the day was missed; all-day events still compare day against day, in any zone.
- calendar: the free/busy check of the guests of an all-day event also asks about the day in the zone of the account (and the update about the days added to the current ones), where it asked about the day in UTC, so a guest busy the evening before was reported and one busy late in the day was missed.
- calendar: a `CLASS` the standard does not define (`X-FOO`, for example) is handled as `PRIVATE`, as RFC 5545 §3.8.1.3 asks, in `calendar_list_events`, the collision warning, the plans and the changes of someone else's events; it used to show the event in full.
- calendar: the plan of a write in a calendar that belongs to someone else names the calendar and its owner in the text the person reads, not only in the structured `shared` block.
- deck: the `total` of `deck_list_cards` counts the cards before `offset`: a short page at `offset: 50` with 3 cards answers 53, not 3. Whether the page was short is decided on what Deck returned, not on what is left after the filter, so a full page is never taken for the end of the list. A page with no card past the first one (an `offset` beyond the end) answers `total: null`, not the `offset` it was asked for.
- notes: `notes_edit` with a new `title` also checks the permission before it writes anything, as Nextcloud requires for a rename: update on the note, delete at the source and create in the folder. A note in a share without the create or delete permission is refused in the plan and in the write, before the shared-content confirmation and before the content is put, so it is no longer overwritten by a call that then reports failure.
- files: the folders `files_move_batch` creates (`mkdirs`) ask for `confirm_shared` when they are made inside somebody else's share, a team folder or an external storage, like `files_mkdir`. Only the folders the batch moves into were judged; one made there with no move using it, with only personal items moving, was created with no shared-content confirmation. The plan lists them in `sharedDirs` and in the text, and nothing is created before the confirmation.
- calendar: the collision warning of a timed event looks at an existing all-day event by the day in the zone of the account, as the warning of an all-day event already does for a timed one. It compared the window with UTC midnights, so an evening appointment was reported against the next day's all-day event and a late one missed the all-day event of its own day (the same in the early morning east of UTC).
- files: an undo of `files_move_batch` that stops on any exception, not only the ones Nextcloud types, keeps only what did not go back, like the run of the batch does, and answers the generic "Failed to move the item." for it. An exception of another kind used to leave the batch whole, and the retry read the items already back home as conflicts.
- talk: the attachment envelope of `talk_read_messages` is also taken from the legacy verbs of a voice message and of a recording (`voice-message`, `record-audio`, `record-video`), which rows written before the spreed migration 14000 still carry. Talk 23 writes voice and recordings as `object_shared`, which was already read, with their `attachmentId`, so `talk_quote_file` already worked on them; the envelope is still taken only from the verbs Talk writes itself, never from a comment a participant typed.

### Known limits

- files: Nextcloud has no create-if-absent, so `files_upload` and `files_create` create the file empty and then write it. An empty file that another client created with the same name in the instant before is taken over, and a client that writes the same name between the last read and the write overwrites it or is overwritten as with any other write, the earlier content kept as a version. Two links for the same path create the file once; the second answers 409 and stays unspent. A write that fails after the empty file appeared leaves that empty file in place (nothing is deleted) and says so.

- deck: `deck_delete_stack` and `deck_delete_board` cannot make "only delete when empty" atomic, because Deck's `CardService::create()` never looks at the `deleted_at` of the list. A card created by another request in the instant right after the second count may go to the trash with the list or board; it is recoverable there. This is documented in the tool description and the gateway.

## 0.9.1

### Fixed

- oauth: a request authenticated with an OAuth access token now has the token owner in the session (`user_id`) while it is handled, through `IUserSession::setUser()`, and the uid is removed again when the request ends. The apps whose services receive the injected `userId` saw a null user before: creating or deleting a Deck card was written and then answered with an error, so the client retried and the card was created twice. The trash bin reads the same session value, so the versions of a note deleted under OAuth could be filed outside the trash of the user. Requests with Basic and an app password keep the session of the core login untouched.
- deck: the tools refuse a call before anything is written when the session the Deck services read is not the authenticated caller, with a clear message instead of an error after the write.

## 0.9.0

### Added

- tools: every write of every module returns its confirmation plan as readable Markdown, so the person reads what they are about to approve. The text is translated to the user's language (English, Brazilian Portuguese and Spanish), and the machine-readable plan is kept in `structuredContent` as before, so nothing a model relies on changes.
- calendar: the plan of an event creation, update, move or transfer warns about a collision with another event, shows the availability of the attendees and names the shared calendar it will use. Collision and availability are computed from the instance; a shared calendar is found by name and owner instead of its DAV path.
- people: a new `users_search` tool finds account ids through the sharing search, so the model asks before guessing an id.
- contacts: `contacts_search_contacts` also searches the read-only accounts catalog of the instance when `addressbook` is omitted or is the catalog path, so colleagues are found. It uses the official `OCP\Contacts\IManager` and applies the admin user-enumeration settings (autocompletion, group restriction, full-match). Each account result carries `accountId`; a personal contact with the same e-mail is kept and gains it. `contacts_read_contact` reads an account with public fields only, `contacts_list_addressbooks` lists the catalog as read-only, and create/edit/delete refuse it.
- deck: creating a card can assign board members, with their names in the plan.

### Changed

- tools: an invalid argument error now names the field and the rule that rejected the value, in camelCase too, so the model can correct the call without guessing. Only safe details are exposed.

### Security

- plans: text written by other people (file names, reasons, excerpts, contact fields, note bodies, deck and talk names) is escaped before it enters a plan, so a name cannot inject Markdown or HTML into the confirmation the user reads. Newlines are normalized and long text is truncated.

### Fixed

- calendar: an event or task in the trash is found under the renamed `-deleted` URI the core uses, so deleting and reading it back works.
- calendar, tasks: the state after a write is read straight from the database instead of the CalDavBackend cache, so the result of a create or update is current.
- files: a batch move puts the plan items into the folders it creates itself, instead of placing them before the folders exist.
- files: the checkout upload accepts the raw bytes whatever the content type sent by the client.
- talk: quoting a file resolves the attachment by its full `ocRoomShare` provider id, instead of the room id alone.
- calendar: the availability log records only the exception class, with no message and no e-mail.

## 0.8.2

### Fixed

- calendar: event creation, update, move and transfer, task creation and Talk event references failed with "Unexpected error" on Nextcloud 33 because the calendar store called a backend method that does not exist; a contract test now checks every backend method the adapters use.
- logging: an unexpected tool failure now logs the exception message (truncated to 300 characters, file paths replaced, no tool arguments) next to the exception class; the client still receives the generic error.

## 0.8.1

### Fixed

- resources: the server failed to boot (HTTP 500 on every request) because the resource registry built the tool guide without its modules; the guide now comes from the tool registry, and a test boots every registered service factory.

## 0.8.0

### Added

#### Files
- Content search with excerpts via `files_search`. When `fulltextsearch` and `files_fulltextsearch` are enabled and indexed, searches file contents (`search_mode: content`) and returns excerpts with node resolution. Gracefully falls back to file name search (`search_mode: name_only`) with an internationalized notice when fulltextsearch is not active or encounters an error. An optional `mode` parameter (`auto` | `name`) allows callers to force name-only search.

#### Notes
- Content search via `notes_search`. Searches note title and Markdown content (up to 1 MiB), returning contextual snippets around query matches, optionally filtered by category.

#### Contacts
- List personal/shared address books, search and read contacts, create/edit through the native CardDAV pipeline, preserving unknown vCard fields, parameters, groups and version. The system user directory is excluded; tools and admin columns follow Contacts app availability.
- Permanent deletion through native CardDAV, with an explicit no-trash warning and the full vCard in the plan. After confirmation, a verified `.vcf` backup is saved in the acting user's `/MCP backups/Contacts/<address-book>/` before DELETE; backup failure prevents deletion and the result gives the backup path for import through Contacts.

#### Tasks
- List VTODO calendars, list/read/create/edit/complete/delete simple tasks through core CalDAV, independent of the optional Tasks app. Undated tasks are included; private/confidential shared tasks are hidden. Deletion uses calendar trash and refuses zero retention. Recurring tasks can be read; writes to series or tasks with participants are refused.

#### Contacts and Tasks
- Separate admin read/create/edit/delete grants with writes off by default; actual before/after plans, central `confirm: true`, shared-owner acknowledgement, optional client ETag and mandatory current `If-Match` for edit/delete, pagination, friendly localized titles/messages and English tool guides.

#### OCR
- Detects the Workflow OCR app (`workflow_ocr`), with no terminal step and nothing that blocks the app. It writes the recognised text into the PDF as a new version, so `files_read` already reads it. A PDF or image with no text layer now comes back as a normal answer with `text_layer: false` and a notice that depends on whether the app is active (ask the admin to install it or view the page as an image; or wait, since it processes in the background by the admin's rule). `files_version_read` does the same.

#### MCP Resources
- Capability advertised as `resources: {}` in both legacy `initialize` (2025-06-18) and modern `server/discover` (2026-07-28) eras.
- `resources/list` exposes `mcp://guide`, rendering the tool guide in Markdown dynamically filtered by the authenticated user's active grants and enabled apps.
- `resources/templates/list` exposes URI templates `nc://files/{path}` (when `files.read` is granted) and `nc://notes/{id}` (when `notes.read` is granted and the Notes app is enabled).
- `resources/read` fetches file contents (with text extraction and truncation up to 100,000 characters; small binary files returned as base64 blobs up to 512 KiB) and note contents (up to 1 MiB).
- Non-existent, ungranted, or hidden resources return standard JSON-RPC errors (`-32602` in modern era, `-32002` in legacy era) and never return empty content.

#### Hidden files and tags
- Hide sensitive files and folders by Nextcloud system tag. The administrator can select system tags in the admin settings; any file or folder bearing those tags (or inside a tagged folder) is completely hidden from MCP tools, behaving as if it does not exist (read, search, list, tree, image view/search, notes, talk attachments, and checkout token usage all return "not found", and write destinations report forbidden without leaking existence).
- Automatic system tag propagation to backups created under `/MCP backups`. VisibilityGuard also mirrors visibility if the original file still exists.
- Zero cost when no hidden tags are configured (default behavior unchanged).

#### OAuth and clients
- ChatGPT (`chatgpt.com`) is allowed by default next to `claude.ai`; an explicit `oauth_client_hosts` value is kept.
- Optional built-in native client `nextcloud-mcp-native` for local programs such as Gemini CLI (loopback redirects, PKCE, off by default; key `oauth_native_client_enabled`). The consent screen labels it "Local program (native client)".

#### Admin and personal settings
- "OAuth clients" section to edit the allowed `client_id` hosts (default `claude.ai`, `chatgpt.com`) and to enable the native client for local programs, showing its `client_id` to copy and the accepted loopback redirects.
- Own "MCP for Nextcloud" entry with the app icon (`img/app.svg`, `img/app-dark.svg`) in both the administration and the personal settings menus.
- "Status" block with the endpoint to copy, the service switch, the app version, eligible and connected users and active OAuth connections.
- The `files_checkout` upload limit is edited in whole MiB in the "Status" block (`GET`/`PUT /api/checkout-limit`), next to the effective limit and PHP's `post_max_size` ceiling; before, it only changed with `occ`.
- "Active connections" block lists every OAuth connection (user, client host or local program, signed in, expires) with search and paging, and revokes one connection or every connection of a user at once (`GET /api/connections`, `DELETE /api/connections/{id}`, `DELETE /api/connections/users/{uid}`). Token hashes are never returned.
- Personal: the user sees their own OAuth clients and revokes any of them (`GET /api/my/connections`, `DELETE /api/my/connections/{id}`, restricted to the signed-in user).
- Admin matrix: filters "only users who can connect" and "only connected users" (server-side, exact total), user counter, pagers on top and bottom, a "connected" badge, optional compact rows, column tooltips and a retry when loading fails.
- Dedicated "Hidden files & tags" section in the MCP admin settings with badge indicators for tag types (invisible, restricted, collaborative) and warning if collaborative tags are selected.
- "OCR" block shows whether Workflow OCR is active and, when it is not, a link to the App Store and the note that it needs `ocrmypdf` on the server.

#### Docs and tool guide
- The Files notes in the tool guide explain `text_layer` and OCR.
- Per-client connection instructions (Claude, ChatGPT, Gemini CLI, app password) in both READMEs, plus the admin "OAuth clients" section and what OAuth enforces. ChatGPT and Gemini CLI are compatible by code and are not yet proven against a real client: the end-to-end test happens after 0.8.0 is deployed.

### Changed

#### Files
- `files_checkout` accepts any file type (DOCX, XLSX, PDF, images) up to the upload limit instead of refusing non-text files; `files_edit` and `files_replace` stay text-only.
- `files_read` and `resources/read` share one text reader, so the 20 MiB limit is checked before any extraction on both paths.

#### OCR
- `files_read` of an image no longer fails as unsupported; it answers with `text_layer: false` and the notice.

#### OAuth and clients
- Client metadata documents are accepted when `token_endpoint_auth_methods_supported` is a non-empty list of strings containing `none` (the singular `token_endpoint_auth_method` is only used when the list is missing), as ChatGPT publishes `private_key_jwt` as its singular value.

#### Admin and personal settings
- The admin page left *Additional settings* and the personal page left *Personal info*; both now live in the app's own section. The admin page is split into blocks (status, OAuth clients, hidden files & tags, OCR, permissions, active connections), each a core settings section.
- Admin matrix: sticky header rows, modules in alternating bands, and one "All" menu per module (allow or deny one operation, or every operation of the module, for the users on the page) instead of the ✓/✕ buttons under each column.

#### Tool guide
- The obsolete `approval_id` gate is gone from the guide's list of extra gates.

### Security
- OAuth: turning the native client off now also invalidates its already-issued access tokens (checked on every request) and deletes its grants, codes and refresh history immediately.
- OAuth: every redirect back to a client (authorization code, `access_denied`, redirectable errors) now carries `iss` (RFC 9207) exactly once, built in one place; required by clients such as Gemini CLI. Disabling the native client also stops it from exchanging codes or refreshing tokens.
- Checkout downloads reject changed file ETags with HTTP 409 before spending the token or opening the content stream.
- CIMD metadata downloads use streaming, reject oversized Content-Length and read at most 64 KiB plus one byte before closing the response.
- Reusing a rotated refresh token revokes all access and refresh grants for its client and owner, including rotation races.
- OAuth authorization and MCP Bearer authentication require the mcp scope; offline_access alone grants no endpoint access.
- MCP always validates its own Bearer credentials and rejects requests whose session belongs to another account.
- Administrative service shutdown and eligibility removal immediately delete OAuth tokens and pending codes; disabling or deleting an account also revokes credentials.
- Hidden tags fail closed: when at least one hidden tag is configured, an error while resolving a folder chain or reading tags hides the item instead of showing it. The warning logged carries no file name or path.
- Backups inherit hidden tags before they receive content: the backup file is created empty, tagged, then filled and size-checked. If tagging fails, the user's write is aborted and only an empty backup remains, so nothing sensitive is left untagged and nothing is deleted.
- A write whose destination collides with a hidden item is refused with the same message as any other forbidden destination. Refusing at all can still tell that the name is taken; this residual risk is documented.

## 0.7.0

### Added
- Files: local editing for an agent with a shell. `files_checkout` returns two single-use links, a download valid for 5 minutes and an upload valid for 15, so the content never passes through the model; the raw upload, `files_replace` and `files_version_restore` work through them. The link is the credential: 32 bytes, bound to the user, the file id and the ETag, and only a keyed hash is stored. An ETag that diverged before the upload writes nothing.
- Files: folder reorganization. `files_tree` is a bounded scan, `files_mkdir` creates a folder and refuses an occupied target (behind `files.create`), `files_copy` and `files_move` move a file or a folder, `files_move_batch` plans first and records what it did, and `files_undo_batch` is the one place Files removes anything. A node outside the user's own folder also asks for `confirm_shared: true`.
- Files: node ownership is now known, and a write to a shared node (a wrapped share included) passes through the `confirm_shared` guard, the same one Deck and Calendar use.
- Files: the tool schema is validated as an array or an object, with `items`, properties and limits.
- Calendar: the five writes (`calendar_create_event`, `calendar_update_event`, `calendar_delete_event`, `calendar_move_event`, `calendar_transfer_event`) go through the official CalDAV pipeline, so invitations, sequences, sync-tokens and the trash behave like the web interface. Internal attendees are resolved to the account e-mail.
- Calendar: the five writes are available out of the box, with no terminal step. The admin matrix decides who gets them (all off by default); every write also needs `confirm: true`, the Nextcloud ACL and `confirm_shared` on someone else's calendar, plus the optional ETag. `occ mcp:calendar-selftest` stays only as an optional diagnostic that reports and changes nothing. The supported Nextcloud range is declared in `info.xml` (33); the internal CalDAV server is covered by the tests and that range, so a new Nextcloud version needs a new app version.
- Calendar: `send_invitations` follows the native `dav/sendInvitations` setting. The plan and the result report `imipEnabled` and never claim an e-mail was sent.
- Calendar: every write needs a plan and an explicit confirmation. Without `confirm: true` the tools return the plan (current versus proposed values, participants, invitation and trash consequences, optional ETag) and never touch DAV. Nothing is stored as an approval and no token is issued: grants, ACL, `confirm_shared` and the optional ETag all still apply on the confirmed call.
- Tools: every write of every module (Files, Notes, Deck, Calendar and Talk) returns a plan without `confirm: true` and changes nothing. The registry decides it from the tool's grant operation, publishes the same `confirm` argument on every writing tool, and runs the write only on the confirmed call, where grants, ACL, ETag and `confirm_shared` are checked again. Nothing is stored between the two calls.
- Files: images the model can see. `files_image_view` returns a reduced preview from the Nextcloud preview generator (1568 px by default, under 1 MB) as MCP image content, `files_images_view` returns up to 6 under a shared 4 MB budget, and `files_image_search` finds images by name, folder, modification date and user-visible system tag (the automatic tags of Recognize included), newest first. All three only read.
- Tool guide: `mcp_guide` describes every tool the user can call, with its parameters, limits and whether it needs `confirm` or `confirm_shared`, built from the same definitions `tools/list` serves. It is in English and tells the model to relay it in the user's language.
- Language: a per-user translation base (`UserL10n`), the tool titles in the user's language and the instructions in English. The language comes from the account preference.
- Language: Spanish (`es`) is included for the whole app, next to English and Brazilian Portuguese, as a first translation that still needs a native review.

### Changed
- Talk: a write without `confirm: true` returns the plan and sends nothing; with it, permissions are checked again and the message goes out. The single-use `approval_id` of 0.6.10 is gone, and nothing about an approval is stored on the server.
- Instructions: the server tells the model to read the tool guide before using a tool it does not know, to show the plan of every write and ask the user before repeating it with `confirm: true`, and never to invent an approval id.
- Tools: the descriptions are read by the model, so they stay English, while every message shown to the user comes out in their own language. Deck, Notes, Talk, Files, the shared messages, the checkout pages, Calendar and the selftest are all covered now.
- Calendar: every string the user sees lives in `CalendarMessages`, next to the schema of the Calendar tools.
- Package: the vendored sabre pins are exact and the vendor allowlist is part of the packaged app.

### Fixed
- Files: a diff has no phantom line and marks the end of the file when the file ends without a newline.
- Files: the upload takes raw bytes only, reads the token from the route and spends the link only at the moment of writing.
- Files: a link that was already spent answers 409 and asks for a new checkout, instead of suggesting the same link again.
- Files: the version restore refuses the backup folder, exactly as the edit already did.
- Files: `files_replace` writes over the raw bytes and the app never deletes a file.
- Files: an undo that stopped halfway can be retried.
- Files: the checkout routes resolve the controller and `sharedBy` resolves the UID of the share.
- Language: the `files_move_batch` confirmation and move-failure messages are translated, and Calendar selftest failures report `FAIL` like the rest of the report uses English statuses.
- Protocol: an invalid-argument error names the field in camelCase too.
- Language: the generic server error and the invalid-argument messages are translated, and the review findings F1-F5 of the two translation phases are fixed.

## 0.6.10

### Added
- Talk: every write (`talk_reply`, `talk_attach_file`, `talk_quote_file` and the new tools) first returns the exact draft. It is sent only after the user approves it, and an approval works once, for those exact arguments.
- Talk: `talk_message_user` (one-to-one) and `talk_send_batch` (several messages under one approval, each result reported per item).
- Talk: `talk_create_group`, behind a new `talk.create` grant that is off by default. An invitation that fails is reported per participant.
- Talk: `talk_reply` can link a Deck card or a calendar event. The user must be able to read it, and a private event of someone else is refused.

## 0.6.9

### Added
- Deck: `deck_followup_cards`, a follow-up of the boards the user owns or manages. It returns cards grouped by assignee, with due date, an `overdue` flag and a link to the card. Every Deck card payload now carries `assignedUsers`, `overdue` and `url`.
- Tools and admin matrix columns of an optional app (Notes, Calendar, Deck, Talk) are hidden while that app is disabled, for everyone or for a given user.

### Fixed
- Deck: due dates and `overdue` use the user's time zone. Near midnight, a card due today no longer shows as late or as due tomorrow for users west of UTC. A `YYYY-MM-DD` written by the tools is stored as local midnight.
- Deck: editing a card's title or description no longer resets the due time set in the Deck web interface.

## 0.6.8

### Fixed
- Admin matrix shows only the Calendar permissions that are actually enabled (read).

## 0.6.7

### Changed
- Calendar is temporarily read-only. Its write tools are hidden from `tools/list`, and direct calls fail closed until they are validated against a real Nextcloud 33 CalDAV setup. Grants already saved are kept.

## 0.6.6

### Added
- Deck and Calendar ask for confirmation before writing to a resource owned by someone else. The server refuses the first call, and the assistant must ask the user before repeating it with `confirm_shared: true`.

## 0.6.5

### Fixed
- Talk and Deck pass an `IUser`, not a UID, to the app availability check.

### Added
- Bilingual README (English and Brazilian Portuguese) and AGPL-3.0 license.

## 0.6.4

### Added
- Talk module: list conversations, read messages without marking them as read, reply (optionally quoting), share a file into a conversation, quote an already shared file.

## 0.6.3

### Added
- Friendly tool titles in the client.

### Changed
- Backup names use the user's local time.

## 0.6.2

### Added
- MCP `2026-07-28` (stateless, `server/discover`) alongside the classic `initialize` flow on the same endpoint.

## 0.6.1

### Fixed
- Accepts every known MCP protocol version and ignores the version header on `initialize`.

## 0.6.0

### Added
- Admin matrix of users × permissions, with search, group filter and a JSON API.

## 0.5.1

### Added
- Deck module: boards, stacks and cards, including create, edit, move and delete.
- Translated OAuth consent screen.

## 0.5.0

### Added
- OAuth sign-in for claude.ai and Claude Desktop: PKCE S256, CIMD client identity and rotating refresh tokens.

## 0.3.1

### Fixed
- The admin switch no longer uses a Nextcloud-reserved configuration key.

## 0.3.0

### Added
- Calendar module.

## 0.2.x

### Added
- Files and Notes modules. Text extraction from PDF, DOCX and ODT. Edits keep a verified backup in `/MCP backups`.
- Production-only package built by `mcp/scripts/package.sh`, with a checker for macOS metadata and missing assets.
