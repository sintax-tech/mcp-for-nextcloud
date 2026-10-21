<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Private TLS and Inspector acceptance

This is an **opt-in campaign runner for the frozen upstream contribution**, not a
production deployment tool or part of ordinary unit-test execution. It resumes an
explicitly selected, stopped synthetic Nextcloud fixture, exercises real Inspector
and browser traffic, restores its temporary configuration and stops the fixture.
The application archive remains unchanged. No preview command, public listener,
firewall rule, shared proxy modification or outbound network is used.

Run the safe runner checks without Docker:

```sh
composer --working-dir=mcp test:clients:unit
```

These checks are also part of `composer check`. The runtime command is separate:

```sh
composer --working-dir=mcp test:clients -- /absolute/private/fixture /absolute/inspector-install /absolute/certutil
```

It requires all three absolute paths and never installs missing dependencies.
Do not point it at another Nextcloud instance. The selected fixture must retain
`run.json`, `installed-files.json` and private `credentials.json` from the closed
UI exercise, match candidate `b96884631bf9bda245ce9895facfa8380ab08998`, and contain
the original `ui-admin`/`ui-firefox` synthetic users. Both owned containers must
be stopped, restart disabled, the Docker network internal and MCP service disabled.
A concurrent run is refused by an exclusive fixture lock. Cleanup also checks the
exact configuration file captured after each successful owned write before
restoring it. A concurrent source edit or uncertain write is a recovery conflict,
not permission to overwrite the file.

## Prerequisites and boundaries

- Linux, a running Docker engine, a user systemd manager, OpenSSL and Node 24.21.0.
  The runner does not start the Docker daemon or install system packages.
- Inspector 2.11.0 installed externally with the reviewed lockfile hash. Its actual
  CLI is used, including a PTY for interactive OAuth. No protocol-era override.
- The project's locked Playwright and Chromium/Firefox browsers. Firefox trusts
  the generated one-day CA only in its private profile's NSS database. `certutil`
  can be a user-local extraction; global trust and `/etc/hosts` remain unchanged.
- Ports 18080, 18443 and 6276 must be available. All listeners bind to loopback.
  Private `.test` resolution is process/profile-specific. The Node runtime supplies
  the fixture proxy; it is not the shared devbox ingress or a production proxy.
- Runtime state defaults to `$XDG_STATE_HOME/mcp-client-tests` or
  `~/.local/state/mcp-client-tests`, mode 0700. Secrets/raw client output remain
  private, mode 0600; OAuth storage is encrypted. The parent run directory is
  **not an upload bundle**. Package caches retain their normal locations.

The account's temporary Files edit grant is restored. A uniquely named fixture
file and its automatic backups are retained. Setup uses the actual Nextcloud UI;
app passwords use native commands with password input over stdin. Administration
and native DAV checks use a private route, not the filtered TLS route.

## Module responsibilities

| File | Responsibility |
| --- | --- |
| `run.mjs` | Explicit invocation, ordered acceptance scenarios and sanitized verdict. |
| `runtime.mjs` | Fixture ownership, private relay, temporary proxy configuration, watchdog and restoration/stop. |
| `browser.mjs` | Real administrator/personal settings, consent browser and scoped UI cleanup. |
| `inspector.mjs` | Actual CLI/PTY execution, isolated encrypted storage and private evidence streams. |
| `proxy.mjs`, `policy.mjs` | Loopback TLS forwarding, reviewed route boundary and required-case verdict. |
| `cleanup.mjs` | Exact-name credential reconciliation and conflict-aware partial configuration restoration. |
| `support.mjs` | Bounded subprocesses, hashes and private JSON persistence. |
| `issuer-diagnostic.mjs` | Exact installed client component diagnostic with synthetic credentials; separate from real OAuth acceptance. |

## Interpreting results

The runner emits case names and outcomes, not credentials or raw protocol payloads.
`result.json` records candidate/source hashes, required cases, failures/blocked
cases and closure. Individual CLI records include exit codes and stream hashes.
A missing required case or failed cleanup returns nonzero. ChatGPT and human
reviews always remain pending; a local pass cannot qualify public HTTPS or S7.

The native Nextcloud 35 token provider has a ten-second local cache. The password
revocation case records immediate DAV status and, if still accepted, waits eleven
seconds once before asserting refusal and testing Inspector again. It does not
flush the cache or claim instantaneous revocation. An unexpected final acceptance
fails. This is a native host behavior, not an MCP authorization workaround.

The issuer diagnostic executes bounded, exact source sections from the pinned
installed Inspector/SDK in a VM, using synthetic values and memory persistence.
It distinguishes the unstamped preregistered client identifier from issuer-keyed
tokens. It neither changes installed dependencies nor certifies every multi-issuer
flow, hosted client or encrypted-store failure mode.

The watchdog bounds fixture runtime to 25 minutes. If a runner is forcibly killed,
do not blindly remove its lock or start another run: inspect the exact run result,
owned containers/listeners and private snapshot first, restore only matching owned
configuration, cancel its exact watchdog timer/service, then retire the stale lock. A failed cleanup
retains the exclusive lock; the watchdog also checks the execution identity. A watchdog stop is not evidence that
all UI/configuration cleanup succeeded.

This runner does not qualify public HTTPS ingress or a hosted ChatGPT connection.
Those checks need an authorized public endpoint and a real client account; private
TLS success alone does not establish their compatibility.

## Encrypted storage across issuers

Run the separate storage qualification without Nextcloud, Docker, TLS listeners or
network requests:

```sh
composer --working-dir=mcp test:clients:storage -- /absolute/inspector-install
```

It requires the same pinned Inspector lock and exact installed CLI bundle hash.
`storage-run.mjs` creates a private copy of the entire bundle with only an appended
export declaration; all original bytes and implementation bodies remain intact.
`storage-worker.mjs` imports it in a fresh process for each case and uses the real
`FileSecretStore`, `NodeOAuthStorage` and provider, with real filesystem locking,
AES-256-GCM/scrypt and synthetic credentials. No memory persistence double is used.
The dependency installation is read-only; the copied bundle is never committed.

The suite checks one resource with distinct issuers, restart/readback, separate
access/refresh tokens and registered client secrets, rotation/deletion, unavailable
keys and altered ciphertext. It also tests preregistered client credentials; the
pinned provider currently returns that resource-wide static secret under another
issuer. That required isolation case **fails**, so the command returns **1**.
It is an explicit opt-in external-client qualification, separate from ordinary
`check`; its failure must remain visible rather than be marked expected/pass.

Evidence lives in `~/.local/state/mcp-client-tests/storage-*/`. Key files, generated
synthetic credentials, raw errors and copies of stores are private, not upload
bundles. The JSON verdict contains sanitized observations and source hashes. The
result proves storage/provider behavior only, not two real OAuth server journeys,
network exfiltration, public HTTPS, ChatGPT or human security acceptance.
