import { z } from "zod";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { NextcloudClient } from "../../core/nextcloudClient.js";
import { mapHttpError } from "../../core/errors.js";
import { wrap } from "../files/tools.js";

const NOTES = "/index.php/apps/notes/api/v1/notes";

async function json(client: NextcloudClient, path: string): Promise<any> {
  const res = await client.request(path, { headers: { Accept: "application/json" } });
  if (!res.ok) throw mapHttpError(res.status, path);
  return res.json();
}

export async function listNotes(client: NextcloudClient): Promise<any[]> {
  return json(client, NOTES);
}
export async function readNote(client: NextcloudClient, id: number): Promise<any> {
  return json(client, `${NOTES}/${id}`);
}

export function registerNotesTools(server: McpServer, client: NextcloudClient): void {
  server.tool("notes_list", "Lista as notas (app Notes) do usuário.", {},
    async () => wrap(async () => JSON.stringify(await listNotes(client), null, 2)));
  server.tool("notes_read", "Lê o conteúdo de uma nota pelo id.",
    { id: z.number().int().describe("id da nota") },
    async ({ id }) => wrap(async () => JSON.stringify(await readNote(client, id), null, 2)));
}
