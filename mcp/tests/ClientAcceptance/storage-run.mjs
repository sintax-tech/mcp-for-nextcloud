// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { hash, save, processRun } from "./support.mjs";
import { verdict } from "./policy.mjs";
const [root, ...extra] = process.argv.slice(2);
if (!root || extra.length || !path.isAbsolute(root)) {
  console.error(
    "Usage: composer test:clients:storage -- ABSOLUTE_INSPECTOR_DIR",
  );
  process.exit(2);
}
process.umask(0o077);
const parent = path.join(
  process.env.XDG_STATE_HOME ?? path.join(process.env.HOME, ".local/state"),
  "mcp-client-tests",
);
fs.mkdirSync(parent, { recursive: true, mode: 0o700 });
const state = fs.mkdtempSync(path.join(parent, "storage-"));
const required = [
  "preflight",
  "seed-a",
  "read-a",
  "seed-b",
  "read-both",
  "rotate-a",
  "read-rotated",
  "clear-a",
  "read-cleared",
  "wrong-key",
  "missing-key",
  "tampered-store",
  "read-after-controls",
  "static-client-boundary",
  "static-client-reload",
];
const record = {
  schemaVersion: 1,
  startedAt: new Date().toISOString(),
  runId: path.basename(state),
  required,
  results: [],
  scope:
    "Installed Inspector encrypted storage/provider integration with synthetic credentials; no OAuth servers or network journeys.",
  https: "pending",
  chatgpt: "pending",
  humanReviews: "pending",
  infrastructureChanged: false,
};
const sources = [
  "storage-run.mjs",
  "storage-worker.mjs",
  "support.mjs",
  "policy.mjs",
];
record.runnerSources = Object.fromEntries(
  sources.map((n) => [n, hash(fs.readFileSync(new URL(n, import.meta.url)))]),
);
try {
  assert.equal(process.version, "v24.21.0");
  const lock = fs.readFileSync(path.join(root, "package-lock.json"));
  assert.equal(
    hash(lock),
    "d6e59c49bd1a5640d33d7d8c77a387e382553762e216173373c0f2af86c704ae",
  );
  const bundle = fs.readFileSync(
    path.join(
      root,
      "node_modules/@modelcontextprotocol/inspector/clients/cli/build/index.js",
    ),
  );
  assert.equal(
    hash(bundle),
    "bd48a8df27412e4c01e876493a4e629d84d823ed44f0e738751a7efa3a8d18ca",
  );
  const suffix =
    "\nexport {FileSecretStore, NodeOAuthStorage, BaseOAuthClientProvider};\n";
  const copy = Buffer.concat([bundle, Buffer.from(suffix)]);
  fs.writeFileSync(path.join(state, "installed-components.mjs"), copy, {
    mode: 0o600,
  });
  fs.symlinkSync(
    path.join(root, "node_modules"),
    path.join(state, "node_modules"),
    "dir",
  );
  record.inspector = {
    version: "2.11.0",
    lockSha256: hash(lock),
    installedBundleSha256: hash(bundle),
    exportSuffixSha256: hash(suffix),
    instrumentedCopySha256: hash(copy),
  };
  const fixture = Object.fromEntries(
    ["a", "a2", "b", "static"].map((n) => [
      n,
      Object.fromEntries(
        ["access", "refresh", "client"].map((field) => [
          field,
          "synthetic-" + crypto.randomBytes(24).toString("hex"),
        ]),
      ),
    ]),
  );
  save(path.join(state, "fixture.private.json"), fixture);
  for (const name of ["key.private", "wrong-key.private"])
    fs.writeFileSync(
      path.join(state, name),
      crypto.randomBytes(32).toString("hex"),
      { mode: 0o600 },
    );
  record.results.push({ id: "preflight", status: "passed" });
  const env = { ...process.env };
  for (const key of Object.keys(env))
    if (
      key.startsWith("MCP_") ||
      key === "NODE_OPTIONS" ||
      key === "NODE_EXTRA_CA_CERTS"
    )
      delete env[key];
  Object.assign(env, {
    MCP_STORAGE_DIR: path.join(state, "store"),
    MCP_INSPECTOR_SECRET_STORE: "file",
    MCP_INSPECTOR_SECRET_KEY_FILE: path.join(state, "key.private"),
    MCP_INSPECTOR_PERSIST_TOKENS: "all",
  });
  let prerequisite = true;
  for (const id of required.slice(1)) {
    if (!prerequisite) {
      record.results.push({
        id,
        status: "blocked",
        reason: "Required storage preparation failed",
      });
      continue;
    }
    const out = await processRun(
      process.execPath,
      [
        fileURLToPath(new URL("./storage-worker.mjs", import.meta.url)),
        state,
        id,
      ],
      { env, timeout: 60000 },
    );
    fs.writeFileSync(path.join(state, id + ".stdout.private"), out.stdout);
    fs.writeFileSync(path.join(state, id + ".stderr.private"), out.stderr);
    const resultPath = path.join(state, id + ".result.json");
    const result = fs.existsSync(resultPath)
      ? JSON.parse(fs.readFileSync(resultPath))
      : { id, status: "failed", errorType: "MissingWorkerResult" };
    if (out.code !== 0 || out.timedOut) result.status = "failed";
    record.results.push({
      ...result,
      exitCode: out.code,
      timedOut: out.timedOut,
      stdoutSha256: hash(out.stdout),
      stderrSha256: hash(out.stderr),
    });
    console.log(id + ": " + result.status.toUpperCase());
    if (
      ![
        "wrong-key",
        "missing-key",
        "tampered-store",
        "static-client-reload",
      ].includes(id) &&
      result.status !== "passed"
    )
      prerequisite = false;
    save(path.join(state, "result.json"), record);
  }
  assert.equal(
    hash(
      fs.readFileSync(
        path.join(
          root,
          "node_modules/@modelcontextprotocol/inspector/clients/cli/build/index.js",
        ),
      ),
    ),
    record.inspector.installedBundleSha256,
  );
  record.installedBundleUnchanged = true;
} catch (error) {
  fs.writeFileSync(
    path.join(state, "runner.error.private.txt"),
    String(error.stack ?? error),
  );
  record.runnerError = error.name;
} finally {
  for (const id of required)
    if (!record.results.some((x) => x.id === id))
      record.results.push({
        id,
        status: "blocked",
        reason: "Required prerequisite did not complete",
      });
  record.finishedAt = new Date().toISOString();
  record.status =
    !record.runnerError && verdict(required, record.results)
      ? "passed"
      : "failed";
  save(path.join(state, "result.json"), record);
  console.log(
    "Inspector storage " +
      record.status.toUpperCase() +
      "; private evidence: " +
      state,
  );
  if (record.status !== "passed") process.exitCode = 1;
}
