import { XMLParser } from "fast-xml-parser";

export interface DavEntry {
  name: string;
  path: string;
  isDir: boolean;
  size: number;
  mtime: string;
  contentType: string;
}

const parser = new XMLParser({ ignoreAttributes: true, removeNSPrefix: true });

function asArray<T>(v: T | T[] | undefined): T[] {
  if (v === undefined) return [];
  return Array.isArray(v) ? v : [v];
}

export function parsePropfind(xml: string, davRoot: string): DavEntry[] {
  const doc = parser.parse(xml);
  const responses = asArray<any>(doc?.multistatus?.response);
  const rootDecoded = decodeURIComponent(davRoot).replace(/\/+$/, "");
  const out: DavEntry[] = [];
  for (const r of responses) {
    const href = decodeURIComponent(String(r.href ?? "")).replace(/\/+$/, "");
    if (href === rootDecoded) continue; // self
    const propstats = asArray<any>(r.propstat);
    const propstat = propstats.find((p) => String(p?.status ?? "").includes("200")) ?? propstats[0];
    const prop = propstat?.prop ?? {};
    const rt = prop.resourcetype;
    const isDir = rt !== undefined && rt !== "" && typeof rt === "object" && "collection" in rt;
    const rel = href.slice(rootDecoded.length) || "/";
    const name = rel.split("/").filter(Boolean).pop() ?? "";
    out.push({
      name,
      path: rel.startsWith("/") ? rel : `/${rel}`,
      isDir,
      size: Number(prop.getcontentlength ?? 0),
      mtime: String(prop.getlastmodified ?? ""),
      contentType: String(prop.getcontenttype ?? (isDir ? "httpd/unix-directory" : "")),
    });
  }
  return out;
}
