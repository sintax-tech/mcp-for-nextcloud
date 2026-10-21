<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Architecture

The application is a modular monolith inside Nextcloud. Capabilities own their
business operations; shared MCP, access, OAuth and platform components have
explicit responsibilities. This keeps a change local without requiring a new
interface, repository or DTO layer for every operation.

The app remains `mcp`, with PSR-4 namespace `OCA\Mcp`. Public tool names, routes,
confirmation behavior and native Nextcloud contracts are compatibility boundaries.
Internal PHP namespaces are implementation details and have changed; integrations
that import them need separate compatibility review.

## Repository layout

```text
mcp/
  appinfo/                 native manifest and routes
  lib/
    AppInfo/               explicit application composition
    Controller/            HTTP request/response adapters
    Mcp/                   protocol, discovery, execution and confirmation
    Access/                shared authorization, visibility and resource policy
    OAuth/                 OAuth flows, connections and revocation
    Platform/              named adapters for native facilities, including DAV
    Modules/
      Files/               reading, editing, search, shares, versions, batches
      Deck/                boards, stacks, cards and assignments
      Talk/                conversations, messages and participants
      Calendar/            event capabilities
      Contacts/            contact capabilities
      Tasks/               task capabilities
      Notes/               note capabilities
      People/              directory lookup
      Logs/                bounded log reading and search
    Db/                    persistence and native entities
    Migration/             registered database migration identities
    Listener/              native event adapters
    BackgroundJob/         registered background jobs
  src/                     authored JavaScript modules
  js/                      generated production entries (ignored)
  tests/                   unit, contract, architecture, browser and native tests
  scripts/                 build, analysis and documentation tooling
  build/                   ignored generated artifacts
  vendor/                  installed Composer dependencies (ignored)
docs/
  en/                      canonical operational and contributor documentation
  es/, ca/, pt-BR/, pt-PT/  translated operational documentation
  assets/                  shared documentation images
```

Nextcloud-facing classes retain their framework registration contracts. There is
no parallel legacy runtime tree, namespace alias layer or transition adapter.
The source checkout is the development artifact; the validated package is the
installation artifact. See [packaging](packaging.md) for its explicit allowlist.

## Capability ownership

Each module has an explicit catalog/factory and thin public tools under `Tools/`.
A tool owns its static definition and delegates work to the relevant capability.
For example, Files uses `Reading/` and `Editing/`; shared file mutation safeguards
live in `Editing/ContentWriter.php`. Read and write handlers can be tested without
constructing unrelated capabilities.

The catalog contains 74 tools: Files 23, Deck 13, Talk 8, Calendar 7, Contacts 6,
Tasks 7, Notes 7, People 1 and Logs 2. Catalog order, definitions, plans and result
rendering have compatibility fixtures. Do not regenerate fixtures simply to make
a structural refactor pass.

Use constructor injection for concrete dependencies. Extract an interface when
there is a real boundary or a substitutable consumer, not as a naming convention.
Avoid generic `Utils`, `Helpers` and service containers passed into handlers.
A folder should describe a capability, policy or native facility, not collect
unrelated code that has more than one caller.

See [adding a tool](adding-a-tool.md) for PHP syntax, registration, focused test
commands and the constraints specific to each module.

## Composition and dependency direction

[Application](../../../mcp/lib/AppInfo/Application.php) is the composition root.
It registers explicit factories and lazy optional-app access. Tool discovery must
not instantiate optional Deck or Talk services just to describe the catalog.
Missing or disabled optional applications are handled through availability policy.

- HTTP controllers and MCP request handlers adapt public input to application
  operations; capability classes do not depend on the HTTP or JSON-RPC transport.
- Modules consume MCP tool contracts and named access/platform facilities. They
  do not own the shared registry, execution pipeline or protocol dispatch.
- Cross-module reuse uses a narrow contract owned by the relevant capability or
  shared facility, not another module's tool handler or a broad service gateway.
- `Access/` owns shared decisions without importing concrete feature tools.
  `Platform/` owns native facilities, not arbitrary domain workflows.
