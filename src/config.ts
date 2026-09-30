export interface NcConfig {
  baseUrl: string;
  username: string;
  appPassword: string;
  timeoutMs: number;
  maxReadChars: number;
  maxReadBytes: number;
}

function required(env: Record<string, string | undefined>, key: string): string {
  const v = (env[key] ?? "").trim();
  if (!v) throw new Error(`Config ausente: ${key} é obrigatório.`);
  return v;
}

function positiveInt(env: Record<string, string | undefined>, key: string, fallback: number, max: number): number {
  const raw = env[key];
  if (raw === undefined || raw === "") return fallback;
  const value = Number(raw);
  if (!Number.isSafeInteger(value) || value <= 0 || value > max)
    throw new Error(`Config inválida: ${key} deve ser inteiro positivo até ${max}.`);
  return value;
}

export function loadConfig(env: Record<string, string | undefined> = process.env): NcConfig {
  const rawBase = required(env, "NEXTCLOUD_BASE_URL");
  if (!/^https?:\/\//i.test(rawBase)) {
    throw new Error("NEXTCLOUD_BASE_URL inválida: precisa começar com http:// ou https:// (scheme).");
  }
  return {
    baseUrl: rawBase.replace(/\/+$/, ""),
    username: required(env, "NEXTCLOUD_USERNAME"),
    appPassword: required(env, "NEXTCLOUD_APP_PASSWORD"),
    timeoutMs: positiveInt(env, "NEXTCLOUD_TIMEOUT_MS", 15000, 300000),
    maxReadChars: positiveInt(env, "NEXTCLOUD_MAX_READ_CHARS", 100000, 10000000)
    ,maxReadBytes: positiveInt(env, "NEXTCLOUD_MAX_READ_BYTES", 20 * 1024 * 1024, 100 * 1024 * 1024),
  };
}
