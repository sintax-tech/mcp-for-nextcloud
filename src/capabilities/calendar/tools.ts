import { z } from "zod";
import { XMLParser } from "fast-xml-parser";
import ical from "node-ical";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { NextcloudClient } from "../../core/nextcloudClient.js";
import { wrap } from "../files/tools.js";

const parser = new XMLParser({ ignoreAttributes: true, removeNSPrefix: true });
function asArray<T>(v: T | T[] | undefined): T[] { return v === undefined ? [] : Array.isArray(v) ? v : [v]; }

export interface CalInfo { name: string; path: string; }
export interface EventInfo { uid: string; summary: string; start: string; end: string; location: string; }

export async function listCalendars(client: NextcloudClient): Promise<CalInfo[]> {
  const calRoot = `/remote.php/dav/calendars/${client.username()}`;
  const xml = await client.dav("PROPFIND", `${calRoot}/`, undefined, { Depth: "1" });
  const doc = parser.parse(xml);
  const out: CalInfo[] = [];
  for (const r of asArray<any>(doc?.multistatus?.response)) {
    const href = decodeURIComponent(String(r.href ?? ""));
    const prop = asArray<any>(r.propstat)[0]?.prop ?? {};
    const name = prop.displayname;
    if (name && href.replace(/\/+$/, "") !== calRoot) {
      out.push({ name: String(name), path: href });
    }
  }
  return out;
}

export async function listEvents(client: NextcloudClient, calendarPath?: string, fromISO?: string, toISO?: string): Promise<EventInfo[]> {
  const from = fromISO ? new Date(fromISO) : new Date();
  const to = toISO ? new Date(toISO) : new Date(Date.now() + 7 * 864e5);
  const fmt = (d: Date) => d.toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");
  const path = calendarPath ?? `/remote.php/dav/calendars/${client.username()}/personal/`;
  const body = `<?xml version="1.0"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop><c:calendar-data/></d:prop>
  <c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">
    <c:time-range start="${fmt(from)}" end="${fmt(to)}"/>
  </c:comp-filter></c:comp-filter></c:filter>
</c:calendar-query>`;
  const xml = await client.dav("REPORT", path, body, { Depth: "1" });
  const doc = parser.parse(xml);
  const out: EventInfo[] = [];
  for (const r of asArray<any>(doc?.multistatus?.response)) {
    const data = asArray<any>(r.propstat)[0]?.prop?.["calendar-data"];
    if (!data) continue;
    const parsed = ical.sync.parseICS(String(data));
    for (const v of Object.values(parsed)) {
      if ((v as any).type === "VEVENT") {
        const e = v as any;
        out.push({
          uid: String(e.uid ?? ""),
          summary: String(e.summary ?? ""),
          start: e.start ? new Date(e.start).toISOString() : "",
          end: e.end ? new Date(e.end).toISOString() : "",
          location: String(e.location ?? ""),
        });
      }
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
