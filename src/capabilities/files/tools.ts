import { z } from "zod";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { NextcloudClient } from "../../core/nextcloudClient.js";
import { parsePropfind, type DavEntry } from "../../core/xml.js";
import { NcError } from "../../core/errors.js";

export function encodePath(p: string): string {
  return "/" + p.split("/").filter(Boolean).map(encodeURIComponent).join("/");
}

export async function listFiles(client: NextcloudClient, path: string): Promise<DavEntry[]> {
  const root = client.webdavFilesRoot();
  const xml = await client.dav("PROPFIND", `${root}${encodePath(path)}`, undefined, { Depth: "1" });
  return parsePropfind(xml, root);
}

export async function searchFiles(client: NextcloudClient, query: string, limit: number): Promise<DavEntry[]> {
  const root = client.webdavFilesRoot();
  const safe = query.replace(/[<&]/g, "");
  const body = `<?xml version="1.0"?>
<d:searchrequest xmlns:d="DAV:">
  <d:basicsearch>
    <d:select><d:prop><d:displayname/><d:getcontentlength/><d:getcontenttype/><d:getlastmodified/><d:resourcetype/></d:prop></d:select>
    <d:from><d:scope><d:href>${root}/</d:href><d:depth>infinity</d:depth></d:scope></d:from>
    <d:where><d:like><d:prop><d:displayname/></d:prop><d:literal>%${safe}%</d:literal></d:like></d:where>
    <d:limit><d:nresults>${Math.max(1, limit)}</d:nresults></d:limit>
  </d:basicsearch>
</d:searchrequest>`;
  const xml = await client.dav("SEARCH", "/remote.php/dav/", body);
  return parsePropfind(xml, root).slice(0, limit);
}

export async function wrap(fn: () => Promise<string>) {
  try {
    return { content: [{ type: "text" as const, text: await fn() }] };
  } catch (e) {
    const msg = e instanceof NcError ? e.message : "Erro inesperado ao acessar o Nextcloud.";
    return { content: [{ type: "text" as const, text: msg }], isError: true };
  }
}

export function registerFilesTools(server: McpServer, client: NextcloudClient): void {
  server.tool(
    "files_list",
    "Lista arquivos e pastas de um diretório do Nextcloud do usuário.",
    { path: z.string().default("/").describe("Caminho da pasta, ex.: /Documentos") },
    async ({ path }) => wrap(async () => JSON.stringify(await listFiles(client, path), null, 2)),
  );
  server.tool(
    "files_search",
    "Busca arquivos por nome no Nextcloud do usuário (conteúdo depende do fulltextsearch).",
    { query: z.string().describe("Termo a buscar"), limit: z.number().int().positive().max(100).default(25) },
    async ({ query, limit }) => wrap(async () => JSON.stringify(await searchFiles(client, query, limit), null, 2)),
  );
}
