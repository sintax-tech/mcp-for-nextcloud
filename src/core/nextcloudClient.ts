import type { NcConfig } from "../config.js";
import { mapHttpError, NcError } from "./errors.js";

export class NextcloudClient {
  private pending = new WeakMap<Response, { controller: AbortController; timer: ReturnType<typeof setTimeout> }>();
  constructor(private cfg: NcConfig, private fetchFn: typeof fetch = fetch) {}

  private authHeader(): string {
    const token = Buffer.from(`${this.cfg.username}:${this.cfg.appPassword}`).toString("base64");
    return `Basic ${token}`;
  }

  webdavFilesRoot(): string {
    return `/remote.php/dav/files/${encodeURIComponent(this.cfg.username)}`;
  }

  username(): string { return this.cfg.username; }

  async request(path: string, init: RequestInit = {}): Promise<Response> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.cfg.timeoutMs);
    try {
      const headers: Record<string, string> = init.headers && !(init.headers instanceof Headers) && !Array.isArray(init.headers)
        ? { ...init.headers as Record<string, string> }
        : Object.fromEntries(new Headers(init.headers).entries());
      for (const key of Object.keys(headers)) if (key.toLowerCase() === "authorization") delete headers[key];
      headers.Authorization = this.authHeader();
      const res = await this.fetchFn(`${this.cfg.baseUrl}${path}`, { ...init, signal: controller.signal, headers });
      this.pending.set(res, { controller, timer });
      return res;
    } catch (err) {
      clearTimeout(timer);
      if (controller.signal.aborted || (err as Error).name === "AbortError")
        throw new NcError("TIMEOUT", "Tempo esgotado ao contatar o Nextcloud.");
      throw new NcError("NETWORK", "Não foi possível conectar ao Nextcloud (verifique a URL base e a rede).");
    }
  }

  private async read<T>(res: Response, operation: (signal: AbortSignal) => Promise<T>): Promise<T> {
    const state = this.pending.get(res);
    if (!state) return operation(new AbortController().signal);
    try {
      return await Promise.race([
        operation(state.controller.signal),
        new Promise<never>((_, reject) => {
          if (state.controller.signal.aborted) reject(new NcError("TIMEOUT", "Tempo esgotado ao contatar o Nextcloud."));
          else state.controller.signal.addEventListener("abort", () => reject(new NcError("TIMEOUT", "Tempo esgotado ao contatar o Nextcloud.")), { once: true });
        }),
      ]);
    } catch (err) {
      if (state.controller.signal.aborted) throw new NcError("TIMEOUT", "Tempo esgotado ao contatar o Nextcloud.");
      throw err;
    } finally {
      clearTimeout(state.timer);
      this.pending.delete(res);
    }
  }

  async ocsGet(path: string, params: Record<string, string> = {}): Promise<any> {
    const qs = new URLSearchParams(params).toString();
    const res = await this.request(qs ? `${path}?${qs}` : path, {
      headers: { "OCS-APIRequest": "true", Accept: "application/json" },
    });
    return this.read(res, async () => {
      if (res.status === 304) return [];
      if (!res.ok) throw mapHttpError(res.status, path);
      const body = await res.json();
      const status = Number(body?.ocs?.meta?.statuscode);
      if (Number.isFinite(status) && status !== 100 && status !== 200)
        throw mapHttpError(status, path);
      return body?.ocs?.data ?? body;
    });
  }

  async dav(method: string, path: string, body?: string, headers: Record<string, string> = {}): Promise<string> {
    const res = await this.request(path, { method, body, headers: { "Content-Type": "application/xml", ...headers } });
    return this.read(res, async () => {
      if (!res.ok) throw mapHttpError(res.status, `${method} ${path}`);
      return res.text();
    });
  }

  async getBytes(path: string): Promise<{ buffer: Buffer; contentType: string }> {
    const res = await this.request(path);
    return this.read(res, async (signal) => {
      if (!res.ok) throw mapHttpError(res.status, `GET ${path}`);
      const tooLarge = () => new NcError("READ_TOO_LARGE", `Arquivo excede o limite de leitura de ${this.cfg.maxReadBytes} bytes.`);
      const length = res.headers.get("content-length");
      if (length !== null && Number(length) > this.cfg.maxReadBytes) {
        void res.body?.cancel();
        throw tooLarge();
      }
      if (!res.body) return { buffer: Buffer.alloc(0), contentType: res.headers.get("content-type") ?? "application/octet-stream" };
      const reader = res.body.getReader();
      const chunks: Uint8Array[] = [];
      let size = 0;
      try {
        while (true) {
          if (signal.aborted) throw new NcError("TIMEOUT", "Tempo esgotado ao contatar o Nextcloud.");
          const { done, value } = await reader.read();
          if (done) break;
          size += value.byteLength;
          if (size > this.cfg.maxReadBytes) { void reader.cancel(); throw tooLarge(); }
          chunks.push(value);
        }
      } catch (err) {
        void reader.cancel();
        throw err;
      } finally { reader.releaseLock(); }
      return { buffer: Buffer.concat(chunks.map(c => Buffer.from(c)), size), contentType: res.headers.get("content-type") ?? "application/octet-stream" };
    });
  }
}
