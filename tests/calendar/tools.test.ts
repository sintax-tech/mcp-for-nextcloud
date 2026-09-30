import { describe, it, expect, vi } from "vitest";
import { readFileSync } from "node:fs";
import { listCalendars, listEvents } from "../../src/capabilities/calendar/tools.js";
import { NextcloudClient } from "../../src/core/nextcloudClient.js";
import type { NcConfig } from "../../src/config.js";

const cfg: NcConfig = { baseUrl: "https://nc.example.com", username: "alice", appPassword: "p", timeoutMs: 5000, maxReadChars: 1000 };
const calendars = readFileSync(new URL("../fixtures/calendars.xml", import.meta.url), "utf8");
const events = readFileSync(new URL("../fixtures/events.xml", import.meta.url), "utf8");

describe("calendar", () => {
  it("listCalendars returns calendars with display names", async () => {
    const fetchFn = vi.fn(async () => new Response(calendars, { status: 207 }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    const cals = await listCalendars(client);
    expect(cals.map(c => c.name).sort()).toEqual(["Pessoal", "Trabalho"]);
    expect(fetchFn.mock.calls[0][1].method).toBe("PROPFIND");
  });
  it("listEvents parses VEVENT summary/start/end via a REPORT", async () => {
    const fetchFn = vi.fn(async () => new Response(events, { status: 207 }));
    const client = new NextcloudClient(cfg, fetchFn as any);
    const evs = await listEvents(client, "/remote.php/dav/calendars/alice/personal/", "2026-09-30", "2026-10-01");
    expect(evs[0].summary).toBe("Reunião de diretoria");
    expect(evs[0].location).toBe("Sala 1");
    expect(fetchFn.mock.calls[0][1].method).toBe("REPORT");
  });
});
