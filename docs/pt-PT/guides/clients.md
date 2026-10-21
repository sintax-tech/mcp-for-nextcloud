<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/clients.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/guides/clients.md).

# Ligar clientes

Todos os clientes usam a mesma URL, `https://cloud.example.com/apps/mcp/`, e autenticam-se como a pessoa que os utiliza, com suas permissões. Todos precisam primeiro do serviço MCP ativo, de um utilizador elegível e de uma ligação pessoal ativa. A configuração específica do cliente muda:

| Cliente | Autenticação | O que o administrador tem de fazer primeiro |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | OAuth **Iniciar sessão**, identidade publicada do Claude (CIMD) | Nada: `claude.ai` é permitido por predefinição |
| **ChatGPT** | OAuth **Iniciar sessão**, identidade publicada da OpenAI (CIMD) | Nada: `chatgpt.com` é permitido por predefinição |
| **Gemini CLI** | OAuth com o cliente nativo integrado na aplicação | Ativar **Permitir programas locais** e copiar o `client_id` |
| **Cliente autoalojado registado** | OAuth público com PKCE | Registar nome e URI exato de callback, depois copiar o ID gerado |
| **Qualquer outro cliente MCP** | Palavra-passe de aplicação (HTTP Basic) | Sem registo de cliente adicional aos requisitos comuns de acesso |

### Claude (claude.ai, Claude Desktop, Cowork e Claude Code — recomendado)

1. *Definições → Conectores → Adicionar conector personalizado*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Autenticação: **Iniciar sessão** (OAuth); cliente: **identidade publicada do Claude** (CIMD). Não são necessários cabeçalhos.
4. **Ligar**: abre-se o Nextcloud; inicie sessão e selecione **Permitir**.

`claude.ai` consta da lista de anfitriões de clientes permitidos por predefinição, pelo que não é necessário alterar nada no servidor. Se o administrador tiver restringido essa lista, o Claude recebe `invalid_client` antes de a página de início de sessão abrir. Peça ao administrador que volte a incluir `claude.ai` em *Definições de administração → MCP for Cloud → Clientes OAuth → Anfitriões de clientes permitidos*.

### ChatGPT

1. No ChatGPT, adicione um conector para um servidor MCP e introduza `https://cloud.example.com/apps/mcp/` como URL.
2. Autenticação: **Iniciar sessão** (OAuth). O ChatGPT identifica-se com o documento de identidade de cliente publicado em `chatgpt.com`; a aplicação transfere esse documento para obter os URIs de redirecionamento registados. Não são necessários cabeçalhos nem palavra-passe de aplicação.
3. **Ligar**: abre-se o Nextcloud; inicie sessão e selecione **Permitir**.

`chatgpt.com` é permitido por predefinição, pelo que uma instalação nunca configurada já aceita o ChatGPT. Se o administrador tiver restringido a lista a outros anfitriões, o ChatGPT recebe `invalid_client` antes de a página de início de sessão abrir. Peça ao administrador que volte a incluir `chatgpt.com` em *Definições de administração → MCP for Cloud → Clientes OAuth → Anfitriões de clientes permitidos*.

### Gemini CLI

São necessários dois passos, um do administrador e outro do utilizador; não é preciso executar comandos no terminal do servidor.

**Administrador** — *Definições de administração → MCP for Cloud → Clientes OAuth*:

1. Ative **Permitir programas locais (cliente nativo)**. A opção está desativada por predefinição.
2. A secção passa a mostrar o `client_id` a copiar — `nextcloud-mcp-native` — e os URIs de redirecionamento aceites.

**Utilizador** — crie ou edite `~/.gemini/settings.json` no seu próprio computador:

```json
{
  "mcpServers": {
    "nextcloud": {
      "url": "https://cloud.example.com/apps/mcp/",
      "oauth": {
        "clientId": "nextcloud-mcp-native"
      }
    }
  }
}
```

3. No Gemini CLI, execute `/mcp auth` (ou `/mcp auth nextcloud`).
4. O Nextcloud abre no navegador: inicie sessão e selecione **Permitir**. O Gemini CLI aguarda numa porta livre escolhida por si, pelo que o navegador regressa a `http://localhost:<port>/oauth/callback`. A aplicação aceita `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` e `http://[::1]/oauth/callback`, ignorando a porta em endereços de loopback (RFC 8252 7.3); por isso, a porta aleatória também corresponde.

