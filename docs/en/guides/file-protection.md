<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# File protection

## Resource restrictions (1.1.0)

Administrators can independently restrict Files to selected folders, Calendar to selected calendars and Tasks to selected task lists. Calendar events (`VEVENT`) and tasks (`VTODO`) remain separate even inside one collection. An empty restricted selection allows no resources; an unrestricted selection adds no resource filter. Restrictions never grant native access.

After account/policy/operation admission, resource scope is checked before tag exclusions and native permissions. An unreadable scope produces temporary unavailability before resource lookup. Out-of-scope resources receive the generic missing-resource refusal and disappear from lists. Restrictions apply to the next operation without revoking the connection.

Notes, Contacts, Deck and Talk have no module resource restriction in 1.1.0. A Files restriction therefore does not automatically restrict Notes stored in those folders. Their own permissions and applicable tag exclusions still apply. File attachment operations and structured references crossing those modules can require the Files scope: Talk hides inaccessible attachment IDs, Tasks filters protected ATTACH references from output while retaining stored DAV content, Calendar emits selected event fields rather than raw ATTACH, and Deck exposes attachment counts rather than file URLs. Free text is not a general data-loss-prevention filter.

While tag exclusions are configured, visible folders cannot be shared, copied or moved to another parent through MCP; refusal occurs before subtree enumeration or mutation. Same-parent renames retain native/lock checks. Existing folder-share listings return `url: null` without reading the public token. Structured output also removes public folder links when exclusions or Files restrictions prevent safe exposure. Use the native Files interface for the refused folder operations.

## Locked file (1.0.5)

Notes and Files writes in the face of `files_lock` (an app up to Nextcloud 34, built in on 35). Common service: `mcp/lib/Platform/FileAccess/Locks/LockAwareWrite.php` (`ILockManager::isLockProviderAvailable` + `getLocks`), without importing files_lock classes.

- **Who blocks:** a manual lock of another person (`TYPE_USER`), an app lock (`TYPE_APP`, Text or Office) and a WebDAV client lock (`TYPE_TOKEN`). APP and TOKEN refuse even the owner. The user's own manual lock lets them write and is kept, as in the Files app; on the checkout-by-link route, which has no user session, it also refuses, as files_lock does there. Without a provider, nothing changes.
- **Where:** before the share confirmation and the backup, and again right before each write (`LockAwareWrite::run`): `putContent`, move, rename and delete of a note, each item of a batch and of an undo, version restore, checkout upload, content of a new file and backup copy. The storage's refusal (`ManuallyLockedException`) is also captured and explained (`capture`, used in the copy, which only reads the source).
- **In doubt, refuse:** a lock query that fails refuses; a folder being moved is checked with everything the user reaches inside it, up to 5,000 items (`LockAwareWrite::TREE_LIMIT`), and a listing larger than the remaining budget refuses without reading the lock of the items. The listing of each folder is read whole before this comparison: `getDirectoryListing()` has no limit on 32–35 and `Folder::search()`, which has one, queries the file cache and does not reproduce the listing. A file hidden by the `VisibilityGuard` is not named and the plan's `lock` carries only `blocking: true`.
- **Plan (`confirm` absent):** it does not refuse; it adds `lock` and a `file_locked` notice in `warnings`. In `files_move_batch` the locked item goes to `denied`, and the whole batch is refused.
- **Version restore:** with a provider, it reads the version through `IVersionManager::read()` and writes through the file, without the core's `rollback()`, whose `handleAppLocks` repeats the write in the Text/Office scope. The guarantee about the replaced content is the backup in `/MCP backups`.
- **Checkout:** lock → 423. Before the link is spent, the link remains valid; afterwards, the response cites the backup and asks for a new `files_checkout`.
- **Message:** app or person (display name; never UID, app id or token), since when, until when and what to do, in en/de/pt_BR/es. A refusal without a safe correlation by the token keeps the holder unidentified. Log only at `debug`, without path or token.
- **Limit:** TOKEN is only refused by the MCP check, which is not atomic with the write, and the files_lock `getLocks()` answers from the request cache, including a negative one: a TOKEN lock created after the first query of the file in the same request is not seen. USER and APP locks are also enforced by the files_lock storage, which reads the lock again at the write, except on deleting or renaming to/from Markdown a Text document: the Text listener (`BeforeNodeDeletedListener`/`BeforeNodeRenamedListener`) runs before the storage and does `resetDocument(..., true)`, releasing the APP lock; a Text APP lock created after the MCP query is released that way and does not prevent the deletion or rename. The MCP only refuses the lock the provider observes. files_lock offers no atomic lock acquisition (separate query and insert, `file_id` without a unique index), so the app takes no lock of its own.

## Hidden tags (1.0.6)

The `VisibilityGuard` applies system-tag hiding on every access path of the connector: reading, search, listing, tree, images, versions, notes, Talk, resources, checkout, writes and undo.

- **Inheritance:** a tag on a file or folder covers all descendants. An item reached through a local share inherits the tags of the owner's source folder chain (storage `OCP\Files\Storage\ISharedStorage` + id in the filecache, resolved once per shared root). Team folders, external storage and federated shares follow the mounted path, without querying the owner; a federated one is checked only by the local tags — those of the remote server are not readable from here.
- **Fail-closed:** a configuration that cannot be validated (the manager failing while the list is saved, or a stored value that is unreadable or invalid) keeps the previous configuration and refuses access, instead of turning the protection off; the admin page warns and offers the repair button, and saving the empty list asks for confirmation. Visibility decisions are invalidated after moving, creating or copying, so no old state is answered.
- **Logs:** with tags configured and the option **Hide the text of log messages** on (default; key `logs_hide_file_names`), the log tools hide the text of messages and exceptions, the user agent and the method outside the known HTTP/WebDAV verbs, reduce the URL to the fixed route prefixes and refuse the `contains` filter, which would match the hidden text. Turned off, the log comes back complete for diagnosis — the option exists because IT teams read exactly those details to map problems. The app's own error log records only the class and the code of the exception, never the message, on any installation.
- **Limits:** the protection applies to this MCP connector, not to other AIs or connectors that read the same Nextcloud; a loose name that another app writes in a structured field of a log entry is not handled; native tests cover local shares, groupfolders and local external mounts in disposable Nextcloud instances. Federation and advanced groupfolder ACL arrangements still require separate host review; they are not certified by that matrix.

- **Freshness and storage boundaries:** another process/node can update exclusion appconfig while a warm node serves the previous value for approximately the core's three-second TTL plus request time. Distinct narrow external mounts can assign different file IDs; a tag on one storage is not automatically a tag on another. Group-backend lists, positive native group-existence caches, app availability and memoized users also have native freshness limits in long-running processes. Clearing the official policy caches does not promise every native cache refreshed.

- The protection applies only to this MCP connector: it does not cover other AI tools or other connectors that read the same Nextcloud, and nothing stops someone from sending a file to an AI by hand.

[Operational guides](README.md).

English · [Español](../../es/guides/file-protection.md) · [Català](../../ca/guides/file-protection.md) · [Português (Brasil)](../../pt-BR/guides/file-protection.md) · [Português (Portugal)](../../pt-PT/guides/file-protection.md)
