<div align="center">

# MCP for Nextcloud

**A native Nextcloud app that turns your Nextcloud into an MCP server.**
Let Claude and other MCP clients work with your files, notes, calendars, contacts, tasks, Deck boards and Talk conversations, with each user's own permissions, and nothing leaves your server that the admin did not allow.

[![Nextcloud 33](https://img.shields.io/badge/Nextcloud-33-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue)](#license)

English · [Português (Brasil)](README.pt-BR.md)

</div>

---

## Why

AI assistants are most useful when they can reach the tools a team already uses. Plugging them into Nextcloud usually means scripts with shared passwords, broad API tokens or data copied elsewhere. **MCP for Nextcloud** runs **inside** your Nextcloud:

- **One URL per instance.** `https://cloud.example.com/apps/mcp/`. No subdomain, no extra service, no Node/Docker/AppAPI.
- **Sign in with Nextcloud.** Clients such as claude.ai use OAuth ("Sign in"): the user logs into their own Nextcloud and clicks **Allow**. App passwords (HTTP Basic) still work for other clients.
- **Admin in control.** Service on/off switch, per-user eligibility and a **user × permission matrix**. Reading is on by default for eligible users; every write, move, delete, transfer or restore is **off until the admin grants it**.
- **Nextcloud ACLs always apply.** The app never widens what a user can already do in Nextcloud.

## Features

| Module | Read | Write (each one needs an admin grant) |
|---|---|---|
| **Files** | list, search by name, search images by tag/date, view images and batch previews (JPEG, PNG, WebP, GIF, TIFF, PDF), read text (TXT/MD, PDF, DOCX, ODT; a scan without text returns `text_layer: false` and, with the optional Workflow OCR app active, gains text in the background), list and read file versions | edit with a diff and a partial replacement, with a verified backup in `/MCP backups` and a Nextcloud version before every write; restore a stored version; check a file out to an agent with a shell through a one-time link; reorganize folders: scan the tree, create a folder, copy, move, move a batch and undo it. **Never deletes.** |
| **Notes** | list, read | create, edit, move between categories, delete (only when the trash bin can recover it) |
| **Calendar** | list calendars and events (recurrence, time zones, all-day) | create, edit, move, delete and transfer events through the real CalDAV pipeline, with participants. The writes are off until an administrator grants them in the admin matrix (no terminal step), and each one returns a plan first: **nothing is written before the user approves it.** |
| **Contacts** | list personal/shared address books, search and read contacts (system user directory excluded) | create and edit while preserving unknown vCard fields; permanently delete only after a verified `.vcf` backup in `/MCP backups/Contacts` |
| **Tasks** | list task calendars and VTODO tasks, including undated tasks; read tasks | create, edit, complete and delete simple tasks through CalDAV; deletion uses calendar trash and is refused when retention is zero |
| **Deck** | boards, stacks, cards, and a **follow-up** of the boards you manage: cards grouped by assignee, with due date, overdue flag (in your own time zone) and a link to the card | create, edit, move, delete cards |
| **Talk** | conversations and messages (never marks anything as read) | reply (optionally quoting a message or linking a Deck card or calendar event you can see), direct message to a user, batch of messages, share or quote a file, create a group conversation (separate grant, off by default). **Nothing is sent before the user approves: a call without `confirm: true` returns only the plan (recipient, final text, attachments, what would be created or shared) and runs nothing; with `confirm: true` it checks the permissions again and sends. No approval id, token or record is kept on the server.** |

Plus:

- **Conversational confirmation before every write (`confirm: true`)**: any tool that changes data (`operation != 'read'`) only executes when called with `confirm: true`. Without it, the server returns the plan (`requiresConfirmation: true`) and makes no changes. The assistant must present the plan to the user and only repeat the call with `confirm: true` after explicit approval. No approval state or tokens are kept on the server.
- **MCP Resources**: dual-era support for Model Context Protocol resources (`resources: {}`). Clients can list `mcp://guide` to read the tool guide directly, discover resource templates (`nc://files/{path}`, `nc://notes/{id}`) filtered by user grants and apps, and read text files (with automatic text extraction), notes, and small binary blobs (<= 512 KiB). Ungranted, missing, or hidden resources fail closed.
- **Tool guide** (`mcp_guide`): the model asks the server what each tool does, its parameters, limits and whether it needs confirmation, built from the same definitions `tools/list` serves, filtered by the user's grants and apps. The guide is in English and the model relays it in the user's language.
- **Friendly tool titles** in the client ("Search files", "List calendars") and MCP annotations (`readOnlyHint`, `destructiveHint`) so clients can ask before risky actions.
- **Safety confirmations** for resources that belong to someone else: shared folders, team folders, other people's Deck boards and calendars. The server refuses the first call and returns a ready-made message, and the assistant must ask the user before repeating it with `confirm_shared: true`. *(Deck and Calendar since 0.6.6; Files and Notes since 0.7.0)*
- **Optional apps respected**: tools and admin matrix columns of an app that is disabled (for everyone or for a given user) are hidden.
- **Localized UI**: admin matrix, personal page, consent screen and every message a tool shows follow the language of the user's Nextcloud account. All modules are covered (Files, Notes, Calendar, Contacts, Tasks, Deck, Talk, the shared messages, the checkout pages and the calendar selftest). English, Brazilian Portuguese and Spanish are included (Spanish is a first translation and still needs a native review); tool descriptions stay English, because the model is what reads them.
- **Both MCP eras**: stateless MCP `2026-07-28` (`server/discover`) and the classic `initialize` flow (`2025-06-18` and earlier) on the same endpoint.

## Contacts and tasks

Contacts use core CardDAV and only your own and shared address books. Editing changes supplied fields and preserves unknown properties, parameters, groups and the vCard version. The Contacts app must be enabled for the user. Tasks use VTODO in calendars supporting that component and work without the optional Tasks app or Calendar UI app.

Both modules have separate `read`, `create`, `edit` and `delete` grants; every write starts disabled. Calls without `confirm: true` return the actual before/after plan and create neither backups nor DAV writes. Shared collections require `confirm_shared: true`. An ETag is optional; when provided it must match, and confirmed edits/deletions always use the current ETag as `If-Match`.

**Contact deletion is permanent: Nextcloud has no contact trash bin.** Its plan shows every vCard field and this warning. After confirmation, the full original vCard is saved and read back in the acting user's `/MCP backups/Contacts/<address-book>/<name-or-uid>.<YYYYmmdd-HHMMSS>-<unique-suffix>.vcf`. Existing files are never selected for overwrite; backup failure prevents deletion. The result gives `backupPath`; import that file through Contacts to recover the contact. This copy is a safety net, not native contact trash.

Task deletion uses native calendar trash and is refused when `dav/calendarRetentionObligation` is `0`. Recurring tasks can be read; writes to recurring tasks or tasks with participants are refused to protect their series and scheduling. Completed tasks are excluded from listings by default; use `include_completed` to include them. Contact searches and task listings accept `limit` (up to 200) and `offset`, and return `nextOffset` for more results.

## Requirements

- Nextcloud **33**
- PHP **8.2+** with `zip`, `mbstring` and `dom`
- Optional apps, only for their own tools (hidden while the app is disabled): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versions (`files_versions`, required for edits), Deleted files (`files_trashbin`, required for deletes)

## Installation

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

   Adjust paths and the web server user to your setup. With Docker, run `occ` inside the container. This manual route is only an alternative: installing from the Nextcloud App Store needs no terminal at all.

3. **Configure** in *Administration settings → Additional settings → MCP*: turn the service on, mark who may connect, and grant write permissions where needed.

## Connecting a client

### claude.ai / Claude Desktop (recommended)

1. *Settings → Connectors → Add custom connector*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Authentication: **Sign in** (OAuth), client: **Claude's published identity** (CIMD). No headers needed.
4. **Connect**: your Nextcloud opens, you log in and click **Allow**.

### Other MCP clients (app password)

Create an app password in *Personal settings → Security*, enable the connection in *Personal settings → MCP*, then configure the client with the URL above and HTTP Basic auth (`username:app-password`). Quick check:

```bash
curl -u 'alice:APP-PASSWORD' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revoking access

- **User**: *Personal settings → MCP → Disconnect* blocks the next request and revokes OAuth tokens. App passwords are revoked in *Security*.
- **Admin**: remove eligibility or turn the service off. Both apply on the next request.

## Security

- Every call re-checks, in this order: authenticated identity, service switch, user eligibility, personal connection, the admin grant for that exact operation, and the Nextcloud ACL of the concrete resource.
- OAuth: PKCE S256, exact redirect URI match, allow-listed CIMD client hosts (default `claude.ai`), tokens stored only as HMAC hashes, one-time rotating refresh tokens.
- No passwords, tokens or file contents in logs. Errors returned to clients are generic.
- The consent screen is CSRF-protected and cannot be framed.
- Found a vulnerability? Please report it privately to the maintainers instead of opening a public issue.

## Development

```bash
cd mcp
composer install
vendor/bin/phpunit          # unit tests with OCP mocks, no Nextcloud needed
```

Code layout: `lib/Tools/<Module>` (one `ToolModule` per app), `lib/OAuth` (authorization server), `lib/Service` (MCP protocol, grant policy), `lib/Controller`, `templates`, `js`, `css`, `l10n`. Detailed technical notes live in [`mcp/README.md`](mcp/README.md) (Portuguese).

The repository root also keeps the original **Node.js stdio prototype** (`src/`, `tests/`), a read-only MCP server used as the behavioural reference for the native app.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

## Roadmap

- Nextcloud App Store release

## License

AGPL-3.0-or-later. Bundled dependency: [`smalot/pdfparser`](https://github.com/smalot/pdfparser) (LGPL-3.0).