Porque existe um cliente nativo integrado: o Gemini CLI não publica um documento de identidade de cliente, por isso a aplicação inclui um cliente público fixo para programas locais. O respetivo ID é igual para todos na instância e isso é seguro, porque não concede acesso por si só — continuam a aplicar-se o início de sessão, a elegibilidade, a ligação pessoal, as permissões do administrador, o ecrã de consentimento e o PKCE. Desativar a opção também impede o cliente de trocar um código de autorização e de renovar um token.

### Administradores: clientes OAuth

Em *Definições de administração → MCP for Cloud → Clientes OAuth*, o administrador escolhe que clientes podem ligar-se, sem precisar de usar o terminal:

- **Anfitriões de clientes permitidos** — anfitriões, separados por vírgulas, cujos documentos de identidade de cliente publicados a aplicação pode transferir; `claude.ai` e `chatgpt.com` são permitidos por predefinição. Indique nomes de anfitrião exatos, sem `https://`, porta, caminho ou `*`, e mantenha pelo menos um anfitrião. O anfitrião é o que consta do URL que o cliente utiliza como `client_id`. Ao guardar, substitui-se a lista inteira. A remoção de um anfitrião recusa imediatamente esse cliente, mesmo que o respetivo documento já esteja em cache.
- **Permitir programas locais (cliente nativo)** — ativa o cliente `nextcloud-mcp-native` descrito acima e apresenta o `client_id` e os URIs de redirecionamento aceites, para copiar.

- **Clientes autoalojados (URIs de redirecionamento personalizados)**: registe nome, estado ativado e URIs exatos de callback, e copie o ID gerado para o cliente. Usam HTTPS ou HTTP de loopback; a porta deve corresponder exatamente, diferente do cliente nativo incluído. Consulte limites e validação em [OAuth](../reference/oauth.md#clientes-publicados-nativos-e-registados).

Os controlos de clientes exigem administrador, proteção CSRF e revisão de política atual, e informam gravações recusados. Uma configuração própria não é substituída pelos valores predefinidos: um valor de `oauth_client_hosts` guardado antes de 0.8.0 continua em uso.

### Outros clientes MCP (palavra-passe de aplicação)

Crie uma palavra-passe de aplicação em *Definições pessoais → Segurança* e ative a ligação em *Definições pessoais → MCP for Cloud*. Em seguida, configure o cliente com o URL acima e autenticação HTTP Basic (`username:app-password`). Verificação rápida:

```bash
curl -u 'alice:APP-PASSWORD' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revogar o acesso

A opção *Desligar* na página pessoal bloqueia o pedido MCP seguinte, mesmo que seja usada a mesma palavra-passe de aplicação. A palavra-passe continua válida no Nextcloud e tem de ser revogada em **Segurança**. Na mesma página, *Revogar* termina uma ligação OAuth específica do próprio utilizador. O administrador também pode retirar a elegibilidade do utilizador ou desativar o serviço; ambas as alterações têm efeito no pedido seguinte. No bloco **Ligações ativas**, o administrador pode revogar imediatamente uma ligação OAuth específica ou todas as ligações de um utilizador; este continua ativo e pode voltar a iniciar sessão.

A revogação invalida gerações de credenciais OAuth ou a época global antes da limpeza adiada. Restituir acesso, desfazer uma alteração de política ou reativar um cliente gerido não restaura códigos/tokens antigos. Remover um URI de redirecionamento personalizado também retira as concessões existentes daquele cliente. Desligar o serviço preserva a preferência de ligação pessoal; o ciclo de vida das palavras-passe de aplicação e a revisão de recuperação são independentes. Consulte [OAuth](../reference/oauth.md#revogação-e-incerteza).

[Guias de operação](README.md).

[English](../../en/guides/clients.md) · [Español](../../es/guides/clients.md) · [Català](../../ca/guides/clients.md) · [Português (Brasil)](../../pt-BR/guides/clients.md) · Português (Portugal)
