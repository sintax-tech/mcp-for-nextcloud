import { z } from "zod";
import { XMLParser } from "fast-xml-parser";
import ical from "node-ical";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { NextcloudClient } from "../../core/nextcloudClient.js";
import { successfulProp, safeDecode } from "../../core/xml.js";
import { wrap } from "../files/tools.js";
import { NcError } from "../../core/errors.js";

const parser = new XMLParser({ ignoreAttributes: true, removeNSPrefix: true });
function asArray<T>(v: T | T[] | undefined): T[] { return v === undefined ? [] : Array.isArray(v) ? v : [v]; }

export interface CalInfo { name: string; path: string; }
export interface EventInfo { uid: string; summary: string; start: string; end: string; location: string; allDay: boolean; timeZone?: string; }

export async function listCalendars(client: NextcloudClient): Promise<CalInfo[]> {
  const calRoot = `/remote.php/dav/calendars/${encodeURIComponent(client.username())}`;
  const xml = await client.dav("PROPFIND", `${calRoot}/`, undefined, { Depth: "1" });
  const doc = parser.parse(xml);
  const out: CalInfo[] = [];
  for (const r of asArray<any>(doc?.multistatus?.response)) {
    const rawHref = String(r.href ?? "");
    const href = safeDecode(rawHref);
    const prop = successfulProp(r);
    const name = prop?.displayname;
    const rt = prop?.resourcetype;
    if (name && rt && typeof rt === "object" && "calendar" in rt && href.replace(/\/+$/, "") !== safeDecode(calRoot))
      out.push({ name: String(name), path: rawHref });
  }
  return out;
}

function parseDate(input: string, label: string): Date {
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(input);
  const timestamp = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,3})?)?(?:Z|[+-]\d{2}:\d{2})$/i.test(input);
  const date = new Date(input);
  const parts = /^(\d{4})-(\d{2})-(\d{2})/.exec(input);
  const validDay = parts && new Date(Date.UTC(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]))).toISOString().slice(0, 10) === parts[0];
  if ((!dateOnly && !timestamp) || !validDay || Number.isNaN(date.getTime()))
    throw new NcError("INVALID_DATE", `Data ISO inválida em ${label}.`);
  return date;
}

function localDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function eventInfo(e: any, start: Date, end: Date, timeZone?: string): EventInfo {
  const allDay = e.datetype === "date";
  const tz = timeZone ?? (e.start as any)?.tz ?? e.rrule?.origOptions?.tzid;
  return {
    uid: String(e.uid ?? ""), summary: String(e.summary ?? ""),
    start: allDay ? localDate(start) : start.toISOString(),
    end: allDay ? localDate(end) : end.toISOString(),
    location: String(e.location ?? ""), allDay,
    ...(tz && !allDay ? { timeZone: String(tz) } : {}),
  };
}

function eventsInWindow(e: any, from: Date, to: Date, timeZone?: string): EventInfo[] {
  const start = e.start instanceof Date ? e.start : undefined;
  const end = e.end instanceof Date ? e.end : start;
  if (!start || !end) return [];
  const duration = end.getTime() - start.getTime();
  const overlaps = (a: Date, b: Date) => a < to && b > from;
  if (!e.rrule) return overlaps(start, end) ? [eventInfo(e, start, end, timeZone)] : [];
  const exclusions = new Set(Object.values(e.exdate ?? {}).map((d: any) => new Date(d).getTime()));
  const overrides = [...new Map((Object.values(e.recurrences ?? {}) as any[])
    .map(o => [new Date(o.recurrenceid).getTime(), o] as const)).values()];
  const overridden = new Set(overrides.map(o => new Date(o.recurrenceid).getTime()));
  const occurrences: EventInfo[] = [];
  const begin = new Date(from.getTime() - Math.max(duration, 0));
  for (const date of e.rrule.between(begin, to, true) as Date[]) {
    const time = date.getTime();
    if (exclusions.has(time) || overridden.has(time)) continue;
    const occurrenceEnd = new Date(time + duration);
    if (overlaps(date, occurrenceEnd)) occurrences.push(eventInfo(e, date, occurrenceEnd, timeZone));
  }
  for (const override of overrides) {
    if (override.status === "CANCELLED" || !(override.start instanceof Date)) continue;
    const overrideEnd = override.end instanceof Date ? override.end : override.start;
    if (overlaps(override.start, overrideEnd)) occurrences.push(eventInfo(override, override.start, overrideEnd, timeZone));
  }
  return occurrences;
}

export async function listEvents(client: NextcloudClient, calendarPath?: string, fromISO?: string, toISO?: string): Promise<EventInfo[]> {
  const from = fromISO ? parseDate(fromISO, "from") : new Date();
  const to = toISO ? parseDate(toISO, "to") : new Date(from.getTime() + 7 * 864e5);
  if (from >= to) throw new NcError("INVALID_DATE", "Intervalo inválido: from deve ser anterior a to.");
  const fmt = (d: Date) => d.toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");
  const body = `<?xml version="1.0"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop><c:calendar-data/></d:prop>
  <c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">
    <c:time-range start="${fmt(from)}" end="${fmt(to)}"/>
  </c:comp-filter></c:comp-filter></c:filter>
</c:calendar-query>`;
  const calendars = calendarPath ? [calendarPath] : (await listCalendars(client)).map(c => c.path);
  const out: EventInfo[] = [];
  for (const path of calendars) {
    const xml = await client.dav("REPORT", path, body, { Depth: "1" });
    const doc = parser.parse(xml);
    for (const r of asArray<any>(doc?.multistatus?.response)) {
      const data = successfulProp(r)?.["calendar-data"];
      if (!data) continue;
      const ics = String(data).replace(/\r?\n[ \t]/g, "");
      const timeZones = new Map<string, string>();
      for (const block of ics.match(/BEGIN:VEVENT[\s\S]*?END:VEVENT/g) ?? []) {
        const uid = /^UID:(.+)$/m.exec(block)?.[1]?.trim();
        const tz = /^DTSTART;[^\r\n]*?TZID=([^;:]+)[^\r\n]*:/m.exec(block)?.[1];
        if (uid && tz && !timeZones.has(uid)) timeZones.set(uid, tz);
      }
      const parsed = ical.sync.parseICS(ics);
      for (const v of Object.values(parsed)) if ((v as any).type === "VEVENT")
        out.push(...eventsInWindow(v, from, to, timeZones.get(String((v as any).uid))));
    }
  }
  return out;
}

export function registerCalendarTools(server: McpServer, client: NextcloudClient): void {
  server.tool("calendar_list_calendars", "Lista os calendários visíveis ao usuário.", {},
    async () => wrap(async () => JSON.stringify(await listCalendars(client), null, 2)));
  server.tool("calendar_list_events", "Lista eventos num intervalo (default: próximos 7 dias).",
    { calendar: z.string().optional().describe("path do calendário (opcional)"),
      from: z.string().optional().describe("ISO date início"),
      to: z.string().optional().describe("ISO date fim") },
    async ({ calendar, from, to }) => wrap(async () => JSON.stringify(await listEvents(client, calendar, from, to), null, 2)));
}
