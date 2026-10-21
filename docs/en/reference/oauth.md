<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# OAuth

The protected-resource metadata is at `/apps/mcp/.well-known/oauth-protected-resource`. A `401` challenge identifies that document. Authorization-server metadata is at `/apps/mcp/.well-known/openid-configuration` and `/apps/mcp/.well-known/oauth-authorization-server`, with issuer `https://<instance>/apps/mcp`.

`oauth/authorize` provides browser consent; `oauth/token` exchanges authorization codes with PKCE S256 and rotates refresh tokens. Access tokens last one hour and refresh tokens 30 days. The database stores credential hashes, not their plaintext values. This is public-client authentication (`none`); a client ID is an identifier, not a secret or a permission grant.

## Published, native and registered clients

Administrators manage clients under **Administration → MCP for Cloud → Clients and connections**. Saves require an administrator, Nextcloud CSRF protection and the current policy revision.

| Client type | Identity and redirect rules |
| --- | --- |
| Published client identity document (CIMD) | HTTPS document on an allowed exact host. Default hosts are `claude.ai,chatgpt.com`. Redirects must match the validated document. |
| Built-in native client | `nextcloud-mcp-native`, disabled by default. Its registered HTTP loopback callbacks allow variable ports for local programs. |
| Administrator-registered custom client | A generated client ID, display name, enabled state and explicit redirect URI list. URI matching is exact, including the port on HTTP loopback. |

The host allowlist accepts hostnames without scheme, port, path or wildcards; at least one host must remain. Saving replaces the list. Removing a host blocks admission even if its identity document is cached. Documents are fetched over HTTPS without redirects, limited to 64 KiB and cached for one hour.

Custom clients support at most 20 entries, names of at most 64 characters and at most 20 redirect URIs per entry. Names must be nonempty and unique ignoring case. Each URI is at most 2,000 bytes, uses HTTPS or HTTP on loopback, and has no userinfo, fragment, wildcard, whitespace, ASCII control character or backslash. An empty redirect list cannot authorize a client. Copy the generated client ID into the client configuration and register its exact callback; custom clients do not inherit the native client's variable-port exception.

The managed-client table is authoritative after migration. Disabling/removing a managed client or removing one of its redirect URIs withdraws existing grants and advances the appropriate client generation. Re-enabling it does not revive an old consent page, code or token. A failed client-table read temporarily refuses authorization instead of showing consent with an assumed allow.

## Connecting through ChatGPT and Gemini CLI (OAuth)

See the [client guide](../guides/clients.md) for configuration examples. ChatGPT uses its published identity on `chatgpt.com`. Gemini CLI can use the built-in native client after an administrator enables it. The native callbacks are `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` and `http://[::1]/oauth/callback`; the selected loopback port may vary.

Every validated client redirect includes the issuer parameter `iss`, including denial and redirectable errors. An error before redirect validation remains on a local page. Client interfaces and hosted-client compatibility still require live-client review; a local protocol test does not certify their current UI.

## Revocation and uncertainty

Each request rechecks identity, service, eligibility, personal connection, application/log role, operation grant, resource restriction, exclusion tags and native ACLs where applicable. A group grant does not bypass those checks. Unknown policy is temporary unavailability, not an implicit allow or destructive revocation.

Personal disconnection, loss of eligibility, service shutdown and account disable/deletion cut OAuth credentials using per-user generations or a global epoch. The cut commits with the policy change; durable cleanup removes obsolete rows afterwards and must leave later credentials intact. Regranting access or re-enabling a client does not revive old credentials. Refresh replay handling still applies to its owner/client lineage.

Resource-scope changes do not revoke OAuth: the next operation evaluates the new restriction. HTTP Basic app passwords belong to Nextcloud, not this OAuth store. MCP disconnection blocks their MCP use, but deleting those passwords requires Nextcloud's own Security controls. Service shutdown preserves the personal connection preference; recovery may also require explicit disconnection and app-password review. See [installation and recovery](../installation.md).

## Security properties

- The consent screen is CSRF-protected and cannot be framed. Login, eligibility, connection, grants, consent and PKCE remain mandatory for native/custom clients.
- Credential generation/cut checks prevent withdrawn credentials from becoming valid after regrant or identity/client recreation. Uncertain database or policy state produces a retryable failure without guessing.
- Administrative client changes validate the complete request before writing. Conflicts reload state rather than silently replaying a mutation.
- Client-facing unexpected errors are generic. Application diagnostics do not log credential plaintext or file contents.
- Report vulnerabilities privately to the maintainers. Do not disable brute-force protection or whitelist an unverified network range to work around a failed connection; investigate the refusal and host logs.

[Operational guides](../guides/README.md).

English · [Español](../../es/reference/oauth.md) · [Català](../../ca/reference/oauth.md) · [Português (Brasil)](../../pt-BR/reference/oauth.md) · [Português (Portugal)](../../pt-PT/reference/oauth.md)
