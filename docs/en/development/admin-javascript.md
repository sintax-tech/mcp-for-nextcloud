<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Administration JavaScript

Administration and personal settings each register one classic script through
`OCP\Util::addScript`. Authored code uses native JavaScript modules; esbuild produces
two self-contained IIFEs without runtime imports or chunks. Nextcloud does not
need Node.js to serve the packaged application.

```text
mcp/src/
  entries/                 admin.js, personal.js: root guards and host wiring
  platform/                Nextcloud host, HTTP, lifecycle and policy state
  settings/                connections.js: explicit admin/personal scope
  admin/
    bootstrap.js           page composition and explicit sibling callbacks
    tabs.js                navigation, history and keyboard focus
    picker.js              searchable selection, confirmation and rollback
    status-feedback.js     transient save feedback
    overview/              service state, checkout limit and endpoint copy
    clients/               OAuth clients, custom-client cards and change handling
    protection/            excluded tags
    logs/                  access groups and privacy
    groups/                group editor and directory
    scope/                 resource-scope controller and view
    inspection/            access-inspection controller and view
    access/                matrix, filters, levels, overrides, exceptions,
                           row actions, bulk planning and receipt-based undo
mcp/js/
  admin.js                 generated, ignored
  personal.js              generated, ignored
```

## Composition and lifecycle

Importing feature modules does not mount a page or read the Nextcloud host. Mounts
receive their root and dependencies, return `destroy()`, and expose `ready` when
they have an initial asynchronous load. The lifecycle owner removes listeners,
timers and late-response UI effects; it does not cancel writes accepted by the
server. Keep DOM and draft ownership inside each feature.

`bootstrap.js` creates the page-local policy channel, mounts the owners and wires
explicit callbacks. For example, the log-access picker receives
`reloadMatrix: matrix.reload`; connections receive an `onCounts` callback that
updates the overview only when the response contains an integer connection count.
Personal connections have no administration module dependency. The picker is
imported directly; there is no global picker facade.

## Revisions, uncertain saves and undo

`platform/policy-state.js` associates each section's revision with its rendered
snapshot. Read tickets reject late responses; invalidating a snapshot disables
writes until it is refreshed. The page-local channel announces only confirmed
adjacent revision changes. It must not rebase drafts onto revisions from error
responses or unrelated administrators' changes.

Preserve transport contracts: settings requests send JSON headers; connection
requests retain their own header behavior. Policy mutations explicitly opt into
`uncertainWrite` handling in `platform/http.js`. A successful HTTP status with an
unreadable JSON body is then an uncertain outcome, not confirmation that a write
succeeded or failed. The owning controller reloads saved state without replaying
the mutation or showing “Saved”/“Change undone”. A failed readback leaves the load
error and retry path visible. Do not apply that behavior indiscriminately to other
HTTP consumers.

Bulk permission changes submit an atomic command with `expectedRevision`. Undo
uses the returned server receipt and saved revision, subject to expiry and policy
validation. It does not reconstruct inverse writes in the browser or overwrite
another administrator's intervening change. Undo cannot restore revoked login
sessions. Group grants, resource scope and inheritance previews retain their own
snapshot and conflict rules; generic UI state must not bypass them.

## Build and verification

Use the pinned prerequisites in [setup](setup.md). Composer is the public task
entry point; npm owns frontend installation and implementation commands.

```sh
npm --prefix mcp ci
composer --working-dir=mcp assets:build
composer --working-dir=mcp test:js
composer --working-dir=mcp check
```

`test`, `test:js` and `package` always build fresh assets. `assets:build` rejects
missing inputs, import cycles, unreachable authored modules, non-app dependencies
and any administration graph in the personal entry. It records the input graph in
ignored `mcp/build/assets-metafile.json`. Never edit or commit generated bundles.

The package contains both generated entries and existing styles/translations,
plus their license notices. Sources, development dependencies, scripts, tests,
configuration and source maps stay outside the production archive. A source checkout
is the development artifact; the checked tarball is the deployment artifact.

Browser cases under `tests/JavaScript/` render real PHP templates and intercept
synthetic API requests. Bare-template mounting is a test isolation aid, not an
alternative production loader. These tests exercise conflict recovery, uncertain
saves, lifecycle and interaction across Chromium, Firefox and WebKit; they do not
certify real-host CSP, authentication, theme/layout, caches or human accessibility.
See [testing](testing.md) for the separate validation layers.
