<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Resources (MCP Resources)

The server announces the `resources: {}` capability in both the legacy `initialize` flow and the modern `server/discover` flow.

- **`resources/list`**: exposes `mcp://guide` (Tool guide), returning the complete documentation in Markdown rendered dynamically based on the permissions granted and the apps active for the user.
- **`resources/templates/list`**: exposes parameterized URI templates:
  - `nc://files/{path}`: file in the user's storage (requires the `files.read` grant).
  - `nc://notes/{id}`: note identified by its numeric id (requires the `notes.read` grant and the Notes app enabled).
- **`resources/read`**: reading resources:
  - `mcp://guide`: delivers the formatted guide as `text/markdown`.
  - `nc://files/{path}`: reads text files (`text/*`, PDF, DOCX, ODT through text extraction identical to `files_read`, with a limit of 100,000 characters) or small binary files as a `blob` (base64) up to 512 KiB. Larger binary files are refused with an error.
  - `nc://notes/{id}`: delivers the note content as `text/markdown` (limit of 1 MiB).
  - Resources that do not exist, are not allowed or are hidden by tags (`VisibilityGuard`) return the standard error (`-32602` in the modern era, `-32002` in the legacy era) and never an empty content array.

[Operational guides](../guides/README.md).

English · [Español](../../es/reference/resources.md) · [Català](../../ca/reference/resources.md) · [Português (Brasil)](../../pt-BR/reference/resources.md) · [Português (Portugal)](../../pt-PT/reference/resources.md)
