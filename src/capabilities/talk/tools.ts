import { z } from "zod";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { NextcloudClient } from "../../core/nextcloudClient.js";
import { wrap } from "../files/tools.js";

const ROOM_API = "/ocs/v2.php/apps/spreed/api/v4";
const CHAT_API = "/ocs/v2.php/apps/spreed/api/v1";

export async function listConversations(client: NextcloudClient): Promise<any[]> {
  return client.ocsGet(`${ROOM_API}/room`);
}

export async function readMessages(client: NextcloudClient, token: string, limit: number): Promise<any[]> {
  const safeToken = encodeURIComponent(token);
  // Talk chat API is v1; lookIntoFuture=0 returns the latest messages.
  return client.ocsGet(`${CHAT_API}/chat/${safeToken}`, { lookIntoFuture: "0", limit: String(Math.min(200, limit)), setReadMarker: "0", markNotificationsAsRead: "0", noStatusUpdate: "1" });
}

export function registerTalkTools(server: McpServer, client: NextcloudClient): void {
  server.tool("talk_list_conversations", "Lista as conversas (Talk) do usuário.", {},
    async () => wrap(async () => JSON.stringify(await listConversations(client), null, 2)));
  server.tool("talk_read_messages", "Lê as últimas mensagens de uma conversa do Talk.",
    { conversation_token: z.string().describe("token da conversa"), limit: z.number().int().positive().max(200).default(50) },
    async ({ conversation_token, limit }) => wrap(async () => JSON.stringify(await readMessages(client, conversation_token, limit), null, 2)));
}
