<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Adding a module or tool

Use the capability-module contract for new work. Start with the existing module when adding a tool to an app already supported. Read [Architecture](architecture.md) before selecting dependency boundaries.

Use the linked catalog modules as examples; keep dispatch in the shared catalog base and business logic in focused work classes.

## Before adding a tool

Define the user operation, its grant, optional native app, inputs, return shape and refusal cases first. A new feature needs explicit scope; a restructuring ticket must preserve the existing behavior. Published MCP tool names and persisted names must not be renamed as an incidental cleanup.

Check [`GrantCatalog`](../../mcp/lib/Access/Grants/GrantCatalog.php) for grant operations, [`GrantMatrix`](../../mcp/lib/Access/Grants/GrantMatrix.php) for module-to-native-app mapping, [`Application::MODULES`](../../mcp/lib/AppInfo/Application.php) and [`ToolDefinition`](../../mcp/lib/Mcp/Tools/ToolDefinition.php) instead of inventing an operation or registration API. A new grant or persisted key needs its own compatibility review and contract evidence.

## Module folder and catalog

Create `mcp/lib/Modules/<App>/` with an `<App>Module` extending `CatalogModule`. Keep the module limited to delegation. `<App>ToolCatalog` is final, has a private constructor and publishes an ordered `public const TOOLS` containing the tool classes. The module's static `catalog()` returns that constant. Register a new module in the explicit ordered `Application::MODULES` list; append new tools to their existing catalog order. There is no second `Application::CATALOG` list to update. `GrantCatalog::CATALOG` is separate: it declares permission operations, not tool or module registration.

Use [`NotesModule`](../../mcp/lib/Modules/Notes/NotesModule.php) and [`NotesToolCatalog`](../../mcp/lib/Modules/Notes/NotesToolCatalog.php) for the module/catalog shape. [`FilesModule`](../../mcp/lib/Modules/Files/FilesModule.php) shows a module without its own constructor. Inherit the base's call/preview dispatch and errors; do not override them with a second dispatcher. A catalog containing only read tools still uses the same base, which rejects previewing a read tool.

Put public tool adapters in `Tools/`. Put focused work classes in folders naming the work: `Reading/`, `Creation/`, `Editing/`, `Moving/`, `Deletion/` or another concrete domain responsibility. Shared work can use a specific folder such as `Locating/` or `Writing/`; a single shared class can live at the module root. Avoid catch-all `Services/`, `Helpers/` or `Utils/` directories.

## Static definitions and schema

`Tool::definition()` is static and returns `ToolDefinition`. It must not resolve a container, authenticate a user or instantiate a native app. Use `Presentation/<App>Schema` to supply the module/app defaults, repeated properties and closed input schema (`additionalProperties: false`); empty properties must encode as an object. [`NotesSchema`](../../mcp/lib/Modules/Notes/Presentation/NotesSchema.php) is a concrete example.

Define the existing grant operation and optional `app`, `grantAnyOf`, `destructiveHint` and `requires` metadata where applicable. The registry derives titles and annotations; tools do not declare their own competing presentation policy. It also adds the confirmation argument to writes. Do not make `confirm` required or set it to `const: true`: the unconfirmed invocation must remain a preview.

For a working definition and its delegation, inspect [`CreateNote`](../../mcp/lib/Modules/Notes/Tools/CreateNote.php) or [`CreateBoard`](../../mcp/lib/Modules/Deck/Tools/CreateBoard.php). Reuse the module's schema builders rather than reproducing a large inline schema in a new tool.

## Work classes and write previews

A read adapter has exactly one object dependency. Its `execute()` calls that class. A write adapter implements `WriteTool` and has exactly two object dependencies of distinct classes: the planner and executor. Its `preview()` calls only the planner; its `execute()` calls only the executor. Each method delegates to one work class, optionally using the shared result/error wrapper.

Use `<Verb><Object>Handler` for new execution work and `<Verb><Object>Preview` for the plan. Keep root/user lookup and business logic in those classes, with narrow constructor dependencies. Do not put a hidden second dispatcher, deferred callback that is never called, or business logic into the thin tool to satisfy a superficial shape check.

The plan reads enough state to explain the proposed change but does not perform it or mint its write artifacts. Preserve each workflow's order of scope, native access, lock, backup and shared-write checks. Reapply write admission on confirmation; a plan is not a reusable permission grant. The Files lazy-root limitations in [Architecture](architecture.md#access-confirmation-and-native-integrations) still apply.

New and rewritten PHP uses tabs, `strict_types`, the project's SPDX header and complete class/method PHPDoc. Keep files focused and within the source-size gate. Extract focused work instead of growing a retained large file.

## Registration, ports and capabilities

Autowire ordinary concrete tools and work classes. Add `<App>Registration` only when a port binding, special construction or capability probe is needed. Its `register()` supplies those bindings; `probes()` returns the probe classes, or an empty list when it has none. Otherwise leave `registration()` returning `null`; an empty registration is rejected.

When a shared layer needs module behavior, declare the interface in the consuming shared layer and bind its implementation from the module registration. [`FilesRegistration`](../../mcp/lib/Modules/Files/FilesRegistration.php) binds `Mcp\Resources\FileTextResource`; [`NotesRegistration`](../../mcp/lib/Modules/Notes/NotesRegistration.php) binds the Notes resource port. Missing ports must fail construction instead of receiving a generic permissive implementation.

Native app PHP namespaces belong only under the owning module's `Integration/`. Resolve optional native services lazily. For installation-wide optional behavior, list `<module>.<capability>` keys in `ToolDefinition::requires`; implement a `CapabilityProbe` in `Integration/` and register its class through `probes()`. [`DeckCapabilities`](../../mcp/lib/Modules/Deck/Integration/DeckCapabilities.php) is the existing example. A capability probe is not a user grant or an alternative to Guests/app checks.

## Grants and presentation

Declare the module's access levels in `mcp/src/admin/access/levels/<app>.js` ([level definitions](../../mcp/src/admin/access/levels/)). The [`src/admin.manifest`](../../mcp/src/admin.manifest) includes them in the existing `admin` bundle; do not add a standalone script or a new `addScript` call. Keep the permission matrix layout and its None/Read/Edit/Full presentation unchanged.

The registry checks capabilities, grants, Guests restrictions and optional app enablement. If `grantAnyOf` allows entry under more than one operation, the work class must check the alternative actually requested before both planning and writing. An unavailable policy or optional service must not silently allow access.

Put descriptions, parameter text, workflow notes and plan rendering in the module's `Presentation/` classes. The module delegates guide notes and optional rendering. Source text is English; user-facing additions need the repository's supported translations in the same release. Use shared errors and response envelopes rather than introducing inconsistent refusal behavior.

## Tests and release documentation

Keep migrated tests in their existing directories. Retarget old constructor/reflection fixtures to the real collaborators with the same doubles and assertions; use an explicit container helper when a catalog module resolves its tools lazily. New module tests may live in `tests/Unit/Modules/<App>/`; no mirror-directory migration is required.

Use the existing golden, effect, access/refusal, architecture and DI checks, plus the relevant real native-app integration coverage. Exercise optional apps and required capabilities present and absent. Structural token checks do not prove a callback runs: review the flow and retain functional evidence. Add tests only for behavior or contracts that require them, not to repeat the implementation.

Follow [Testing](testing.md) for commands and evidence limits. Update the CHANGELOG and applicable READMEs for accepted visible changes; coordinate translated store descriptions and final release notes with release preparation. Packaging is a separate check and publishing is a separately authorized action; see [Packaging](packaging.md).
