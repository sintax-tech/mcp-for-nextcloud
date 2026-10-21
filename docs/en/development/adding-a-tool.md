<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Adding a tool

Files, Deck, Talk, Calendar, Contacts, Tasks, Notes, People and Logs are implemented examples
of capability-based authoring. Their 74 tools use explicit catalogs and focused
capabilities. Shared MCP infrastructure now has final owners; administration remains
a separate scope.

## Find the owner

```text
mcp/lib/
├── AppInfo/Application.php          # explicit composition and lazy factories
├── Mcp/Tools/                      # contracts, registry, Discovery, Execution, Validation, Results
├── Mcp/Confirmation/               # write gate, stateless plans and rendering
├── Mcp/Guide/                      # guide navigation and schema rendering
├── Mcp/Protocol/                   # protocol entry point and request handlers
├── Access/                         # Grants, Visibility, AppAvailability, Logs
├── Modules/Files/
│   ├── FilesModule.php             # metadata and dispatch only
│   ├── FilesToolCatalog.php        # ordered tool classes
│   ├── Tools/                      # one schema and entry point per operation
│   ├── Contracts/                  # FileResourceReader
│   ├── Reading/                    # text extraction, listing, tree, resources
│   ├── Search/                     # content/name search and query operators
│   ├── Editing/                    # edit, replace, shared commit, backup, diff
│   ├── Versions/                   # version reads and protected restoration
│   ├── Checkout/Http/              # temporary request-body ownership
│   ├── Creation/                   # inline and uploaded new files
│   ├── Images/                     # selection, rendering, metadata, search
│   ├── Sharing/                    # recipient, grants, plans, apply, removal
│   ├── Reorganization/             # folders, copy/move, batches and undo
│   └── Presentation/               # guide, renderer dispatch, plan formatting
└── Platform/FileAccess/            # Nodes, Paths, Locks, Sharing and backups
```

Checkout also owns token policy, token persistence and binary writes. The HTTP
adapter remains in `Controller/CheckoutController.php`, as required by the framework.
ResourceRegistry depends on `FileResourceReader`, not Files tool handlers.

Keep the schema and entry points together. Put resource orchestration in a named
capability when it warrants reuse or focused testing. Avoid `helpers`, `utils` or
a replacement module-sized service. Import shared contracts from `Mcp/Tools`,
confirmation from `Mcp/Confirmation`, policy from `Access`, and named native
facilities from `Platform`. Feature tools must not import ToolRegistry, ToolExecutor
or protocol handlers. Application alone assembles that chain.

## Define metadata and behavior

[`ReadFile`](../../../mcp/lib/Modules/Files/Tools/ReadFile.php) has one constructor
dependency, `FileReader`. Its metadata is static and its context carries only the
authenticated user ID:

```php
public static function definition(): ToolDefinition {
    return new ToolDefinition(
        name: 'files_read',
        module: 'files',
        operation: 'read',
        description: ReadingMessages::readTool(),
        inputSchema: [
            'type' => 'object',
            'properties' => ['path' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => ReadingMessages::path(),
            ]],
            'additionalProperties' => false,
            'required' => ['path'],
        ],
    );
}

public function execute(array $arguments, ToolContext $context): array {
    return $this->reader->readText($context->userId, $arguments['path'])->toToolResult();
}
```

The registry validates arguments before dispatch. Capabilities still normalize
paths and enforce resource permissions and visibility. Never take identity from
tool arguments or put services in `ToolContext`. Preserve response shape:
`files_read` returns extracted text then metadata, images return image/text blocks,
and `files_share` returns text plus structured content with its one-time password.

`ToolDefinition` also supports optional `app`, `grantAnyOf` and `destructiveHint`.
Keep public metadata intact when migrating an existing operation. Discovery must
not construct services, load optional applications or cache localized definitions.

## Define a write

[`EditFile`](../../../mcp/lib/Modules/Files/Tools/EditFile.php) implements
`WriteTool` and maps validated arguments to a typed request in both phases:

```php
public function preview(array $arguments, ToolContext $context): array {
    return $this->editor->preview(EditRequest::fromArray($arguments), $context->userId);
}

public function execute(array $arguments, ToolContext $context): array {
    return $this->editor->execute(EditRequest::fromArray($arguments), $context->userId);
}
```

Preview returns a domain plan and has no write side effects. The shared write gate
owns global `confirm: true`; `confirm_shared` is a separate resource decision.
Sharing uses its existing `plan_state` protocol and recipient-specific grants.
Do not replace either protocol with the other.

