<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Native integration tests

These tests load the installed Nextcloud core, not unit doubles. Run an explicit
cell with `composer --working-dir=mcp test:integration -- 35 sqlite fresh`.
Prefix the three cell arguments with `--plan` to inspect pins without starting
containers. `ci/matrix.php --matrix` lists all 16 supported cells. Ordinary
`composer check` excludes only this native suite and never starts Docker.

Docker and Linux `ip`/`flock`, PHP, Composer, the locked Node/npm versions, curl and OpenSSL
must already be available. The runner does not manage the shared daemon. Each run
has unique labeled containers/network, a non-overlapping private subnet and no
published ports. Its exit trap deletes only matching resources, including their
anonymous disposable volumes. A failed required app install fails the run. Candidate packaging is serialized with
`flock` because npm dependencies and generated assets belong to this checkout.
Run ordinary checks separately from native packaging; their build steps do not join
this native-runner lock.

Artifacts live under ignored `mcp/build/integration/<run>/mcp-it/artifacts`:
package hashes, runtime identities, JUnit and sanitized logs. Private raw step/SQL
logs and disposable credentials stay outside this upload directory. Never upload
its parent. Missing or empty tests fail. Database-only and baseline-only tests
explicitly explain their inapplicability; missing applications are failures.

Guests v4.10.1 is pinned and unpacked for every cell; its native security tests
enable it only for their own scenarios and restore the prior configuration.
They cover app-password and OAuth admission, module restrictions, hidden external
storage, group-only sharing and sessionless checkout-owner restrictions.

The native suites run in order: `compat`, integration, then `last` (global epoch).
Two independent database sessions, real core events and Basic/Bearer requests are
part of the evidence. The shared `tests/Support/Access/Policy/PolicyServices.php`
file composes real owners only; the unit bootstrap and its doubles are never loaded.

`ci/legacy-policy.php` and the corresponding assertions in
`Compat/UpgradeSnapshotTest.php` deliberately name the two pinned 1.0.8 layouts.
The probe runs in a separate process and checks each loaded class's source. These
are exact baseline-fixture exceptions to retired-name scans, not candidate loaders.
`ci/replacement.php` similarly seeds the pinned upstream layout, then verifies unchanged database state and bearer access
after Apache restart. `upgrade-upstream` installs 1.1.0 and runs `occ upgrade` to
the candidate 1.1.2; `upgrade-upstream-111` separately upgrades upstream 1.1.1.
`replace-upstream` installs upstream 1.1.2 and performs
same-version replacement without forcing migrations. All three retain the actual
declared versions and use only disposable instances.

CLI probes require explicit completion: Nextcloud can log an exception and exit
with status zero, so `Support/ProbeCompletion.php` makes an incomplete probe fail.
Replacement verification removes only its own seeded user/client/scope after
checking their preservation, before the shared suite's fresh-fixture assertions.

The NC35/SQLite/fresh cell also rehearses coordinated recovery after the suite.
While maintenance remains enabled, it copies the actual SQLite database and app,
revokes credentials after the backup, restores both, restarts the host to clear
caches, invalidates restored credentials and explicitly disconnects the fixture
account. Only after checking the restored restrictions does it allow a new
connection; old access/refresh tokens and pending codes stay invalid. It leaves
MCP closed. Backups and fixture secrets remain private inside the disposable
container, never uploaded. This covers a controlled same-version restore, not a
file-only downgrade or every operator's backup system. Nextcloud app passwords
have their own lifecycle and still need a separate recovery review.

During pre-version harness qualification only, the explicit environment variable
`MCP_IT_QUALIFICATION_VERSION=1.1.0` changes the extracted candidate copy and writes
`qualification.txt`. Such a run cannot qualify the final package. Final release
validation must omit it and repeat every cell. Filtered diagnostic PHPUnit runs
(`MCP_IT_PHPUNIT_ARGS`) also do not count as full-cell evidence.

See [testing](../../../docs/en/development/testing.md) for the validation layers
and [upgrade and recovery](../../../docs/en/installation.md#upgrade-and-recovery)
for coordinated restoration and credential invalidation. Record the exact source,
package hash, selected cell, exit status and inapplicable skips with each run;
filtered runs and evidence from an older artifact do not qualify a new candidate.