- Shared DAV transport and collection access live under `Platform/Dav`; Calendar,
  Contacts and Tasks retain their own event/contact/task semantics. Calendar and
  Tasks resource restrictions remain independent even for the same collection.

Boundary tests enforce these directions and reject retired owners. Update the
actual dependency contract when a boundary changes; do not bypass the scanners.

## MCP contracts and write flow

`Mcp/Protocol` owns JSON-RPC routing and envelopes. `Mcp/Tools/Discovery` owns
availability and publication; `Execution` owns invocation; `Validation` and
`Results` own argument and result contracts. `Mcp/Guide`, `Resources` and `Prompts`
own their respective publications.

A readonly `ToolDefinition` records name, module, operation, description and input
schema, plus optional app, grant alternatives and destructive hint. Its `toArray()`
shape and ordering are part of the captured publication contract. A readonly
`ToolContext` contains the authenticated user ID supplied by the registry; tool
arguments never select the acting identity.

Write tools provide a side-effect-free preview and a separate execution operation.
`Mcp/Confirmation/WriteGate` enforces the shared approval protocol; handlers cannot
skip it by calling an unguarded public mutation path. Plans retain their state and
comparison semantics. Shared-collection approval is distinct from ordinary write
approval. Revalidate the native object, permission and version/ETag at the mutation
boundary rather than trusting an earlier preview.

## Access, policy and OAuth

Shared access has named owners for grants, groups, policy transactions and
resolution, receipts, events, inspection, resource scope, visibility, application
availability, Guests restrictions and log access. `Access/Guests` receives explicit
lazy service factories from `Application`; optional Guests services are never
resolved while Guests is disabled, and no container is injected into access policy. Unknown authorization never becomes an allow result.
Resource scope narrows access; it does not grant an operation or replace native
Nextcloud ACLs. File exposure and tag exclusion follow the
[exclusion and folder exposure contract](contracts/exclusion-and-folder-exposure.md).

Policy commands carry an expected revision and commit through the policy
transaction boundary. Durable events are recorded with the change; retries and
post-commit effects must preserve the already committed outcome. A failure in a
diagnostic logger must not turn that outcome into a claimed rollback. Group
lifecycle uncertainty and deferred credential cuts require fresh eligibility and
generation checks before destructive cleanup.

Undo uses a server receipt, expected revision and expiry. It must not overwrite
an intervening policy change or restore revoked sign-ins. OAuth connection and
revocation owners preserve client-specific isolation, credential generations,
PKCE and exact redirect matching. Keep authentication and authorization checks
at every public entry point, including direct resource and checkout paths.

Database migrations and job identities are deployment contracts. A file-only
downgrade cannot restore policy and credential state; use coordinated recovery as
described in [installation](../installation.md#upgrade-and-recovery).

## Browser ownership

Authored JavaScript lives in `mcp/src/`. Explicit page composition mounts scoped
owners and destroys their listeners and late-response effects. Two self-contained
classic-script bundles serve the administration and personal settings pages.
Nextcloud does not need Node.js at runtime.

Each policy editor owns its rendered snapshot and revision. Confirmed changes can
notify sibling owners through a page-local channel; that channel is not a global
state store. An unconfirmed write result triggers readback without automatic replay
or a success message. See [administration JavaScript](admin-javascript.md) for the
ownership map and transport rules.

## Verification and documentation

The unified `composer --working-dir=mcp check` gate validates documentation,
syntax, both static-analysis scopes, PHP tests, browser tests and the production
package. Native integration and private real-client qualification are separate
explicit commands. Unit doubles, rendered-template browser tests and native server
cells prove different contracts; none alone establishes all client compatibility.
See [testing](testing.md) for commands, failure rules and evidence limits.

Code, comments and contributor documentation use English. User-visible messages
use Nextcloud localization. English operational documentation is canonical;
translated pages carry source provenance and explicit human-review status. See
[documentation authoring](documentation.md) and [translation maintenance](translations.md).
