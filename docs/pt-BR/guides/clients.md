<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/guides/clients.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../en/guides/clients.md).

# Conectando clientes

Todos os clientes usam a mesma URL, `https://cloud.example.com/apps/mcp/`, e se autenticam como a pessoa que os utiliza, com suas permissões. Todos precisam primeiro do serviço MCP ativo, de um usuário elegível e de uma conexão pessoal ativa. A configuração específica do cliente muda:

| Cliente | Autenticação | O que o administrador precisa fazer antes |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | **Entrar agora** (OAuth), identidade publicada do Claude (CIMD) | Nada: o `claude.ai` é permitido por padrão |
| **ChatGPT** | **Entrar agora** (OAuth), identidade publicada da OpenAI (CIMD) | Nada: o `chatgpt.com` é permitido por padrão |
| **Gemini CLI** | OAuth com o cliente nativo embutido no app | Ligar **Permitir programas locais** e copiar o `client_id` |
| **Cliente auto-hospedado registrado** | OAuth público com PKCE | Registrar nome e URI exato de callback, depois copiar o ID gerado |
| **Qualquer outro cliente MCP** | Senha de aplicativo (HTTP Basic) | Sem registro de cliente adicional aos requisitos comuns de acesso |

### Claude (claude.ai, Claude Desktop, Cowork e Claude Code — recomendado)

1. *Configurações → Conectores → Adicionar conector personalizado*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Autenticação: **Entrar agora** (OAuth). Cliente: **identidade publicada do Claude** (CIMD). Não precisa de cabeçalho.
4. **Vincular**: o seu Nextcloud abre, você faz login e clica em **Permitir**.

O `claude.ai` já está na lista de hosts de clientes permitidos, então nada precisa ser mudado no servidor. Se um administrador restringiu essa lista, o Claude é recusado com `invalid_client` antes mesmo de a tela de login abrir: peça para ele recolocar o `claude.ai` em *Configurações de administração → MCP for Cloud → Clientes OAuth → Hosts de clientes permitidos*.

### ChatGPT

1. No ChatGPT, adicione um novo conector para servidor MCP e cole `https://cloud.example.com/apps/mcp/` como URL.
2. Autenticação: **Sign in** (OAuth). O ChatGPT se identifica com o documento de identidade de cliente publicado em `chatgpt.com`; o app baixa esse documento para descobrir os redirect URIs registrados. Não precisa de cabeçalho nem de senha de app.
3. **Conectar**: o seu Nextcloud abre, você faz login e clica em **Permitir**.

O `chatgpt.com` é permitido por padrão, então uma instalação nunca configurada já aceita o ChatGPT. Se um administrador restringiu a lista para outros hosts, o ChatGPT é recusado com `invalid_client` antes da tela de login — peça para ele recolocar o `chatgpt.com` em *Configurações de administração → MCP for Cloud → Clientes OAuth → Hosts de clientes permitidos*.

### Gemini CLI

Dois passos, um do administrador e um do usuário, sem nenhum passo no terminal do servidor.

**Administrador** — *Configurações de administração → MCP for Cloud → Clientes OAuth*:

1. Marque **Permitir programas locais (cliente nativo)**. Por padrão está desligado.
2. A seção passa a mostrar o `client_id` para copiar — `nextcloud-mcp-native` — e os redirect URIs aceitos.

**Usuário** — crie ou edite `~/.gemini/settings.json` na sua própria máquina:

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

3. No Gemini CLI, rode `/mcp auth` (ou `/mcp auth nextcloud`).
4. O seu Nextcloud abre no navegador: faça login e clique em **Permitir**. O Gemini CLI está ouvindo numa porta livre escolhida por ele mesmo, então o navegador retorna para `http://localhost:<porta>/oauth/callback`. O app aceita `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` e `http://[::1]/oauth/callback`, e ignora a porta para endereços loopback (RFC 8252 7.3), então a porta aleatória ainda casa.

Por que um cliente embutido: o Gemini CLI não publica documento de identidade de cliente, então o app traz um cliente público fixo para programas locais. O id é o mesmo para todo mundo na instância, e isso é seguro porque ele não concede nada sozinho — o login, a elegibilidade, a conexão pessoal, as liberações do administrador, a tela de consentimento e o PKCE continuam valendo. Desligar a opção também impede o cliente de trocar um código de autorização e de renovar token.

### Administradores: Clientes OAuth

*Configurações de administração → MCP for Cloud → Clientes OAuth* decide quais clientes podem conectar, sem nenhum passo no terminal:

- **Hosts de clientes permitidos** — os hosts cujo documento de identidade de cliente publicado o app aceita baixar, separados por vírgula; `claude.ai` e `chatgpt.com` por padrão. Só nome de host exato: sem `https://`, sem porta, sem caminho, sem `*`, e é preciso deixar pelo menos um. O host é o que está na URL que o cliente usa como `client_id`. Salvar substitui a lista inteira, e remover um host recusa aquele cliente na hora, mesmo que o documento dele já esteja em cache.
- **Permitir programas locais (cliente nativo)** — liga o cliente `nextcloud-mcp-native` descrito acima e mostra o `client_id` e os redirect URIs aceitos para copiar.

- **Clientes auto-hospedados (URIs de redirecionamento personalizados)**: registre nome, estado habilitado e URIs exatos de callback, e copie o ID gerado para o cliente. Usam HTTPS ou HTTP de loopback; a porta deve corresponder exatamente, diferente do cliente nativo incluído. Consulte limites e validação em [OAuth](../reference/oauth.md#clientes-publicados-nativos-e-registrados).

Os controles de clientes exigem administrador, proteção CSRF e revisão de política atual, e informam salvamentos recusados. Uma configuração própria não é substituída pelos padrões: um valor de `oauth_client_hosts` salvo antes de 0.8.0 continua em uso.

### Outros clientes MCP (senha de app)

Crie uma senha de app em *Configurações pessoais → Segurança* e ative a conexão em *Configurações pessoais → MCP for Cloud*. Depois configure o cliente com a URL acima e autenticação HTTP Basic (`usuario:senha-de-app`). Teste rápido:

```bash
curl -u 'alice:APP-PASSWORD' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revogando acesso

*Disconnect* na página pessoal bloqueia a próxima requisição MCP, inclusive com a mesma senha de app. A senha de app continua válida no Nextcloud e deve ser revogada em **Segurança**. Na mesma página, *Revogar* corta um cliente OAuth específico do próprio usuário. O administrador também pode remover a elegibilidade do usuário ou desativar o serviço; ambos valem na próxima requisição. No bloco **Conexões ativas**, o administrador revoga uma conexão OAuth específica, ou todas de um usuário, na hora; o usuário continua habilitado e pode entrar de novo.

A revogação invalida gerações de credenciais OAuth ou a época global antes da limpeza adiada. Restituir acesso, desfazer uma alteração de política ou reabilitar um cliente gerenciado não restaura códigos/tokens antigos. Remover um URI de redirecionamento personalizado também retira as concessões existentes daquele cliente. Desligar o serviço preserva a preferência de conexão pessoal; o ciclo de vida das senhas de aplicativo e a revisão de recuperação são independentes. Consulte [OAuth](../reference/oauth.md#revogação-e-incerteza).

[Guias de operação](README.md).

[English](../../en/guides/clients.md) · [Español](../../es/guides/clients.md) · [Català](../../ca/guides/clients.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/guides/clients.md)
