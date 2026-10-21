<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Tools

Each tool only appears in `tools/list` and can only be called when the user has the grant for the operation and the required app is enabled for them. Grant and app are checked again on every call. Everything runs as the authenticated user, through their own Nextcloud folder, and the Nextcloud permissions and shares keep applying.

### Tool guide (`mcp_guide`)

`mcp_guide` (title **Tool guide**) describes the tools to the model itself, so it does not have to guess what each one does:

- **no argument** — one line per module with the tool titles, only for the modules available **to that user** (the same grant and app filter as `tools/list`: a disabled module or one without a grant does not appear);
- **with `module`** (`files`, `notes`, `calendar`, `contacts`, `tasks`, `deck`, `talk`, `people`, `logs`) — each tool of the module: description, whether it reads or writes, parameters with type, whether required, description, `enum` and limits, and the confirmations it requires;
- **with `tool`** — a single tool, with the module it belongs to.

The guide is written in English; the model relays it in the user’s language.

The text comes from the real definitions (`ToolModule::definitions()`, `inputSchema`, `annotations` and `ToolPresentation`), so changing a tool's schema changes the guide: there is no second copy of the documentation to keep up to date. What the schema does not carry — how to find things, with which paths and IDs, and where writes stop — goes in `ToolGuideNotes::guideNotes()`, a short note per module, written next to the module. The response carries the same content in markdown and in `structuredContent`, and the tool is read-only: it reads definitions, it never runs a tool.

A module or tool the user cannot see is refused with a message that lists what they can use. There is no approval stored on the server: the rule of confirming before writing lives in the `instructions` of `initialize` and applies to every tool that changes something.

Plus:

- **Conversational confirmation before every write (`confirm: true`)**: any tool that changes data (`operation != 'read'`) only executes when called with `confirm: true`. Without it, the server returns the plan (`requiresConfirmation: true`) and makes no changes. The assistant must present the plan to the user and only repeat the call with `confirm: true` after explicit approval. No approval state or tokens are kept on the server.
- **OCR through Workflow OCR**: when the Workflow OCR app is installed, scanned PDFs gain a text layer that `files_read` reads normally. Without it, a PDF or image with no text comes back with `text_layer: false` and a notice, and the admin page shows the OCR status with a link to install it. Nothing is blocked.
- **Friendly tool titles** in the client ("Search files", "List calendars") and MCP annotations (`readOnlyHint`, `destructiveHint`) so clients can ask before risky actions.
- **Safety confirmations** for resources that belong to someone else: shared folders, team folders, other people's Deck boards and calendars. The server refuses the first call and returns a ready-made message, and the assistant must ask the user before repeating it with `confirm_shared: true`. *(Deck and Calendar since 0.6.6; Files and Notes since 0.7.0)*
- **Optional apps respected**: tools and admin matrix columns of an app that is disabled (for everyone or for a given user) are hidden.
- **Localized UI**: admin matrix, personal page, consent screen and every message a tool shows follow the language of the user's Nextcloud account. All modules are covered (Files, Notes, Calendar, Contacts, Tasks, Deck, Talk, the shared messages, the checkout pages and the calendar selftest). English, Brazilian Portuguese and Spanish are included (Spanish is a first translation and still needs a native review); tool descriptions stay English, because the model is what reads them.
- **Both MCP eras**: stateless MCP `2026-07-28` (`server/discover`) and the classic `initialize` flow (`2025-06-18` and earlier) on the same endpoint.

- [Files](tools/files.md)
- [Notes](tools/notes.md)
- [Calendar](tools/calendar.md)
- [Contacts](tools/contacts.md)
- [Tasks](tools/tasks.md)
- [Deck](tools/deck.md)
- [Logs](tools/logs.md)
- [Talk](tools/talk.md)
- [People](tools/people.md)

`mcp_status` provides diagnostics without reading user data.

[Operational guides](../guides/README.md).

English · [Español](../../es/reference/tools.md) · [Català](../../ca/reference/tools.md) · [Português (Brasil)](../../pt-BR/reference/tools.md) · [Português (Portugal)](../../pt-PT/reference/tools.md)
