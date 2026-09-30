import { describe, it, expect, vi } from "vitest";
import { listConversations, readMessages } from "../../src/capabilities/talk/tools.js";
import { NextcloudClient } from "../../src/core/nextcloudClient.js";
import type { NcConfig } from "../../src/config.js";

const cfg: NcConfig = { baseUrl: "https://nc.example.com", username: "alice", appPassword: "p", timeoutMs: 5000, maxReadChars: 1000, maxReadBytes: 20 * 1024 * 1024 };
function ocs(data: unknown, status = 200) { return new Response(JSON.stringify({ ocs: { data } }), { status, headers: { "content-type": "application/json" } }); }

describe("talk", () => {
  it("listConversations returns rooms via the v4 OCS API", async () => {
    const fetchFn = vi.fn(async () => ocs([{ token: "abc", displayName: "Diretoria" }]));
    const client = new NextcloudClient(cfg, fetchFn as any);
    const rooms = await listConversations(client);
    expect(rooms[0].displayName).toBe("Diretoria");
    expect(String(fetchFn.mock.calls[0][0])).toContain("/ocs/v2.php/apps/spreed/api/v4/room");
    expect(fetchFn.mock.calls[0][1].headers["OCS-APIRequest"]).toBe("true");
  });
  it("readMessages fetches a conversation's chat messages with a limit", async () => {
    const fetchFn = vi.fn(async () => ocs([{ id: 1, actorDisplayName: "Bob", message: "oi", timestamp: 1 }]));
    const client = new NextcloudClient(cfg, fetchFn as any);
    const msgs = await readMessages(client, "abc", 10);
    expect(msgs[0].message).toBe("oi");
    expect(String(fetchFn.mock.calls[0][0])).toBe("https://nc.example.com/ocs/v2.php/apps/spreed/api/v1/chat/abc?lookIntoFuture=0&limit=10&setReadMarker=0&markNotificationsAsRead=0&noStatusUpdate=1");
  });
});
