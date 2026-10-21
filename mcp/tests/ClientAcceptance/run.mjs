// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from "node:fs";
import path from "node:path";
import https from "node:https";
import http from "node:http";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { reconcileNamedToken } from "./cleanup.mjs";
import { Runtime } from "./runtime.mjs";
import { Inspector } from "./inspector.mjs";
import { Browser } from "./browser.mjs";
import { startProxy } from "./proxy.mjs";
import { command, save, hash, resultJson } from "./support.mjs";
import { verdict } from "./policy.mjs";
import { diagnoseIssuer } from "./issuer-diagnostic.mjs";
const args = process.argv.slice(2);
if (args.length !== 3) {
  console.error(
    "Usage: composer test:clients -- ABSOLUTE_FIXTURE_DIR ABSOLUTE_INSPECTOR_DIR ABSOLUTE_CERTUTIL",
  );
  process.exit(2);
}
for (const arg of args) assert(path.isAbsolute(arg));
process.umask(0o077);
const [fixture, inspectorRoot, certutil] = args;
const stateRoot = path.join(
  process.env.XDG_STATE_HOME ?? path.join(process.env.HOME, ".local/state"),
  "mcp-client-tests",
);
fs.mkdirSync(stateRoot, { recursive: true, mode: 0o700 });
const state = fs.mkdtempSync(path.join(stateRoot, "p1-"));
const origin = "https://mcp-p1.test:18443";
const hostname = "mcp-p1.test:18443";
const ca = path.join(state, "ca.pem");
const required = [
  "preflight",
  "private-trust",
  "tls-boundary",
  "basic-roundtrip",
  "oauth-consent",
  "callback-refusal",
  "consent-denial",
  "client-withdrawal",
  "basic-disconnect",
  "native-password-revocation",
  "issuer-isolation",
  "closure",
];
let interrupted = false;
const results = [];
const runtime = new Runtime(fixture, state);
let browser, proxy, inspector, tokenId, tokenName;
const denied = new Set();
const record = {
  schemaVersion: 1,
  candidate: "b96884631bf9bda245ce9895facfa8380ab08998",
  startedAt: new Date().toISOString(),
  state,
  origin,
  results,
  required,
  chatgpt: "pending",
  humanReviews: "pending",
  publicExposure: false,
  egressAdded: false,
};
record.runnerSources = Object.fromEntries(
  fs
    .readdirSync(new URL(".", import.meta.url))
    .filter((n) => n.endsWith(".mjs"))
    .map((n) => [n, hash(fs.readFileSync(new URL(n, import.meta.url)))]),
);
const persist = () => save(path.join(state, "result.json"), record);
fs.writeFileSync(
  path.join(state, "ledger.md"),
  "# P0/P1 execution ledger\n\nRuntime is private and application candidate remains frozen.\nRuling: reuse retained isolated fixture and pinned Inspector; do not rerun the unrelated native matrix.\n",
);
async function check(id, fn) {
  const start = Date.now();
  try {
    if (interrupted) throw new Error("Runner interrupted");
    const detail = await fn();
    if (interrupted) throw new Error("Runner interrupted");
    results.push({
      id,
      status: "passed",
      milliseconds: Date.now() - start,
      ...detail,
    });
    console.log(id + ": PASS");
    return true;
  } catch (e) {
    fs.writeFileSync(
      path.join(state, id + ".error.private.txt"),
      String(e.stack ?? e),
      { mode: 0o600 },
    );
    results.push({
      id,
      status: "failed",
      milliseconds: Date.now() - start,
      errorType: e.name,
    });
    console.log(id + ": FAIL (private diagnostic retained)");
    return false;
  } finally {
    persist();
  }
}
function request(target, { method = "GET", headers = {}, body } = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(target);
    const tls = u.protocol === "https:";
    const req = (tls ? https : http).request(
      u,
      {
        method,
        headers,
        ...(tls
          ? {
              ca: fs.readFileSync(ca),
              servername: u.hostname,
              lookup: (_h, _o, cb) =>
                cb(null, [{ address: "127.0.0.1", family: 4 }]),
            }
          : {}),
        timeout: 15000,
      },
      (res) => {
        let data = "";
        res.on("data", (c) => (data += c));
        res.on("end", () =>
          resolve({ status: res.statusCode, headers: res.headers, body: data }),
        );
      },
    );
    req.on("error", reject);
    req.on("timeout", () => req.destroy(new Error("Request deadline")));
    req.end(body);
  });
}
let basicHeader;
async function dav(method, name, body) {
  return request(
    "http://127.0.0.1:18080/remote.php/dav/files/ui-firefox/" + name,
    {
      method,
      body,
      headers: { Authorization: basicHeader, "Content-Type": "text/plain" },
    },
  );
}
const target = "/P1-" + path.basename(state) + ".txt";
const abort = () => {
  throw new Error("Required prerequisite failed");
};
for (const signal of ["SIGTERM", "SIGINT"])
  process.on(signal, () => {
    interrupted = true;
    process.exitCode = 1;
    void browser?.userBrowser?.close().catch(() => {});
  });