Reuse `ContentWriter` for verified text backup/write/receipt orchestration.
Retain phase-specific checks: edit preview checks ETags before shared notices and
lock warnings; execution refuses locks and missing shared confirmation before
backup/ETag checks. Versioning is required on execution. An omitted edit ETag
remains valid. Mutation checks do not promise an atomic transaction against
concurrent permission, tag, ETag or lock changes.

## Register once

1. Add the handler and its focused capability/tests if needed.
2. Add its class to the ordered `FilesToolCatalog::classes()` list.
3. Add its named lazy factory to `Application`'s Files registration:

   ```php
   'files_read' => static fn () => $c->get(ReadFile::class),
   ```

4. For a write, extend its capability renderer and the presentation dispatcher.
   For a new public name, supply the existing shared title/grant/presentation
   metadata; a structural migration preserves it.
5. Run public dispatch and contract tests.

The module constructor checks that factory keys equal catalog names without
calling a factory. `call` and `preview` resolve exactly the selected tool. A wrong
name or missing write capability fails on resolution. There is one Files entry in
`Application::MODULES`; no FilesModule dispatch edit or parallel controller is
needed for an ordinary tool. Capture the container only in composition. Lazy
optional version/image providers are closures passed to capabilities by Application.

## Test at the right boundary

Tests live under `tests/Unit/Modules/Files`, grouped by capability. Fixtures live
under `tests/Support/Files`. `FilesReadFixture` builds file access, visibility and
extraction only; `FilesEditFixture` adds write collaborators. Neither builds the
full module. Shared fake trees and locks live under `tests/Support/Platform/FileAccess`,
users under `tests/Support/Identity`, and shared MCP fixtures under `tests/Support/Mcp`.

`FilesFactories::complete()` supports a focused module test: supply only the
handlers exercised; selecting any other handler fails explicitly. The broader
`FilesToolsTestCase` remains a public compatibility fixture for cross-capability
scenarios, not a production service locator or a requirement for direct tests.

From `mcp/`, with dependencies installed:

```sh
composer test -- tests/Unit/Modules/Files/Reading/ReadFileTest.php
composer test -- --filter 'EditFileTest|TextFileEditorTest'
composer test -- --filter 'FilesDispatchTest|FilesMigrationCompatibilityTest|FilesBoundaryTest|ApplicationRegistrationTest'
```

Reuse existing refusal/recovery and protocol tests; add tests for new failure
boundaries instead of duplicating every capability case. Architecture checks scan
final owners and retired references, including service strings and test support.
No-deletion checks recursively cover Files, the HTTP adapter and shared backup
primitives. Only `BatchUndo::undoBatch` can remove its own empty created folder.

Run the complete gate from the repository root with `composer --working-dir=mcp check`
(or `composer check` from `mcp/`). External task catalogs should delegate to this
command. See [testing](testing.md) and [static analysis](static-analysis.md) for
the command scopes and host model.

## Deck tools and capabilities

Deck uses the same `Tool` / `WriteTool` contracts and explicit catalog as Files.
Final owners live in `lib/Modules/Deck`; tests mirror capabilities under
`tests/Unit/Modules/Deck`, with fixtures in `tests/Support/Deck`.

A read entry point receives one typed query closure bound to a capability method,
plus `ResultBoundary` and `DeckFormatterFactory`. Bind it explicitly in Application:

```php
new ReadCard(
    $c->get(CardReader::class)->findCard(...),
    $c->get(ResultBoundary::class),
    $c->get(DeckFormatterFactory::class),
);
```

A write entry point receives its operation, plan builder and error boundaries:

```php
new EditCard(
    $c->get(CardEditing::class),
    $c->get(EditCardPlan::class),
    $c->get(ResultBoundary::class),
    $c->get(PreviewBoundary::class),
);
```

The write tool delegates `execute` with `ToolContext::userId` and wraps the raw
operation payload once. `preview` delegates to the side-effect-free plan builder.
Add the real tool class to `DeckToolCatalog::classes()` and its named lazy factory
to Application's `DeckModule` registration. The module verifies exact factory
keys, selected-tool identity and the write interface. `DeckSchema::definitionArray`
preserves Deck's metadata order. Guide text belongs to `DeckGuide`; rendered plans
belong to the card/structure renderers.

