// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from "node:fs";
import path from "node:path";
import vm from "node:vm";
import { createRequire } from "node:module";
import assert from "node:assert/strict";
import { hash } from "./support.mjs";
// Diagnostic-only execution of exact pinned installed component bodies. No server
// response is fabricated, and no installed dependency or real token store is edited.
export async function diagnoseIssuer(root) {
  const bundle = path.join(
    root,
    "node_modules/@modelcontextprotocol/inspector/clients/cli/build/index.js",
  );
  const source = fs.readFileSync(bundle, "utf8");
  const require = createRequire(bundle);
  const core = require("@modelcontextprotocol/core");
  const sdkPath = path.join(
    root,
    "node_modules/@modelcontextprotocol/client/dist/index.mjs",
  );
  const sdk = fs.readFileSync(sdkPath, "utf8");
  function section(text, start, end) {
    const a = text.indexOf(start),
      b = text.indexOf(end, a + start.length);
    assert(a >= 0 && b > a);
    return text.slice(a, b);
  }
  const pieces = [
    section(
      source,
      "function setOwnEntry(",
      "// ../../core/auth/node/secret-store.ts",
    ),
    section(
      source,
      "function withIssuer(",
      "// ../../core/auth/node/storage-node.ts",
    ),
    section(
      source,
      "var MutableRedirectUrlProvider =",
      "// ../../core/auth/challenge.ts",
    ),
    section(
      sdk,
      "function discardIfIssuerMismatch(",
      "/**\n* Type guard distinguishing",
    ),
  ];
  const warnings = [];
  const context = vm.createContext({
    OAuthClientInformationSchema2: core.OAuthClientInformationSchema,
    OAuthTokensSchema4: core.OAuthTokensSchema,
    console: { warn: (m) => warnings.push(m) },
  });
  vm.runInContext(
    pieces.join("\n") +
      "\nglobalThis.components={OAuthStorageBase,OAuthMemoryStore,BaseOAuthClientProvider,discardIfIssuerMismatch};",
    context,
    { timeout: 1000 },
  );
  const {
    OAuthStorageBase,
    OAuthMemoryStore,
    BaseOAuthClientProvider,
    discardIfIssuerMismatch,
  } = context.components;
  let snapshot;
  const backend = {
    read: async () => snapshot,
    write: async (value) => {
      snapshot = structuredClone(value);
    },
  };
  const storage = new OAuthStorageBase(new OAuthMemoryStore(), backend);
  const resource = "https://fixture.test/apps/mcp/";
  const issuerA = "https://issuer-a.test",
    issuerB = "https://issuer-b.test";
  const provider = new BaseOAuthClientProvider(resource, { storage });
  await storage.savePreregisteredClientInformation(resource, {
    client_id: "synthetic-public-client",
  });
  await provider.saveClientInformation(
    { client_id: "synthetic-public-client", issuer: issuerA },
    { issuer: issuerA },
  );
  const registration = await provider.clientInformation({ issuer: issuerA });
  assert.equal(registration.issuer, undefined);
  discardIfIssuerMismatch(registration, issuerA);
  assert.equal(warnings.length, 1);
  assert.match(warnings[0], /SEP-2352/);
  await provider.saveTokens(
    {
      access_token: "synthetic-not-a-credential",
      token_type: "Bearer",
      issuer: issuerA,
    },
    { issuer: issuerA },
  );
  assert.equal((await provider.tokens({ issuer: issuerA })).issuer, issuerA);
  assert.equal(await provider.tokens({ issuer: issuerB }), undefined);
  assert.equal(
    discardIfIssuerMismatch(
      await provider.tokens({ issuer: issuerA }),
      issuerB,
    ),
    undefined,
  );
  const reloaded = new OAuthStorageBase(new OAuthMemoryStore(), backend);
  assert.equal((await reloaded.getTokens(resource, issuerA)).issuer, issuerA);
  assert.equal(await reloaded.getTokens(resource, issuerB), undefined);
  return {
    status: "passed",
    sourceSha256: hash(source),
    sdkSha256: hash(sdk),
    componentSha256: pieces.map(hash),
    syntheticOnly: true,
    preregisteredClientReadUnstamped: true,
    warningReproduced: true,
    issuerBoundTokenRoundtrip: true,
    mismatchedIssuerTokenRefused: true,
    scope:
      "Exact installed provider/storage/SDK component diagnostic with synthetic credentials and in-memory persistence; not a hosted multi-issuer end-to-end certification.",
  };
}
