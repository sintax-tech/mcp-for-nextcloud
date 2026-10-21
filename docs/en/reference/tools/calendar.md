<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Calendar

| Module | Read | Write |
| --- | --- | --- |
| **Calendar** | list calendars and events (recurrence, time zones, all-day) | create, edit, move, delete and transfer events through the real CalDAV pipeline, with participants. The writes are off until an administrator grants them in the admin matrix (no terminal step), and each one returns a plan first: **nothing is written before the user approves it.** |

| Tool | Grant | Notes |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendars visible to the user, own and shared. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Events in the range (default: next 7 days), with recurrence expanded. |
| `calendar_create_event` | calendar.create | Simple event; `attendees` are internal UIDs resolved to the account's email. |
| `calendar_update_event` | calendar.edit | Updates text/dates and replaces attendees; `attendees: []` removes all. An attendee change on a series or by another organizer is refused. |
| `calendar_move_event` | calendar.move | MOVE within the same owner; attendees are not notified, as in the Calendar app. |
| `calendar_delete_event` | calendar.delete | Deletes the series into the trash bin; requires active retention. |
| `calendar_transfer_event` | calendar.transfer | MOVE to a calendar of another owner shared with write access; refuses attendees. |

Calendar exposes reading and the five writes, with no terminal step. The writes start off in the admin matrix (Administration → MCP) and only appear for those who received the grant; every write also requires `confirm: true`, the Nextcloud ACL (and `confirm_shared` on someone else's calendar) and the optional ETag. Compatibility: the app declares `min-version` 32 and `max-version` 35 in `info.xml`; the internal part (the core's `EmbeddedCalDavServer`) is covered by the tests and by that range, and a new Nextcloud version requires a new version of the app. The Calendar app must be enabled for the user. Sabre and Symfony Console come from the Nextcloud core and stay out of the package's `vendor/`.

| Write tool | Grant | Behavior |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Simple event; `attendees` are internal UIDs resolved to the account's email. |
| `calendar_update_event` | calendar.edit | Updates text/dates and replaces attendees; `attendees: []` removes all. An attendee change on a series or by another organizer is refused. |
| `calendar_move_event` | calendar.move | MOVE within the same owner; attendees are not notified, as in the Calendar app. |
| `calendar_delete_event` | calendar.delete | Deletes the series into the trash bin; requires active retention. |
| `calendar_transfer_event` | calendar.transfer | MOVE to a calendar of another owner shared with write access; refuses attendees. |

Create, update and delete use `send_invitations: false` by default (`x-nc-scheduling: false`). `true` hands the invitation to Nextcloud's CalDAV scheduling, including existing guests and CANCEL. The plan and the result report `imipEnabled`, read from the native `dav/sendInvitations` setting: with it off the server does not send email (the internal iTIP still reaches the guest's inbox) and the assistant must tell the user so. The app never confirms email delivery. Writes on someone else's calendar still require `confirm_shared: true` after user confirmation.

**Confirmation before every write (no state on the server).** The five writes, called without `confirm: true`, only return a plan (`requiresConfirmation: true`): current and proposed values, attendees added/removed, destination, consequence for invitations/CANCEL/trash bin and the current `etag`. Nothing is written or scheduled. The assistant must show the plan, ask the user and only after an explicit yes repeat the call with `confirm: true` and the same arguments, resending the `etag` of the plan (optional; if it comes and differs, the write is refused and a new plan is needed). There is no table, token or approval record: the server does not prove the human yes, but permissions, ACL, grants and `confirm_shared` keep applying. The selftest uses the handlers directly and does not go through this flow.

See the live `mcp_guide` for the authoritative schema and available operations.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/calendar.md) · [Català](../../../ca/reference/tools/calendar.md) · [Português (Brasil)](../../../pt-BR/reference/tools/calendar.md) · [Português (Portugal)](../../../pt-PT/reference/tools/calendar.md)
