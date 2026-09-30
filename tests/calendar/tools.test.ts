import { describe, it, expect, vi } from "vitest";
import { readFileSync } from "node:fs";
import { listCalendars, listEvents } from "../../src/capabilities/calendar/tools.js";
import { NextcloudClient } from "../../src/core/nextcloudClient.js";
import type { NcConfig } from "../../src/config.js";

const cfg: NcConfig = { baseUrl: "https://nc.example.com", username: "alice", appPassword: "p", timeoutMs: 5000, maxReadChars: 1000, maxReadBytes: 20 * 1024 * 1024 };
const calendars = readFileSync(new URL("../fixtures/calendars.xml", import.meta.url), "utf8");
const events = readFileSync(new URL("../fixtures/events.xml", import.meta.url), "utf8");
const recurring = readFileSync(new URL("../fixtures/recurring-events.xml", import.meta.url), "utf8");

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
  it("queries every visible calendar when none is specified", async () => {
    const fetchFn = vi.fn(async (_url: string, init: RequestInit) =>
      new Response(init.method === "PROPFIND" ? calendars : events, { status: 207 }));
    const result = await listEvents(new NextcloudClient(cfg, fetchFn as any), undefined, "2026-09-30", "2026-10-01");
    expect(result).toHaveLength(2);
    expect(fetchFn.mock.calls.filter(c => c[1].method === "REPORT")).toHaveLength(2);
  });
  it("rejects invalid or reversed date windows", async () => {
    const client = new NextcloudClient(cfg, vi.fn() as any);
    await expect(listEvents(client, undefined, "2026-02-30", "2026-03-01")).rejects.toThrow(/data|ISO/i);
    await expect(listEvents(client, undefined, "2026-10-01", "2026-09-30")).rejects.toThrow(/from.*to/i);
  });
  it("expands recurrence, excludes EXDATE, applies override, and retains all-day and TZ", async () => {
    const client = new NextcloudClient(cfg, async () => new Response(recurring, { status: 207 }));
    const result = await listEvents(client, "/remote.php/dav/calendars/alice/personal/", "2026-09-08", "2026-09-30");
    expect(result.filter(e => e.uid === "weekly")).toHaveLength(3);
    expect(result.some(e => e.start === "2026-09-15T12:00:00.000Z")).toBe(false);
    expect(result.find(e => e.summary === "Moved")?.start).toBe("2026-09-22T14:00:00.000Z");
    expect(result.find(e => e.summary === "Weekly meeting")?.timeZone).toBe("America/Sao_Paulo");
    expect(result.find(e => e.uid === "holiday")).toMatchObject({ start: "2026-09-10", end: "2026-09-11", allDay: true });
  });
  it("uses only the successful propstat and calendar collections", async () => {
    const mixed = calendars.replace('<d:propstat><d:prop>\n      <d:displayname>Pessoal', '<d:propstat><d:prop><d:displayname>Wrong</d:displayname></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat><d:propstat><d:prop>\n      <d:displayname>Pessoal');
    const client = new NextcloudClient(cfg, async () => new Response(mixed, { status: 207 }));
    expect((await listCalendars(client)).map(c => c.name)).toEqual(["Pessoal", "Trabalho"]);
  });
  it("encodes a username containing reserved URL characters", async () => {
    const fetchFn = vi.fn(async () => new Response(calendars, { status: 207 }));
    await listCalendars(new NextcloudClient({ ...cfg, username: "a # %" }, fetchFn as any));
    expect(fetchFn.mock.calls[0][0]).toContain("/calendars/a%20%23%20%25/");
  });
  it("keeps an encoded href usable for REPORT", async () => {
    const encoded = calendars.replaceAll("/calendars/alice/", "/calendars/a%20%23%20%25/");
    const fetchFn = vi.fn(async (_url: string, init: RequestInit) => new Response(init.method === "PROPFIND" ? encoded : events, { status: 207 }));
    await listEvents(new NextcloudClient({ ...cfg, username: "a # %" }, fetchFn as any), undefined, "2026-09-30", "2026-10-01");
    expect(fetchFn.mock.calls.filter(c => c[1].method === "REPORT").every(c => String(c[0]).includes("a%20%23%20%25"))).toBe(true);
  });
  it("retains TZID for a non-recurring event with node-ical 0.26", async () => {
    const data = events.replace('DTSTART:20260930T130000Z', 'DTSTART;TZID=America/Sao_Paulo:20260930T100000')
      .replace('DTEND:20260930T140000Z', 'DTEND;TZID=America/Sao_Paulo:20260930T110000');
    const client = new NextcloudClient(cfg, async () => new Response(data, { status: 207 }));
    const result = await listEvents(client, "/remote.php/dav/calendars/alice/personal/", "2026-09-30", "2026-10-01");
    expect(result[0]).toMatchObject({ start: "2026-09-30T13:00:00.000Z", timeZone: "America/Sao_Paulo" });
  });
  it("rejects a calendar window longer than one year before REPORT", async () => {
    const fetchFn = vi.fn();
    const client = new NextcloudClient(cfg, fetchFn as any);
    await expect(listEvents(client, "/remote.php/dav/calendars/alice/personal/", "2026-01-01", "2027-02-01"))
      .rejects.toThrow(/janela|intervalo|limite/i);
    expect(fetchFn).not.toHaveBeenCalled();
  });
  it("caps dense SECONDLY recurrences instead of returning thousands of events", async () => {
    const dense = events.replace('DTSTART:20260930T130000Z', 'DTSTART:20260930T000000Z')
      .replace('DTEND:20260930T140000Z', 'DTEND:20260930T000001Z')
      .replace('LOCATION:Sala 1', 'RRULE:FREQ=SECONDLY\nLOCATION:Sala 1');
    const client = new NextcloudClient(cfg, async () => new Response(dense, { status: 207 }));
    await expect(listEvents(client, "/remote.php/dav/calendars/alice/personal/", "2026-09-30", "2026-10-01"))
      .rejects.toThrow(/ocorrência|limite/i);
  });
  it("preserves civil duration of recurring all-day events across DST", async () => {
    const originalTZ = process.env.TZ;
    process.env.TZ = "America/New_York";
    try {
      const multi = events.replace('DTSTART:20260930T130000Z', 'DTSTART;VALUE=DATE:20260307')
        .replace('DTEND:20260930T140000Z', 'DTEND;VALUE=DATE:20260310')
        .replace('LOCATION:Sala 1', 'RRULE:FREQ=WEEKLY;COUNT=2\nLOCATION:Sala 1');
      const client = new NextcloudClient(cfg, async () => new Response(multi, { status: 207 }));
      const result = await listEvents(client, "/remote.php/dav/calendars/alice/personal/", "2026-03-14", "2026-03-18");
      expect(result).toHaveLength(1);
      expect(result[0]).toMatchObject({ start: "2026-03-14", end: "2026-03-17", allDay: true });
    } finally { process.env.TZ = originalTZ; }
  });
});
