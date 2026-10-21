<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Notes

| Module | Read | Write |
| --- | --- | --- |
| **Notes** | list, read, search (by title and Markdown content) | create, edit, move between categories, delete (only when the trash bin can recover it) |

| Tool | Grant | Notes |
| --- | --- | --- |
| `notes_list` `{}` | notes.read | `.md`/`.txt` notes in the Notes app folder (`notesPath` preference, default `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Search by title and Markdown content (up to 1 MiB), with a contextual snippet of the match. Optionally restricted to a category. |
| `notes_read` `{id}` | notes.read | Note with content; limit of 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Never overwrites; on collision, uses `Title (2)`. |
| `notes_edit` `{id, content?, title?, etag?}` | notes.edit | Content and/or title. |
| `notes_move` `{id, category, etag?}` | notes.move | Changes category (subfolder), without overwriting. |
| `notes_delete` `{id, etag?}` | notes.delete | Requires `confirm: true`, the trash bin (`files_trashbin`) active and the note's storage covered by it; on external storage without a trash bin, deletion is blocked. |


See the live `mcp_guide` for the authoritative schema and available operations.

Notes tools require the Notes app enabled for the user. Reading starts allowed; creating, editing, moving and deleting start denied until the administrator grants them. There is no timeout of its own: reading is local to PHP and the byte limit protects against large files. Slow external storage is limited to PHP's `max_execution_time`.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/notes.md) · [Català](../../../ca/reference/tools/notes.md) · [Português (Brasil)](../../../pt-BR/reference/tools/notes.md) · [Português (Portugal)](../../../pt-PT/reference/tools/notes.md)
