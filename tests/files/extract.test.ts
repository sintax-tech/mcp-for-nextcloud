import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { extractText } from "../../src/capabilities/files/extract.js";

describe("extractText", () => {
  it("extracts text from a PDF", async () => {
    const buf = readFileSync(new URL("../fixtures/sample.pdf", import.meta.url));
    expect(await extractText(buf, "sample.pdf", "application/pdf")).toContain("HELLO_MCP");
  });
  it("extracts text from a DOCX", async () => {
    const buf = readFileSync(new URL("../fixtures/sample.docx", import.meta.url));
    expect(await extractText(buf, "sample.docx", "")).toContain("HELLO_MCP");
  });
  it("returns plain text unchanged for text/plain", async () => {
    expect(await extractText(Buffer.from("olá"), "a.txt", "text/plain")).toBe("olá");
  });
  it("does not throw on a corrupt PDF — returns a graceful notice", async () => {
    const out = await extractText(Buffer.from("%PDF-broken\x00\x00"), "bad.pdf", "application/pdf");
    expect(out).toMatch(/não foi possível extrair|erro/i);
  });
});
