<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Talk

| Module | Read | Write |
| --- | --- | --- |
| **Talk** | conversations and messages (never marks anything as read) | reply (optionally quoting a message or linking a Deck card or calendar event you can see), direct message to a user, batch of messages, share or quote a file, create a group conversation (separate grant, off by default). **Nothing is sent before the user approves: a call without `confirm: true` returns only the plan (recipient, final text, attachments, what would be created or shared) and runs nothing; with `confirm: true` it checks the permissions again and sends. No approval id, token or record is kept on the server.** |


See the live `mcp_guide` for the authoritative schema and available operations.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/talk.md) · [Català](../../../ca/reference/tools/talk.md) · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)
