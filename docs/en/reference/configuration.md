<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Configuration

Use the administration and personal settings interfaces for policy changes. Direct `occ` writes to policy preferences bypass revision checks, group resolution and credential revocation; they are not a supported way to administer the 1.1.0 policy. Read-only diagnostics such as `occ config:app:get mcp service_enabled` remain useful.

| Storage | Key or owner | Meaning |
| --- | --- | --- |
| App `mcp` | `service_enabled` | `1` on; `0` or absent off. Change through the service command/UI. |
| User, app `mcp` | `eligible` | Individual `1`/`0` override of **Can connect**; absence allows group inheritance, then the off default. |
| User, app `mcp` | `connected` | `1` means the personal connection is active. This is distinct from a live OAuth credential. |
| User, app `mcp` | `grant_<module>_<operation>` | Individual `1`/`0` override. Absence means trusted group inheritance, then read allowed except logs; other operations denied. |
| Policy tables | `mcp_policy_state`, `mcp_policy_events` | Revision/global authentication epoch and durable audit, cleanup and receipt events. |
| Group tables | `mcp_group_grants`, `mcp_group_life` | Grant-only policy and observed group identity/lifecycle. |
| Authentication tables | `mcp_auth_users`, `mcp_auth_recheck` | Per-user credential generations/cuts and deferred lifecycle rechecks. |
| Resource tables | `mcp_scope_modes`, `mcp_scope_items` | Independent Files, Calendar and Tasks restrictions. |
| Client table | `mcp_oauth_clients` | Authoritative managed native/custom client state and generations after migration. |
| App `mcp` | `oauth_client_hosts` | Exact published-client hosts; absent or empty uses `claude.ai,chatgpt.com`. Edit in OAuth clients. |
| Legacy app inputs | `oauth_native_client_enabled`, `oauth_custom_clients` | Migration inputs for the client table, not the live managed-client authority. |
| Own table | `mcp_logs_groups` | Canonical JSON groups and version; empty means administrators only. Imports legacy appconfig on first read. |
| App `mcp` | `checkout_max_bytes` | Checkout upload limit in bytes; default 50 MiB, constrained by PHP's `post_max_size`. |
| App `mcp` | `hidden_system_tags` | JSON list of excluded system-tag IDs; `[]` disables tag exclusions. |
| App `mcp` | `logs_hide_file_names` | `1` by default: hide log-message text while exclusions are configured; `0` allows the complete log for diagnosis. |

These tables describe persistence, not a public SQL mutation API. Editing rows or restoring only some tables can break revision, lifecycle and revocation guarantees. See [permissions](../guides/permissions.md), [OAuth](oauth.md) and the [upgrade/recovery guidance](../installation.md).

Nextcloud owns `enabled`, `installed_version`, `types`, `levels` and `ocsid`; they are not MCP policy switches. In particular, Nextcloud's `enabled=yes` is distinct from `service_enabled=1`.

[Operational guides](../guides/README.md).

English · [Español](../../es/reference/configuration.md) · [Català](../../ca/reference/configuration.md) · [Português (Brasil)](../../pt-BR/reference/configuration.md) · [Português (Portugal)](../../pt-PT/reference/configuration.md)
