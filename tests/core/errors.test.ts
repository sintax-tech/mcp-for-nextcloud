import { describe, it, expect } from "vitest";
import { mapHttpError, NcError } from "../../src/core/errors.js";

describe("mapHttpError", () => {
  it("401 -> AUTH, safe message", () => {
    const e = mapHttpError(401, "files_list");
    expect(e).toBeInstanceOf(NcError);
    expect(e.code).toBe("AUTH");
    expect(e.message).toMatch(/autentica/i);
  });
  it("403 -> FORBIDDEN", () => { expect(mapHttpError(403, "x").code).toBe("FORBIDDEN"); });
  it("404 -> NOT_FOUND", () => { expect(mapHttpError(404, "x").code).toBe("NOT_FOUND"); });
  it("500 -> HTTP, includes context but no secret", () => {
    const e = mapHttpError(500, "talk_list");
    expect(e.code).toBe("HTTP");
    expect(e.message).toContain("talk_list");
  });
});
