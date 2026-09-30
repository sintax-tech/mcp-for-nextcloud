import { describe, it, expect, vi } from "vitest";
import { NextcloudClient } from "../../src/core/nextcloudClient.js";
import { NcError } from "../../src/core/errors.js";
import type { NcConfig } from "../../src/config.js";

const cfg: NcConfig = {
  baseUrl: "https://nc.example.com",
  username: "alice",
  appPassword: "secret-pass",
  timeoutMs: 5000,
  maxReadChars: 1000,
};

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
}

describe("NextcloudClient", () => {
  it("sends Basic auth built from user:appPassword", async () => {
    const fetchFn = vi.fn(async (_url: any, _init: any) => jsonResponse({ ok: true }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    await client.request("/foo");
    const init = fetchFn.mock.calls[0][1];
    const expected = "Basic " + Buffer.from("alice:secret-pass").toString("base64");
    expect(init.headers["Authorization"]).toBe(expected);
  });

  it("ocsGet sets OCS headers and unwraps ocs.data", async () => {
    const fetchFn = vi.fn(async () => jsonResponse({ ocs: { data: [{ id: 1 }] } }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    const data = await client.ocsGet("/ocs/v2.php/apps/spreed/api/v4/room");
    expect(data).toEqual([{ id: 1 }]);
    const init = fetchFn.mock.calls[0][1];
    expect(init.headers["OCS-APIRequest"]).toBe("true");
    expect(init.headers["Accept"]).toBe("application/json");
  });

  it("maps 401 to an NcError whose message never contains the password", async () => {
    const fetchFn = vi.fn(async () => new Response("nope", { status: 401 }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    await expect(client.ocsGet("/x")).rejects.toSatisfy((e: unknown) => {
      return e instanceof NcError && e.code === "AUTH" && !(e as Error).message.includes("secret-pass");
    });
  });

  it("webdavFilesRoot returns the per-user dav path", () => {
    const client = new NextcloudClient(cfg, (async () => jsonResponse({})) as any);
    expect(client.webdavFilesRoot()).toBe("/remote.php/dav/files/alice");
  });
});
