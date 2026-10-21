<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Installation

## Requirements

- Nextcloud **32 to 35**, each release checked by the API contract tests against the oldest build of its major (with the matching Deck and Talk); Deck 1.16+ and Talk 22+ on 32, up to Deck 1.19 and Talk 25 on 35
- PHP **8.2+** with `zip`, `mbstring` and `dom`
- Optional apps, only for their own tools (hidden while the app is disabled): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versions (`files_versions`, required for edits), Deleted files (`files_trashbin`, required for deletes)
- Deck and Talk are optional: their tools appear only when the app is enabled in a supported version; an older release counts as disabled, its tools stay hidden and the admin page says which version brings them back

The normal installation is through the Nextcloud App Store: *Apps* → search for **MCP for Cloud** → **Download and enable** (or `occ app:install mcp`). The store package is signed and updates through the app updater. The SSH installation below is the alternative, for development or for testing a local build.

### Install via SSH

Replace `<server>`, `<nextcloud>` (installation root), `<apps>` (writable apps directory, for example `custom_apps` or `apps`, according to `apps_paths` in `config/config.php`) and `<www>` (web server user, for example `www-data`).

```sh
scp build/mcp-1.1.2.tar.gz <server>:/tmp/
ssh <server>
sudo tar -xzf /tmp/mcp-1.1.2.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

In Docker, run the `occ` commands inside the container as the web server user. If PHP uses OPcache with `validate_timestamps=0`, restart PHP-FPM after copying the files.

To update a build installed through SSH: `occ app:disable mcp`, remove `<apps>/mcp`, extract the new package, `occ app:enable mcp` and `occ upgrade` if Nextcloud asks for it. The folder must be replaced whole, so no old files are left in `vendor/`. To remove: `occ app:remove mcp`.

The 1.1.0 migrations retain the original migration IDs and import legacy native/custom-client settings into authoritative rows. Valid 1.0.8 preferences and credentials survive upgrade. The supported qualification paths include original upstream 1.0.8, the migrated local 1.0.8, upgrades from upstream 1.1.0 and 1.1.1, and replacement of an already-migrated upstream 1.1.2 installation. Same-version replacement does not force an upgrade or rerun migrations to reset policy.

## Upgrade and recovery

Before updating, arrange a consistent backup of the application, database and required Nextcloud configuration/data, following the operator's backup procedure. Maintenance mode prevents traffic during replacement without changing MCP's service policy or revoking credentials merely to perform a routine upgrade. Replace the app directory as a whole, run the required native upgrade, clear/restart PHP caches where necessary, then verify migration history, assets, permissions and existing connections before reopening traffic.

Prefer forward repair after a failed update. Rolling only application files back to 1.0.8 is unsafe: that code does not enforce the new epoch/generation revocations or resource restrictions. A database backup can also contain credentials revoked after it was taken.

For coordinated recovery, keep Nextcloud in maintenance while restoring matching application and database state and any required configuration/data. Before reopening MCP, invalidate restored OAuth codes/tokens through the supported policy commands, explicitly disconnect accounts where reconnection is required, and review effective permissions and resource restrictions. Review/revoke Nextcloud app passwords separately in the native Security controls. Re-enabling service access alone is not this review. An old version that cannot enforce required restrictions cannot be reopened for those users.

The disposable native suite rehearses a same-version application/SQLite restore, old-credential rejection and explicit reconnection. It does not certify an operator's backup tooling, a file-only downgrade or every production storage arrangement.

[Operational guides](guides/README.md).

English · [Español](../es/installation.md) · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
