# Native Nextcloud MCP app — Sprint 01

This local test release targets Nextcloud 33. It exposes MCP 2025-06-18 over stateless Streamable HTTP at the URL shown in the admin and personal settings. The only callable tool is `mcp_status`, which returns a generic availability message and reads no user data. Grants for Files, Notes, Deck, Calendar and Talk are stored but the data tools are not yet available.

## Install for a local test

Unpack the `mcp/` directory into a writable Nextcloud apps directory, then run `php occ app:enable mcp` as the web-server user. Check `php occ app:list` and open Settings → Administration → Additional and Settings → Personal information. The package is for internal tests; installation and client compatibility must be exercised on an actual Nextcloud 33 instance.

An administrator enables MCP and makes a user eligible. The user then activates their own connection on the personal settings page. New users, including admins, are ineligible and disconnected by default. A user creates an app password in Nextcloud Security settings and enters their Nextcloud user ID and app password in an HTTP MCP client using Basic authentication. A client must support Streamable HTTP and MCP version `2025-06-18`; browser OAuth discovery is not available in this sprint. The app does not collect or store the password.

To disconnect, use the personal settings button. This denies subsequent MCP requests, including requests made with the same app password. Revoke the app password separately in Nextcloud Security settings. Admins can also disable the whole service or remove eligibility.

The endpoint accepts POST JSON-RPC messages with `Content-Type: application/json` and `Accept: application/json, text/event-stream`. After `initialize`, subsequent requests send `MCP-Protocol-Version: 2025-06-18`. GET and DELETE return 405; SSE and persistent sessions are not provided. The endpoint rejects foreign `Origin` values. Authentication and Nextcloud ACLs for data tools will be exercised when those tools are implemented.
