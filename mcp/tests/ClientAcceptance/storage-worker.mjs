// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from "node:fs";
import path from "node:path";
import { pathToFileURL } from "node:url";
import assert from "node:assert/strict";
import { save } from "./support.mjs";
// This worker never speaks OAuth or opens a listener. Each invocation imports the
// exact installed bundle with an export-only suffix in an isolated process.
const [state, action] = process.argv.slice(2);
process.umask(0o077);
const detail = {};
try {
  const { NodeOAuthStorage, FileSecretStore, BaseOAuthClientProvider } =
    await import(pathToFileURL(path.join(state, "installed-components.mjs")));
  const fixture = JSON.parse(
    fs.readFileSync(path.join(state, "fixture.private.json")),
  );
  const resource = "https://resource.test/apps/mcp/";
  const A = "https://issuer-a.test",
    B = "https://issuer-b.test",
    C = "https://issuer-c.test";
  let dir = path.join(state, "store");
  if (["wrong-key", "missing-key", "tampered-store"].includes(action)) {
    const copy = path.join(state, action);
    fs.cpSync(dir, copy, { recursive: true, errorOnExist: true, force: false });
    dir = copy;
  }
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  const secretPath = path.join(dir, "secrets.json");
  if (action === "wrong-key")
    process.env.MCP_INSPECTOR_SECRET_KEY_FILE = path.join(
      state,
      "wrong-key.private",
    );
  if (action === "missing-key")
    delete process.env.MCP_INSPECTOR_SECRET_KEY_FILE;
  if (action === "tampered-store") {
    const envelope = JSON.parse(fs.readFileSync(secretPath));
    const parts = envelope.data.split(".");
    const bytes = Buffer.from(parts[2], "base64");
    bytes[0] ^= 1;
    parts[2] = bytes.toString("base64");
    envelope.data = parts.join(".");
    save(secretPath, envelope);
  }
  const secrets = new FileSecretStore({ filePath: secretPath });
  const storage = new NodeOAuthStorage(path.join(dir, "oauth.json"), secrets);
  const provider = new BaseOAuthClientProvider(resource, { storage });
  async function write(label, issuer) {
    await provider.saveClientInformation(
      { client_id: label, client_secret: fixture[label].client },
      { issuer, registrationKind: "dcr" },
    );
    await provider.saveTokens(
      {
        access_token: fixture[label].access,
        refresh_token: fixture[label].refresh,
        token_type: "Bearer",
      },
      { issuer },
    );
  }
  async function read(label, issuer) {
    const tokens = await provider.tokens({ issuer });
    assert.equal(tokens?.issuer, issuer);
    assert.equal(tokens?.access_token, fixture[label].access);
    assert.equal(tokens?.refresh_token, fixture[label].refresh);
    const client = await provider.clientInformation({ issuer });
    assert.equal(client?.issuer, issuer);
    assert.equal(client?.client_secret, fixture[label].client);
  }
  async function absent(issuer) {
    assert.equal(await provider.tokens({ issuer }), undefined);
    assert.equal(await provider.clientInformation({ issuer }), undefined);
  }
  async function disk() {
    assert.deepEqual(await secrets.readOnDiskEncryption(), {
      state: "encrypted",
    });
    const envelope = JSON.parse(fs.readFileSync(secretPath));
    assert.equal(envelope.encryption, "aes-256-gcm");
    assert.equal(envelope.kdf.algorithm, "scrypt");
    for (const file of fs.readdirSync(dir)) {
      const p = path.join(dir, file);
      if (!fs.statSync(p).isFile()) continue;
      assert.equal(fs.statSync(p).mode & 0o077, 0);
      const bytes = fs.readFileSync(p, "utf8");
      for (const entry of Object.values(fixture))
        for (const value of Object.values(entry))
          assert(
            !bytes.includes(value),
            "Synthetic secret appeared in plaintext",
          );
    }
    Object.assign(detail, {
      aes256Gcm: true,
      scrypt: true,
      plaintextSecretsAbsent: true,
      privateFileModes: true,
    });
  }
  switch (action) {
    case "seed-a":
      await write("a", A);
      await disk();
      break;
    case "read-a":
      await read("a", A);
      await absent(B);
      await disk();
      detail.freshProcessReload = true;
      break;
    case "seed-b":
      await write("b", B);
      await read("a", A);
      await disk();
      break;
    case "read-both":
      await read("a", A);
      await read("b", B);
      await absent(C);
      await disk();
      detail.freshProcessReload = true;
      break;
    case "rotate-a":
      await write("a2", A);
      await disk();
      break;
    case "read-rotated":
      await read("a2", A);
      await read("b", B);
      await disk();
      detail.otherIssuerPreserved = true;
      break;
    case "clear-a":
      await storage.clearTokens(resource, A);
      await storage.clearClientInformation(resource, false, A);
      await disk();
      break;
    case "read-cleared":
    case "read-after-controls":
      await absent(A);
      await read("b", B);
      await disk();
      detail.otherIssuerPreserved = true;
      break;
    case "wrong-key":
    case "missing-key":
    case "tampered-store": {
      const before = fs.readFileSync(secretPath);
      assert.equal((await secrets.readOnDiskEncryption()).state, "unreadable");
      // The integrated backend rejects unreadable state instead of returning
      // empty credentials. Require its exact error, not an arbitrary rejection.
      await assert.rejects(provider.tokens({ issuer: B }), {
        name: "SecretFileKeyMismatchError",
      });
      await assert.rejects(provider.clientInformation({ issuer: B }), {
        name: "SecretFileKeyMismatchError",
      });
      await assert.rejects(
        provider.saveTokens(
          { access_token: fixture.a.access, token_type: "Bearer" },
          { issuer: B },
        ),
        { name: "SecretFileKeyMismatchError" },
      );
      detail.credentialsUnavailable = true;
      detail.providerReadAndWriteRefused = true;
      await assert.rejects(
        secrets.set("synthetic-write-probe", "secret", fixture.a.access),
        { name: "SecretFileKeyMismatchError" },
      );
      await assert.rejects(
        secrets.deleteAllForServer("synthetic-write-probe"),
        { name: "SecretFileKeyMismatchError" },
      );
      assert.deepEqual(fs.readFileSync(secretPath), before);
      Object.assign(detail, {
        writeAndDeleteRefused: true,
        encryptedBytesUnchanged: true,
      });
      break;
    }
    case "static-client-boundary": {
      // Persist a static registration, then read it in a separate worker below.
      await storage.savePreregisteredClientInformation(resource, {
        client_id: "synthetic-static-client",
        client_secret: fixture.static.client,
      });
      await disk();
      break;
    }
    case "static-client-reload": {
      const a = await provider.clientInformation({ issuer: A });
      const b = await provider.clientInformation({ issuer: B });
      assert.equal(a?.client_secret, fixture.static.client);
      detail.sameStaticSecretReturnedForOtherIssuer =
        b?.client_secret === fixture.static.client;
      detail.staticRegistrationHasIssuer = a?.issuer !== undefined;
      detail.freshProcessReload = true;
      await disk();
      assert.equal(
        b?.client_secret,
        undefined,
        "Static credential was not isolated by requested issuer",
      );
      break;
    }
    default:
      throw new Error("Unknown required storage case");
  }
  save(path.join(state, action + ".result.json"), {
    id: action,
    status: "passed",
    detail,
  });
} catch (error) {
  fs.writeFileSync(
    path.join(state, action + ".error.private.txt"),
    String(error.stack ?? error),
    { mode: 0o600 },
  );
  save(path.join(state, action + ".result.json"), {
    id: action,
    status: "failed",
    errorType: error.name,
    detail,
  });
  process.exitCode = 1;
}
