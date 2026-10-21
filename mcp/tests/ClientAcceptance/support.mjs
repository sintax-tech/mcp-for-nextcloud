// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from "node:fs";
import crypto from "node:crypto";
import { execFileSync, spawn } from "node:child_process";
export const hash = (data) =>
  crypto.createHash("sha256").update(data).digest("hex");
export function command(file, args, input) {
  return execFileSync(file, args, {
    input,
    encoding: "utf8",
    timeout: 60000,
    stdio: ["pipe", "pipe", "pipe"],
    maxBuffer: 8 * 1024 * 1024,
  });
}
export function save(path, value) {
  fs.writeFileSync(path, JSON.stringify(value, null, 2) + "\n", {
    mode: 0o600,
  });
}
export async function processRun(
  file,
  args,
  { env = process.env, timeout = 60000, onData, signal } = {},
) {
  signal?.throwIfAborted();
  const child = spawn(file, args, {
    env,
    detached: true,
    stdio: ["pipe", "pipe", "pipe"],
  });
  child.stdin.end();
  let stdout = "",
    stderr = "",
    timedOut = false;
  const terminate = () => {
    try {
      process.kill(-child.pid, "SIGKILL");
    } catch {}
  };
  signal?.addEventListener("abort", terminate, { once: true });
  process.once("SIGTERM", terminate);
  process.once("SIGINT", terminate);
  const timer = setTimeout(() => {
    timedOut = true;
    terminate();
  }, timeout);
  child.stdout.on("data", (b) => {
    stdout += b;
    onData?.(stdout + stderr);
  });
  child.stderr.on("data", (b) => {
    stderr += b;
    onData?.(stdout + stderr);
  });
  try {
    const code = await new Promise((resolve, reject) => {
      child.on("error", reject);
      child.on("close", resolve);
    });
    return { code, stdout, stderr, timedOut };
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener("abort", terminate);
    process.removeListener("SIGTERM", terminate);
    process.removeListener("SIGINT", terminate);
    terminate();
  }
}
export function resultJson(out) {
  return JSON.parse(
    out.split(/\r?\n/).find((line) => line.startsWith('{"result"')),
  ).result;
}