Native Deck services enter adapters through explicit provider closures. `DeckCaller`
requires the authenticated session before resolving BoardService. The controller
already binds Bearer identity. Do not create a sessionless caller or reuse native
service graphs across different users. Formatters are selected per invocation for
the current user's timezone; messages resolve the current translation context.

Reuse `VerifiedWrite` to distinguish saved, probable and unconfirmed results.
A probable parent must receive no child writes. Retain partial assignment and
blueprint results. Empty-structure deletion uses its authorized recount closure
through soft-delete recovery; repeating ordinary access checks on the deleted
parent can prevent restoration. That closure stays inside the operation.

Test operations with narrow capability mocks. `DeckDoubles` is a typed fixture
collection, not an operation facade. `DeckToolFixtures` composes real entry points;
`DeckNativeFixtures` creates explicit native-provider test scopes. None enters the
production package. Supported native API fallbacks remain permanent behavior.

```sh
composer test -- tests/Unit/Modules/Deck
composer test -- tests/Architecture/DeckBoundaryTest.php tests/Unit/Contract/DeckMigrationCompatibilityTest.php tests/Unit/ApplicationRegistrationTest.php
```

## Talk tools and capabilities

Talk's eight tools live under `lib/Modules/Talk/Tools`. Each owns its definition and
implements `Tool` or `WriteTool`; it delegates to one capability and the appropriate
result/preview boundaries. Schemas use limits owned by the corresponding capability.
Add the class to `TalkToolCatalog::classes()` in the intended public order and add
its named lazy factory to `Application`'s `TalkModule` registration. Definitions,
`tools/list`, generated guides and plan rendering must work without native Talk.

`TalkModule` validates factory coverage and tool identity. It rejects unknown names
and previews of reads before resolving a factory. Tool failures are mapped once;
factory-resolution failures use the same boundaries without wrapping a successful
tool result twice. The registry still owns app grants and `confirm: true` handling.
There is no stored approval, token, preview prerequisite or automatic retry.

Inject only the native provider closures a capability needs. `TalkServiceFactory`
receives one fixed class name and a no-argument resolution closure at application
composition. It rechecks the actual user, app version/enablement and class presence
on every call. `TalkConstants` owns native constants. Capabilities do not receive a
container or a general Talk service gateway. `MessageSender` sends text;
`ReplyTargetReader` reads parents; `QuotedMessage` describes them for previews.
Attachment sharing and system-message publication have separate owners.

Keep resource checks in their capabilities, including mandatory production
`VisibilityGuard` injection for file/share access. Talk may use only the public
Deck card and Calendar event reference contracts. Calendar's reference reader
owns its active access/store/parser/classification/mapper dependencies.

Plans belong to their operations; the message, attachment and group renderers
consume plan data without lookups. Concern-specific message classes preserve
translation IDs. Group invitation, batch and caption failures retain the product's
partial outcomes: never add rollback or retry as a structural cleanup.

Tests mirror capabilities under `tests/Unit/Modules/Talk`. `TalkNativeFixtures`
contains independent provider doubles; `TalkModuleFixtures` composes real workflows
over explicit test leaves. Neither is shipped. Preserve the frozen metadata/guide
contract and the shared plan, visibility, registry and authentication contracts.

```sh
cd mcp
composer test -- tests/Unit/Modules/Talk tests/Unit/Modules/Calendar/References
composer test -- tests/Architecture/TalkBoundaryTest.php tests/Unit/Contract/TalkMigrationCompatibilityTest.php tests/Unit/ApplicationRegistrationTest.php
```

## Language and documentation

Write code, identifiers, comments, PHPDoc and technical documentation in English.
Use the capability's message class for localized user-facing text. Preserve
meaningful multilingual fixtures, accents and byte-encoding cases.

English documentation lives under `docs/en/`. Translations mirror relative paths
and English filenames under their language directory. Link only existing pages;
use explicit English fallback links. Record the English source path and actual
revision with `translation-source` and `translation-source-revision` comments as
specified in the [translation policy](translations.md).
No placeholder translations or documentation website are required.

The production archive includes the explicitly selected operational guides and
README translations, with links transformed for offline use. Development guides
are excluded. See [packaging](packaging.md) for the distribution contract; translated
pages remain drafts until human review.

## Calendar, Contacts, Tasks and DAV

Start at `Modules/<feature>/Tools/<Operation>.php`: the static `ToolDefinition` and
Tool/WriteTool entry points are the only publication owner. Add the class to that
module's explicit catalog and its lazy factory map in `AppInfo/Application.php`.
`Mcp/Tools/DavCollectionSchema` supplies field fragments, never complete definitions.
Keep the catalog's ordered projection so raw/public metadata remains compatible.

