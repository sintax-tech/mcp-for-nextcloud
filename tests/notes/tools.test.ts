import { describe, it, expect, vi } from "vitest";
import { listNotes, readNote } from "../../src/capabilities/notes/tools.js";
import { NextcloudClient } from "../../src/core/nextcloudClient.js";
import type { NcConfig } from "../../src/config.js";

const cfg: NcConfig = { baseUrl: "https://nc.example.com", username: "alice", appPassword: "p", timeoutMs: 5000, maxReadChars: 1000, maxReadBytes: 20 * 1024 * 1024 };
function json(body: unknown, status = 200) { return new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } }); }

describe("notes", () => {
  it("listNotes hits the notes API and returns the array", async () => {
    const fetchFn = vi.fn(async () => json([{ id: 7, title: "Ata", category: "Reuniões", modified: 1 }]));
    const client = new NextcloudClient(cfg, fetchFn as any);
    const notes = await listNotes(client);
    expect(notes[0].title).toBe("Ata");
    expect(String(fetchFn.mock.calls[0][0])).toContain("/index.php/apps/notes/api/v1/notes");
  });
  it("readNote fetches a single note by id", async () => {
    const fetchFn = vi.fn(async () => json({ id: 7, title: "Ata", content: "decisões..." }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    expect((await readNote(client, 7)).content).toBe("decisões...");
    expect(String(fetchFn.mock.calls[0][0])).toContain("/notes/7");
  });
});
