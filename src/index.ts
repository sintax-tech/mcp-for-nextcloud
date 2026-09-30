#!/usr/bin/env node
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { loadConfig } from "./config.js";
import { NextcloudClient } from "./core/nextcloudClient.js";
import { registerFilesTools } from "./capabilities/files/tools.js";
import { registerNotesTools } from "./capabilities/notes/tools.js";
import { registerCalendarTools } from "./capabilities/calendar/tools.js";
import { registerTalkTools } from "./capabilities/talk/tools.js";

async function main() {
  const cfg = loadConfig();
  const client = new NextcloudClient(cfg);
  const server = new McpServer({ name: "mcp-for-nextcloud", version: "0.1.0" });

  registerFilesTools(server, client, cfg.maxReadChars);
  registerNotesTools(server, client);
  registerCalendarTools(server, client);
  registerTalkTools(server, client);

  await server.connect(new StdioServerTransport());
}

main().catch((e) => {
  // stderr only; never leak secrets, never write to stdout (reserved for MCP)
  process.stderr.write(`[mcp-for-nextcloud] falha ao iniciar: ${e?.message ?? e}\n`);
  process.exit(1);
});
