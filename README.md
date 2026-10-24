<div align="center">

# MCP for Cloud

**A native Nextcloud app that turns your Nextcloud into an MCP server.**
Let Claude and other MCP clients work with your files, notes, calendars, contacts, tasks, Deck boards and Talk conversations, with each user's own permissions, and nothing leaves your server that the admin did not allow.

[![Tests](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![Nextcloud App Store](https://img.shields.io/badge/App%20Store-mcp-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/mcp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue)](#license)
[![XERJ Corpus Hub contributor](https://hub.xerj.org/contributors/jhowjaw/badge.svg)](https://hub.xerj.org/contributors/jhowjaw)

English · [Português (Brasil)](README.pt-BR.md) · [Deutsch](README.de.md) · [Español](README.es.md)

</div>

---

## What's new in 1.2.1

See [the changelog](CHANGELOG.md#121) for the full changes.

- **Backup settings ([#17](https://github.com/sintax-tech/mcp-for-nextcloud/issues/17)):** in *Data protection*, choose one folder name directly in each acting user's personal root (default `MCP backups`; `.mcp-backups` is allowed). The folder appears with the first copy. Changing the name never moves or deletes content; all previously configured names, including the legacy default, remain protected against MCP writes and keep the backup-mirror visibility rules.
- **Files copies:** on by default. An administrator can suspend MCP pre-write copies only while native `files_versions` is enabled. Availability is checked again for the acting user; unavailable versioning makes copies mandatory and existing operations still refuse when their Versions prerequisite is missing. This does not guarantee a native version for every file/backend. Contact deletion always requires a verified copy in the configured folder, even with Files copies suspended. Earlier backups are preserved.
- **Mail ([#1](https://github.com/sintax-tech/mcp-for-nextcloud/issues/1)):** `mail_list_accounts` and `mail_list_mailboxes` list only the caller's accounts and cached folders/counters. Mail is optional and requires the `mail.read` grant. These calls do not synchronize IMAP, read messages or change mail state. Open the account in Mail once if its folder cache is empty; an old cache can return old information. The opt-in integration cell uses disposable Dovecot and Mail, not a production mail server.
- **Subtasks ([#18](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18)):** tasks return their `parent`; create and edit set it within the same calendar or clear it with `null`, and `tasks_list_tasks` lists the direct subtasks of one parent. Loops and nesting deeper than 64 levels are refused; confirmed MCP parent writes run one at a time and need the server's file locking. Contributed by @mturgeonferland.
- **Visibility:** a nested ordinary folder named `MCP backups` is no longer mistaken for the user's root backup folder. Scope, exclusion tags and fail-closed checks still apply.

Backup paths shown below use the default name; a configured name replaces that root component.

Earlier changes, including 1.2, are kept in [History](HISTORY.md).

## Why

AI assistants are most useful when they can reach the tools a team already uses. Plugging them into Nextcloud usually means scripts with shared passwords, broad API tokens or data copied elsewhere. **MCP for Cloud** runs **inside** your Nextcloud:

- **One URL per instance.** `https://cloud.example.com/apps/mcp/`. No subdomain, no extra service, no Node/Docker/AppAPI.
- **Sign in with Nextcloud.** Clients such as claude.ai use OAuth ("Sign in"): the user logs into their own Nextcloud and clicks **Allow**. App passwords (HTTP Basic) still work for other clients.
- **Admin in control.** Service on/off switch, per-user eligibility, **user × permission matrix** and **grants by native Nextcloud group**. Reading is on by default for eligible users; every write, move, delete, transfer, restore or share is **off until the admin grants it**, sharing a file and creating a public link being two separate grants. Groups only grant permissions; individual user overrides (`Allow` or `Deny`) always take precedence.
- **Nextcloud ACLs always apply.** The app never widens what a user can already do in Nextcloud. In 1.1.0 an optional per-resource scope lets an administrator narrow a user's MCP access to specific folders, calendars and task lists, edited on the admin page itself.
- **Exclude sensitive files from MCP access by system tag.** Administrators can configure system tags (recommended: restricted or invisible) to make tagged files and folders completely invisible to the tools and resources of this MCP connector, including everything below a tagged folder (the server log tools only while "Hide the text of log messages" is on). While any exclusion tag is configured, folder sharing, folder public links, folder copying and moving a folder to another directory are refused in MCP so a hidden descendant cannot be exposed indirectly. It fails closed: if a tag lookup errors, the item stays hidden. Backups taken by the app inherit the tag before they receive any content.

Earlier releases: see [HISTORY.md](HISTORY.md).

## Features

| Module | Read | Write (each one needs an admin grant) |
|---|---|---|
| **Files** | list, search (by name or content with snippets via fulltextsearch), search images by tag/date, view images and batch previews (JPEG, PNG, WebP, GIF, TIFF, PDF), read text (TXT/MD, PDF, DOCX, ODT; a scan without text returns `text_layer: false` and, with the optional Workflow OCR app active, gains text in the background), list and read file versions | edit text with a diff and a partial replacement, with a verified backup in the configured folder when MCP copies are enabled (Nextcloud versioning applies as to any edit); restore a stored version; check any file (DOCX, XLSX, PDF, images, up to the upload limit) out to an agent with a shell through a one-time link; reorganize folders: scan the tree, create a folder, copy, move, move a batch and undo it. **Never deletes the file.** Also list the shares you created, share an own file or folder with a person, a group or a public link (with a generated password when asked), and remove a share — which removes the access, not the file. *(While any exclusion tag is configured, sharing, linking, copying or moving a folder to another directory is refused in MCP; individual files and same-parent folder renames continue to work.)* |
| **Notes** | list, read, search (by title and Markdown content) | create, edit, move between categories, delete (only when the trash bin can recover it) |
| **Calendar** | list calendars and events (recurrence, time zones, all-day) | create, edit, move, delete and transfer events through the real CalDAV pipeline, with participants. The writes are off until an administrator grants them in the admin matrix (no terminal step), and each one returns a plan first: **nothing is written before the user approves it.** |
| **Contacts** | list personal/shared address books, search and read contacts, including the read-only accounts catalog of the instance (colleagues, with `accountId` for invitations) under the admin user-enumeration settings | create and edit while preserving unknown vCard fields; permanently delete only after a verified `.vcf` backup in `/MCP backups/Contacts` |
| **Tasks** | list task calendars and VTODO tasks, including undated tasks, optionally only those with a category; read tasks, with their categories | create, edit, complete and delete simple tasks through CalDAV, including their categories; deletion uses calendar trash and is refused when retention is zero |
| **Deck** | boards, stacks, cards, labels on Nextcloud 32–35, visible file associations (the share id is distinct from the file id), and a **follow-up** of the boards you manage: cards grouped by assignee, with due date and due time, overdue flag (in your own time zone) and a link to the card. Dependencies are listed only on Nextcloud 34 and 35. An association or relation you cannot see is omitted, with no count | create, edit, move and delete cards, assign or unassign board members, edit labels, and link or unlink one existing visible file without deleting it; native dependencies only on Nextcloud 34 and 35; create a board with its lists and first cards, or a list in a board you manage; delete a board or a list only when it is empty, straight to the Deck trash |
| **Server log** | for administrators and the groups they choose: list the entries of `nextcloud.log` with filters, and summarize them (totals, frequent signatures, URLs, user agents, entries per hour). Off by default for everyone; IPs masked, output marked as untrusted, every read audited. While exclusion tags are configured, the admin option "Hide the text of log messages" (on by default) hides the text of messages and exceptions, the user agent and the URL detail, and refuses the `contains` filter; turned off, the logs come back in full for diagnosis | — (read only) |
| **Talk** | conversations and messages (never marks anything as read) | reply (optionally quoting a message or linking a Deck card or calendar event you can see), direct message to a user, batch of messages, share or quote a file, create a group conversation (separate grant, off by default). **Nothing is sent before the user approves: a call without `confirm: true` returns only the plan (recipient, final text, attachments, what would be created or shared) and runs nothing; with `confirm: true` it checks the permissions again and sends. No approval id, token or record is kept on the server.** |
| **Mail** (optional) | the caller's own Mail accounts (id, name and address; no server settings or credentials; accounts delegated by other people are not listed) and the folders of each account, with their role and the unread/total counts as the Mail app last cached them. Needs the separate `mail.read` grant and Mail 5.0.0 or later enabled for the user. It does not synchronize IMAP, list or read messages, or mark anything as read; when an account's folder cache is empty, it asks you to open the account in Mail once | — (read only) |

Plus:

- **Conversational confirmation before every write (`confirm: true`)**: any tool that changes data (`operation != 'read'`) only executes when called with `confirm: true`. Without it, the server returns the plan (`requiresConfirmation: true`) and makes no changes. The assistant must present the plan to the user and only repeat the call with `confirm: true` after explicit approval. No approval state or tokens are kept on the server.
- **MCP Resources**: dual-era support for Model Context Protocol resources (`resources: {}`). Clients can list `mcp://guide` to read the tool guide directly, discover resource templates (`nc://files/{path}`, `nc://notes/{id}`) filtered by user grants and apps, and read text files (with automatic text extraction), notes, and small binary blobs (<= 512 KiB). Ungranted, missing, or hidden resources fail closed.
- **OCR through Workflow OCR**: when the Workflow OCR app is installed, scanned PDFs gain a text layer that `files_read` reads normally. Without it, a PDF or image with no text comes back with `text_layer: false` and a notice, and the admin page shows the OCR status with a link to install it. Nothing is blocked.
- **Tool guide** (`mcp_guide`): the model asks the server what each tool does, its parameters, limits and whether it needs confirmation, built from the same definitions `tools/list` serves, filtered by the user's grants and apps. The guide is in English and the model relays it in the user's language.
- **Friendly tool titles** in the client ("Search files", "List calendars") and MCP annotations (`readOnlyHint`, `destructiveHint`) so clients can ask before risky actions.
- **Safety confirmations** for resources that belong to someone else: shared folders, team folders, other people's Deck boards and calendars. The server refuses the first call and returns a ready-made message, and the assistant must ask the user before repeating it with `confirm_shared: true`. *(Deck and Calendar since 0.6.6; Files and Notes since 0.7.0)*
- **Optional apps respected**: tools and admin matrix columns of an app that is disabled (for everyone or for a given user) are hidden.
- **Localized UI**: admin matrix, personal page, consent screen and every message a tool shows follow the language of the user's Nextcloud account. All modules are covered (Files, Notes, Calendar, Contacts, Tasks, Deck, Talk, the shared messages, the checkout pages and the calendar selftest). English, Brazilian Portuguese, Spanish and German are included (German by @matt-ek); tool descriptions stay English, because the model is what reads them.
- **Both MCP eras**: stateless MCP `2026-07-28` (`server/discover`) and the classic `initialize` flow (`2025-06-18` and earlier) on the same endpoint.

## Contacts and tasks

Contacts use core CardDAV and only your own and shared address books. Editing changes supplied fields and preserves unknown properties, parameters, groups and the vCard version. The Contacts app must be enabled for the user. Tasks use VTODO in calendars supporting that component and work without the optional Tasks app or Calendar UI app. Task categories (the `CATEGORIES` property) are free text, as in the Tasks app: `categories` is returned when reading and listing, and on create and edit a list replaces all the categories, `[]` clears them and omitting it keeps them; `tasks_list_tasks` can filter by one `category`, ignoring case. Subtasks link to their parent as in the Tasks app (`RELATED-TO`): `parent` is returned when reading and listing, on create and edit a parent UID makes the task its subtask, `null` makes it top-level and omitting it keeps it; the parent must be a visible task of the same calendar, and loops and nesting deeper than 64 levels are refused; `tasks_list_tasks` can list the direct subtasks of one `parent`.

Both modules have separate `read`, `create`, `edit` and `delete` grants; every write starts disabled. Calls without `confirm: true` return the actual before/after plan and create neither backups nor DAV writes. Shared collections require `confirm_shared: true`. An ETag is optional; when provided it must match, and confirmed edits/deletions always use the current ETag as `If-Match`.

**Contact deletion is permanent: Nextcloud has no contact trash bin.** Its plan shows every vCard field and this warning. After confirmation, the full original vCard is saved and read back in the acting user's `/MCP backups/Contacts/<address-book>/<name-or-uid>.<YYYYmmdd-HHMMSS>-<unique-suffix>.vcf`. Existing files are never selected for overwrite; backup failure prevents deletion. The result gives `backupPath`; import that file through Contacts to recover the contact. This copy is a safety net, not native contact trash.

Task deletion uses native calendar trash and is refused when `dav/calendarRetentionObligation` is `0`. Recurring tasks can be read; writes to recurring tasks or tasks with participants are refused to protect their series and scheduling. Completed tasks are excluded from listings by default; use `include_completed` to include them. Contact searches and task listings accept `limit` (up to 200) and `offset`, and return `nextOffset` for more results.

## Deck in 1.2.0

Labels, due time, native dependencies and a native file link are part of this release. The app does not imitate an API that is absent, and it does not upload, copy or delete the file.

- **Labels** (`deck_list_labels`, `deck_edit_card_labels`) are available on Nextcloud 32 through 35 when Deck is enabled. Proved Deck releases: 1.16.8, 1.17.7, 1.18.5 and 1.19.0. A label from another board is refused. The write changes only the requested ids.
- **Due time** is read and written with the card. Input is a local date `YYYY-MM-DD` (midnight in the account time zone), `YYYY-MM-DDTHH:MM` or `YYYY-MM-DDTHH:MM:SS` in that time zone, or a time with `Z` or `±HH:MM`. The card returns `duedate` as the local date and `dueAt` as an ISO time with the account offset. There is no IANA field. `overdue` stays day-based. A local time that does not exist (a daylight-saving gap) is refused. `dueBefore` stays date-only.
- **Dependencies** (`deck_read_card_dependencies`, `deck_add_card_dependency`, `deck_remove_card_dependency`) appear only on Nextcloud 34 and 35, proved with Deck 1.18.5 and 1.19.0. On Nextcloud 32 and 33 those tools are absent. Direction is outgoing: `cardId` is the origin and `targetId` is the card it depends on. `assignDependentCard(A, B)` stores B in the dependent cards of A. Self-relations and cycles through readable cards are refused. A relation to a card you cannot read is omitted, with no count and no id. A cycle through those cards cannot be detected. A concurrent change between the last check and the native write can still create a cycle.
- **Files** use the native Deck share (share type 12) on Nextcloud 32 through 35. `deck_list_card_files` and `deck_unlink_card_file` are on all four versions. `attachmentId` is the share id and is not the `fileId`. Listing needs `deck.read` and `files.read`. Unlinking needs `deck.edit` and `files.read`, keeps the original file, and an editor of the card can remove a visible association created by someone else. Independent shares may keep access. A missing association and one you cannot see share one refusal: no count and no handle. Opening the card in Deck is the generic next step, and that answer does not confirm that a hidden association exists. This app does not unlink a hidden file. A folder you can already see, and an archived card or board you can already see, are refused in plain language.
- **Linking** (`deck_link_card_file`) needs three grants that already exist: `deck.edit`, `files.read` and `files.share`. Reading and unlinking do not need `files.share`. The plan shows the file path, the owner, whether this is a reshare, the current recipient total, a limited list of names, the shares you created, and read-only access (READ=1) for current members and for people who later join the board, a group or a team (a Nextcloud circle). Unlinking that share removes it for everyone. Access is checked only when the link is created. The server does not revoke the link afterwards. A policy turned on later, a change of board, group or team membership, and the moment between the last check and the native share can change who can read the file. The plan hash does not make the share atomic. The server does not attest human consent. A new plan or an uncertain result is not a record that the write succeeded or that a person approved it.
- **Refusals** use the same answer when the facts cannot be read. A link is refused for a Guest (the hide-users override is not applied to this OAuth session), for group-only sharing, when group sharing is off and the board includes a group or a team, when internal expiry is mandatory, when sharing or resharing is off, and when a recipient is not a fully resolved local user, group or team. Those answers do not ask you to change a setting or to sign in another way.

## Requirements

- Nextcloud **32 to 35**, each release checked by the API contract tests against the oldest build of its major (with the matching Deck and Talk); Deck 1.16+ and Talk 22+ on 32, up to Deck 1.19 and Talk 25 on 35
- PHP **8.2+** with `zip`, `mbstring` and `dom`
- Optional apps, only for their own tools (hidden while the app is disabled): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versions (`files_versions`, required for edits), Deleted files (`files_trashbin`, required for deletes)
- Deck and Talk are optional: their tools appear only when the app is enabled in a supported version; an older release counts as disabled, its tools stay hidden and the admin page says which version brings them back

## Installation

**From the App Store (recommended).** As an administrator, open *Apps*, search for **MCP for Cloud** and click **Download and enable**. From a terminal, the equivalent is `sudo -u www-data php /var/www/nextcloud/occ app:install mcp`. The store package is signed, and updates arrive through the normal Nextcloud app updater.

**Manual build (alternative, for development).**

1. **Build the package** (on any machine with PHP and Composer):

   ```bash
   cd mcp
   ./scripts/package.sh          # creates build/mcp-<version>.tar.gz
   ```

   The script bundles only production files and checks the archive, including macOS metadata (`._*`), extra top-level folders and missing assets.

2. **Copy and enable it on the server:**

   ```bash
   scp build/mcp-<version>.tar.gz user@server:/tmp/
   ssh user@server
   sudo tar -xzf /tmp/mcp-<version>.tar.gz -C /var/www/nextcloud/apps/
   sudo chown -R www-data:www-data /var/www/nextcloud/apps/mcp
   sudo -u www-data php /var/www/nextcloud/occ app:enable mcp
   ```

   Adjust paths and the web server user to your setup. With Docker, run `occ` inside the container.

Then, **configure** in *Administration settings → MCP for Cloud* (the app has its own entry with an icon in the settings menu): turn the service on, mark who may connect (individually or by native Nextcloud group), grant write permissions where needed, and select the system tags that exclude sensitive files from MCP access (restricting users to specific folders, calendars and task lists is described in [Per-resource scope](#per-resource-scope)). The page has five tabs, each with its own address: **Overview** (endpoint to copy, service switch, app version, eligible/connected users, active connections and the editable checkout upload limit in MiB, shown next to PHP's `post_max_size` ceiling), **Access and permissions** (the **Permissions** matrix — with search, group, "who can connect / connected" and app filters, a fixed column of names, one level per module in every cell (None, Read, Edit, Full, with an Operations view), only the real exceptions marked, an actions menu per row, a selection bar that changes several people after a confirmation that counts who changes, "Undo" for 10 seconds, and pagers on top and bottom — and the **Group grants** editor to grant *Can connect* and module operations to native Nextcloud groups with a preview before saving; with a reset of individual overrides to group inheritance (preview, undo) and an **Access inspector**, see [Individual overrides, reset and the access inspector](#individual-overrides-reset-and-the-access-inspector); and the **Resource scope** editor, with the restriction of one user to folders, calendars and task lists, see [Per-resource scope](#per-resource-scope)), **Clients and connections** (OAuth clients and active connections), **Data protection** (**Excluded from MCP access**, the system tags that exclude files) and **Advanced** (**Server log**: the groups whose members may read it besides the administrators, **Log privacy**: the switch that hides the text of log messages while files are excluded by tags, and **OCR**).

Server log access groups, group grants, policy revisions and per-resource scopes are stored in the app's own versioned tables, created automatically by its native migrations without any terminal command. Existing appconfig server log groups are imported on first read. The admin APIs return a `version`/`revision` with GET and require it on writes; stale saves get 409 without overwriting concurrent changes.

## Connecting a client

Every client talks to the same URL, `https://cloud.example.com/apps/mcp/`, and authenticates as the person using it, with that person's own permissions. Only the authentication step differs:

| Client | Authentication | Something the administrator has to do first |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | OAuth **Sign in**, Claude's published identity (CIMD) | Nothing: `claude.ai` is allowed by default |
| **ChatGPT** | OAuth **Sign in**, OpenAI's published identity (CIMD) | Nothing: `chatgpt.com` is allowed by default |
| **Gemini CLI** | OAuth with the app's built-in native client | Tick **Allow local programs** and copy the `client_id` |
| **Self-hosted harness** that takes a `client_id` and returns to its own domain | OAuth with a self-hosted client the administrator adds | **Add client** under *Self-hosted clients*: name it, list the harness's redirect URI and copy its `client_id` |
| **Any other MCP client** | App password (HTTP Basic) | Nothing |

### Claude (claude.ai, Claude Desktop, Cowork and Claude Code)

1. *Settings → Connectors → Add custom connector*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Authentication: **Sign in** (OAuth), client: **Claude's published identity** (CIMD). No headers needed.
4. **Connect**: your Nextcloud opens, you log in and click **Allow**.

`claude.ai` is in the allowed client hosts out of the box, so nothing has to be changed on the server. If an administrator narrowed that list, Claude is refused with `invalid_client` before the login page even opens: ask them to put `claude.ai` back in *Administration settings → MCP for Cloud → OAuth clients → Allowed client hosts*.

### ChatGPT

1. In ChatGPT, add a new connector for an MCP server and paste `https://cloud.example.com/apps/mcp/` as its URL.
2. Authentication: **Sign in** (OAuth). ChatGPT identifies itself with the client identity document published at `chatgpt.com`; the app downloads that document to learn the redirect URIs it registered. No headers, no app password.
3. **Connect**: your Nextcloud opens, you log in and click **Allow**.

`chatgpt.com` is allowed by default, so an installation that was never configured already accepts ChatGPT. If an administrator narrowed the list to other hosts, ChatGPT is refused with `invalid_client` before the login page opens — ask them to add `chatgpt.com` back in *Administration settings → MCP for Cloud → OAuth clients → Allowed client hosts*.

### Gemini CLI

Two steps, one from the administrator and one from the user, with no terminal on the server.

**Administrator** — *Administration settings → MCP for Cloud → OAuth clients*:

1. Tick **Allow local programs (native client)**. It is off by default.
2. The section then reveals the `client_id` to copy — `nextcloud-mcp-native` — and the redirect URIs it accepts.

**User** — create or edit `~/.gemini/settings.json` on your own machine:

```json
{
  "mcpServers": {
    "nextcloud": {
      "url": "https://cloud.example.com/apps/mcp/",
      "oauth": {
        "clientId": "nextcloud-mcp-native"
      }
    }
  }
}
```

3. In Gemini CLI, run `/mcp auth` (or `/mcp auth nextcloud`).
4. Your Nextcloud opens in the browser: log in and click **Allow**. Gemini CLI is listening on a free port it picked itself, so the browser returns to `http://localhost:<port>/oauth/callback`. The app accepts `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` and `http://[::1]/oauth/callback`, and ignores the port for loopback addresses (RFC 8252 7.3), so the random port still matches.

Why a built-in client: Gemini CLI has no published client identity document, so the app ships one fixed public client for local programs. Its id is the same for everyone on the instance, and that is safe because it grants nothing by itself — the login, the eligibility, the personal connection, the administrator's grants, the consent screen and PKCE all still apply. Turning the switch off also stops the client from exchanging an authorization code and from refreshing a token.

### Self-hosted harnesses (custom redirect URI)

For an MCP harness you run yourself (on your own domain) that supports OAuth and lets you type a `client_id`, but publishes no client identity document of its own. A harness that does publish one only needs its host added to **Allowed client hosts**.

**Administrator** — *Administration settings → MCP for Cloud → OAuth clients*:

1. Under **Self-hosted clients (custom redirect URIs)**, click **Add client**. There are none by default, and none is accepted until one is added.
2. Give it a **Name**, for example `QwenPaw`. People see this name on the consent screen ("Allow QwenPaw to access MCP with your account?") and in their connections. It must be unique and at most 64 characters.
3. In **Accepted redirect URIs**, enter the harness's callback URL, one per line, exactly as the harness sends it (for example `https://harness.example.com/oauth/callback`), and click **Save**. Each one must be an absolute `https` URI without user name, `*` or `#fragment`, up to 2000 bytes; plain `http` is accepted only for loopback addresses (`127.0.0.1`, `localhost`, `[::1]`). From 1 to 20.
4. Copy the `client_id` shown after saving, for example `nextcloud-mcp-custom-3f9c2a7b1d04`. Each client gets its own random id, which does not change when it is renamed.

Add one client per harness: each has its own name, redirect URIs, **Enabled** setting and `client_id`, so one can be turned off or deleted without touching the others. At most 20 clients.

**In the harness**: add an MCP server with the URL `https://cloud.example.com/apps/mcp/`, choose OAuth, and set the client id to the copied `client_id` with no client secret (it is a public client with PKCE). Connecting opens your Nextcloud: log in and click **Allow**; the consent screen shows the client's name, says it was added by your administrator, and names the host it returns to.

The redirect URI has to match one of that client's URIs character for character — a different path, query, **port** (also on `127.0.0.1`, `localhost` and `[::1]`: register `http://127.0.0.1:8080/cb` and only port 8080 returns) or trailing slash, or another client's URI, is refused before the login page. Unticking **Enabled**, deleting the client or removing one of its URIs revokes every connection, code and refresh token of that client, and only of that client (the same people's other clients stay connected): the change and the deletion are one all-or-nothing step, so if the server cannot delete them the change is not saved either. A consent page that was already open, a code exchange or a refresh that started before the change never succeeds after it, and a client that is turned on again, or a URI that is added back, does not bring an old connection or an old consent page back: connect again. Renaming it, adding a URI or enabling it signs no one out. A save made from an out-of-date page (another window or administrator saved first) is refused and the page reloads, so it can never turn a client back on or put a removed URI back. The `client_id` grants nothing by itself: the login, eligibility, personal connection, administrator grants, consent and PKCE all still apply.

### Administrators: OAuth clients

*Administration settings → MCP for Cloud → OAuth clients* decides which clients may connect, without any terminal step:

- **Allowed client hosts** — the hosts whose published client identity document the app is willing to download, separated by commas; `claude.ai` and `chatgpt.com` by default. Exact host names only: no `https://`, no port, no path, no `*`, and at least one host has to stay. The host is the one in the URL the client uses as its `client_id`. Saving replaces the whole list, and removing a host refuses that client immediately, even when its document is already cached.
- **Allow local programs (native client)** — enables the `nextcloud-mcp-native` client described above and shows the `client_id` and the accepted redirect URIs to copy.
- **Self-hosted clients (custom redirect URIs)** — the clients described above, each with its name, **Enabled** setting, `client_id` and the exact redirect URIs it may return to, port included.

How the three kinds of client differ: a client that publishes an identity document (Claude, ChatGPT) must have its host in **Allowed client hosts**; the **native client** (Gemini CLI and other local programs) is one fixed client that may return only to `http://localhost`, `http://127.0.0.1` or `http://[::1]` at `/oauth/callback` and may choose its port, because it cannot know it in advance (RFC 8252 7.3); a **self-hosted client** returns only to the exact URIs the administrator registered for it and never gets the free-port exception. App passwords (HTTP Basic) are independent of every OAuth client: deleting a client does not revoke them, and they are revoked in *Personal settings → Security*.

These controls are administrator-only and CSRF-protected, and they report a refused save. A configuration you set yourself is not overwritten by the defaults: an `oauth_client_hosts` value saved before 0.8.0 keeps being used as it is.

### Other MCP clients (app password)

Create an app password in *Personal settings → Security*, enable the connection in *Personal settings → MCP for Cloud*, then configure the client with the URL above and HTTP Basic auth (`username:app-password`). Quick check:

```bash
curl -u 'alice:APP-PASSWORD' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revoking access

- **User**: *Personal settings → MCP for Cloud → Disconnect* blocks the next request and revokes OAuth tokens. The same page lists the user's own OAuth clients (client, signed in, expires) with **Revoke** for each one. App passwords are revoked in *Security*.
- **Admin**: remove eligibility (individually or by removing the group grant / group membership) or turn the service off. Both apply on the next request. *Administration settings → MCP for Cloud → Active connections* lists every OAuth connection (user, client, signed in, expires) and revokes one, or all of a user, at once; the user can sign in again while they may connect.

## Security

- Every call re-checks the authenticated identity, the service switch, user eligibility (an individual override, or a grant of one of the user's native groups), the personal connection and the admin grant for that exact operation, and then every resource check must pass; when several refuse, the answer follows the precedence
  `Per-resource scope > Exclusion by system tag > Native Nextcloud ACL`. After the access and operation permission checks, an unreadable resource scope returns 503 before any item is looked up. An excluded resource answers "not found" even where the native ACL alone would answer "forbidden"; a refusal by the native ACL alone keeps Nextcloud's answer. Per-resource scope covers Files, Calendar and Tasks.
  Exclusion and per-resource scope only narrow access; they never grant or widen what Nextcloud ACLs allow. An excluded resource returns the exact same "not found" response as a nonexistent one and is omitted from listings, search and previews; a folder, calendar or task list out of scope behaves the same way, and a scope that cannot be read answers 503 instead of listing everything.
- OAuth: PKCE S256, exact redirect URI match (only the native client and the clients that publish an identity document may use a free port on an `http` loopback URI, per RFC 8252 7.3; a self-hosted client matches the registered URI exactly, port included), client identity documents accepted only from the allowed hosts the administrator edits in the admin page (default `claude.ai` and `chatgpt.com`), the public-client method `none` as the only accepted token authentication method, tokens stored only as HMAC hashes, one-time rotating refresh tokens.
- A client identity document is downloaded over HTTPS from an allowed host only, without following redirects, capped at 64 KiB and cached for an hour. Dropping a host from the list blocks the client at once, cache or no cache.
- Every redirect back to a client carries `iss` with this server's issuer identifier (RFC 9207) — on the authorization code, on a denial and on redirectable errors alike. Errors raised before the redirect URI is validated are shown on a local page instead of being redirected.
- The native client is off by default. Once enabled it is a public client with a fixed, non-secret `client_id` that may only return to a loopback callback, and it grants no access on its own: the login, the eligibility, the personal connection, the administrator's grants, the consent screen and PKCE all still apply. Turning it off also stops it from exchanging an authorization code or refreshing a token.
- The admin "OAuth clients" section is administrator-only, CSRF-protected, refuses an invalid host list instead of writing it, and shows the effective state and any save error.
- No passwords, tokens or file contents in logs. Errors returned to clients are generic.
- The consent screen is CSRF-protected and cannot be framed.
- Found a vulnerability? Please report it privately to the maintainers instead of opening a public issue.

### Group permissions, guarantees and limits

In *Administration settings → MCP for Cloud → Access and permissions → Group grants*, administrators can grant *Can connect* and module operations to Nextcloud groups, with a preview before saving that shows the permissions added and removed, the current members and the credential holders that may be signed out (an estimate; it does not show the effective access of each member after the change).

- **How group grants combine with user overrides:** groups only **grant** (`Allow`); they never deny or narrow. If a user has an individual override (`Allow` or `Deny`) for *Can connect* or for a specific operation, that individual override always wins. Without an individual override, the user inherits `Allow` if any of their trusted groups grants the permission; otherwise the module's default applies (`Allow` for `read`, except `logs.read`; `Deny` for every write and for *Can connect*).
- **Why only native Nextcloud groups (`'Database'` backend) can grant permissions:** a group can grant MCP permissions only when Nextcloud serves it exclusively through a single backend whose reported name is `'Database'`. Groups coming from LDAP, SAML, third-party apps, an unidentified backend, or multiple backends at once appear disabled in the group list with the reason. External backends do not reliably deliver membership or deletion events to Nextcloud and can share group IDs across backends; **to give MCP access to members of an external group, grant permissions to those users individually in the *Permissions* matrix.**
- **What "awaiting confirmation" (`needs_confirm`) means and how to resolve it:** if a group creation or deletion is rolled back or observed while another database transaction is open, or if a group's backend classification changes, the app marks the group as **awaiting confirmation**. While in this state, the group grants nothing, and its saved permissions are preserved rather than deleted automatically. To restore the group, open *Access and permissions → Group grants*, review the group's permissions and click **Confirm current policy**.
  - A group awaiting confirmation only makes a user's access temporarily unavailable (HTTP 503, without revoking their OAuth connections) when the user's eligibility or tool permission depended **exclusively** on that group. If the user has an individual `Allow` override or another valid group that grants the permission, they continue working normally. In `tools/list`, a tool whose grant is temporarily unknown is omitted rather than failing the whole list (and if a tool accepts alternative grants, one known allowed grant is enough).
  - When an administrator removes *Can connect* from a group (or deletes a group), if the app cannot verify remaining eligibility for any member with active credentials, the save is refused (503) without changing anything so the administrator can retry.
  - When an administrator deletes a group, the app first suspends it (awaiting confirmation) and only then lists the members that hold credentials; the group is marked as being deleted only after every cut was recorded. If something fails midway, or the process stops, the group stays suspended and nothing is erased; meanwhile what it granted answers "unavailable". Every new connection that depended on the group renews its state and is refused if the suspension wins. Residual limit: if the suspension loses the race three times, a connection made in that interval only becomes valid again if an administrator explicitly confirms the group's policy.
  - When someone leaves a group, the exit is recorded even if the person has an individual *Allow*; a reset in progress at that moment is refused (conflict) and, repeated, cuts.
  - Returning an individual *Allow* to *Inherit* cuts the connection only if the user actually loses eligibility; if a group still keeps the user eligible nothing is cut, and in doubt the change is refused without cutting.
- **Guarantee and declared limits:** the app guarantees enforcement **by delivered events** — it applies every user and group signal that Nextcloud delivers outside a foreign database transaction. Because Nextcloud does not expose transactional hooks or authenticated backend identities, the following limits apply by design:
  1. **Group deleted and recreated with the same ID (`GID`) when signals are lost:** if the PHP process dies before the end-of-request processing runs, during the short window between Nextcloud's commit and that deferred processing (including a delete and recreate within the same transaction), or if a foreign transaction is still open when the process exits, a newly recreated group with the same `GID` may inherit the previous group's saved policy, and concurrent requests during that window may still evaluate the old policy.
  2. **Backend name `'Database'`:** Nextcloud does not reserve or authenticate group backend names (`INamedBackend`). Any group backend installed by a server administrator that reports its name as `'Database'` is treated as the native database backend.
  3. **Backend source changing away and back between observations:** if a group's backend changes to an untrusted source and changes back to `'Database'` before the app observes it, the intermediate change is not detected (any observed loss of trust suspends the group into awaiting confirmation).
  4. **In-memory caches in long-running processes (`occ` / workers):** Nextcloud's `Database::groupExists` keeps a positive cache, `AppManager` keeps its own cache of enabled apps, and `IUserManager::get` returns memoized `IUser` objects. Normal web requests use a fresh PHP process per request and are unaffected, whereas long-running CLI or worker processes may observe stale state until they finish.
  5. **Decided exception when revoking OAuth connections on uncertain user events:** by default, when in doubt about whether a user lost eligibility (or when an account is deleted), the app revokes the user's OAuth connections and requires signing in again. The single intentional exception is when a user event is captured inside another caller's open database transaction (where the app never runs SQL, because a SQL error on PostgreSQL would abort Nextcloud's own transaction) and, by the time the app processes the queued recheck without an event timestamp, the user is still eligible: in that case, neither old nor new credentials are revoked, so a user who remained eligible is not unnecessarily disconnected.
  6. **Cost on large installations (measured, 1.1.0):** deleting a group and taking *Can connect* from a group cost time proportional to the number of users who have already connected an MCP client (not to the size of the group): with about 1,000 of them, roughly 9 to 19 seconds in one request, and the preview of taking *Can connect* away about the same; opening a group of 5,000 members takes 1.5 to 2 seconds. Figures measured on one machine with a local database; on slower hardware or a remote database they grow in proportion. An optimization is planned for 1.3.0.

### Individual overrides, reset and the access inspector

- **Back to group inheritance:** in *Access and permissions → Permissions*, choosing *Inherit* in the window of a cell removes its individual override, so groups and defaults decide again; the actions menu of a row has *Give everything back to inheritance…*, and the people selected on the page (the selection bar, with *Select every user on this page*) can be reset together with *Give back to inheritance…*. The cell's *Inherit from groups and defaults* saves immediately, using the inheritance shown in its window, and asks for confirmation if the person would lose *Can connect*; the row and selection reset actions first open the before/after preview described below. Both offer *Undo* for 10 seconds. One reset takes at most 50 users and 2,500 cells.
- **Preview, before and after (row and selection resets):** nothing is saved before a preview. It lists the overrides to remove (allowed, denied, invalid) and, cell by cell, the result before → after (an individual override, the groups that grant it, the default, or *Unknown*), with the number of results that change, stay the same or are unknown. For users who lose *Can connect* it warns, with their number: “N users lose “Can connect” and the access it gives. Their MCP connections are revoked, and undoing this does not sign them back in.” It also says when a group keeps a user connected, and that removing an individual Deny for *Can connect* lets the groups decide again, which may give access. If what a user inherits cannot be read, the result is shown as unknown, a reset that would remove *Can connect* is refused until the groups can be read, and a retry is always your own click.
- **Refused when the table changed:** the reset is sent with the policy revision the preview was read at (or, if the preview could not be read, the revision of the table). If the permissions changed since then (in this page, by another administrator, by a change of a group's grants or by a member leaving a group), nothing is written: the preview closes or says that the table is older than the preview, and the table has to be reloaded before the reset can be reviewed again.
- **Undo:** after a reset the page offers *Undo* for 10 seconds, restoring the removed overrides from the server's receipt. It does not reconnect anyone who was signed out.
- **Limit: pinning an Allow that is already inherited.** A cell that is allowed only through a group has no value of its own, and the table has no direct control to save an individual Allow over it. To keep that Allow if the group changes, uncheck the box (Deny) and check it again (Allow), as the help text on the page says. Do not do this for *Can connect*: denying it signs the user out.
- **Access inspector:** *Access and permissions → Access inspector* is for administrators only and only reads; nothing is changed. Enter a user ID to see every operation with its result (*Allowed*, *Blocked* or *Unknown*), the first check that blocks it (the account, the service, eligibility, the user's connection, the app of the module, the server log availability and role, the grant, or the resource scope), where the grant comes from (an individual value, named groups, the default, or a group awaiting confirmation) and, for each module, whether the user is restricted to some resources (*Restricted to N*). The same page explains one path in the user's files and one calendar or task list, with the same policy checks plus the Nextcloud permission of the user and, for a path, the exclusion tags. For an excluded path it gives the reason, and names the excluded folder when the exclusion is inherited from it; these details are shown only to administrators, while the tools keep answering "not found". When a check cannot be confirmed the answer is *Unknown* (*Not confirmed: … so access is not known*), never *Allowed*. The inspector uses the resolution the tools use, not a parallel rule.
- **Audit of the inspector:** every question is written to the Nextcloud log at *info* level (administrator, inspected user, kind of question, module and a result code; never a path, a calendar or a name), so it shows up only when the server `loglevel` is 1 or lower; the default (2) does not record *info* lines, and this is not a guaranteed audit trail. A failed inspection is logged at error level with the class of the failure only.

### Excluded from MCP access

In *Administration settings → MCP for Cloud → Data protection → Excluded from MCP access*, administrators choose the system tags (restricted or invisible tags are recommended) that hide sensitive files and folders from this MCP connector:

- **Inheritance and storage coverage:** a tag on a file or folder covers the item itself and every file and folder below it at any depth, whether created through MCP, WebDAV or the Nextcloud Files UI. An item reached through a local share inherits the exclusion of its owner's source folder chain. Team folders (`groupfolders`) and external storage (`files_external`) are checked along their mounted path (the folder above a mount root is resolved again by its path, so external storage and individually shared team-folder files are not falsely blocked). Federated shares are checked only by local tags (see the limits below).
- **Surface covered:** all `files_*` tools (listing, tree, read, name and full-text search, image previews and image search, text edit/replace, create, upload, `mkdir`, copy, move, batch move and undo, versions, shares and one-time `files_checkout` download/upload links), `notes_*`, MCP resources (`nc://files/{path}` and `nc://notes/{id}`), Talk (`talk_attach_file` and `talk_quote_file` check the caller's view of the file and refuse excluded files as not found; `talk_read_messages` returns `attachmentId: null` when the file of a room attachment is excluded), Tasks (`ATTACH` properties pointing to local Nextcloud URLs `/f/`, `/s/` or `/remote.php/...` on any host, IP, loopback or alias, as well as Calendar `X-NC-FILE-ID`/`FILENAME` parameters, are stripped from `tasks_read_task` and from the plans and results of the Tasks write tools when the target file is excluded, while the server's stored object keeps them on edits; public folder links are stripped while exclusion tags are active), Calendar (event outputs carry no `ATTACH`/file URLs), Deck (card outputs omit `attachmentCount` to avoid revealing files outside the caller's scope or hidden by exclusion tags) and Server log (while "Hide the text of log messages" is on).
- **Deck labels:** `deck_list_labels` lists labels of a readable board. `deck_edit_card_labels` adds/removes only the requested IDs, preserving all other labels. It requires `deck.edit`, an editable active card and labels from its own board. Preview first; repeat its `plan_state` with `confirm: true` (and `confirm_shared: true` on another owner’s board). A changed state needs a new plan. Partial or unverified results must be checked in Deck before further changes.
- **Native Deck dependencies:** `deck_read_card_dependencies` requires `deck.read` and appears only on NC34/35 with the compatible native API (container-proved Deck1.18.5/1.19.0; newer patches require the version/API checks). It reads outgoing relations: native `assignDependentCard(A,B)` means B is in A.dependentCards, represented as origin A → target B; this does not automatically block tasks. Only readable nodes/edges appear, ordered by IDs, with no hidden count or handle. No pagination: the whole readable reachable subgraph must fit 100 nodes, 500 edges and shortest-path depth 20; otherwise the call refuses. Cycles through unreadable cards cannot be detected, so the visible graph does not prove the global graph acyclic. `deck_add_card_dependency` / `deck_remove_card_dependency` require `deck.edit`, origin EDIT and target READ, including removal. Each call changes only A→B; both cards and boards must be active. Preview + `plan_state` + `confirm:true` (and `confirm_shared:true` when needed); self-relations and detectable cycles are refused. Changed state needs renewed approval; duplicate/absent removal performs no write. Another change between the final check and the native write can still create a cycle. Check uncertain results in the UI before retrying.
- **Deck file associations:** `deck_list_card_files` requires `deck.read` + `files.read` and lists only native `file` associations whose nodes pass Files scope, exclusion tags and READ. `attachmentId` is a share ID, distinct from `fileId`; legacy uploads and folders are unsupported. `deck_unlink_card_file` requires `deck.edit` + `files.read` and an active editable card/board. Preview and confirm with `plan_state` + unchanged `file_state` (and `confirm_shared` when needed). Unlinking preserves the original and independent shares, revoking this association for all current/future members even when another member created it. Invisible and nonexistent associations have the same refusal, without counts or handles. An absent association is a no-op only with the valid original receipt and the original file still visible; otherwise request a new preview or use Deck. A change between the final validation and native unlink remains possible. Linking a file is covered in [Deck in 1.2.0](#deck-in-120): it uses deck.edit, files.read and files.share, and the check applies only when the link is created.
- **Folder operations while exclusion tags are configured:** whenever at least one exclusion tag is configured, the MCP connector refuses to **share** (`files_share`), **create or update a public link** (`files_share` with `with: "link"`), **copy** (`files_copy`), or **move to another directory** (`files_move`, `files_move_batch`, `files_undo_batch`) any **folder**, and hides `url` (`url: null`) on existing folder public links in `files_list_shares`.
  - **Why:** Nextcloud's storage API cannot guarantee complete visibility of every descendant inside a folder (unreadable subfolders return empty, unavailable mounts disappear from listings, and listing can have side effects). Sharing, linking, copying, or moving a folder into a shared directory could otherwise expose an excluded file inside it.
  - **What still works and how to perform folder operations:** all operations on **individual files**, renaming a folder within the **same parent directory** (the folder does not enter a different share, and the existing lock checks still apply), and removing an existing folder share (`files_unshare`) continue to work in MCP. Visibility is checked first (an excluded folder or an unreadable tag configuration always answers "not found"); only a visible folder receives the refusal message explaining that folder sharing, copying and moving to another directory must be done in the Nextcloud Files web interface while exclusion tags are active.
- **Fail-closed:** a configuration that cannot be validated or a tag query that fails keeps the item hidden, and the admin page explains the state and offers the repair button (the *Access inspector* explains why a path is reached or not, including the exclusion reason; see [Individual overrides, reset and the access inspector](#individual-overrides-reset-and-the-access-inspector)).
- **Declared limits:**
  - **This connector only:** the exclusion applies only to this MCP connector. It does not cover other AI tools, other connectors that read the same Nextcloud, the Nextcloud web interface, or files a user uploads manually to an AI.
  - **Federated shares:** checked only against local system tags on this Nextcloud instance; tags on the remote Nextcloud server cannot be read.
  - **Server log:** file names and paths inside log messages are hidden only while **Hide the text of log messages** is enabled (on by default); a bare file name written by another app into a structured log field is not redacted.
  - **Free text:** existing free-text mentions of a file name or path inside Talk messages, calendar descriptions, task descriptions or Deck cards are not redacted.
  - **External storage mounted twice with different roots:** mounting the *same* external directory twice with different roots (for example `/ext` and `/ext/Open`, where a tagged parent folder lies outside the narrower mount) creates two separate Nextcloud storages with distinct file IDs; a tag applied in one mount does not cover reads through the other. Tag the file in the mount where it is read. Two mounts of the *same* root (an alias) share file IDs and are protected together.
  - **Exclusion list cache window (~3 s across processes):** changes saved in the admin UI apply immediately on that web node. If the exclusion tag list is changed via `occ` or on another web node in a multi-node setup, Nextcloud's local appconfig cache may serve the previous list for about 3 seconds (Nextcloud keeps the app config in a local cache for 3 s; measured at 2.7 to 3.9 s on Nextcloud 32–35).
  - **No badge in Nextcloud Files:** the Nextcloud Files interface shows the system tag name as usual, without a separate badge indicating MCP exclusion.

### Per-resource scope

Administrators can restrict an individual user so that, instead of accessing all resources their Nextcloud account can see, they can only access an explicit allow-list of resources in MCP. The restriction is edited on the page itself, in *Administration settings → MCP for Cloud → Access and permissions → Resource scope*: enter the user ID, choose per module "All resources" or "Only selected", and pick the folders, calendars or task lists the user reaches in their own Nextcloud. Review the selection in the panel and click **Save**; the change offers **Undo** for 10 seconds, and a draft can be discarded. The scope is open for Files, Calendar and Tasks, and it narrows every consumer: all `files_*` tools, the `nc://files` resources, the `files_checkout` links and the structured file references of Talk and Tasks, plus every calendar and task tool. The restricted modules are marked *Restricted to N* in the permissions matrix (or *Scope unknown* when the restriction cannot be read), and the affected user sees their own restriction, read-only, in *Personal settings → Resource scope*.

- **Supported modules:**
  - **Files:** allowed folders, covering the selected folders and everything below them. A node is inside the scope when the node itself or one of its ancestors is a selected folder, in the chain the user reaches: their own tree, a mounted share, or the owner's chain above a share root. A folder outside the scope answers exactly like a missing one, listing `/` shows the outermost selected folders at their real paths (instead of the root), and a search filters by the scope before its results. Ancestors crossed only on the way to a destination (creating a folder at or below a selected one) answer to the exclusion tags only, so a write inside the scope works without its ancestors becoming reachable.
  - **Calendar:** allowed event calendars (`VEVENT`). Listing, reading, creating, editing, deleting, moving and transferring events all go through the scope: a calendar outside it is missing, with the same refusal as a calendar that does not exist, and a calendar of the scope that the user can only read stays read-only. Moving or transferring an event requires both the source and the destination calendar to be in the allow-list. Event references in Talk follow the same rule.
  - **Tasks:** allowed task lists (`VTODO`), configured **independently** of Calendar even when events and tasks live in the same underlying Nextcloud calendar: the event tools answer to the Calendar scope and the task tools to the Tasks scope. A calendar is identified by its Nextcloud calendar id, which a share keeps and a rename does not change. Reading an object by its UID looks only at the component of the tool, so an event and a task with the same UID never answer each other.
- **Notes is outside the Files resource scope:** the Notes module has its own storage model and is not restricted by a user's Files folder allow-list (system-tag exclusion still applies to Notes).
- **Precedence and response:** `Per-resource scope > Exclusion by system tag > Native Nextcloud ACL`. After the access and operation permission checks, an unreadable resource scope returns 503 before any item is looked up. Per-resource scope only narrows access; it never grants access to a folder, calendar or task list that Nextcloud ACLs deny, and a system-tagged file inside an allowed folder remains hidden. A folder, calendar or task list out of scope returns the exact same "not found" message as a nonexistent one, and is left out of listings. A scope that cannot be read answers 503 (temporarily unavailable) for any of them, existing or not, instead of listing everything. The admin pages and the picker of the editor still show every folder, calendar and task list of the user, so a scope never hides a resource from the person who configures it.
  - **Structured file references:** a Talk attachment or quote, and a task `ATTACH`, are omitted when the file is excluded **or** outside the user's folder scope. A reference **resolved by ID** (a Talk room share, an `ATTACH` carrying an `X-NC-FILE-ID`) must pass for **every** alias of that ID in the user's tree: all inside the scope and free of exclusion tags, with at least one of them readable, so a file the user also reaches through a restricted alias cannot leak through another module. A task reference resolved **only by path** (an `ATTACH` without an id, or a `FILENAME`) checks the node at that path. The Files tools decide by the path that was requested.
- **Who configures it and session behavior:** only administrators edit a user's resource scope (up to 200 items per user and module; every admin query and change is written to the Nextcloud log at *info* level, without resource names; it shows up only when the server `loglevel` is 1 or lower, since the default (2) does not record *info* lines, so this is not a guaranteed audit trail). A user sees their own restriction, read-only, in *Personal settings → Resource scope* (`GET /apps/mcp/api/my/scope`), where a restriction that cannot be read is said to be unknown, never shown as unrestricted. The effect of a scope on one user can be checked in the **Access inspector**. Changing a user's scope **does not revoke** their active OAuth connections; the new scope applies on the next request.
- **Empty scope, deleted accounts and recreated accounts:** a module restricted to an empty list gives access to no resource of that module. When Nextcloud notifies the deletion of an account, the restriction of that account is removed on the spot, and only up to the policy revision read when the deletion started: a scope saved after that point is a later revision and is kept. If the notification is lost, the restriction stays, and the periodic job only **counts** the accounts it finds absent in its diagnostic line in the Nextcloud log — it never removes anything, because it cannot prove that an account is gone (an unreachable directory backend may answer "no such user", and the account can come back). An account recreated with the same UID therefore keeps the previous restriction instead of failing open to unrestricted access.
- **What is not supported in 1.1.0:** per-resource scope for Notes, Contacts, Deck and Talk; per-resource scope by group (groups only grant permissions; a user with a per-resource scope remains restricted even when granted module access through a group); and free-text redaction.
- **Declared limits of the folder scope:**
  - **Backups live outside the scope:** when MCP pre-write copies are enabled, the verified copy is created in the configured backup folder (default `/MCP backups`) in the user's root, even when that folder is outside the selected scope. It need not be selected to overwrite an allowed file: the copy is of a file the user was already allowed to edit, and the out-of-scope copy itself stays unreachable through the Files tools (reading or listing `/MCP backups` still answers by the scope). A backup that genuinely fails prevents the original from being changed.
  - **A folder selected as a scope root cannot be renamed by MCP:** the destination parent (the folder's own parent, outside the scope) is not reachable, so the move is refused. Rename it in the Nextcloud Files interface, or select its parent in the scope as well. Moving and copying *inside* the scope keep working.
  - **Share source moved by another process during the call:** the chain a node is judged by, including the owner's chain above a share root, is resolved by metadata and remembered for the duration of the operation. If the owner moves the source in another process while the call runs, that single call can still judge the file by the old chain; the next call reads the new one. The same window exists for the exclusion-tag cache (see the next item).
  - **External storage mounted twice with different roots:** the same limit as for exclusion tags (see [Excluded from MCP access](#excluded-from-mcp-access)) applies to the folder ids of a scope, for the same reason: the folder selected under `/ext` and the same folder selected under `/ext/Open` are two different Nextcloud storages with their own ids, and each only protects the files read through itself. Select the folder in the mount where the file is read; two mounts of the *same* root (an alias) are protected together.
  - **Exclusion-tag list cache (~3 s across processes):** a change to the exclusion tag list made with `occ`, or on another web node of a multi-node installation, may take about 3 seconds to apply; a change made through this page on the same node applies immediately. This window is separate from the per-request one described above. Nothing here needs a terminal: the app is plug-and-play and `occ` is not part of any documented procedure.

### Upgrading, incidents and rollback

- **Zero-step upgrade from 1.0.8:** updating the app runs its native database migrations automatically and seeds the initial policy state without `occ` or manual SQL. Existing individual user grants keep their values, server log groups are imported on first read, and active OAuth connections keep working.
- **Going back to 1.0.8 is not safe, even before adopting groups or scopes:** the new tables stay empty and individual permissions keep their values, but 1.1.0 revokes OAuth credentials logically (by generation and epoch) and removes them physically later. A credential revoked in 1.1.0 and not yet removed may be valid again in 1.0.8. Do not do it: see the incident procedure below.
- **After adopting group grants or per-resource scopes — do not downgrade to 1.0.8:** downgrading the code to 1.0.8 on the same database is **not supported and unsafe**:
  1. 1.0.8 does not read `mcp_group_grants`, so every permission granted through a group stops applying;
  2. 1.0.8 does not check the OAuth generation and epoch columns (`auth_gen` and `auth_global_epoch`), so OAuth tokens that 1.1.0 cut only by advancing a user's generation or the global epoch may be accepted again;
  3. 1.0.8 does not read `mcp_scope_modes` or `mcp_scope_items`, so users restricted to specific resources would regain MCP access to all of their Nextcloud resources.
- **Going back from 1.1.0 (incident procedure):** the preferred way is to move forward: update to a fixed 1.1.x release from the app store, keeping the 1.1.0 schema and epochs. Never put only the 1.0.8 code over the 1.1.0 database, and never restore the database and the code from different backups. If you must go back, restore the **whole installation** (code, configuration, database and data directory) from the **same** backup taken before 1.1.0, with Nextcloud in maintenance mode and following Nextcloud's own restore procedure. Then, in the MCP admin settings, **switch the MCP service off**: this removes every OAuth connection and, while it is off, refuses app-password (Basic) clients and checkout links. The MCP app does not remove app passwords (they belong to Nextcloud) and the restored database brings back those revoked after the backup, so before switching the service on again each MCP user must revoke the app passwords used with the MCP in *Personal settings → Security*; keep users who have not done so ineligible. Checkout links already issued are valid for at most 15 minutes (downloads 5, uploads 15) and are refused while the service is off, so leave the service off for at least 15 minutes. Then switch the service on and reapply what was changed after the backup: permissions, accounts, user connections and, explicitly, revoked app passwords and disabled accounts. A restore loses everything done after the backup, revocations included. None of this needs a terminal, `occ` or SQL, and it is an incident procedure, not a requirement to use 1.1.0.

## Development

```bash
cd mcp
composer install
vendor/bin/phpunit          # unit tests with OCP mocks, no Nextcloud needed
```

Code layout: `lib/Tools/<Module>` (one `ToolModule` per app), `lib/OAuth` (authorization server), `lib/Service` (MCP protocol, grant policy), `lib/Controller`, `templates`, `js`, `css`, `l10n`. Detailed technical notes live in [`mcp/README.md`](mcp/README.md).

The original Node.js stdio prototype (read-only) was removed; it remains in the git history up to commit 27739f0 (tag v0.11.0).

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

## License

AGPL-3.0-or-later, with the additional section 7(b) attribution term in [NOTICE](NOTICE). The complete license is in [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve the Sintax attribution in the administration page, documentation and copyright notices when redistributing or making modified versions available over a network.

## Credits

The package includes Smalot PdfParser, Symfony Polyfill Mbstring and the Composer autoloader. See versions, licenses, copyright and development dependencies in [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contributors.** Thanks to @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/pull/5)) for the root visibility check and to @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) for the report behind the hidden-tags audit and ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) for the German translation, to @Zegorax ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/pull/8)) for the OAuth clients registered by the administrator, and to @mturgeonferland ([#18](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18)) for subtasks in the Tasks tools.

## About Sintax

Developed by [Sintax](https://sintax.tech) · sintax.tech.

- Website: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contact: contato@sintax.tech
- Other Nextcloud apps by Sintax: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)
