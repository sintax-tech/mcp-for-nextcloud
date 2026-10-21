// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const metadata = new Set([
  "oauth-protected-resource",
  "oauth-authorization-server",
  "openid-configuration",
  "jwks.json",
]);
export function allowed(method, target) {
  const path = target.split("?")[0];
  if (/%|\\|\/\/|(?:^|\/)\.\.?(?:\/|$)/.test(path)) return false;
  if (path === "/apps/mcp/") return ["POST", "GET", "DELETE"].includes(method);
  if (path.startsWith("/apps/mcp/.well-known/"))
    return method === "GET" && metadata.has(path.split("/").at(-1));
  if (path === "/apps/mcp/oauth/authorize" || path === "/login")
    return ["GET", "POST"].includes(method);
  if (path === "/apps/mcp/oauth/token") return method === "POST";
  // Static-only dependencies, not arbitrary application controllers or PHP files.
  return (
    ["GET", "HEAD"].includes(method) &&
    /^\/(?:core|dist|apps\/(?:theming|mcp))\/[a-zA-Z0-9_./-]+\.(?:js|mjs|css|svg|png|ico|woff2?|ttf)$/.test(
      path,
    )
  );
}
export function verdict(required, results) {
  return (
    required.length > 0 &&
    required.every(
      (id) =>
        results.filter((r) => r.id === id).length === 1 &&
        results.find((r) => r.id === id).status === "passed",
    )
  );
}
