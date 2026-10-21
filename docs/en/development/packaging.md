<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Source packaging

Start with [development setup](setup.md) for PHP, Composer, Node/npm and dependency installation. A full repository checkout is required. Runtime installation of the built archive needs none of the development toolchain.

In the `mcp/` directory:

```sh
composer package
```

Produces `build/mcp-<version>.tar.gz` with one `mcp/` root: application code,
assets, legal files, production-only Composer dependencies, concise app READMEs
and the operational documentation selected by `docs/documentation.json`. The
script builds the two JavaScript entries and copies documentation from its
canonical repository owners. Node dependencies, source JavaScript, tests, checker
scripts and developer/history guides stay out of the archive.

### Package rule: no macOS metadata

A package with `._*` (AppleDouble), `.DS_Store` or PAX headers with `com.apple.*` xattrs is refused by the App Store and may break the installation. Therefore:

- tar runs as `COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' --exclude='.DS_Store'`. `--no-xattrs` was added because, without it, bsdtar writes `com.apple.provenance` in the PAX headers of every entry;
- the check is done by `scripts/check_package.php`, which reads the tar with its own PHP reader, because macOS's `tar -tzf` hides the `._*` entries and `PharData` does not expose the PAX headers;
- the package is refused, deleted, and the script exits with code 1, if it has:
  - a `._*` or `.DS_Store` entry;
  - a PaxHeader entry or extended PAX header;
  - a top-level entry other than `mcp/`;
  - a package in `vendor/` outside the runtime dependencies (`smalot/`, `symfony/polyfill-mbstring/`, `composer/`, `autoload.php`), such as `sabre/*` or `symfony/console`;
  - a script or style referenced by `Util::addScript('mcp', X)`/`Util::addStyle('mcp', X)` missing from the package.

`php scripts/check_package.php build/mcp-<version>.tar.gz` inspects tar structure,
entry safety, legal files, dependencies and referenced assets. It is only one part
of verification: `composer package` also validates source provenance, stages docs,
extracts the inspected candidate and checks its documentation inventory, bytes and
links before publishing the final filename. Use the complete command for a release
artifact; the PHP inspector alone does not establish documentation coherence.

### Runtime dependencies

| Package | Use | License |
| --- | --- | --- |
| `smalot/pdfparser` | PDF text extraction in `files_read` | LGPL-3.0 |
| `symfony/polyfill-mbstring` | Dependency of pdfparser; inactive when the `mbstring` extension exists | MIT |

The license files accompany each package in `vendor/`. `vendor/autoload.php` is loaded by `lib/AppInfo/Application.php`.

## Canonical documentation bundle

The manifest is the only operational page/asset allowlist; it also selects optional
localized app READMEs. Paths under `docs/` map to `mcp/docs/` in the artifact. Local
link destinations are rewritten only in staged copies, using parsed Markdown/HTML
positions; prose, metadata, fragments and copyright remain intact. App legal paths
keep their existing archive locations. There is no tracked `mcp/docs/` mirror.

All local links in these documents must stay inside the extracted package. An
omitted target fails instead of implicitly including development pages. Extra,
missing or stale-but-link-valid content fails exact inventory/byte checks. The
existing PHP tar reader rejects unsafe paths, duplicate destinations, links and
special entry types before extraction. The private stage is cleaned on failure or
success; the current version's previous artifact is invalidated before any fallible
step. Building from a copied app directory without the full source checkout fails.

Source and translation checks also run for direct `sh scripts/package.sh` calls.
Freshness is advisory; invalid provenance is an error. Full source history is
required for packaging, including CI's standalone package job. Runtime installation
needs neither Git nor Node nor development dependencies.