try {
  if (
    !(await check("preflight", async () => {
      assert.equal(process.version, "v24.21.0");
      const lock = fs.readFileSync(
        path.join(inspectorRoot, "package-lock.json"),
      );
      assert.equal(
        hash(lock),
        "d6e59c49bd1a5640d33d7d8c77a387e382553762e216173373c0f2af86c704ae",
      );
      const pkg =
        JSON.parse(lock).packages[
          "node_modules/@modelcontextprotocol/inspector"
        ];
      assert.equal(pkg.version, "2.11.0");
      await runtime.prepare();
      return { inspectorVersion: pkg.version, lockSha256: hash(lock) };
    }))
  )
    abort();
  if (
    !(await check("private-trust", async () => {
      command("openssl", [
        "req",
        "-x509",
        "-newkey",
        "rsa:2048",
        "-nodes",
        "-days",
        "1",
        "-keyout",
        state + "/ca.key",
        "-out",
        ca,
        "-subj",
        "/CN=MCP private test CA",
        "-addext",
        "basicConstraints=critical,CA:TRUE",
      ]);
      command("openssl", [
        "req",
        "-new",
        "-newkey",
        "rsa:2048",
        "-nodes",
        "-keyout",
        state + "/server.key",
        "-out",
        state + "/server.csr",
        "-subj",
        "/CN=mcp-p1.test",
      ]);
      fs.writeFileSync(
        state + "/extensions",
        "subjectAltName=DNS:mcp-p1.test\nbasicConstraints=critical,CA:FALSE\nextendedKeyUsage=serverAuth\n",
      );
      command("openssl", [
        "x509",
        "-req",
        "-in",
        state + "/server.csr",
        "-CA",
        ca,
        "-CAkey",
        state + "/ca.key",
        "-CAcreateserial",
        "-days",
        "1",
        "-out",
        state + "/server.pem",
        "-extfile",
        state + "/extensions",
      ]);
      fs.mkdirSync(state + "/firefox");
      command(certutil, [
        "-N",
        "--empty-password",
        "-d",
        "sql:" + state + "/firefox",
      ]);
      command(certutil, [
        "-A",
        "-d",
        "sql:" + state + "/firefox",
        "-n",
        "MCP private test CA",
        "-t",
        "C,,",
        "-i",
        ca,
      ]);
      proxy = await startProxy({
        key: fs.readFileSync(state + "/server.key"),
        cert: fs.readFileSync(state + "/server.pem"),
        host: hostname,
        port: 18443,
        upstream: 18080,
        denied,
      });
      inspector = new Inspector(inspectorRoot, state, ca, origin);
      const secret = JSON.parse(
        fs.readFileSync(path.join(fixture, "credentials.json")),
      );
      browser = new Browser(secret, state, origin);
      await browser.start();
      await browser.setup();
      await browser.login(browser.user, origin, "ui-firefox", secret.alice);
      return {
        caSha256: hash(fs.readFileSync(ca)),
        certutilSha256: hash(fs.readFileSync(certutil)),
        proxySourceSha256: hash(
          fs.readFileSync(new URL("./proxy.mjs", import.meta.url)),
        ),
      };
    }))
  )
    abort();
  if (
    !(await check("tls-boundary", async () => {
      const challenge = await request(origin + "/apps/mcp/");
      assert.equal(challenge.status, 401);
      assert.match(
        challenge.headers["www-authenticate"],
        new RegExp(
          'resource_metadata="' +
            origin +
            '/apps/mcp/.well-known/oauth-protected-resource"',
        ),
      );
      const meta = await request(
        origin + "/apps/mcp/.well-known/oauth-authorization-server",
        {
          headers: {
            "X-Forwarded-Host": "attacker.invalid",
            "X-Forwarded-Proto": "http",
          },
        },
      );
      assert.equal(meta.status, 200);
      const authorization = JSON.parse(meta.body);
      assert.equal(authorization.issuer, origin + "/apps/mcp");
      assert.equal(
        authorization.authorization_endpoint,
        origin + "/apps/mcp/oauth/authorize",
      );
      assert.equal(
        authorization.token_endpoint,
        origin + "/apps/mcp/oauth/token",
      );
      assert.equal(
        authorization.jwks_uri,
        origin + "/apps/mcp/.well-known/jwks.json",
      );
      const metadataUrl = challenge.headers["www-authenticate"].match(
        /resource_metadata="([^"]+)"/,
      )[1];
      assert.equal(
        metadataUrl,
        origin + "/apps/mcp/.well-known/oauth-protected-resource",
      );
      const resourceReply = await request(metadataUrl);
      assert.equal(resourceReply.status, 200);
      const resource = JSON.parse(resourceReply.body);
      assert.equal(resource.resource, origin + "/apps/mcp/");
      assert.deepEqual(resource.authorization_servers, [authorization.issuer]);
      const oidc = await request(
        origin + "/apps/mcp/.well-known/openid-configuration",
      );
      assert.equal(oidc.status, 200);
      assert.deepEqual(JSON.parse(oidc.body), authorization);
      const jwks = await request(authorization.jwks_uri);
      assert.equal(jwks.status, 200);
      assert.deepEqual(JSON.parse(jwks.body), { keys: [] });
      for (const p of [
        "/settings/admin/mcp",
        "/apps/mcp/api/grants",
        "/remote.php/dav",
        "/index.php/apps/mcp/",
        "/apps/mcp/%2e%2e/api/grants",
      ])
        assert.equal((await request(origin + p)).status, 403);
      assert.equal(
        (
          await request(origin + "/login", {
            headers: { Host: "attacker.invalid" },
          })
        ).status,
        403,
      );
      // A fresh HTTPS client without the private CA must reject the same endpoint.
      await assert.rejects(
        new Promise((resolve, reject) => {
          https
            .get("https://127.0.0.1:18443/login", (res) => {
              res.resume();
              resolve();
            })
            .on("error", reject);
        }),
        (e) =>
          [
            "UNABLE_TO_VERIFY_LEAF_SIGNATURE",
            "SELF_SIGNED_CERT_IN_CHAIN",
            "DEPTH_ZERO_SELF_SIGNED_CERT",
          ].includes(e.code),
      );
      return {
        metadataIssuer: authorization.issuer,
        metadataResource: resource.resource,
        exactEndpointsVerified: true,
        certificateVerification: true,
      };
    }))
  )
    abort();
  tokenName = "p1-" + path.basename(state);
  const tokenOutput = command(
    "docker",
    [
      "exec",
      "-i",
      "-u",
      "www-data",
      runtime.run.nc,
      "sh",
      "-c",
      "read -r OC_PASS; export OC_PASS; php occ user:auth-tokens:add --password-from-env --name " +
        tokenName +
        " ui-firefox",
    ],
    browser.secret.alice + "\n",
  );
  const password = tokenOutput.trim().split("\n").at(-1);
  assert.match(password, /^[a-zA-Z0-9]{72}$/);
  const tokens = JSON.parse(
    runtime.occ("user:auth-tokens:list", "ui-firefox", "--output=json"),
  );
  const list = Array.isArray(tokens) ? tokens : Object.values(tokens);
  tokenId = list.find((t) => t.name === tokenName)?.id;
  assert(tokenId);
  basicHeader =
    "Basic " + Buffer.from("ui-firefox:" + password).toString("base64");
  save(state + "/basic.private.json", {
    mcpServers: {
      nextcloud: {
        type: "http",
        url: origin + "/apps/mcp/",
        headers: { Authorization: basicHeader },
      },
    },
  });
  assert.equal((await dav("PUT", target.slice(1), "P1 before\n")).status, 201);
  await check("basic-roundtrip", async () => {
    const init = await inspector.success(await inspector.basic("initialize"));
    assert.equal(init.protocolVersion, "2025-06-18");
    const tools = await inspector.success(
      await inspector.basic("tools", "tools/list"),
    );
    assert(tools.tools.some((t) => t.name === "files_edit"));
    for (const method of ["GET", "DELETE"])
      assert.equal(
        (
          await request(origin + "/apps/mcp/", {
            method,
            headers: { Authorization: basicHeader },
          })
        ).status,
        405,
      );
    assert.equal(
      (
        await request(origin + "/apps/mcp/", {
          headers: {
            Authorization: basicHeader,
            Origin: "https://attacker.invalid",
          },
        })
      ).status,
      403,
    );
    const read = await inspector.success(
      await inspector.basic("read", "tools/call", "files_read", {
        path: target,
      }),
    );
    assert.equal(read.content[0].text, "P1 before\n");
    const plan = await inspector.success(
      await inspector.basic("plan", "tools/call", "files_edit", {
        path: target,
        content: "P1 after\n",
      }),
    );
    assert.equal(plan.structuredContent.requiresConfirmation, true);
    assert.equal((await dav("GET", target.slice(1))).body, "P1 before\n");
    const edit = await inspector.success(
      await inspector.basic("confirm", "tools/call", "files_edit", {
        path: target,
        content: "P1 after\n",
        confirm: true,
        etag: plan.structuredContent.etag,
      }),
    );
    assert.equal((await dav("GET", target.slice(1))).body, "P1 after\n");
    const backup = JSON.parse(edit.content[0].text).backup;
    assert.equal((await dav("GET", backup.slice(1))).body, "P1 before\n");
    return {
      protocol: init.protocolVersion,
      tools: tools.tools.length,
      fixture: target,
    };
  });
  if (interrupted) abort();
  const id = await browser.addClient("P1 Inspector " + path.basename(state));
  await check("oauth-consent", async () => {
    const out = await inspector.oauth("oauth-login", id, "oauth", {
      interactive: true,
      page: browser.user,
    });
    const value = await inspector.success(out);
    assert(value.tools.some((t) => t.name === "files_read"));
    const call = (name, tool, args) =>
      inspector.oauth(name, id, "oauth", { tool, args });
    const before = await inspector.success(
      await call("oauth-read", "files_read", { path: target }),
    );
    assert.equal(before.content[0].text, "P1 after\n");
    const plan = await inspector.success(
      await call("oauth-plan", "files_edit", {
        path: target,
        content: "P1 OAuth after\n",
      }),
    );
    assert.equal(plan.structuredContent.requiresConfirmation, true);
    assert.equal((await dav("GET", target.slice(1))).body, "P1 after\n");
    const edit = await inspector.success(
      await call("oauth-confirm", "files_edit", {
        path: target,
        content: "P1 OAuth after\n",
        confirm: true,
        etag: plan.structuredContent.etag,
      }),
    );
    const after = await inspector.success(
      await call("oauth-readback", "files_read", { path: target }),
    );
    assert.equal(after.content[0].text, "P1 OAuth after\n");
    assert.equal((await dav("GET", target.slice(1))).body, "P1 OAuth after\n");
    const backup = JSON.parse(edit.content[0].text).backup;
    assert.equal((await dav("GET", backup.slice(1))).body, "P1 after\n");
    return {
      pkce: "S256",
      exactCallback: true,
      roundtrip: true,
      independentReadbackAndBackup: true,
    };
  });
  await check("callback-refusal", async () => {
    for (const callback of [
      "http://127.0.0.1:6277/oauth/callback",
      "https://attacker.invalid/callback",
    ]) {
      const u = new URL(origin + "/apps/mcp/oauth/authorize");
      for (const [key, v] of Object.entries({
        client_id: id,
        redirect_uri: callback,
        response_type: "code",
        scope: "mcp",
        state: "p1-negative",
        code_challenge: crypto.randomBytes(32).toString("base64url"),
        code_challenge_method: "S256",
        resource: origin + "/apps/mcp/",
      }))
        u.searchParams.set(key, v);
      const reply = await browser.user.goto(u.href);
      assert.equal(reply.status(), 200);
      assert.equal(new URL(browser.user.url()).origin, origin);
      assert.match(
        await browser.user.locator(".mcp-hosts").innerText(),
        /redirect_uri is not registered/,
      );
      assert.equal(
        await browser.user
          .getByRole("button", { name: "Allow", exact: true })
          .count(),
        0,
      );
    }
    return {
      source: "direct HTTP server refusal, not Inspector local validation",
    };
  });
  await check("consent-denial", async () => {
    const out = await inspector.oauth("oauth-denied", id, "denied", {
      interactive: true,
      page: browser.user,
      deny: true,
    });
    assert.notEqual(out.code, 0);
    assert.match(out.stdout + out.stderr, /access_denied|denied/i);
  });
  await check("client-withdrawal", async () => {
    await inspector.success(await inspector.oauth("before-disable", id));
    await browser.changeClient(id, "disable");
    const disabled = await inspector.oauth("disabled", id);
    assert.equal(disabled.code, 3);
    await browser.changeClient(id, "enable");
    assert.equal((await inspector.oauth("reenabled-old", id)).code, 3);
    await inspector.success(
      await inspector.oauth("fresh-consent", id, "fresh", {
        interactive: true,
        page: browser.user,
      }),
    );
    await browser.changeClient(id, "redirect");
    assert.equal(
      (await inspector.oauth("redirect-removed", id, "fresh")).code,
      3,
    );
    await browser.changeClient(id, "restore");
    assert.equal(
      (await inspector.oauth("redirect-restored-old", id, "fresh")).code,
      3,
    );
    await inspector.success(
      await inspector.oauth("fresh-restored", id, "restored", {
        interactive: true,
        page: browser.user,
      }),
    );
    await browser.changeClient(id, "delete");
    assert.equal(
      (await inspector.oauth("client-deleted", id, "restored")).code,
      3,
    );
  });
  await check("basic-disconnect", async () => {
    await browser.connect();
    await inspector.success(await inspector.basic("before-disconnect"));
    await browser.disconnect();
    assert.equal((await inspector.basic("disconnected")).code, 3);
    assert.equal((await dav("GET", target.slice(1))).status, 200);
    await browser.connect();
    await inspector.success(await inspector.basic("after-reconnect"));
  });
  await check("native-password-revocation", async () => {
    await browser.connect();
    await inspector.success(await inspector.basic("before-password-revoke"));
    assert.equal((await dav("GET", target.slice(1))).status, 200);
    const deletedId = tokenId;
    runtime.occ("user:auth-tokens:delete", "ui-firefox", String(deletedId));
    tokenId = null;
    const remaining = JSON.parse(
      runtime.occ("user:auth-tokens:list", "ui-firefox", "--output=json"),
    );
    assert(!Object.values(remaining).some((t) => t.id === deletedId));
    const immediate = (await dav("GET", target.slice(1))).status;
    assert([200, 401].includes(immediate));
    // Native NC35 caches tokens locally for ten seconds; occ has a separate cache.
    // Preserve the immediate observation, then verify the documented bounded cutoff.
    if (immediate === 200)
      await new Promise((resolve) => setTimeout(resolve, 11000));
    const after = (await dav("GET", target.slice(1))).status;
    assert.equal(after, 401);
    assert.equal((await inspector.basic("password-revoked")).code, 3);
    return {
      immediateDavStatus: immediate,
      afterNativeCacheTtlDavStatus: after,
      nativeTokenCacheTtlSeconds: 10,
      immediateRevocationClaimed: immediate === 401,
    };
  });
  await check("issuer-isolation", async () => diagnoseIssuer(inspectorRoot));
} catch (e) {
  fs.writeFileSync(
    path.join(state, "runner.error.private.txt"),
    String(e.stack ?? e),
    { mode: 0o600 },
  );
  console.log("Runner stopped at prerequisite; missing cases remain blocked.");
} finally {
  const failures = [];
  try {
    if (browser) failures.push(...(await browser.close()));
  } catch {
    failures.push("browser-cleanup");
  }
  if (tokenName)
    try {
      reconcileNamedToken(runtime, tokenName);
    } catch {
      failures.push("native-token-cleanup");
    }
  proxy?.closeAllConnections();
  if (proxy) await new Promise((resolve) => proxy.close(resolve));
  let closure;
  try {
    closure = await runtime.close();
  } catch {
    closure = { status: "failed", errors: ["runtime-close"] };
  }
  results.push({
    id: "closure",
    ...closure,
    status:
      failures.length || closure.status !== "passed" ? "failed" : "passed",
    cleanupFailures: failures,
  });
  for (const id of required)
    if (!results.some((r) => r.id === id))
      results.push({
        id,
        status: "blocked",
        reason: "Required prerequisite did not complete",
      });
  save(path.join(state, "denied-paths.json"), [...denied].sort());
  record.finishedAt = new Date().toISOString();
  record.status = verdict(required, results) ? "passed" : "failed";
  persist();
  console.log(
    "P0/P1 " + record.status.toUpperCase() + "; private evidence: " + state,
  );
  if (record.status !== "passed") process.exitCode = 1;
}
