<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Development setup

Use a source checkout, PHP 8.2–8.5 and Composer **2.8 or newer**. Composer's script
argument controls require that minimum. CI provisions the current Composer 2 release.
PHP lint and static analysis need no Node.js. Documentation checks, PHPUnit, browser
tests and source packaging require the pinned Node/npm toolchain below because they build assets.
Make, Docker, devbox and a live Nextcloud are not required.
Packaging additionally uses a POSIX shell and tar. Documentation provenance and
packaging require a full Git checkout with the recorded English source commits.
A shallow clone fails explicitly; the validator never fetches history silently.

Install the PHP extensions required by the lock file and suite: DOM/XML (including
SimpleXML, XMLReader and XMLWriter), mbstring, intl, gd, zip, sqlite3/PDO SQLite,
curl, fileinfo, tokenizer, Phar and zlib. Composer validates its platform requirements;
the suite also uses image, locale and archive extensions. CI explicitly enables
mbstring, intl, gd, zip and sqlite3 on its PHP image.

Activate the Node/npm versions below, then run from the repository root:

```sh
composer --working-dir=mcp install --no-interaction
composer --working-dir=mcp check-platform-reqs
npm --prefix mcp ci
composer --working-dir=mcp assets:build
composer --working-dir=mcp test
# Install the browser prerequisites below before the complete check.
composer --working-dir=mcp check
```

From `mcp/`, omit `--working-dir=mcp`. There is one Composer manifest in that app
directory. Install uses the committed lock; do not run update or ignore platform
requirements to repair a normal setup. PHPStan and PHPUnit are development dependencies,
so a production archive's vendor tree is insufficient for contributor commands.

`check` validates the manifest/lock, documentation/provenance, syntax, analysis,
tests and package, stopping on
the first failure. Packaging stages a separate production-only install and may need
network access for uncached locked dependencies. It leaves `mcp/build/mcp-<version>.tar.gz`
and never replaces your development vendor tree or publishes the archive.

The test command sets memory, post and upload limits to 1G consistently. Invoking
PHPUnit directly can produce environment-dependent limit failures. Use the canonical
commands for verification and [focused tests](testing.md) while developing.

Composer caches remain in their normal per-user locations. Generated project output
stays in ignored `mcp/build/`, `.phpunit.cache/` and `.cache/phpstan/`. Do not commit
credentials, user content, caches or harness state. Analysis declarations are committed
source inputs, not caches; their [provenance](../../../mcp/phpstan/README.md) is reviewed.

Ordinary development commands do not install or modify a Nextcloud instance. The
explicit private [client acceptance command](testing.md#private-real-client-acceptance)
resumes and temporarily configures only its selected synthetic fixture. Other real-host tests
use a separate disposable instance, and existing write diagnostics have effects as
documented in the [troubleshooting guide](../guides/troubleshooting.md). A green local suite does not replace those checks.

## Administration JavaScript development

Frontend verification uses Node **24.21.0** (see `mcp/.node-version`) and npm
**11.19.0**. Activate those versions with your Node version manager before running
Composer's asset build, PHPUnit, JavaScript, package or complete checks. The system Node version is not assumed.
Install the committed dependency lock and the matching browsers explicitly:

```sh
npm --prefix mcp ci
cd mcp
node node_modules/playwright/cli.js install chromium firefox webkit
```

Linux also needs Playwright's documented browser system libraries. CI installs them
with `install --with-deps`; local verification fails visibly if a browser or library
is absent. Browser downloads use the standard per-user Playwright cache. Test pages,
traces and other generated output stay in ignored `mcp/build/`; the fixture server
binds only to `127.0.0.1`. It uses actual PHP templates and synthetic APIs and does
not replace verification on a real Nextcloud host.

Author JavaScript in `mcp/src/`; `mcp/js/admin.js` and `personal.js` are ignored
build products. Run `composer --working-dir=mcp assets:build` after editing before
a focused npm test or loading a source checkout in a disposable Nextcloud instance.
The complete Composer commands build automatically and never install missing tools.
See [JavaScript ownership](admin-javascript.md) for the module boundaries.

The original Node.js stdio prototype was removed; it remains in Git history up to
commit `27739f0` (tag `v0.11.0`).
