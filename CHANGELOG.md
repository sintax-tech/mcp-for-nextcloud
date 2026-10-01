# Changelog

All notable changes to the native Nextcloud app (`mcp/`). Versions follow `mcp/appinfo/info.xml`.

## Unreleased (0.8.0)

### Added
- Contacts: list personal/shared address books, search and read contacts, create/edit through the native CardDAV pipeline, preserving unknown vCard fields, parameters, groups and version. The system user directory is excluded; tools and admin columns follow Contacts app availability.
- Contacts: permanent deletion through native CardDAV, with an explicit no-trash warning and the full vCard in the plan. After confirmation, a verified `.vcf` backup is saved in the acting user's `/MCP backups/Contacts/<address-book>/` before DELETE; backup failure prevents deletion and the result gives the backup path for import through Contacts.
- Tasks: list VTODO calendars, list/read/create/edit/complete/delete simple tasks through core CalDAV, independent of the optional Tasks app. Undated tasks are included; private/confidential shared tasks are hidden. Deletion uses calendar trash and refuses zero retention. Recurring tasks can be read; writes to series or tasks with participants are refused.
- Contacts and Tasks: separate admin read/create/edit/delete grants with writes off by default; actual before/after plans, central `confirm: true`, shared-owner acknowledgement, optional client ETag and mandatory current `If-Match` for edit/delete, pagination, friendly localized titles/messages and English tool guides.
- OCR: detects the Workflow OCR app (`workflow_ocr`), with no terminal step and nothing that blocks the app. It writes the recognised text into the PDF as a new version, so `files_read` already reads it. A PDF or image with no text layer now comes back as a normal answer with `text_layer: false` and a notice that depends on whether the app is active (ask the admin to install it or view the page as an image; or wait, since it processes in the background by the admin's rule). `files_version_read` does the same.
- Admin: an "OCR" block shows whether Workflow OCR is active and, when it is not, a link to the App Store and the note that it needs `ocrmypdf` on the server.
- Tool guide: the Files notes explain `text_layer` and OCR.
- MCP Resources: capability advertised as `resources: {}` in both legacy `initialize` (2025-06-18) and modern `server/discover` (2026-07-28) eras.
- MCP Resources: `resources/list` exposes `mcp://guide`, rendering the tool guide in Markdown dynamically filtered by the authenticated user's active grants and enabled apps.
- MCP Resources: `resources/templates/list` exposes URI templates `nc://files/{path}` (when `files.read` is granted) and `nc://notes/{id}` (when `notes.read` is granted and the Notes app is enabled).
- MCP Resources: `resources/read` fetches file contents (with text extraction and truncation up to 100,000 characters; small binary files returned as base64 blobs up to 512 KiB) and note contents (up to 1 MiB).
- MCP Resources: non-existent, ungranted, or hidden resources return standard JSON-RPC errors (`-32602` in modern era, `-32002` in legacy era) and never return empty content.
- Files: content search with excerpts via `files_search`. When `fulltextsearch` and `files_fulltextsearch` are enabled and indexed, searches file contents (`search_mode: content`) and returns excerpts with node resolution. Gracefully falls back to file name search (`search_mode: name_only`) with an internationalized notice when fulltextsearch is not active or encounters an error. An optional `mode` parameter (`auto` | `name`) allows callers to force name-only search.
- Notes: content search via `notes_search`. Searches note title and Markdown content (up to 1 MiB), returning contextual snippets around query matches, optionally filtered by category.
- Files: hide sensitive files and folders by Nextcloud system tag. The administrator can select system tags in the admin settings; any file or folder bearing those tags (or inside a tagged folder) is completely hidden from MCP tools, behaving as if it does not exist (read, search, list, tree, image view/search, notes, talk attachments, and checkout token usage all return "not found", and write destinations report forbidden without leaking existence).
- Files: automatic system tag propagation to backups created under `/MCP backups`. VisibilityGuard also mirrors visibility if the original file still exists.
- Admin UI: dedicated "Hidden files & tags" section in the MCP admin settings with badge indicators for tag types (invisible, restricted, collaborative) and warning if collaborative tags are selected.
- Zero cost when no hidden tags are configured (default behavior unchanged).

### Changed
- Files: `files_read` of an image no longer fails as unsupported; it answers with `text_layer: false` and the notice.
- Files: `files_read` and `resources/read` share one text reader, so the 20 MiB limit is checked before any extraction on both paths.
- Tool guide: the obsolete `approval_id` gate is gone from the guide's list of extra gates.

### Security
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
