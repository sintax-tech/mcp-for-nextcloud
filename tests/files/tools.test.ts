import { describe, it, expect, vi } from "vitest";
import { readFileSync } from "node:fs";
import { listFiles, searchFiles } from "../../src/capabilities/files/tools.js";
import { NextcloudClient } from "../../src/core/nextcloudClient.js";
import type { NcConfig } from "../../src/config.js";

const cfg: NcConfig = { baseUrl: "https://nc.example.com", username: "alice", appPassword: "p", timeoutMs: 5000, maxReadChars: 1000 };
const propfind = readFileSync(new URL("../fixtures/propfind.xml", import.meta.url), "utf8");

describe("files listFiles", () => {
  it("PROPFINDs the requested path (depth 1) and returns entries", async () => {
    const fetchFn = vi.fn(async () => new Response(propfind, { status: 207 }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    const entries = await listFiles(client, "/");
    expect(entries.map(e => e.name).sort()).toEqual(["Documentos", "relatorio.pdf"]);
    const [url, init] = fetchFn.mock.calls[0];
    expect(String(url)).toContain("/remote.php/dav/files/alice");
    expect(init.method).toBe("PROPFIND");
    expect(init.headers["Depth"]).toBe("1");
  });
});

describe("files searchFiles", () => {
  it("issues a SEARCH and caps results at the limit", async () => {
    const fetchFn = vi.fn(async () => new Response(propfind, { status: 207 }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    const entries = await searchFiles(client, "relat", 1);
    expect(entries).toHaveLength(1);
    expect(fetchFn.mock.calls[0][1].method).toBe("SEARCH");
  });
});

describe("files access control (Review Focus)", () => {
  it("listFiles surfaces a 403 as an NcError (FORBIDDEN), not a crash", async () => {
    const fetchFn = vi.fn(async () => new Response("no", { status: 403 }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    await expect(listFiles(client, "/segredo")).rejects.toMatchObject({ code: "FORBIDDEN" });
  });
});
