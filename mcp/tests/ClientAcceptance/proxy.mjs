// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import https from "node:https";
import http from "node:http";
import { allowed } from "./policy.mjs";
export async function startProxy({ key, cert, host, port, upstream, denied }) {
  const server = https.createServer(
    { key, cert, minVersion: "TLSv1.2" },
    (req, res) => {
      if (req.headers.host !== host || !allowed(req.method, req.url)) {
        denied.add(`${req.method} ${req.url.split("?")[0]}`);
        res.writeHead(403);
        res.end();
        return;
      }
      const headers = { ...req.headers };
      for (const name of Object.keys(headers))
        if (
          name.startsWith("x-forwarded-") ||
          ["forwarded", "connection", "proxy-authorization"].includes(name)
        )
          delete headers[name];
      Object.assign(headers, {
        host,
        "x-forwarded-proto": "https",
        "x-forwarded-host": host,
        "x-forwarded-for": "127.0.0.1",
      });
      const out = http.request(
        {
          hostname: "127.0.0.1",
          port: upstream,
          path: req.url,
          method: req.method,
          headers,
          timeout: 120000,
        },
        (reply) => {
          res.writeHead(reply.statusCode, reply.headers);
          reply.pipe(res);
        },
      );
      let bytes = 0;
      req.on("data", (chunk) => {
        bytes += chunk.length;
        if (bytes > 1048576) {
          res.writeHead(413);
          res.end();
          out.destroy();
        }
      });
      req.pipe(out);
      req.on("aborted", () => out.destroy());
      res.on("close", () => {
        if (!res.writableEnded) out.destroy();
      });
      out.on("timeout", () => out.destroy());
      out.on("error", () => {
        if (!res.headersSent) res.writeHead(502);
        res.end();
      });
    },
  );
  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(port, "127.0.0.1", resolve);
  });
  return server;
}
