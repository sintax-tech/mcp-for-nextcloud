export interface NcConfig {
  baseUrl: string;
  username: string;
  appPassword: string;
  timeoutMs: number;
  maxReadChars: number;
}

function required(env: Record<string, string | undefined>, key: string): string {
  const v = (env[key] ?? "").trim();
  if (!v) throw new Error(`Config ausente: ${key} é obrigatório.`);
  return v;
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
    timeoutMs: Number(env.NEXTCLOUD_TIMEOUT_MS ?? "") || 15000,
    maxReadChars: Number(env.NEXTCLOUD_MAX_READ_CHARS ?? "") || 100000,
  };
}
