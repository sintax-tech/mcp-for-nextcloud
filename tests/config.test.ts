import { describe, it, expect } from "vitest";
import { loadConfig } from "../src/config.js";

const base = {
  NEXTCLOUD_BASE_URL: "https://nc.example.com/",
  NEXTCLOUD_USERNAME: "alice",
  NEXTCLOUD_APP_PASSWORD: "secret-pass",
};

describe("loadConfig", () => {
  it("normalizes baseUrl by stripping the trailing slash", () => {
    expect(loadConfig(base).baseUrl).toBe("https://nc.example.com");
  });
  it("applies defaults for timeout and maxReadChars", () => {
    const c = loadConfig(base);
    expect(c.timeoutMs).toBe(15000);
    expect(c.maxReadChars).toBe(100000);
  });
  it("throws a message WITHOUT the password when a field is missing", () => {
    expect(() => loadConfig({ ...base, NEXTCLOUD_USERNAME: "" }))
      .toThrowError(/NEXTCLOUD_USERNAME/);
  });
  it("rejects a baseUrl without http(s) scheme", () => {
    expect(() => loadConfig({ ...base, NEXTCLOUD_BASE_URL: "nc.example.com" }))
      .toThrowError(/scheme|http/i);
  });
});
