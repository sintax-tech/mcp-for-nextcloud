<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Architecture

MCP for Cloud is a native Nextcloud app. The MCP endpoint exposes Nextcloud operations through tools and resources, using the authenticated account and the app's access policy. Nextcloud remains responsible for the underlying application data and native permissions.

**1.3.0 development status:** this guide describes the capability-module structure being assembled for the release. Before the release, recheck the Files deletion, reorganization and checkout paths after their remaining splits, and verify the removal of legacy classes. The examples linked below already exist; this page does not certify that every restructuring step has finished.

## Repository and runtime layout

The repository root holds user READMEs, the CHANGELOG, legal documents and these development guides. The installable app is under `mcp/`; paths below are relative to that directory.

- `lib/Modules/<App>/` owns each capability module: catalog, tools, work classes, presentation and native integrations.
- `lib/Mcp/` owns protocol dispatch, tool contracts and discovery, resources, confirmation and guide presentation.
- `lib/Access/` owns grants, policy snapshots, eligibility, Guests restrictions, visibility and resource scopes.
- `lib/OAuth/` owns authorization, clients, tokens and connection management. Its `Storage/` boundary is shared with access policy where required.
- `lib/Platform/` supplies lower-level ports and compatibility, file-access, backup and time primitives.
- `lib/AppInfo/`, `Controller/`, `Db/`, `Migration/`, `Listener/`, `Middleware/` and `Settings/` retain Nextcloud bootstrap, HTTP, persistence and lifecycle responsibilities.
- `templates/`, `src/`, `js/`, `css/`, `img/` and `l10n/` hold UI templates, authored assets, committed bundles, styles, images and translations.

This is an ownership map, not a rule that every directory must be nested in a module. Production dependencies are installed into the packaged `vendor/`; development dependencies, tests and these guides stay in the repository.

## Dependency boundaries

A module can use `Mcp`, `Access` and `Platform`; those layers must not depend on a concrete module to obtain application-specific behavior. A lower layer declares a port when it needs behavior supplied by a module. For example, [`FileTextResource`](../../mcp/lib/Mcp/Resources/FileTextResource.php) belongs to `Mcp`, and the Files registration binds its implementation.

Within the shared layers, `Platform` is the lower boundary; `OAuth/Storage` can be used by `Access`, and the rest of `OAuth` can use `Access`. This storage exception does not permit a reverse dependency on the OAuth server. Constructor and registration graphs must remain acyclic.

A module names another native app's PHP API only inside its own `Integration/` boundary. An app ID in an availability check is distinct from a PHP namespace dependency. Prefer a lower-layer interface to direct module-to-module coupling. The executable rules and any explicitly temporary exceptions live in [`tests/Architecture`](../../mcp/tests/Architecture), including `boundary-exceptions.json`; a temporary exception has an owner and an expiry, not an open-ended waiver.

## Module and tool lifecycle

[`Application::MODULES`](../../mcp/lib/AppInfo/Application.php) is the ordered module list. A catalog module extends [`CatalogModule`](../../mcp/lib/Mcp/Tools/CatalogModule.php) and returns its final, non-instantiable `<App>ToolCatalog::TOOLS` constant. The order is observable in `tools/list`; append a new tool instead of reordering existing tools.

A tool publishes a static [`ToolDefinition`](../../mcp/lib/Mcp/Tools/ToolDefinition.php). Reading definitions does not instantiate its work classes or native app services. The base checks duplicate names and requires every writing definition to implement `WriteTool`. It resolves a tool lazily from the container when called or previewed and reuses it within the request. Unknown tools and previews of read tools use the base's canonical errors.

A read tool has one object dependency and calls it from `execute()`. A write tool has two dependencies of distinct classes: `preview()` calls only the planner, and `execute()` calls only the executor. User/root resolution and business logic belong to those work classes. The module delegates catalog, guide notes and optional plan rendering. See [Adding a module](adding-a-module.md) for the complete contract.

A `<App>Registration` exists only for port bindings, special construction or capability probes that autowiring cannot supply. Do not register every concrete work class or create an empty registration. A module without those needs returns `null` from `registration()`.

## Access, confirmation and native integrations

[`ToolAccess`](../../mcp/lib/Mcp/Tools/Discovery/ToolAccess.php) evaluates installation capabilities first, then the grant, Guests restrictions and optional app availability. Missing capabilities hide the tool before policy lookup. Grants and app availability are checked for the current request; an unreadable policy is not a permissive fallback. With `grantAnyOf`, a tool must also enforce the alternative appropriate to its actual arguments before planning and writing.

The access policy supplies an operation snapshot; visibility and resource-scope guards apply to file reads and references as well as writes. Native permissions still apply. Capability results are installation-wide and cached per request; they are not per-user authorization decisions. Native integrations resolve optional app services lazily, so definitions and discovery do not accidentally require a disabled app.

The registry adds confirmation metadata for non-read operations. Without confirmation, the tool produces a plan. State-bound confirmation and the relevant workflow guards are checked before the requested write. Plans must not create the requested backup, folder or checkout token in advance. Confirmed work still checks visibility, shared-write admission, locks, file-type rules and the operation's own constraints in the established order.

This does not promise that every preview has no platform effects. Files previews can use a lazy native root; a qualifying native NotFound can trigger actor setup and one retry. Mount setup can normalize received-share targets, and the lazy preview view can differ from the fully initialized confirmed-call view. Preserve these documented limits when splitting the workflow; see [`FilesPreviewContext`](../../mcp/lib/Modules/Files/FilesPreviewContext.php).

## Assets and packaging boundaries

Authored JavaScript is under `mcp/src/`; [`build_assets.php`](../../mcp/scripts/build_assets.php) builds committed bundles according to the asset manifests. The production archive contains generated runtime assets, not the authored sources or development harness. The repository's guides are not copied into the archive. [Packaging](packaging.md) explains the checks and the distinction between building and publishing.

## Legacy size policy

New source files must fit the default size limit enforced by [`FileSizeTest`](../../mcp/tests/Architecture/FileSizeTest.php). [`size-inventory.json`](../../mcp/scripts/dev/size-inventory.json) records the retained final exceptions and the transitional entries that must disappear with their owning restructuring steps. Existing ceilings cannot rise and transitional files cannot grow. The structure does not imply that all retained files are below the default limit.

## Compatibility and limitations

The restructuring preserves published MCP names, argument schemas, ordering, grants, responses and requested effects against the recorded behavioral baseline. Separately approved release features are listed in the [CHANGELOG](../../CHANGELOG.md); internal reorganization is not a claim that the whole release has no visible changes.

Static architecture checks establish structure, not callback reachability or backend behavior. Mocks do not establish native ACL, mount, version, transaction or external-storage behavior. Token comparison, golden contracts, effect contracts and real integration cells provide different evidence; none is a universal security or performance guarantee. See [Testing](testing.md).

## Credits

The capability-module architecture follows the proposal by @marcjoancr in [#14](https://github.com/sintax-tech/mcp-for-nextcloud/pull/14).

## Further reading

- [Adding a module](adding-a-module.md)
- [Testing](testing.md)
- [Packaging](packaging.md)
- [User documentation](../../README.md) and [technical reference](../../mcp/README.md)
