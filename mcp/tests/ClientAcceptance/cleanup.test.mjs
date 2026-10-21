// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { Runtime } from "./runtime.mjs";
import { canRestoreConfig, reconcileNamedToken } from "./cleanup.mjs";
test("cleanup reconciles a created password even when its ID was never captured", () => {
  let tokens = [
    { id: 1, name: "other" },
    { id: 2, name: "owned-name" },
  ];
  const removed = [];
  const runtime = {
    occ: (cmd, uid, id) => {
      assert.equal(uid, "ui-firefox");
      if (cmd === "user:auth-tokens:list") return JSON.stringify(tokens);
      removed.push(id);
      tokens = tokens.filter((t) => String(t.id) !== id);
    },
  };
  reconcileNamedToken(runtime, "owned-name");
  assert.deepEqual(removed, ["2"]);
  assert.deepEqual(tokens, [{ id: 1, name: "other" }]);
});
test("a partially applied owned proxy configuration restores without clobbering concurrent values", () => {
  const before = { trusted_domains: ["127.0.0.1"], unrelated: "original" };
  const changes = [
    { key: "trusted_domains", index: 9, value: "mcp-p1.test:18443" },
    { key: "trusted_proxies", index: 9, value: "10.0.0.1" },
  ];
  const partial = {
    ...before,
    trusted_domains: { 0: "127.0.0.1", 9: "mcp-p1.test:18443" },
  };
  assert.equal(canRestoreConfig(before, partial, changes), true);
  assert.equal(
    canRestoreConfig(before, { ...partial, unrelated: "concurrent" }, changes),
    false,
  );
  assert.equal(
    canRestoreConfig(
      before,
      { ...partial, trusted_domains: { 0: "127.0.0.1", 9: "someone-else" } },
      changes,
    ),
    false,
  );
});
test("failed cleanup retains the exclusive fixture lock while a watchdog can still run", async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "mcp-cleanup-"));
  fs.writeFileSync(path.join(dir, "run.json"), "{}");
  fs.writeFileSync(path.join(dir, "p1.lock"), "owned");
  try {
    const runtime = new Runtime(dir, dir);
    runtime.locked = true;
    runtime.started = ["fixture"];
    runtime.run.nc = "fixture";
    runtime.ports = [];
    runtime.occ = () => {
      throw new Error("simulated failure");
    };
    runtime.files = () => ({});
    runtime.originalFiles = {};
    runtime.inspect = () => ({
      Config: { Labels: { "mcp.ui.run": undefined } },
      State: { Running: false },
    });
    runtime.docker = () => "";
    const result = await runtime.close();
    assert.equal(result.status, "failed");
    assert.equal(fs.existsSync(path.join(dir, "p1.lock")), true);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test("cleanup refuses a concurrent config source edit even when CONFIG values are unchanged", async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "mcp-source-conflict-"));
  fs.writeFileSync(
    path.join(dir, "run.json"),
    JSON.stringify({ nc: "fixture", run_id: "owned" }),
  );
  let restored = false;
  try {
    const runtime = new Runtime(dir, dir);
    runtime.started = ["fixture"];
    runtime.ports = [];
    runtime.originalConfig = "<?php $CONFIG=[];";
    runtime.expectedConfig = runtime.originalConfig;
    runtime.originalConfigValues = {};
    runtime.originalFiles = {};
    runtime.configValues = () => ({});
    runtime.files = () => ({});
    runtime.occ = () => "";
    runtime.restoreConfig = () => {
      restored = true;
    };
    runtime.docker = (action) =>
      action === "exec"
        ? runtime.expectedConfig + " // concurrent comment"
        : "";
    runtime.inspect = () => ({
      Config: { Labels: { "mcp.ui.run": "owned" } },
      State: { Running: false },
    });
    const result = await runtime.close();
    assert.equal(restored, false);
    assert.equal(result.status, "failed");
    assert(result.errors.includes("config-restore-conflict"));
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
