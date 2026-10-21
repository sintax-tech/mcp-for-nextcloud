<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contacts and Tasks (0.8.0)

| Module | Tools | Dependency |
|---|---|---|
| Contacts | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | Contacts app enabled; core CardDAV |
| Tasks | `tasks_list_calendars`, `tasks_list_tasks`, `tasks_read_task`, `tasks_create_task`, `tasks_edit_task`, `tasks_complete_task`, `tasks_delete_task` | core DAV; does not require the Tasks or Calendar app |

The Contacts catalogs are own/shared, explicitly filtering any system principal. Reads use lazy adapters of the native backend, after the access filter; writes go through the core's Sabre (a separate CardDAV dispatcher and the existing `EmbeddedDavDispatcher` for VTODO). The session must match the actor and the principal is fixed at execution. Calendar collection queries default to VEVENT; Tasks selects VTODO.

`GrantPolicy`/`GrantMatrix` include `contacts` and `tasks` with read/create/edit/delete, with writes off by default. Completing a task uses edit. `PreviewsWrites` produces the plan with no side effects; `WriteGate` requires confirm:true; shares require confirm_shared; a supplied ETag is checked and If-Match uses the re-read one. Editing a vCard preserves unknown fields, parameters, groups and the version; the plan includes every before/after property and the full vCard.

**Deleting a contact is PERMANENT in Nextcloud 33, with no trash bin.** Only after confirmation, `ContactBackup` saves the complete original vCard in `/MCP backups/Contacts/<catalog>/<name-or-uid>.<YYYYmmdd-HHMMSS>-<unique-suffix>.vcf`, in the account's time zone and in the actor's folder. It uses an application mutex, a random suffix and a collision check so it never selects existing files, and verifies the copy by re-reading the bytes. A failure prevents the CardDAV DELETE; the result reports backupPath. Re-import through the Contacts interface to recover. No backup is created in the preview.

Tasks use VTODO, include undated tasks, hide PRIVATE/CONFIDENTIAL ones of another owner and exclude objects in the trash bin. Reading recurrence is allowed; writes on series or tasks with attendees are refused. Fields that did not change stay in the iCalendar; completing writes STATUS/PERCENT-COMPLETE/COMPLETED, and lowering progress reopens the task. Deleting uses the CalDAV trash bin, refuses zero retention and checks deleted-at after the DELETE. Listings use limit/offset/nextOffset; completed tasks require include_completed. Categories (`CATEGORIES`) are free text: reading and listing return `categories`, merged from every `CATEGORIES` line (escaped commas respected), trimmed and deduplicated ignoring Unicode case, keeping the first spelling; create and edit accept `categories` (up to 50 items of 1–255 characters after trimming, no control characters, validated before any write): omitting it leaves every line untouched, `[]` removes them and a list replaces them with one multi-value line. `tasks_list_tasks` filters by `category` (exact, case-folded) before `offset`/`limit`, together with `include_completed`; the plan shows each name quoted, before and after. Categories of tasks hidden by CLASS are never read, filtered or shown.

There is no migration, approval_id, approval record, occ requirement, extra service or auxiliary database. Descriptions/ToolGuideNotes stay in English; titles and messages use Translator with de/pt_BR/es parity. Tests cover plans, backup, ACL, grants, ETag, preservation and real Sabre dispatch; they do not prove the internal plugins on a real Nextcloud installation.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/contacts-tasks.md) · [Català](../../../ca/reference/tools/contacts-tasks.md) · [Português (Brasil)](../../../pt-BR/reference/tools/contacts-tasks.md) · [Português (Portugal)](../../../pt-PT/reference/tools/contacts-tasks.md)
