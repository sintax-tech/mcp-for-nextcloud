<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Logs

| Module | Read | Write |
| --- | --- | --- |
| **Server log** | for administrators and the groups they choose: list the entries of `nextcloud.log` with filters, and summarize them (totals, frequent signatures, URLs, user agents, entries per hour). Off by default for everyone; IPs masked, output marked as untrusted, every read audited. While exclusion tags are configured, the admin option "Hide the text of log messages" (on by default) hides the text of messages and exceptions, the user agent and the URL detail, and refuses the `contains` filter; turned off, the logs come back in full for diagnosis | — (read only) |


See the live `mcp_guide` for the authoritative schema and available operations.

| Module | Tools | Dependency |
| --- | --- | --- |
| Logs | `logs_list`, `logs_analyze` | read-only access to `nextcloud.log` through the core's `ILogFactory`/`IFileBased::getEntries`; requires a file-based `log_type` (the default); see [Server log](../../guides/server-logs.md#server-log-102) |

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/logs.md) · [Català](../../../ca/reference/tools/logs.md) · [Português (Brasil)](../../../pt-BR/reference/tools/logs.md) · [Português (Portugal)](../../../pt-PT/reference/tools/logs.md)
