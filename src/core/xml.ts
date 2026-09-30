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

export function safeDecode(value: string): string {
  try { return decodeURIComponent(value); } catch { return value; }
}

export function successfulProp(response: any): any | undefined {
  return asArray<any>(response?.propstat).find(p => /\s200(?:\s|$)/.test(String(p?.status ?? "")))?.prop;
}

function asArray<T>(v: T | T[] | undefined): T[] {
  if (v === undefined) return [];
  return Array.isArray(v) ? v : [v];
}

export function parsePropfind(xml: string, davRoot: string): DavEntry[] {
  const doc = parser.parse(xml);
  const responses = asArray<any>(doc?.multistatus?.response);
  const rootDecoded = safeDecode(davRoot).replace(/\/+$/, "");
  const out: DavEntry[] = [];
  for (const r of responses) {
    const href = safeDecode(String(r.href ?? "")).replace(/\/+$/, "");
    if (href === rootDecoded) continue; // self
    if (!href.startsWith(`${rootDecoded}/`)) continue;
    const prop = successfulProp(r);
    if (!prop) continue;
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
