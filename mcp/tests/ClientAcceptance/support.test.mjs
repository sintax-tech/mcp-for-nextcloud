// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from "node:test";
import assert from "node:assert/strict";
import { processRun } from "./support.mjs";
test("a real child failure remains nonzero and preserves separate evidence streams", async () => {
  const result = await processRun(process.execPath, [
    "-e",
    'process.stdout.write("fixture result");process.stderr.write("fixture failure");process.exitCode=7',
  ]);
  assert.equal(result.code, 7);
  assert.equal(result.stdout, "fixture result");
  assert.equal(result.stderr, "fixture failure");
  assert.equal(result.timedOut, false);
});
test("a hung child is terminated and cannot report a passing exit", async () => {
  const result = await processRun(
    process.execPath,
    ["-e", "setInterval(()=>{},1000)"],
    { timeout: 100 },
  );
  assert.equal(result.timedOut, true);
  assert.notEqual(result.code, 0);
});
test("aborting an interactive client terminates its process without waiting for the deadline", async () => {
  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), 100);
  try {
    const result = await processRun(
      process.execPath,
      ["-e", "setInterval(()=>{},1000)"],
      { timeout: 5000, signal: abort.signal },
    );
    assert.equal(result.timedOut, false);
    assert.notEqual(result.code, 0);
  } finally {
    clearTimeout(timer);
  }
});
test("a previously aborted operation cannot launch a successful child", async () => {
  const abort = new AbortController();
  abort.abort();
  await assert.rejects(
    processRun(process.execPath, ["-e", "process.exit(0)"], {
      signal: abort.signal,
    }),
    { name: "AbortError" },
  );
});
