import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { parsePropfind } from "../../src/core/xml.js";

const xml = readFileSync(new URL("../fixtures/propfind.xml", import.meta.url), "utf8");

describe("parsePropfind", () => {
  const entries = parsePropfind(xml, "/remote.php/dav/files/alice");

  it("excludes the collection self-entry and returns 2 children", () => {
    expect(entries).toHaveLength(2);
  });
  it("marks folders as directories and files as not", () => {
    expect(entries.find(e => e.name === "Documentos")?.isDir).toBe(true);
    expect(entries.find(e => e.name === "relatorio.pdf")?.isDir).toBe(false);
  });
  it("returns paths relative to the dav root and parses size/type", () => {
    const pdf = entries.find(e => e.name === "relatorio.pdf")!;
    expect(pdf.path).toBe("/relatorio.pdf");
    expect(pdf.size).toBe(12345);
    expect(pdf.contentType).toBe("application/pdf");
  });
});
