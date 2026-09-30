# Changelog

All notable changes to the native Nextcloud app (`mcp/`). Versions follow `mcp/appinfo/info.xml`.

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
