# mcp-for-nextcloud

Servidor **MCP (Model Context Protocol)** que permite a uma IA (Claude, ChatGPT/OpenAI, Gemini — qualquer cliente compatível com MCP) **ler e responder sobre o conteúdo do seu Nextcloud**, restrito **exatamente ao que o seu usuário tem acesso**.

> **v1 — somente leitura.** O servidor autentica *como você* (app-password) e usa as APIs normais do Nextcloud, então herda todas as permissões: se você não vê na interface, o MCP também não vê.

## Fontes suportadas (v1)

| Ferramenta | O que faz |
|---|---|
| `files_list` | Lista arquivos/pastas de um diretório |
| `files_search` | Busca arquivos por nome (busca por conteúdo requer o app **fulltextsearch**) |
| `files_read` | Lê o texto de um arquivo (txt/md direto; PDF/DOCX com extração de texto) |
| `notes_list` / `notes_read` | Lista e lê notas do app **Notes** |
| `calendar_list_calendars` / `calendar_list_events` | Lista calendários e eventos (CalDAV) |
| `talk_list_conversations` / `talk_read_messages` | Lista conversas e lê mensagens do **Talk** |

## Pré-requisitos

- **Node 20+**
- Um **app-password** do Nextcloud: *Configurações → Segurança → Dispositivos e sessões → Criar nova senha de app*.

## Instalação

```bash
npm install
npm run build
```

## Configuração

Variáveis de ambiente (veja `.env.example`):

| Variável | Descrição |
|---|---|
| `NEXTCLOUD_BASE_URL` | URL base, ex.: `https://dalcomad.cloud` |
| `NEXTCLOUD_USERNAME` | seu usuário |
| `NEXTCLOUD_APP_PASSWORD` | o app-password gerado |
| `NEXTCLOUD_TIMEOUT_MS` | opcional (default 15000) |
| `NEXTCLOUD_MAX_READ_CHARS` | opcional (default 100000) |

## Uso no Claude Desktop / Claude Code

Adicione ao `mcp.json`:

```json
{
  "mcpServers": {
    "nextcloud": {
      "command": "node",
      "args": ["/ABS/PATH/mcp-for-nextcloud/dist/index.js"],
      "env": {
        "NEXTCLOUD_BASE_URL": "https://dalcomad.cloud",
        "NEXTCLOUD_USERNAME": "seu-usuario",
        "NEXTCLOUD_APP_PASSWORD": "xxxxx-xxxxx-xxxxx-xxxxx-xxxxx"
      }
    }
  }
}
```

O mesmo servidor funciona em outros clientes MCP (ChatGPT em modo desenvolvedor / Responses API, Gemini) apontando para o binário/endpoint.

## Segurança

- O app-password fica só nas variáveis de ambiente — **nunca** é logado nem gravado em disco.
- Todo acesso é feito como o usuário autenticado; nada além do que ele já pode ver é exposto.
- 401/403/404 do Nextcloud viram mensagens seguras, sem vazar segredo.

## Desenvolvimento

```bash
npm test          # roda a suíte (vitest)
npm run dev       # roda via tsx (sem build)
```

## Roadmap

- **v2:** escrita com confirmação (criar nota/evento, postar no Talk) + auditoria.
- **v3:** transporte HTTP remoto + OAuth/Login Flow v2 (multi-usuário, hospedado).