Put Calendar workflows in Reading, Creation, Editing, Relocation or Deletion.
Events owns iCalendar content and event policy; Scheduling owns invitations and
preview warnings. `Execution/EventApproval` prepares current state and collects
shared notices. Its event-only preparation port is also used by the CLI Selftest
runner. Public tools never request administrative or ACL-probe ports. Talk imports
only Calendar's event-reference contracts.

Contacts owns AddressBooks, Directory, Search, Reading, Creation, Editing, Deletion
and Cards. Directory lookup, enumeration visibility and public-field mapping have
separate owners. Deletion verifies its Files backup and rechecks ownership and ETag
before native DELETE. Tasks owns Reading, Creation, Editing, Completion, Deletion
and Content; document mapping, privacy/complexity, patching, dates and categories
have separate classes. Do not recreate a TaskData facade. Contacts/Tasks entry tools
preserve their direct unconfirmed-call plan behavior as well as registry WriteGate.

Shared `Platform/Dav` provides collection/object read ports, native CalDAV/CardDAV
writers, session/response mechanics, retention checks and ISO dates. Event-range,
VTODO and contact queries stay with feature Integration adapters. Lazy providers
are wired at composition; no capability receives a container. All mutations go
through native DAV, and preview/rendering never writes or resolves a native server.

Keep pure renderers under Presentation, split by save/change, relocation and delete
families. A saved plan contains its own user-dependent values (including timezone);
do not retain request identity or locale in a shared module.

Run the changed feature suite and `tests/Unit/Platform/Dav` independently, then the
DAV compatibility/catalog, application-registration and architecture tests. The
[frozen contracts](../../../mcp/tests/Support/Dav/Compatibility/README.md) cover
all 20 definitions and 12 write plans. Finish with the complete Composer `check`
command. Run the separate [native integration matrix](../../../mcp/tests/Integration/README.md)
when changing host contracts; unit doubles do not establish real DAV behavior.

## Notes tools and capabilities

All seven tools live under `lib/Modules/Notes/Tools`. `NotesToolCatalog` owns order
and validates write contracts; `NotesModule` publishes metadata, dispatches through
seven explicit lazy factories and renders saved plans without resolving tools.
Keep Notes' raw metadata key order and empty object/array schema shapes intact.

A tool delegates to `Reading`, `Search`, `Creation`, `Editing`, `Moving` or
`Deletion`. The workflow owns root resolution and the ordering of validation,
shared confirmation, locks and mutation. `Storage` owns folder navigation, scoped
lookup, metadata and naming; `Content` owns byte limits and read normalization.
`Execution` contains narrow write access, snapshot and plan notice helpers. Keep
operation-specific decisions in the workflow instead of expanding these helpers
into a second module. `Presentation` only renders already-prepared plans.

Public requests still pass through `ToolRegistry` and `WriteGate`. Notes direct
module writes execute immediately; do not add a second general confirmation gate.
Shared-write confirmation remains independent. Edit may save content before a
rename fails: preserve partial-success text, lock data and exception boundaries.
Previews must never create categories or mutate content. Re-read current state on
confirmed execution; a saved preview is presentation data, not an executable plan.

The resource registry depends only on `Contracts/NoteResourceReader`.
`Resources/NextcloudNoteResourceReader` uses its own unfiltered lookup followed by
mandatory visibility assertion before reading bytes; tools use filtered lookup.
Application is the only external production consumer of Notes implementation
classes. Do not add Notes app service dependencies or delegate through Files tools.

Tests mirror ownership under `tests/Unit/Modules/Notes`. `NotesTestFactory` in
`tests/Support/Notes` executes actual Application registration closures with
explicit host doubles. Immutable compatibility fixtures preserve definitions,
plans, results and English/Portuguese rendering from the pre-extraction commit.
Do not regenerate them to accommodate a refactor.

## People: a single read capability

`Modules/People` contains four production classes. `Tools/SearchUsers` owns static
metadata and the MCP JSON envelope; `Search/PeopleSearch` owns argument coercion,
native sharing search, safe native-failure mapping and result projection.
`PeopleToolCatalog` preserves metadata order and validates read-only entries;
`PeopleModule` owns guide notes and lazy dispatch. Definitions and guides do not
resolve the feature's ISearch/logger dependencies.

