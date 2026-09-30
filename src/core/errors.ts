export class NcError extends Error {
  constructor(public code: string, message: string) {
    super(message);
    this.name = "NcError";
  }
}

export function mapHttpError(status: number, context: string): NcError {
  if (status === 401) return new NcError("AUTH", "Falha de autenticação com o Nextcloud (verifique usuário e app-password).");
  if (status === 403) return new NcError("FORBIDDEN", "Sem acesso a este recurso no Nextcloud.");
  if (status === 404) return new NcError("NOT_FOUND", "Recurso não encontrado no Nextcloud.");
  return new NcError("HTTP", `Erro ao acessar o Nextcloud (HTTP ${status}) em ${context}.`);
}
