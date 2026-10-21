// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import assert from "node:assert/strict";
import { processRun, save, hash, resultJson } from "./support.mjs";
export class Inspector {
  constructor(root, state, ca, origin) {
    this.root = root;
    this.state = state;
    this.ca = ca;
    this.origin = origin;
    this.sequence = 0;
    this.executable = path.join(root, "node_modules/.bin/mcp-inspector");
    this.key = path.join(state, "oauth-key.private");
    fs.writeFileSync(this.key, crypto.randomBytes(32).toString("hex"), {
      mode: 0o600,
    });
    this.preload = path.join(state, "dns.cjs");
    fs.writeFileSync(
      this.preload,
      "const dns=require('node:dns');const original=dns.lookup;dns.lookup=function(host,options,callback){if(host!=='mcp-p1.test')return original.apply(this,arguments);if(typeof options==='function'){callback=options;options={};}queueMicrotask(()=>callback(null,...(options?.all?[[{address:'127.0.0.1',family:4}]]:['127.0.0.1',4])));};\n",
      { mode: 0o600 },
    );
  }
  env(store) {
    const dir = path.join(this.state, store);
    fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
    const env = {
      ...process.env,
      NO_COLOR: "1",
      TERM: "dumb",
      NODE_EXTRA_CA_CERTS: this.ca,
      NODE_OPTIONS: "--require=" + this.preload,
      MCP_STORAGE_DIR: dir,
      MCP_INSPECTOR_OAUTH_STATE_PATH: path.join(dir, "oauth.json"),
      MCP_CLIENT_CONFIG_PATH: path.join(dir, "client.json"),
      MCP_AUTO_OPEN_ENABLED: "false",
      MCP_INSPECTOR_SECRET_STORE: "file",
      MCP_INSPECTOR_SECRET_KEY_FILE: this.key,
    };
    delete env.MCP_CATALOG_PATH;
    delete env.MCP_INSPECTOR_SECRET_KEY;
    return env;
  }
  async run(
    name,
    args,
    { store = "basic", interactive = false, page, deny = false } = {},
  ) {
    const abort = new AbortController();
    const options = {
      env: this.env(store),
      timeout: interactive ? 300000 : 60000,
      signal: abort.signal,
    };
    let urlSeen;
    let browserError;
    let consent;
    if (interactive)
      options.onData = (text) => {
        const match = text.match(/Please navigate to: (https?:[^\s]+)/);
        if (!match || urlSeen) return;
        urlSeen = match[1];
        consent = (async () => {
          const u = new URL(urlSeen);
          assert.equal(u.origin, this.origin);
          assert.equal(u.searchParams.get("code_challenge_method"), "S256");
          assert.equal(
            u.searchParams.get("redirect_uri"),
            "http://127.0.0.1:6276/oauth/callback",
          );
          await page.goto(urlSeen);
          await page.locator(".mcp-consent").waitFor();
          await page
            .getByRole("button", {
              name: deny ? "Cancel" : "Allow",
              exact: true,
            })
            .click();
        })().catch((e) => {
          browserError = e;
          abort.abort();
        });
      };
    const common = [
      "--cli",
      ...args,
      "--format",
      "json",
      "--connect-timeout",
      interactive ? "240000" : "15000",
    ];
    const quote = (v) => "'" + v.replaceAll("'", "'\\''") + "'";
    const out = interactive
      ? await processRun(
          "script",
          [
            "-qefc",
            [this.executable, ...common].map(quote).join(" "),
            "/dev/null",
          ],
          options,
        )
      : await processRun(this.executable, common, options);
    await consent;
    const prefix = path.join(
      this.state,
      String(++this.sequence).padStart(3, "0") + "-" + name,
    );
    fs.writeFileSync(prefix + ".stdout", out.stdout, { mode: 0o600 });
    fs.writeFileSync(prefix + ".stderr", out.stderr, { mode: 0o600 });
    save(prefix + ".json", {
      name,
      exitCode: out.code,
      timedOut: out.timedOut,
      stdoutSha256: hash(out.stdout),
      stderrSha256: hash(out.stderr),
      pty: interactive,
      authorizationUrlObserved: !!urlSeen,
      browserFailed: !!browserError,
    });
    if (browserError) throw browserError;
    assert.equal(out.timedOut, false, "Inspector timed out");
    return out;
  }
  basic(name, method = "initialize", tool, args = {}) {
    const command = [
      "--config",
      path.join(this.state, "basic.private.json"),
      "--server",
      "nextcloud",
      "--method",
      method,
    ];
    if (tool)
      command.push(
        "--tool-name",
        tool,
        "--tool-args-json",
        JSON.stringify(args),
      );
    return this.run(name, command);
  }
  oauth(name, id, store = "oauth", options = {}) {
    return this.run(
      name,
      [
        "--server-url",
        this.origin + "/apps/mcp/",
        "--transport",
        "http",
        "--method",
        options.tool ? "tools/call" : "tools/list",
        ...(options.tool
          ? [
              "--tool-name",
              options.tool,
              "--tool-args-json",
              JSON.stringify(options.args ?? {}),
            ]
          : []),
        "--client-id",
        id,
        "--callback-url",
        "http://127.0.0.1:6276/oauth/callback",
        ...(options.interactive ? [] : ["--stored-auth-only"]),
      ],
      { store, ...options },
    );
  }
  async success(out) {
    assert.equal(out.code, 0);
    return resultJson(out.stdout);
  }
}
