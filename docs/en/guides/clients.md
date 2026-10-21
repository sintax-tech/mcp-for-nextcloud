<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Connecting clients

Every client talks to the same URL, `https://cloud.example.com/apps/mcp/`, and authenticates as the person using it, with that person's own permissions. Every client first needs the MCP service enabled, an eligible user and an active personal connection. The client-specific configuration differs:

| Client | Authentication | Something the administrator has to do first |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | OAuth **Sign in**, Claude's published identity (CIMD) | Nothing: `claude.ai` is allowed by default |
| **ChatGPT** | OAuth **Sign in**, OpenAI's published identity (CIMD) | Nothing: `chatgpt.com` is allowed by default |
| **Gemini CLI** | OAuth with the app's built-in native client | Tick **Allow local programs** and copy the `client_id` |
| **Registered self-hosted client** | Public OAuth with PKCE | Register its name and exact callback URI, then copy its generated client ID |
| **Any other MCP client** | App password (HTTP Basic) | No client registration beyond the common access prerequisites |

### Claude (claude.ai, Claude Desktop, Cowork and Claude Code — recommended)

1. *Settings → Connectors → Add custom connector*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Authentication: **Sign in** (OAuth), client: **Claude's published identity** (CIMD). No headers needed.
4. **Connect**: your Nextcloud opens, you log in and click **Allow**.

`claude.ai` is in the allowed client hosts out of the box, so nothing has to be changed on the server. If an administrator narrowed that list, Claude is refused with `invalid_client` before the login page even opens: ask them to put `claude.ai` back in *Administration settings → MCP for Cloud → OAuth clients → Allowed client hosts*.

### ChatGPT

1. In ChatGPT, add a new connector for an MCP server and paste `https://cloud.example.com/apps/mcp/` as its URL.
2. Authentication: **Sign in** (OAuth). ChatGPT identifies itself with the client identity document published at `chatgpt.com`; the app downloads that document to learn the redirect URIs it registered. No headers, no app password.
3. **Connect**: your Nextcloud opens, you log in and click **Allow**.

`chatgpt.com` is allowed by default, so an installation that was never configured already accepts ChatGPT. If an administrator narrowed the list to other hosts, ChatGPT is refused with `invalid_client` before the login page opens — ask them to add `chatgpt.com` back in *Administration settings → MCP for Cloud → OAuth clients → Allowed client hosts*.

### Gemini CLI

Two steps, one from the administrator and one from the user, with no terminal on the server.

**Administrator** — *Administration settings → MCP for Cloud → OAuth clients*:

1. Tick **Allow local programs (native client)**. It is off by default.
2. The section then reveals the `client_id` to copy — `nextcloud-mcp-native` — and the redirect URIs it accepts.

**User** — create or edit `~/.gemini/settings.json` on your own machine:

```json
{
  "mcpServers": {
    "nextcloud": {
      "url": "https://cloud.example.com/apps/mcp/",
      "oauth": {
        "clientId": "nextcloud-mcp-native"
      }
    }
  }
}
```

3. In Gemini CLI, run `/mcp auth` (or `/mcp auth nextcloud`).
4. Your Nextcloud opens in the browser: log in and click **Allow**. Gemini CLI is listening on a free port it picked itself, so the browser returns to `http://localhost:<port>/oauth/callback`. The app accepts `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` and `http://[::1]/oauth/callback`, and ignores the port for loopback addresses (RFC 8252 7.3), so the random port still matches.

Why a built-in client: Gemini CLI has no published client identity document, so the app ships one fixed public client for local programs. Its id is the same for everyone on the instance, and that is safe because it grants nothing by itself — the login, the eligibility, the personal connection, the administrator's grants, the consent screen and PKCE all still apply. Turning the switch off also stops the client from exchanging an authorization code and from refreshing a token.

### Administrators: OAuth clients

*Administration settings → MCP for Cloud → OAuth clients* decides which clients may connect, without any terminal step:

- **Allowed client hosts** — the hosts whose published client identity document the app is willing to download, separated by commas; `claude.ai` and `chatgpt.com` by default. Exact host names only: no `https://`, no port, no path, no `*`, and at least one host has to stay. The host is the one in the URL the client uses as its `client_id`. Saving replaces the whole list, and removing a host refuses that client immediately, even when its document is already cached.
- **Allow local programs (native client)** — enables the `nextcloud-mcp-native` client described above and shows the `client_id` and the accepted redirect URIs to copy.

- **Self-hosted clients (custom redirect URIs)** — register a client name, enabled state and its exact callback URIs, then copy the generated client ID into that client. Custom redirects use HTTPS or HTTP loopback; their port must match exactly, unlike the built-in native client. Limits and validation are in [OAuth](../reference/oauth.md#published-native-and-registered-clients).

Client controls are administrator-only, CSRF-protected and revision-checked, and report a refused save. A configuration you set yourself is not overwritten by the defaults: an `oauth_client_hosts` value saved before 0.8.0 keeps being used as it is.

### Other MCP clients (app password)

Create an app password in *Personal settings → Security*, enable the connection in *Personal settings → MCP for Cloud*, then configure the client with the URL above and HTTP Basic auth (`username:app-password`). Quick check:

```bash
curl -u 'alice:APP-PASSWORD' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revoking access

*Disconnect* on the personal page blocks the next MCP request, even with the same app password. The app password remains valid in Nextcloud and must be revoked under **Security**. On the same page, *Revoke* cuts a specific OAuth client of the user themselves. The administrator can also remove the user's eligibility or turn the service off; both apply on the next request. In the **Active connections** block, the administrator revokes a specific OAuth connection, or all of a user's, immediately; the user remains enabled and can sign in again.

Revocation cuts OAuth credential generations or the global epoch before deferred cleanup. Regranting access, undoing a policy change or re-enabling a managed client does not restore old OAuth codes/tokens. Removing a custom redirect URI also withdraws that client's existing grants. Service shutdown preserves the personal connection preference; app-password lifecycle and recovery review remain separate. See [OAuth](../reference/oauth.md#revocation-and-uncertainty).

[Operational guides](README.md).

English · [Español](../../es/guides/clients.md) · [Català](../../ca/guides/clients.md) · [Português (Brasil)](../../pt-BR/guides/clients.md) · [Português (Portugal)](../../pt-PT/guides/clients.md)
