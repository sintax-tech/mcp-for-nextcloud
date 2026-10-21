// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from "node:test";
import assert from "node:assert/strict";
import { allowed, verdict } from "./policy.mjs";
test("the private edge permits protocol and consent but not administration or path aliases", () => {
  for (const [method, path] of [
    ["POST", "/apps/mcp/"],
    ["GET", "/apps/mcp/.well-known/oauth-protected-resource"],
    ["POST", "/apps/mcp/oauth/token"],
    ["GET", "/login"],
  ])
    assert.equal(allowed(method, path), true, path);
  for (const path of [
    "/settings/admin/mcp",
    "/remote.php/dav/files/a",
    "/apps/mcp/api/grants",
    "/apps/mcp/checkout/token",
    "/index.php/apps/mcp/",
    "/apps/mcp/../api/grants",
    "/apps/mcp/%2e%2e/api/grants",
    "//login",
    "/core/../config/config.php",
  ])
    assert.equal(allowed("GET", path), false, path);
  assert.equal(allowed("PUT", "/login"), false);
});
test("blocked and absent required cases cannot produce a passing suite", () => {
  assert.equal(
    verdict(["tls", "oauth"], [{ id: "tls", status: "passed" }]),
    false,
  );
  assert.equal(verdict(["tls"], [{ id: "tls", status: "blocked" }]), false);
  assert.equal(verdict(["tls"], [{ id: "tls", status: "passed" }]), true);
  assert.equal(verdict([], []), false);
});
