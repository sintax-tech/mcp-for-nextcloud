<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Files preview: lazy native roots and a single native-miss fallback

The user's explicit choice on 2026-10-29 was **“Restringir no preview”**.
A preview runs the existing plan and all its admission checks using the actor's
lazy canonical home root. Only `OCP\Files\NotFoundException`, caught before
`NodeAccess::run` maps native errors, permits explicit full actor mount setup and
one repetition of that plan. The second miss propagates to the normal mapper.
`ToolFailure` (including hidden/scope not-found refusals), native ACL refusals and
other errors never permit that fallback. No confirmed operation or sink is retried.
Confirmed `FilesModule::call()` retains its existing full actor setup.

The native exception must **reach the adapter**. `FileCreation::folder()` catches
its native miss internally and maps it to `ToolFailure`; destination planners
that use `nodeExists()` can receive `false` instead of an exception. Those paths
keep their lazy view and do not trigger a retry. No `previous`-exception option
or `ToolFailure` fallback is implemented.

Consequently, with warm setup and a received share not yet mounted, a preview
of `files_create`/`files_upload` can refuse a folder that the full setup of the
confirmed call would resolve. A `files_mkdir`/copy/move planner can instead
predict parents, collisions or shared impact from its lazy view differently
from `call()`. This does not authorize an unsafe write: the confirmed call
performs its own checks again, including `confirm_shared`, and can refuse it.
These are conclusions from the source paths, not runtime reproductions of
these particular divergences. Preview does not promise unrestricted parity
with the confirmed call.

The real bound session is checked before and after lazy root lookup, and by
ActorRoot before and after full setup when fallback is necessary. These checks
do not undo earlier native hook effects, prove atomic identity/revocation or
observe transient changes that have already reverted.

The plan does not create a tool backup, folder or checkout token. That is not a
promise of a platform request without effects: native lazy lookup can already
complete mount setup with cold state; a genuinely missing path may invoke the
explicit fallback. Native providers/hooks may normalize an unverified received
share target and create its configured receiving folder. The change reduces
forced setup during preview and refusals; it cannot eliminate native bootstrap
effects. It does not change grants, scope, ACL, Guests, aliases or visibility.

PreviewRootTest distinguishes native miss/repeated miss, policy and ACL refusal,
legacy construction and session changes. The integrated mount HTTP test checks
exact bytes and path/size/mime/etag, then checks identity in a separate unconfirmed
delete preview. That later ID is only subsequent coherence, not proof of the
identity used by the first read lookup, and is not a preparatory warmup.
