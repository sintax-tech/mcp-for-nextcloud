import type { NcConfig } from "../config.js";
import { mapHttpError, NcError } from "./errors.js";

export class NextcloudClient {
  constructor(private cfg: NcConfig, private fetchFn: typeof fetch = fetch) {}

  private authHeader(): string {
    const token = Buffer.from(`${this.cfg.username}:${this.cfg.appPassword}`).toString("base64");
    return `Basic ${token}`;
  }

  webdavFilesRoot(): string {
    return `/remote.php/dav/files/${this.cfg.username}`;
  }

  username(): string {
    return this.cfg.username;
  }

  async request(path: string, init: RequestInit = {}): Promise<Response> {
    const url = `${this.cfg.baseUrl}${path}`;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.cfg.timeoutMs);
    try {
      return await this.fetchFn(url, {
        ...init,
        signal: controller.signal,
        headers: { Authorization: this.authHeader(), ...(init.headers as Record<string, string> ?? {}) },
      });
    } catch (err) {
      if ((err as Error).name === "AbortError") throw new NcError("TIMEOUT", "Tempo esgotado ao contatar o Nextcloud.");
      throw new NcError("NETWORK", "Não foi possível conectar ao Nextcloud (verifique a URL base e a rede).");
    } finally {
      clearTimeout(timer);
    }
  }

  async ocsGet(path: string, params: Record<string, string> = {}): Promise<any> {
    const qs = new URLSearchParams(params).toString();
    const res = await this.request(qs ? `${path}?${qs}` : path, {
      headers: { "OCS-APIRequest": "true", Accept: "application/json" },
    });
    if (!res.ok) throw mapHttpError(res.status, path);
    const body = await res.json();
    return body?.ocs?.data ?? body;
  }

  async dav(method: string, path: string, body?: string, headers: Record<string, string> = {}): Promise<string> {
    const res = await this.request(path, { method, body, headers: { "Content-Type": "application/xml", ...headers } });
    if (!res.ok) throw mapHttpError(res.status, `${method} ${path}`);
    return await res.text();
  }

  async getBytes(path: string): Promise<{ buffer: Buffer; contentType: string }> {
    const res = await this.request(path);
    if (!res.ok) throw mapHttpError(res.status, `GET ${path}`);
    const ab = await res.arrayBuffer();
    return { buffer: Buffer.from(ab), contentType: res.headers.get("content-type") ?? "application/octet-stream" };
  }
}