This feature needs no repository, gateway or cross-module contract: OCP's ISearch
already provides the native boundary. Keep matches/account projection private to
PeopleSearch and its single translated failure literal at the point of use. Add a
separate mapper/message owner only when a real new responsibility warrants it.

ISearch uses the authenticated host session, not ToolContext's UID. Do not add
impersonation, account enumeration, independent group filtering or result caching.
The narrow native-call catch logs only the exception class; projection and logger
failures retain their existing boundary. ToolResult owns encoding, including invalid
UTF-8 substitution. Preserve typed deduplication and exact-before-partial ordering.

Tests live in `tests/Unit/Modules/People`; frozen contracts live under
`tests/Support/People/Compatibility`. Global publication, write-gate and visibility
builders all traverse Application modules, so constructor changes must update all
three.


## Logs: restricted reads with mandatory audit

`Modules/Logs` separates `Listing/ListEntries` and `Analysis/AnalyzeEntries` behind
`Tools/ListLogs` and `Tools/AnalyzeLogs`. `Reading/LogQuery` validates arguments and
uses the native reader/scanner once; `LogWindow` carries raw entries only inside
Logs, alongside the privacy state of that query. Listing redacts entries before
presentation; analysis uses the same window and a fresh time-settings lookup.
`Presentation/LogEnvelope` owns public scan/source metadata and the untrusted-data
warning. Keep the focused parsing, filtering, analysis and redaction algorithms in
their existing owners; do not add a generic logging framework.

`LogsModule` implements `RestrictedModule` as well as the normal publication/guide
contracts. Application provides three independent lazy routes: an exact tool factory
map, a `LogsAccess` role factory and a `LogAudit` factory. Static definitions/guide
resolve none. Registry discovery can check role eligibility without constructing
the query pipeline. Denied or schema-invalid calls can audit without constructing
a tool. Add new tools to the catalog and explicit factory map together.

Public calls must continue through ToolRegistry: role eligibility precedes the
read grant, which defaults to denied even for administrators. The registry owns
audit outcomes; tools must not audit again. `Audit/LogAudit` bounds known filters,
never records searched text, and preserves native event/logger ordering. A failed
success audit withholds the data. Preserve original refusal and safe-error boundaries.

The sole native log interface is `ILogFactory::get('file')` followed by
`IFileBased::getEntries(limit, offset)`. Never open paths or inspect rotated logs.
Settings are read afresh under the existing shared-service lifecycle. Keep
`LogsAccess`, `LogFileNames`, `VisibilityGuard` and `GrantPolicy` as explicit shared
dependencies; Application is the sole outside production importer of Logs internals.

Tests mirror capabilities under `tests/Unit/Modules/Logs`; synthetic log support and
immutable pre-extraction fixtures live under `tests/Support/Logs`. Real Application
closure tests cover access, privacy changes and audit failure. The boundary scanner
rejects retired routes and containers.

## Shared MCP ownership

The controller authenticates and supplies the UID. `Mcp/Protocol/McpProtocol`
validates the envelope and protocol era, then dispatches through `ToolRequests`,
`PromptRequests` or `ResourceRequests`. `ProtocolMetadata` is the only owner of
protocol constants; `JsonRpcResponse` owns envelopes and safe validation messages.

`ToolRegistry` preserves catalog order and first-match lookup. `ToolAvailability`
rechecks role, grant and optional-app gates for each request. `ToolPublication`
projects titles, annotations and confirmation schemas. `ToolExecutor` validates,
previews or executes, maps failures and audits restricted modules. Never cache
availability across calls, move role checks behind validation, or return successful
restricted data after failed audit. Preview and changed-plan branches retain their
existing return-before-audit behavior.

Keep the same `ToolAvailability` in registry and executor, and the same
`VisibilityGuard` across file/note resources, tools, checkout and Logs privacy.
`ToolRegistry::guide()` supplies one guide to both tool and resource publication.
Keep factories lazy; discovery must not resolve native optional-app collaborators.
Use `Platform/Logging/ExceptionSummary` for redacted exception diagnostics, never
messages containing server state. Both protocol eras are supported product behavior.

Shared tests mirror final owners under `tests/Unit/Mcp`, `tests/Unit/Access` and
`tests/Unit/Platform`. Run the architecture scanners, feature compatibility captures,
shared contract tests, full suite and production package checks after changing these
boundaries. Native-host and real-client acceptance are separate from these local
checks; record the artifact and limits of each validation run.
