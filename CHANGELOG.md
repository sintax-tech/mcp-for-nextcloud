# Changelog

All notable changes to the native Nextcloud app (`mcp/`). Versions follow `mcp/appinfo/info.xml`.

## 0.10.0

### Added

- files: three tools for the shares of the files and folders the user owns. `files_list_shares` lists the shares the user created — of one file or folder, or of all of them (50 per page, `offset` up to 1000) — with the type, the recipient, the `view`/`edit` permission, the expiry, whether a password exists and the link URL; the password itself is never returned, a hidden node counts as missing and a Talk attachment is listed as not removable. `files_share` shares an own file or folder with a person (`user:<uid>`) or a group (`group:<gid>`), or changes the share that already exists for that recipient (`permission` `view`/`edit`, `expires` in the account's timezone, `note`); the plan shows before → after, says when a web-made share loses its re-share right, and says "nothing to change" when the call would change nothing. The administrator's sharing rules are explained before writing and a refusal of the core becomes a translated message. With `with: "link"` the same tool creates or changes the public link of an own file or folder (one per file, view only); the server generates the password, through the password policy app or a 16-character secure random fallback, validates it against the policy before saving it, and creates it when `password: true` is passed or the administrator requires one. It is returned once, in the result of that execution, and never in the plan, the log or `files_list_shares`; the plan warns that anyone with the link can open the file, and the administrator's link rules (links off, maximum and default validity) are applied before writing. `files_unshare` removes a share the user created, named by the `shareId` of `files_list_shares` or by `path` plus `with`; the plan says who loses access (a person, every member of a group, or "the link will stop working") and that the file itself stays untouched. What is not the user's own — somebody else's share, a share of a file owned by somebody else, a hidden node, an unknown id — is answered as "not found" without revealing it; a Talk attachment is removed in Talk. Both writes are checked again when the call is confirmed, and each one requires the grant of the kind it acts on (`share` or `link`).
- files: two tools create a NEW file, under the existing `files.create` grant. `files_upload` is for any type generated locally (docx, xlsx, pdf, images): the plan names the file, the folder, the link lifetime and the upload limit of `files_checkout` (a declared `size` over it is refused before any link exists), and the confirmed call returns `uploadUrl`, a single-use link valid for 15 minutes bound to the user and the path (never a node id), with the exact `curl -sS -T file -X PUT -H "Content-Type: application/octet-stream"` command. The link is spent on the checkout upload route, which answers 201 with `path`, `size`, `etag` and `fileId`; an empty body creates an empty file, and a multipart body (400), a body over the limit (413), a name taken in the meantime (409) or a folder that went away (404) leave the link usable. `files_create` writes a small text file inline (`.md`, `.txt`, `.csv`, `.json`, `.html`, `.xml`, `.yaml`, `.yml`, up to 1 MB), and its plan quotes the beginning of the text. Neither ever overwrites a file with content: an existing name is refused in the plan, in the confirmation and when the bytes arrive, also when another client creates it with content in between (the file is created empty first, and a file that turns out not to be empty is left alone) or changes it between the creation and the write (it is read again right before the write: another id, ETag or size is a 409 that writes nothing). An empty body for a link whose plan declared a `size` above zero is refused (400) and the link stays usable. The folder must exist (a hidden one answers like a missing one), accept new files and, outside the personal folder, be confirmed with `confirm_shared`; names Nextcloud refuses (`IFilenameValidator`: reserved names, forbidden characters and extensions) are argument errors that never echo the value. The tool guide and the descriptions send a new file to `files_upload`/`files_create` and keep `files_checkout` for replacing an existing one. A new prompt, `create_nextcloud_file`, teaches that flow to anyone with `files.create`; the `edit_nextcloud_file_locally` prompt (`files.edit`) only adds it when `files.create` is granted too. The `files.create` column of the admin matrix now reads "Create folders, copies and new files".
- tools: a tool may list alternative grants (`grantAnyOf`); it is listed and callable with any of them, and checks the one each call needs.
- grants: the Files module gains two operations, `share` (people and groups) and `link` (public links), so an administrator can allow one without the other. Both are new columns of the admin matrix and both start denied.
- deck: a Deck can now be set up from scratch. `deck_create_board` creates a board owned by the user with its lists and first cards (up to 20 lists and 100 cards, optional colour, assignees limited to the user because nobody else has access to a new board) after one confirmation, and the plan shows the whole tree. If a step fails midway nothing is undone: the result lists what was created (`created`), what was not (`failed`, with a safe reason) and `warnings`, so a retry does not duplicate anything. `deck_create_stack` adds a list to a board the user manages, at the end or at a position, and asks `confirm_shared` on somebody else's board. Both use the existing `deck.create` grant.
- deck: `deck_delete_stack` and `deck_delete_board` delete a list or a board only when it is empty: any card, active or archived, refuses the call with the number of cards left (the plan already says so), and the count is taken again when the call is confirmed. Only the owner can delete a board; both go to the Deck trash and can be recovered there. Both use the existing `deck.delete` grant.

### Security

- files: the public upload route of `files_checkout` and `files_upload` refuses everything the headers and the token can refuse — a multipart body, an empty one, a `Content-Length` over the limit, an unknown link, a link of another kind or of another session, a spent or expired one — before a byte of the body is copied to the temporary folder, so a request without a valid link cannot fill the disk.
- files: a `files_upload` link is no longer burned by a refusal that only read: a folder that became shared, lost its create permission or can no longer be updated is answered (409 or 403) before the link is spent.
- files: a lock or a full quota while a new file is written keeps its own message (423 and 507 on the upload route) instead of a generic error, and any other failure is logged with the exception class only.
- files: `files_list_shares` without `path` lists only shares of files the user owns. A share the user created of a received file (a re-share made in another interface) no longer appears, with its link URL or recipient, and takes no place on a page.
- files: the confirmed `files_share` and `files_unshare` execute exactly what the plan showed. The plan carries `plan_state`, an opaque HMAC (with the instance secret) of the action, the share and its fields before and after, never a password, also cited in the line for the model at the end of the plan. The confirmed call gives it back: when the share appeared, changed or went away in the meantime (create → update, update → create, update → nothing, nothing → update, a share removed and made again), nothing is written and the answer is the new plan with "the share changed since the plan; check it again". A confirmed call without `plan_state` is an argument error. The helper (`PlanState`, `PlanChanged`) is generic, for other writes later, and the value is bound to the calling account.
- files: that `plan_state` also binds every argument that changes the effect: the node (id and path), the recipient (kind and id), and for `files_share` the permission, `expires`, `note` and `password` as the call gave them; for `files_unshare` the share id and type. A plan made for one person no longer confirms a share with another person, a group or another file when both would find the same state: the confirmed call writes nothing and answers with the new plan.
- files: an unexpected failure while the link password is validated (a policy listener throwing something other than a refusal, whose message may carry the candidate) or while the share is saved (an `Error` included) becomes the safe "password refused" or "share refused" message. The log keeps the exception class only, so the generated password never reaches it.
- people: a failure of the core search in `users_search` becomes the fixed message "the search for people failed; try again", and the log keeps the exception class only, so the search term never reaches the log.

### Fixed

- files: the checkout upload never writes a body the server could not keep (larger than its declared length, or a temporary file that could not be written): it answers 413 and the link stays usable, where it could have written an empty file.
- files: an undo of `files_move_batch` that stopped halfway (a lock or a permission that changed) no longer ends in a fatal error. It keeps only what did not go back, so the next undo finishes the batch; this was broken since 0.9.0.
- files: `files_move_batch` confirmed with `confirm_shared: true` moves the shared items too, in their place in the order, records them and can undo them. They used to be dropped without a word; without the confirmation the plan still asks for it.
- files: `files_move`, `files_copy` and `files_move_batch` refuse `/MCP backups` and what is inside it, as source and as destination, with the message of the other write tools. Moving or renaming that folder could expose the backup of a file that was hidden later.
- files: the folders of `files_move_batch` (`mkdirs`) go through the checks of `files_mkdir` in the plan and again before the first one is made. A folder is no longer created inside a hidden folder, and a name taken by a file, the backup folder or a folder without the create permission no longer leaves folders made and no batch to undo them; the plan names the folder that cannot be created and is not ok.
- files: `files_image_search` with a hidden `folder` answers "not found", like a folder that does not exist, instead of an empty list.
- files: `files_copy` of a folder with a hidden file or folder inside is refused before anything is copied. The copy gives every node a new id, the hidden tag stays with the original, and the copy of the hidden file was readable.
- files: the plan of `files_undo_batch` says a folder the batch created goes when the undo leaves it empty, as the run does; it used to say the folder stays.
- files: `files_tree` says `truncated` only when an entry was left out, not when `limit` is exactly the number of entries.
- deck: a Deck write no longer answers with an error once it may have been saved. Deck 1.17.5 writes first and only then runs activity, events and notifications, any of which may throw (a default label of a new board, a board event of a new list, a notification of a deleted card). After an exception of the write itself, every write tool (create, edit, move and delete a card, assign and unassign, create a board and a list, delete a list and a board) reads the real state again: a card of the user with the same title created in the last two minutes, the card or list in the trash, the fields and modification time of the edited card, the destination list of the moved card, the new board or list that did not exist before. When the write happened, the answer is a success with the warning "Saved; Deck reported an error afterwards (notification or activity)"; when it did not, the usual error; when the state cannot be read, a result that is not an error and says to read it with `deck_list_cards`, `deck_read_card`, `deck_list_stacks` or `deck_list_boards` before trying again. `deck_create_board` lists a step saved this way as created and one that cannot be confirmed as a warning, never in `failed`. A refusal before the write (permission, validation, session, the rules of the tools) stays an error, and a list or board whose delete could not be taken back after a card arrived is a partial result pointing to the Deck trash, not an error. The log keeps the exception class only.
- deck: that read-back no longer trusts the exception class. Deck's own `NoPermissionException` and `BadRequestException` are also followed by a read-back, because a listener of another app may throw them after Deck saved the data; a real refusal read back finds nothing and stays the usual error. Only the refusals this app raises itself before calling Deck (rules of the tools, session, conflicts, argument errors) skip the read-back.
- deck: an item found again only by likeness is never taken as proof. A card found by owner, title and time, or a list or board with the title that was not in the snapshot, is answered as `confirmed: "probable"` with its id and the warning "Probably created: check it before continuing", and nothing else is written on it: no assignment on the card, no card inside the list, no list or card inside the board. In `deck_create_board` the steps that depended on a probable board or list are reported in `failed` with the reason and the result is not `complete`. Exact reads by id (edit, move, delete, assign of a known card) are still confirmed.
- deck: `deck_delete_stack` and `deck_delete_board` no longer leave a card in the trash with the list or the board. The Deck deletes without looking at the cards, so a card created by another request between the count and the delete was lost with it. The cards are now counted again right after the delete; when one showed up, the board is brought back with Deck's own undo and the list is restored through the Deck update with `deletedAt: 0` (Deck has no undo for a list), and the call is refused as "received cards while it was being deleted; nothing was deleted".
- deck: when that restore throws (the list's `StackService::update()` with `deletedAt: 0`, or the board's `BoardService::deleteUndo()`), the item is read again before anything is claimed: Deck saves `deleted_at = 0` before its activity and events, so an item that is active again is answered like a delete that lost a card. If the item cannot be read at all, the answer is not an error and says to read it with `deck_list_stacks` or `deck_list_boards`.
- notes: `notes_edit` with `content` and a `title` already taken in the category no longer overwrites the note and then fails. Everything that can refuse — the size, the title that cleans to nothing, the title already used — is checked before the first write, and the plan refuses the same title, so nothing is written and the note keeps its content (Notes keeps no backup).
- notes: the plan of `notes_edit` compares the whole content to say what changes. An edit that only touches the part after the excerpt the plan shows was reported as "nothing changed"; it is now listed as a content change and the plan gives the size before and after.
- notes: `notes_create` asks for `confirm_shared` when the notes folder, or the nearest existing folder of the category, is a share, a team folder or an external storage, like the other writes of Notes; its plan lists the shared resource.
- talk: `talk_attach_file` refuses a blank or too long caption before it creates the share, where it used to create the share, post the card and report the caption as not sent.
- talk: `talk_read_messages` takes an attachment envelope only from the comments Talk writes itself (rich-object and system messages). A participant who typed JSON shaped like an envelope no longer hides their text or forges an attachment id.
- checkout: the links of `files_checkout` and `files_upload` are refused (403, the same answer as a revoked grant) when the account was disabled or removed after the link was issued, before the body is read and without spending the link.
- calendar: the CLASS of a private or confidential appointment of someone else is now read component by component, as the iCalendar defines it: the master and each `RECURRENCE-ID` override have their own. An override is treated by its own CLASS and, with none, takes the one of the master; `CLASS` in lower case counts as well. `calendar_list_events`, the collision warning of the plans and every other read of the calendar hid only what the master said, so a private occurrence of a public series, altered by its organiser, showed its title. A change to an object with a private or confidential component in someone else's calendar is refused as before, now also when only an override carries the CLASS.
- calendar: the collision warning of an all-day event looks at the day in the zone of the account. It searched the day in UTC, so an appointment of the evening before could be reported as overlapping and one of the late evening of the day was missed; all-day events still compare day against day, in any zone.
- calendar: the free/busy check of the guests of an all-day event also asks about the day in the zone of the account (and the update about the days added to the current ones), where it asked about the day in UTC, so a guest busy the evening before was reported and one busy late in the day was missed.
- calendar: a `CLASS` the standard does not define (`X-FOO`, for example) is handled as `PRIVATE`, as RFC 5545 §3.8.1.3 asks, in `calendar_list_events`, the collision warning, the plans and the changes of someone else's events; it used to show the event in full.
- calendar: the plan of a write in a calendar that belongs to someone else names the calendar and its owner in the text the person reads, not only in the structured `shared` block.
- deck: the `total` of `deck_list_cards` counts the cards before `offset`: a short page at `offset: 50` with 3 cards answers 53, not 3. Whether the page was short is decided on what Deck returned, not on what is left after the filter, so a full page is never taken for the end of the list. A page with no card past the first one (an `offset` beyond the end) answers `total: null`, not the `offset` it was asked for.
- notes: `notes_edit` with a new `title` also checks the permission before it writes anything, as Nextcloud requires for a rename: update on the note, delete at the source and create in the folder. A note in a share without the create or delete permission is refused in the plan and in the write, before the shared-content confirmation and before the content is put, so it is no longer overwritten by a call that then reports failure.
- files: the folders `files_move_batch` creates (`mkdirs`) ask for `confirm_shared` when they are made inside somebody else's share, a team folder or an external storage, like `files_mkdir`. Only the folders the batch moves into were judged; one made there with no move using it, with only personal items moving, was created with no shared-content confirmation. The plan lists them in `sharedDirs` and in the text, and nothing is created before the confirmation.
- calendar: the collision warning of a timed event looks at an existing all-day event by the day in the zone of the account, as the warning of an all-day event already does for a timed one. It compared the window with UTC midnights, so an evening appointment was reported against the next day's all-day event and a late one missed the all-day event of its own day (the same in the early morning east of UTC).
- files: an undo of `files_move_batch` that stops on any exception, not only the ones Nextcloud types, keeps only what did not go back, like the run of the batch does, and answers the generic "Failed to move the item." for it. An exception of another kind used to leave the batch whole, and the retry read the items already back home as conflicts.
- talk: `talk_read_messages` reads a voice message and an audio or video recording as an attachment too (the verbs `voice-message`, `record-audio` and `record-video` Talk 23 writes next to `object_shared`), with its `attachmentId`, so `talk_quote_file` works on them. They were shown as the raw envelope without an id. The envelope is still taken only from the verbs Talk writes itself, never from a comment a participant typed.

### Known limits

- files: Nextcloud has no create-if-absent, so `files_upload` and `files_create` create the file empty and then write it. An empty file that another client created with the same name in the instant before is taken over, and a client that writes the same name between the last read and the write overwrites it or is overwritten as with any other write, the earlier content kept as a version. Two links for the same path create the file once; the second answers 409 and stays unspent. A write that fails after the empty file appeared leaves that empty file in place (nothing is deleted) and says so.

- deck: `deck_delete_stack` and `deck_delete_board` cannot make "only delete when empty" atomic, because Deck's `CardService::create()` never looks at the `deleted_at` of the list. A card created by another request in the instant right after the second count may go to the trash with the list or board; it is recoverable there. This is documented in the tool description and the gateway.

## 0.9.1

### Fixed

- oauth: a request authenticated with an OAuth access token now has the token owner in the session (`user_id`) while it is handled, through `IUserSession::setUser()`, and the uid is removed again when the request ends. The apps whose services receive the injected `userId` saw a null user before: creating or deleting a Deck card was written and then answered with an error, so the client retried and the card was created twice. The trash bin reads the same session value, so the versions of a note deleted under OAuth could be filed outside the trash of the user. Requests with Basic and an app password keep the session of the core login untouched.
- deck: the tools refuse a call before anything is written when the session the Deck services read is not the authenticated caller, with a clear message instead of an error after the write.

## 0.9.0

### Added

- tools: every write of every module returns its confirmation plan as readable Markdown, so the person reads what they are about to approve. The text is translated to the user's language (English, Brazilian Portuguese and Spanish), and the machine-readable plan is kept in `structuredContent` as before, so nothing a model relies on changes.
- calendar: the plan of an event creation, update, move or transfer warns about a collision with another event, shows the availability of the attendees and names the shared calendar it will use. Collision and availability are computed from the instance; a shared calendar is found by name and owner instead of its DAV path.
- people: a new `users_search` tool finds account ids through the sharing search, so the model asks before guessing an id.
- contacts: `contacts_search_contacts` also searches the read-only accounts catalog of the instance when `addressbook` is omitted or is the catalog path, so colleagues are found. It uses the official `OCP\Contacts\IManager` and applies the admin user-enumeration settings (autocompletion, group restriction, full-match). Each account result carries `accountId`; a personal contact with the same e-mail is kept and gains it. `contacts_read_contact` reads an account with public fields only, `contacts_list_addressbooks` lists the catalog as read-only, and create/edit/delete refuse it.
- deck: creating a card can assign board members, with their names in the plan.

### Changed

- tools: an invalid argument error now names the field and the rule that rejected the value, in camelCase too, so the model can correct the call without guessing. Only safe details are exposed.

### Security

- plans: text written by other people (file names, reasons, excerpts, contact fields, note bodies, deck and talk names) is escaped before it enters a plan, so a name cannot inject Markdown or HTML into the confirmation the user reads. Newlines are normalized and long text is truncated.

### Fixed

- calendar: an event or task in the trash is found under the renamed `-deleted` URI the core uses, so deleting and reading it back works.
- calendar, tasks: the state after a write is read straight from the database instead of the CalDavBackend cache, so the result of a create or update is current.
- files: a batch move puts the plan items into the folders it creates itself, instead of placing them before the folders exist.
- files: the checkout upload accepts the raw bytes whatever the content type sent by the client.
- talk: quoting a file resolves the attachment by its full `ocRoomShare` provider id, instead of the room id alone.
- calendar: the availability log records only the exception class, with no message and no e-mail.

## 0.8.2

### Fixed

- calendar: event creation, update, move and transfer, task creation and Talk event references failed with "Unexpected error" on Nextcloud 33 because the calendar store called a backend method that does not exist; a contract test now checks every backend method the adapters use.
- logging: an unexpected tool failure now logs the exception message (truncated to 300 characters, file paths replaced, no tool arguments) next to the exception class; the client still receives the generic error.

## 0.8.1

### Fixed

- resources: the server failed to boot (HTTP 500 on every request) because the resource registry built the tool guide without its modules; the guide now comes from the tool registry, and a test boots every registered service factory.

## 0.8.0

### Added

#### Files
- Content search with excerpts via `files_search`. When `fulltextsearch` and `files_fulltextsearch` are enabled and indexed, searches file contents (`search_mode: content`) and returns excerpts with node resolution. Gracefully falls back to file name search (`search_mode: name_only`) with an internationalized notice when fulltextsearch is not active or encounters an error. An optional `mode` parameter (`auto` | `name`) allows callers to force name-only search.

#### Notes
- Content search via `notes_search`. Searches note title and Markdown content (up to 1 MiB), returning contextual snippets around query matches, optionally filtered by category.

#### Contacts
- List personal/shared address books, search and read contacts, create/edit through the native CardDAV pipeline, preserving unknown vCard fields, parameters, groups and version. The system user directory is excluded; tools and admin columns follow Contacts app availability.
- Permanent deletion through native CardDAV, with an explicit no-trash warning and the full vCard in the plan. After confirmation, a verified `.vcf` backup is saved in the acting user's `/MCP backups/Contacts/<address-book>/` before DELETE; backup failure prevents deletion and the result gives the backup path for import through Contacts.

#### Tasks
- List VTODO calendars, list/read/create/edit/complete/delete simple tasks through core CalDAV, independent of the optional Tasks app. Undated tasks are included; private/confidential shared tasks are hidden. Deletion uses calendar trash and refuses zero retention. Recurring tasks can be read; writes to series or tasks with participants are refused.

#### Contacts and Tasks
- Separate admin read/create/edit/delete grants with writes off by default; actual before/after plans, central `confirm: true`, shared-owner acknowledgement, optional client ETag and mandatory current `If-Match` for edit/delete, pagination, friendly localized titles/messages and English tool guides.

#### OCR
- Detects the Workflow OCR app (`workflow_ocr`), with no terminal step and nothing that blocks the app. It writes the recognised text into the PDF as a new version, so `files_read` already reads it. A PDF or image with no text layer now comes back as a normal answer with `text_layer: false` and a notice that depends on whether the app is active (ask the admin to install it or view the page as an image; or wait, since it processes in the background by the admin's rule). `files_version_read` does the same.

#### MCP Resources
- Capability advertised as `resources: {}` in both legacy `initialize` (2025-06-18) and modern `server/discover` (2026-07-28) eras.
- `resources/list` exposes `mcp://guide`, rendering the tool guide in Markdown dynamically filtered by the authenticated user's active grants and enabled apps.
- `resources/templates/list` exposes URI templates `nc://files/{path}` (when `files.read` is granted) and `nc://notes/{id}` (when `notes.read` is granted and the Notes app is enabled).
- `resources/read` fetches file contents (with text extraction and truncation up to 100,000 characters; small binary files returned as base64 blobs up to 512 KiB) and note contents (up to 1 MiB).
- Non-existent, ungranted, or hidden resources return standard JSON-RPC errors (`-32602` in modern era, `-32002` in legacy era) and never return empty content.

#### Hidden files and tags
- Hide sensitive files and folders by Nextcloud system tag. The administrator can select system tags in the admin settings; any file or folder bearing those tags (or inside a tagged folder) is completely hidden from MCP tools, behaving as if it does not exist (read, search, list, tree, image view/search, notes, talk attachments, and checkout token usage all return "not found", and write destinations report forbidden without leaking existence).
- Automatic system tag propagation to backups created under `/MCP backups`. VisibilityGuard also mirrors visibility if the original file still exists.
- Zero cost when no hidden tags are configured (default behavior unchanged).

#### OAuth and clients
- ChatGPT (`chatgpt.com`) is allowed by default next to `claude.ai`; an explicit `oauth_client_hosts` value is kept.
- Optional built-in native client `nextcloud-mcp-native` for local programs such as Gemini CLI (loopback redirects, PKCE, off by default; key `oauth_native_client_enabled`). The consent screen labels it "Local program (native client)".

#### Admin and personal settings
- "OAuth clients" section to edit the allowed `client_id` hosts (default `claude.ai`, `chatgpt.com`) and to enable the native client for local programs, showing its `client_id` to copy and the accepted loopback redirects.
- Own "MCP for Nextcloud" entry with the app icon (`img/app.svg`, `img/app-dark.svg`) in both the administration and the personal settings menus.
- "Status" block with the endpoint to copy, the service switch, the app version, eligible and connected users and active OAuth connections.
- The `files_checkout` upload limit is edited in whole MiB in the "Status" block (`GET`/`PUT /api/checkout-limit`), next to the effective limit and PHP's `post_max_size` ceiling; before, it only changed with `occ`.
- "Active connections" block lists every OAuth connection (user, client host or local program, signed in, expires) with search and paging, and revokes one connection or every connection of a user at once (`GET /api/connections`, `DELETE /api/connections/{id}`, `DELETE /api/connections/users/{uid}`). Token hashes are never returned.
- Personal: the user sees their own OAuth clients and revokes any of them (`GET /api/my/connections`, `DELETE /api/my/connections/{id}`, restricted to the signed-in user).
- Admin matrix: filters "only users who can connect" and "only connected users" (server-side, exact total), user counter, pagers on top and bottom, a "connected" badge, optional compact rows, column tooltips and a retry when loading fails.
- Dedicated "Hidden files & tags" section in the MCP admin settings with badge indicators for tag types (invisible, restricted, collaborative) and warning if collaborative tags are selected.
- "OCR" block shows whether Workflow OCR is active and, when it is not, a link to the App Store and the note that it needs `ocrmypdf` on the server.

#### Docs and tool guide
- The Files notes in the tool guide explain `text_layer` and OCR.
- Per-client connection instructions (Claude, ChatGPT, Gemini CLI, app password) in both READMEs, plus the admin "OAuth clients" section and what OAuth enforces. ChatGPT and Gemini CLI are compatible by code and are not yet proven against a real client: the end-to-end test happens after 0.8.0 is deployed.

### Changed

#### Files
- `files_checkout` accepts any file type (DOCX, XLSX, PDF, images) up to the upload limit instead of refusing non-text files; `files_edit` and `files_replace` stay text-only.
- `files_read` and `resources/read` share one text reader, so the 20 MiB limit is checked before any extraction on both paths.

#### OCR
- `files_read` of an image no longer fails as unsupported; it answers with `text_layer: false` and the notice.

#### OAuth and clients
- Client metadata documents are accepted when `token_endpoint_auth_methods_supported` is a non-empty list of strings containing `none` (the singular `token_endpoint_auth_method` is only used when the list is missing), as ChatGPT publishes `private_key_jwt` as its singular value.

#### Admin and personal settings
- The admin page left *Additional settings* and the personal page left *Personal info*; both now live in the app's own section. The admin page is split into blocks (status, OAuth clients, hidden files & tags, OCR, permissions, active connections), each a core settings section.
- Admin matrix: sticky header rows, modules in alternating bands, and one "All" menu per module (allow or deny one operation, or every operation of the module, for the users on the page) instead of the ✓/✕ buttons under each column.

#### Tool guide
- The obsolete `approval_id` gate is gone from the guide's list of extra gates.

### Security
- OAuth: turning the native client off now also invalidates its already-issued access tokens (checked on every request) and deletes its grants, codes and refresh history immediately.
- OAuth: every redirect back to a client (authorization code, `access_denied`, redirectable errors) now carries `iss` (RFC 9207) exactly once, built in one place; required by clients such as Gemini CLI. Disabling the native client also stops it from exchanging codes or refreshing tokens.
- Checkout downloads reject changed file ETags with HTTP 409 before spending the token or opening the content stream.
- CIMD metadata downloads use streaming, reject oversized Content-Length and read at most 64 KiB plus one byte before closing the response.
- Reusing a rotated refresh token revokes all access and refresh grants for its client and owner, including rotation races.
- OAuth authorization and MCP Bearer authentication require the mcp scope; offline_access alone grants no endpoint access.
- MCP always validates its own Bearer credentials and rejects requests whose session belongs to another account.
- Administrative service shutdown and eligibility removal immediately delete OAuth tokens and pending codes; disabling or deleting an account also revokes credentials.
- Hidden tags fail closed: when at least one hidden tag is configured, an error while resolving a folder chain or reading tags hides the item instead of showing it. The warning logged carries no file name or path.
- Backups inherit hidden tags before they receive content: the backup file is created empty, tagged, then filled and size-checked. If tagging fails, the user's write is aborted and only an empty backup remains, so nothing sensitive is left untagged and nothing is deleted.
- A write whose destination collides with a hidden item is refused with the same message as any other forbidden destination. Refusing at all can still tell that the name is taken; this residual risk is documented.

## 0.7.0

### Added
- Files: local editing for an agent with a shell. `files_checkout` returns two single-use links, a download valid for 5 minutes and an upload valid for 15, so the content never passes through the model; the raw upload, `files_replace` and `files_version_restore` work through them. The link is the credential: 32 bytes, bound to the user, the file id and the ETag, and only a keyed hash is stored. An ETag that diverged before the upload writes nothing.
- Files: folder reorganization. `files_tree` is a bounded scan, `files_mkdir` creates a folder and refuses an occupied target (behind `files.create`), `files_copy` and `files_move` move a file or a folder, `files_move_batch` plans first and records what it did, and `files_undo_batch` is the one place Files removes anything. A node outside the user's own folder also asks for `confirm_shared: true`.
- Files: node ownership is now known, and a write to a shared node (a wrapped share included) passes through the `confirm_shared` guard, the same one Deck and Calendar use.
- Files: the tool schema is validated as an array or an object, with `items`, properties and limits.
- Calendar: the five writes (`calendar_create_event`, `calendar_update_event`, `calendar_delete_event`, `calendar_move_event`, `calendar_transfer_event`) go through the official CalDAV pipeline, so invitations, sequences, sync-tokens and the trash behave like the web interface. Internal attendees are resolved to the account e-mail.
- Calendar: the five writes are available out of the box, with no terminal step. The admin matrix decides who gets them (all off by default); every write also needs `confirm: true`, the Nextcloud ACL and `confirm_shared` on someone else's calendar, plus the optional ETag. `occ mcp:calendar-selftest` stays only as an optional diagnostic that reports and changes nothing. The supported Nextcloud range is declared in `info.xml` (33); the internal CalDAV server is covered by the tests and that range, so a new Nextcloud version needs a new app version.
- Calendar: `send_invitations` follows the native `dav/sendInvitations` setting. The plan and the result report `imipEnabled` and never claim an e-mail was sent.
- Calendar: every write needs a plan and an explicit confirmation. Without `confirm: true` the tools return the plan (current versus proposed values, participants, invitation and trash consequences, optional ETag) and never touch DAV. Nothing is stored as an approval and no token is issued: grants, ACL, `confirm_shared` and the optional ETag all still apply on the confirmed call.
- Tools: every write of every module (Files, Notes, Deck, Calendar and Talk) returns a plan without `confirm: true` and changes nothing. The registry decides it from the tool's grant operation, publishes the same `confirm` argument on every writing tool, and runs the write only on the confirmed call, where grants, ACL, ETag and `confirm_shared` are checked again. Nothing is stored between the two calls.
- Files: images the model can see. `files_image_view` returns a reduced preview from the Nextcloud preview generator (1568 px by default, under 1 MB) as MCP image content, `files_images_view` returns up to 6 under a shared 4 MB budget, and `files_image_search` finds images by name, folder, modification date and user-visible system tag (the automatic tags of Recognize included), newest first. All three only read.
- Tool guide: `mcp_guide` describes every tool the user can call, with its parameters, limits and whether it needs `confirm` or `confirm_shared`, built from the same definitions `tools/list` serves. It is in English and tells the model to relay it in the user's language.
- Language: a per-user translation base (`UserL10n`), the tool titles in the user's language and the instructions in English. The language comes from the account preference.
- Language: Spanish (`es`) is included for the whole app, next to English and Brazilian Portuguese, as a first translation that still needs a native review.

### Changed
- Talk: a write without `confirm: true` returns the plan and sends nothing; with it, permissions are checked again and the message goes out. The single-use `approval_id` of 0.6.10 is gone, and nothing about an approval is stored on the server.
- Instructions: the server tells the model to read the tool guide before using a tool it does not know, to show the plan of every write and ask the user before repeating it with `confirm: true`, and never to invent an approval id.
- Tools: the descriptions are read by the model, so they stay English, while every message shown to the user comes out in their own language. Deck, Notes, Talk, Files, the shared messages, the checkout pages, Calendar and the selftest are all covered now.
- Calendar: every string the user sees lives in `CalendarMessages`, next to the schema of the Calendar tools.
- Package: the vendored sabre pins are exact and the vendor allowlist is part of the packaged app.

### Fixed
- Files: a diff has no phantom line and marks the end of the file when the file ends without a newline.
- Files: the upload takes raw bytes only, reads the token from the route and spends the link only at the moment of writing.
- Files: a link that was already spent answers 409 and asks for a new checkout, instead of suggesting the same link again.
- Files: the version restore refuses the backup folder, exactly as the edit already did.
- Files: `files_replace` writes over the raw bytes and the app never deletes a file.
- Files: an undo that stopped halfway can be retried.
- Files: the checkout routes resolve the controller and `sharedBy` resolves the UID of the share.
- Language: the `files_move_batch` confirmation and move-failure messages are translated, and Calendar selftest failures report `FAIL` like the rest of the report uses English statuses.
- Protocol: an invalid-argument error names the field in camelCase too.
- Language: the generic server error and the invalid-argument messages are translated, and the review findings F1-F5 of the two translation phases are fixed.

## 0.6.10

### Added
- Talk: every write (`talk_reply`, `talk_attach_file`, `talk_quote_file` and the new tools) first returns the exact draft. It is sent only after the user approves it, and an approval works once, for those exact arguments.
- Talk: `talk_message_user` (one-to-one) and `talk_send_batch` (several messages under one approval, each result reported per item).
- Talk: `talk_create_group`, behind a new `talk.create` grant that is off by default. An invitation that fails is reported per participant.
- Talk: `talk_reply` can link a Deck card or a calendar event. The user must be able to read it, and a private event of someone else is refused.

## 0.6.9

### Added
- Deck: `deck_followup_cards`, a follow-up of the boards the user owns or manages. It returns cards grouped by assignee, with due date, an `overdue` flag and a link to the card. Every Deck card payload now carries `assignedUsers`, `overdue` and `url`.
- Tools and admin matrix columns of an optional app (Notes, Calendar, Deck, Talk) are hidden while that app is disabled, for everyone or for a given user.

### Fixed
- Deck: due dates and `overdue` use the user's time zone. Near midnight, a card due today no longer shows as late or as due tomorrow for users west of UTC. A `YYYY-MM-DD` written by the tools is stored as local midnight.
- Deck: editing a card's title or description no longer resets the due time set in the Deck web interface.

## 0.6.8

### Fixed
- Admin matrix shows only the Calendar permissions that are actually enabled (read).

## 0.6.7

### Changed
- Calendar is temporarily read-only. Its write tools are hidden from `tools/list`, and direct calls fail closed until they are validated against a real Nextcloud 33 CalDAV setup. Grants already saved are kept.

## 0.6.6

### Added
- Deck and Calendar ask for confirmation before writing to a resource owned by someone else. The server refuses the first call, and the assistant must ask the user before repeating it with `confirm_shared: true`.

## 0.6.5

### Fixed
- Talk and Deck pass an `IUser`, not a UID, to the app availability check.

### Added
- Bilingual README (English and Brazilian Portuguese) and AGPL-3.0 license.

## 0.6.4

### Added
- Talk module: list conversations, read messages without marking them as read, reply (optionally quoting), share a file into a conversation, quote an already shared file.

## 0.6.3

### Added
- Friendly tool titles in the client.

### Changed
- Backup names use the user's local time.

## 0.6.2

### Added
- MCP `2026-07-28` (stateless, `server/discover`) alongside the classic `initialize` flow on the same endpoint.

## 0.6.1

### Fixed
- Accepts every known MCP protocol version and ignores the version header on `initialize`.

## 0.6.0

### Added
- Admin matrix of users × permissions, with search, group filter and a JSON API.

## 0.5.1

### Added
- Deck module: boards, stacks and cards, including create, edit, move and delete.
- Translated OAuth consent screen.

## 0.5.0

### Added
- OAuth sign-in for claude.ai and Claude Desktop: PKCE S256, CIMD client identity and rotating refresh tokens.

## 0.3.1

### Fixed
- The admin switch no longer uses a Nextcloud-reserved configuration key.

## 0.3.0

### Added
- Calendar module.

## 0.2.x

### Added
- Files and Notes modules. Text extraction from PDF, DOCX and ODT. Edits keep a verified backup in `/MCP backups`.
- Production-only package built by `mcp/scripts/package.sh`, with a checker for macOS metadata and missing assets.
