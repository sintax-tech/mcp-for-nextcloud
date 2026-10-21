<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Permissions and administration

Open **Administration → MCP for Cloud**. The five tabs are **Overview**, **Access and permissions**, **Clients and connections**, **Data protection** and **Advanced**. Their addresses (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) and keyboard navigation open the corresponding section.

## Enable access

The service, user eligibility (**Can connect**) and personal connection start off, including for administrators. Enable the service, allow the user to connect, then let that user connect in **Personal settings → MCP for Cloud**. Authentication alone never grants access: the account, service, eligibility, connection, optional app, operation permission and native Nextcloud resource permissions must all allow the request.

Reading starts allowed except for server logs. Other operations start denied. Files sharing with people/groups (`files.share`) and public links (`files.link`) are independent permissions. Listing shares is a read operation.

The Overview shows the endpoint, app version, connection counts and checkout upload limit. The checkout limit is edited in MiB and cannot exceed PHP's `post_max_size`. Advanced contains server-log roles/privacy and OCR. Data protection contains the exclusion tags; see [file protection](file-protection.md).

## Guests app restrictions

Starting with 1.1.2, MCP reapplies the Guests app restrictions after authenticating
an app password or OAuth token. A guest whose allowed-app list excludes MCP is
refused. MCP returns HTTP 403; Guests may reject an app-password login earlier
in Nextcloud (its rejection is surfaced as HTTP 500 on Nextcloud 35). Tools, Notes resources and Talk references for disallowed
apps are hidden or refused; file-version tools also require Guests to allow
`files_versions`. Files and Tasks use the always-allowed `files` and `dav` apps.

Hidden external storage remains inaccessible, including Nextcloud 35 mounts
resolved directly by path. Structured file references in Talk and Tasks, including
publication of Talk attachments, use the same hidden-storage check. When Guests hides other accounts, sharing remains
limited to the guest's groups. An unreadable Guests state fails closed.

Checkout and upload links apply the same relevant restrictions to their owner
before resolving a file. A refused guest receives the answer of an unknown link
(404), without spending it. Guests versions older than 4.8.0 cannot apply these
sessionless restrictions, so guest-owned links are refused. Non-guest accounts
and installations without Guests retain their existing behavior.

## Individual choices and group inheritance

Access and permissions provides module levels and an **Operations** view for individual permissions. Search uses name, ID or email; email is not displayed. Group and eligibility/connection filters run on the server, with 50 users per page. Disabled accounts cannot be edited, and unavailable applications or log roles remain unavailable without erasing saved choices.

Resolution has this order:

1. An individual's explicit **Allow** or **Deny** wins.
2. Without an individual choice, any trusted group grant allows the operation.
3. Otherwise the default applies. Groups grant access; they do not deny or narrow it.

Only groups from Nextcloud's own database backend may grant. LDAP, SAML, other application backends and ambiguous backend identities cannot safely supply group inheritance. Give their users individual permissions instead. A recreated or uncertain group does not silently regain its old authority: the editor explains the state and offers confirmation of the current policy where applicable.

Use **Group grants** to select a group, edit its operations and preview before saving. Member counts and sign-out estimates are administrative planning information, not an authorization decision for each member. Removing **Can connect** signs out the members who actually lose eligibility. Large groups can take time to process; this integration does not claim a new performance guarantee.

**Inherit** and the row/selection reset actions remove individual overrides. The preview identifies individual choices, granting groups, defaults and unknown results. An unreadable group or policy is **Unknown**, never an implicit allow or a reason to erase credentials. Requests that depend on that uncertainty fail temporarily; tools whose authorization is unknown are omitted from discovery while independently allowed tools remain available.

## Saving, conflicts and undo

Policy writes carry the revision displayed by the interface. A stale preview or concurrent change produces a conflict and a reload; the browser does not silently replay the write. An uncertain response is not shown as a successful save. Group/resource/client drafts stay drafts until their respective Save action.

Reset/bulk requests are limited to 50 users and 2,500 cells. Preview validity is 120 seconds; server receipts expire after 600 seconds. The interface offers **Undo** for 10 seconds while its control is available, pausing the timer on pointer hover or keyboard focus. Undo still requires the saved revision and a valid receipt: it cannot overwrite intervening changes. Restoring a permission does not restore revoked credentials or sign users back in.

## Resource restrictions and inspection

An administrator can restrict one user's **Files**, **Calendar** and **Tasks** resources from the access drawer. Each module is independent: **All resources** means unrestricted; **Only selected** with an empty selection grants access to none. The limit is 200 selected resources per user and module. Restrictions only narrow existing grants and native permissions; changing them does not revoke the connection. They apply to the next operation.

Notes, Contacts, Deck and Talk have no module resource restriction in 1.1.0. Structured file references and attachment operations can still require Files scope checks; see [file protection](file-protection.md). A folder restriction is not a general restriction on everything every other module stores beneath that folder.

The administrator-only inspector reports **Allowed**, **Blocked** or **Unknown**, the blocking check and the permission's source. It may explain an excluded path to the administrator; MCP errors retain generic refusals. Queries and changes can produce info-level audit messages without resource names or paths. These messages depend on the host log level and are not a guaranteed audit trail.

## Clients and personal settings

Manage published client hosts, the native client and registered custom clients under **Clients and connections**; see [OAuth](../reference/oauth.md). Administrative APIs require an administrator and Nextcloud CSRF protection. Personal connection/scope APIs derive identity from the session and expose only that user's state. Responses never contain token hashes.

Personal settings show connection status, connected clients, their revocation controls and the user's read-only resource restrictions. For HTTP Basic, create an app password under **Personal settings → Security**. MCP does not ask for or store the main password. Disconnecting MCP does not delete that Nextcloud app password; see [revoking access](clients.md#revoking-access).

A residual write risk remains: a generic refusal can distinguish an unavailable destination from one where creation would succeed, even though it does not disclose why the destination is unavailable.

[Operational guides](README.md).

English · [Español](../../es/guides/permissions.md) · [Català](../../ca/guides/permissions.md) · [Português (Brasil)](../../pt-BR/guides/permissions.md) · [Português (Portugal)](../../pt-PT/guides/permissions.md)
