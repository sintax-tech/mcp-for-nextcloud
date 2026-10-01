<div align="center">

# MCP for Nextcloud

**App nativo do Nextcloud que transforma o seu Nextcloud em um servidor MCP.**
Deixe o Claude e outros clientes MCP trabalharem com seus arquivos, notas, calendários, contatos, tarefas, quadros do Deck e conversas do Talk. Cada usuário usa as próprias permissões, e nada sai do servidor sem liberação do admin.

[![Nextcloud 33](https://img.shields.io/badge/Nextcloud-33-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![Licença: AGPL-3.0](https://img.shields.io/badge/Licen%C3%A7a-AGPL--3.0-blue)](#licença)

[English](README.md) · Português (Brasil)

</div>

---

## Por quê

Assistentes de IA rendem mais quando alcançam as ferramentas que o time já usa. Ligar esses assistentes ao Nextcloud costuma exigir scripts com senha compartilhada, tokens amplos ou dados copiados para fora. O **MCP for Nextcloud** roda **dentro** do seu Nextcloud:

- **Uma URL por instância.** `https://cloud.example.com/apps/mcp/`. Sem subdomínio, sem serviço extra, sem Node/Docker/AppAPI.
- **Login pelo Nextcloud.** Clientes como o claude.ai usam OAuth ("Entrar agora"): a pessoa faz login no próprio Nextcloud e clica em **Permitir**. Senha de app (HTTP Basic) continua valendo para outros clientes.
- **O admin no controle.** Liga e desliga o serviço, define quem pode conectar e mantém uma **matriz usuário × permissão**. Leitura vem ligada para usuários autorizados. Escrever, mover, excluir, transferir e restaurar ficam **desligados até o admin liberar**.
- **As permissões do Nextcloud sempre valem.** O app nunca amplia o que o usuário já pode fazer no Nextcloud.
- **Ocultação de arquivos sensíveis por etiqueta.** O administrador pode escolher etiquetas de sistema (preferencialmente restritas ou invisíveis) para ocultar completamente arquivos e pastas das ferramentas e dos recursos MCP, incluindo tudo o que estiver dentro deles. Em caso de dúvida, oculta: se a consulta da etiqueta falhar, o item continua oculto. Os backups feitos pelo app recebem a etiqueta antes de receber qualquer conteúdo.

## Novidades da 0.8.0

Se você vem da 0.7.0, isto é o que a nova versão acrescenta. A lista completa, incluindo as correções de segurança, está no [`CHANGELOG.md`](CHANGELOG.md).

- **Contatos e Tarefas.** Catálogos e contatos (`contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact`) e tarefas VTODO (`tasks_list_calendars`, `tasks_list_tasks`, `tasks_read_task`, `tasks_create_task`, `tasks_edit_task`, `tasks_complete_task`, `tasks_delete_task`). Contatos passam pelo CardDAV do core e preservam campos desconhecidos do vCard; tarefas não dependem de app opcional. Os dois módulos têm grants próprios de `read`/`create`/`edit`/`delete`, todas as escritas desligadas por padrão. As regras de exclusão e de backup estão em [Contatos e tarefas](#contatos-e-tarefas).
- **Busca por conteúdo.** `files_search` busca dentro dos arquivos e devolve trechos quando o app `fulltextsearch` está ativo e indexado, e cai para busca por nome com um aviso quando não está. `notes_search` busca no título e no Markdown das notas.
- **OCR pelo Workflow OCR.** Com esse app opcional instalado, um PDF escaneado ganha uma camada de texto que o `files_read` lê normalmente. Sem ele, uma página sem texto volta como `text_layer: false` mais um aviso, e a página de admin mostra o status do OCR. Nada fica bloqueado.
- **Recursos MCP (MCP Resources).** O cliente pode listar `mcp://guide` para ler o guia das ferramentas, descobrir os templates `nc://files/{path}` e `nc://notes/{id}` e ler arquivos e notas como recursos. Recurso sem permissão, inexistente ou oculto falha fechada.
- **Ocultar arquivos sensíveis por etiqueta.** O administrador escolhe etiquetas de sistema nas configurações de admin e tudo o que as tiver, inclusive o conteúdo de uma pasta etiquetada, fica invisível para as ferramentas — leitura, busca, listagem, visão de imagens e destinos de escrita agem como se não existisse. Em caso de dúvida, oculta; e os backups do app recebem a etiqueta antes de receber qualquer conteúdo.
- **Entrar pelo ChatGPT e pelo Gemini CLI.** `chatgpt.com` é aceito ao lado de `claude.ai`, e um cliente nativo embutido e opcional (`nextcloud-mcp-native`, desligado por padrão) cobre programas locais como o Gemini CLI. Ambos se configuram na nova seção de admin **Clientes OAuth**, sem passo no terminal. *Compatível por código, ainda não comprovado em cliente real* — veja [Conectando um cliente](#conectando-um-cliente).
- **Uma seção de configurações de verdade.** O app tem uma entrada própria **MCP for Nextcloud**, com ícone, nos menus de administração e pessoal, e a página de admin é dividida em blocos: Status, Clientes OAuth, Arquivos ocultos e etiquetas, OCR, Permissões e Conexões ativas.
- **Retirar qualquer tipo de arquivo.** O `files_checkout` agora aceita DOCX, XLSX, PDF e imagens até o limite de envio, e não só texto. O próprio limite passa a ser editável em MiB pela página de admin, em vez de só por `occ`.

Quem vem de uma versão mais antiga deve fazer backup do `config/config.php` antes, e ler o [CHANGELOG](CHANGELOG.md) para as mudanças de segurança desta versão.

## Funcionalidades

| Módulo | Leitura | Escrita (cada uma exige liberação do admin) |
|---|---|---|
| **Arquivos** | listar, buscar (por nome ou conteúdo com trechos via fulltextsearch), buscar imagens por tag/data, ver imagens e prévias em lote (JPEG, PNG, WebP, GIF, TIFF, PDF), ler texto (TXT/MD, PDF, DOCX, ODT; um escaneado sem texto retorna `text_layer: false` e, com o app opcional Workflow OCR ativo, ganha texto em segundo plano), listar e ler versões do arquivo | editar texto com diff e substituição de trecho, com backup conferido em `/MCP backups` e versão do Nextcloud antes de cada gravação; restaurar uma versão guardada; retirar qualquer arquivo (DOCX, XLSX, PDF, imagens, até o limite de envio) para um agente com terminal por um link de uso único; reorganizar pastas: varrer a árvore, criar pasta, copiar, mover, mover um lote e desfazer. **Nunca exclui.** |
| **Notas** | listar, ler, buscar (por título e conteúdo Markdown) | criar, editar, mover entre categorias, excluir (só quando a lixeira permite recuperar) |
| **Calendário** | listar calendários e eventos (recorrência, fusos, dia inteiro) | criar, editar, mover, excluir e transferir eventos pelo pipeline CalDAV de verdade, com participantes. As escritas ficam desligadas até o administrador liberá-las na matriz de permissões (sem passo no terminal), e cada uma devolve antes um plano: **nada é gravado antes de o usuário aprovar.** |
| **Contatos** | listar catálogos pessoais/compartilhados, buscar e ler contatos (sem catálogo de usuários do sistema) | criar e editar preservando campos desconhecidos do vCard; excluir permanentemente só após backup `.vcf` verificado em `/MCP backups/Contacts` |
| **Tarefas** | listar agendas de tarefas e VTODO, incluindo tarefas sem data; ler tarefas | criar, editar, concluir e excluir tarefas simples pelo CalDAV; exclusão usa a lixeira do calendário e recusa retenção zero |
| **Deck** | quadros, listas, cards e **acompanhamento** dos quadros que você gerencia: cards por responsável, com prazo, indicação de atraso (no seu fuso) e link do card | criar, editar, mover e excluir cards |
| **Talk** | conversas e mensagens (nunca marca como lido) | responder (podendo citar uma mensagem ou incluir o link de um card do Deck ou evento que você pode ver), mensagem direta para um usuário, lote de mensagens, compartilhar ou citar arquivo, criar conversa em grupo (permissão própria, desligada por padrão). **Nada é enviado antes de o usuário aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou compartilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registro no servidor.** |

E também:

- **Confirmação conversacional antes de qualquer escrita (`confirm: true`)**: qualquer ferramenta que altera dados (`operation != 'read'`) só executa quando chamada com `confirm: true`. Sem isso, o servidor apenas devolve o plano (`requiresConfirmation: true`) sem modificar nada. O assistente deve apresentar o plano ao usuário e só repetir a chamada com `confirm: true` após aprovação explícita. Nenhum estado ou token de aprovação é gravado no servidor.
- **Recursos MCP (MCP Resources)**: suporte nas duas gerações do protocolo (`resources: {}`). Clientes podem listar `mcp://guide` para ler o guia das ferramentas diretamente, descobrir templates de recursos (`nc://files/{path}` e `nc://notes/{id}`) filtrados pelos grants e apps do usuário, e ler arquivos de texto (com extração automática de texto), notas e pequenos binários em base64 (<= 512 KiB). Recursos sem permissão, inexistentes ou ocultos falham com código padrão.
- **OCR pelo Workflow OCR**: com o app Workflow OCR instalado, os PDFs escaneados ganham uma camada de texto que o `files_read` lê normalmente. Sem ele, um PDF ou imagem sem texto volta com `text_layer: false` e um aviso, e a página de admin mostra o status do OCR com o link para instalar. Nada fica bloqueado.
- **Guia das ferramentas** (`mcp_guide`): o modelo pergunta ao servidor o que cada ferramenta faz, seus parâmetros, limites e se exige confirmação. O guia é montado das mesmas definições que o `tools/list` entrega, filtrado pelas permissões e apps do usuário. Ele é escrito em inglês e o modelo repassa no idioma do usuário.
- **Nomes amigáveis das ferramentas** no cliente ("Buscar arquivos", "Listar calendários") e anotações MCP (`readOnlyHint`, `destructiveHint`), para o cliente pedir confirmação em ações de risco.
- **Confirmação de segurança** em recursos de outras pessoas: pastas compartilhadas, pastas de time, quadros do Deck e calendários de outro dono. O servidor recusa a primeira chamada e devolve uma mensagem pronta. O assistente precisa perguntar ao usuário antes de repetir com `confirm_shared: true`. *(Deck e Calendário desde a 0.6.6; Arquivos e Notas desde a 0.7.0)*
- **Apps opcionais respeitados**: as tools e as colunas da matriz de admin de um app desativado (para todos ou para um usuário) ficam ocultas.
- **Interface traduzida**: a matriz de admin, a página pessoal, a tela de consentimento e toda mensagem mostrada por uma tool seguem o idioma da conta Nextcloud do usuário. Todos os módulos estão cobertos (Arquivos, Notas, Calendário, Contatos, Tarefas, Deck, Talk, as mensagens compartilhadas, as páginas de checkout e o selftest do calendário). Inglês, português do Brasil e espanhol vêm incluídos (o espanhol é uma primeira tradução e ainda precisa de revisão de um nativo); as descrições das tools ficam em inglês, porque quem as lê é o modelo.
- **As duas gerações do MCP** no mesmo endpoint: `2026-07-28`, sem estado (`server/discover`), e o fluxo clássico com `initialize` (`2025-06-18` e anteriores).

## Contatos e tarefas

Contatos usam o CardDAV do core e apenas os catálogos próprios e compartilhados com você. A edição altera os campos fornecidos e preserva propriedades desconhecidas, parâmetros, grupos e a versão do vCard. O app Contacts precisa estar habilitado para o usuário. Tarefas usam VTODO nas agendas que suportam esse componente, sem depender do app opcional Tasks nem da interface Calendar.

Os dois módulos têm grants próprios de `read`, `create`, `edit` e `delete`; toda escrita começa desligada. Sem `confirm: true`, a chamada devolve o plano real de antes/depois e não cria backups nem faz escritas DAV. Coleções de outro dono exigem `confirm_shared: true`. O ETag é opcional; quando enviado, precisa conferir, e edições/exclusões confirmadas sempre usam o ETag atual em `If-Match`.

**Excluir contato é permanente: o Nextcloud não tem lixeira de contatos.** O plano mostra todos os campos do vCard e esse aviso. Após a confirmação, o vCard original completo é salvo e relido na pasta do usuário que executa a ação: `/MCP backups/Contacts/<catálogo>/<nome-ou-uid>.<AAAAmmdd-HHMMSS>-<sufixo-único>.vcf`. Arquivos existentes nunca são escolhidos para sobrescrita; falha no backup impede a exclusão. O resultado informa `backupPath`; importe o arquivo pela interface Contacts para recuperar o contato. Essa cópia é uma proteção, não uma lixeira nativa de contatos.

A exclusão de tarefas usa a lixeira nativa do calendário e é recusada com `dav/calendarRetentionObligation` em `0`. Tarefas recorrentes podem ser lidas; alterar tarefas recorrentes ou com participantes é recusado para proteger a série e o agendamento. Tarefas concluídas ficam fora da listagem por padrão; `include_completed` permite incluí-las. Busca de contatos e listagem de tarefas aceitam `limit` (até 200) e `offset`, devolvendo `nextOffset` quando há mais resultados.

## Requisitos

- Nextcloud **33**
- PHP **8.2+** com `zip`, `mbstring` e `dom`
- Apps opcionais, só para as próprias ferramentas (ocultas enquanto o app estiver desativado): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versões (`files_versions`, obrigatório para editar), Arquivos excluídos (`files_trashbin`, obrigatório para excluir)

## Instalação

1. **Gere o pacote** em qualquer máquina com PHP e Composer:

   ```bash
   cd mcp
   ./scripts/package.sh          # gera build/mcp-<versão>.tar.gz
   ```

   O script empacota só arquivos de produção e confere o arquivo final: metadados do macOS (`._*`), pastas extras na raiz e assets faltando.

2. **Copie e habilite no servidor:**

   ```bash
   scp build/mcp-<versão>.tar.gz usuario@servidor:/tmp/
   ssh usuario@servidor
   sudo tar -xzf /tmp/mcp-<versão>.tar.gz -C /var/www/nextcloud/apps/
   sudo chown -R www-data:www-data /var/www/nextcloud/apps/mcp
   sudo -u www-data php /var/www/nextcloud/occ app:enable mcp
   ```

   Ajuste os caminhos e o usuário do servidor web ao seu ambiente. Com Docker, rode o `occ` dentro do container. Esse caminho manual é só uma alternativa: instalando pela App Store do Nextcloud não há passo no terminal.

3. **Configure** em *Configurações de administração → MCP for Nextcloud* (o app tem entrada própria, com ícone, no menu de configurações): ligue o serviço, marque quem pode conectar, libere as permissões de escrita necessárias e selecione etiquetas de sistema para ocultar arquivos sensíveis. A página é dividida em blocos: **Status** (endpoint para copiar, serviço, versão do app, usuários habilitados/conectados, conexões ativas e o limite de envio do checkout editável em MiB, ao lado do teto `post_max_size` do PHP), **Clientes OAuth**, **Arquivos e etiquetas ocultas**, **OCR**, **Permissões** (a matriz usuários × permissões, com busca, grupo, filtros "pode conectar / conectados", menu "Todos" por módulo para os usuários da página e paginação em cima e embaixo) e **Conexões ativas**.

## Conectando um cliente

Todos os clientes falam com a mesma URL, `https://cloud.example.com/apps/mcp/`, e se autenticam como quem está usando, com as permissões dessa pessoa. Só a etapa de autenticação muda:

| Cliente | Autenticação | O que o administrador precisa fazer antes |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | **Entrar agora** (OAuth), identidade publicada do Claude (CIMD) | Nada: o `claude.ai` é permitido por padrão |
| **ChatGPT** | **Entrar agora** (OAuth), identidade publicada da OpenAI (CIMD) | Nada: o `chatgpt.com` é permitido por padrão |
| **Gemini CLI** | OAuth com o cliente nativo embutido no app | Ligar **Permitir programas locais** e copiar o `client_id` |
| **Qualquer outro cliente MCP** | Senha de app (HTTP Basic) | Nada |

### Claude (claude.ai, Claude Desktop, Cowork e Claude Code — recomendado)

1. *Configurações → Conectores → Adicionar conector personalizado*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Autenticação: **Entrar agora** (OAuth). Cliente: **identidade publicada do Claude** (CIMD). Não precisa de cabeçalho.
4. **Vincular**: o seu Nextcloud abre, você faz login e clica em **Permitir**.

O `claude.ai` já está na lista de hosts de clientes permitidos, então nada precisa ser mudado no servidor. Se um administrador restringiu essa lista, o Claude é recusado com `invalid_client` antes mesmo de a tela de login abrir: peça para ele recolocar o `claude.ai` em *Configurações de administração → MCP for Nextcloud → Clientes OAuth → Hosts de clientes permitidos*.

### ChatGPT

> **Compatível por código, ainda não comprovado em cliente real.** O lado do servidor está implementado e coberto por testes, mas o primeiro teste de ponta a ponta contra um conector real do ChatGPT só acontece depois que a 0.8.0 for publicada. Até lá, trate como esperado funcionar e ainda não verificado.

1. No ChatGPT, adicione um novo conector para servidor MCP e cole `https://cloud.example.com/apps/mcp/` como URL.
2. Autenticação: **Sign in** (OAuth). O ChatGPT se identifica com o documento de identidade de cliente publicado em `chatgpt.com`; o app baixa esse documento para descobrir os redirect URIs registrados. Não precisa de cabeçalho nem de senha de app.
3. **Conectar**: o seu Nextcloud abre, você faz login e clica em **Permitir**.

O `chatgpt.com` é permitido por padrão, então uma instalação nunca configurada já aceita o ChatGPT. Se um administrador restringiu a lista para outros hosts, o ChatGPT é recusado com `invalid_client` antes da tela de login — peça para ele recolocar o `chatgpt.com` em *Configurações de administração → MCP for Nextcloud → Clientes OAuth → Hosts de clientes permitidos*.

### Gemini CLI

> **Compatível por código, ainda não comprovado em cliente real.** O lado do servidor está implementado e coberto por testes, mas o primeiro teste de ponta a ponta contra um login real do Gemini CLI só acontece depois que a 0.8.0 for publicada. Até lá, trate como esperado funcionar e ainda não verificado.

Dois passos, um do administrador e um do usuário, sem nenhum passo no terminal do servidor.

**Administrador** — *Configurações de administração → MCP for Nextcloud → Clientes OAuth*:

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

*Configurações de administração → MCP for Nextcloud → Clientes OAuth* decide quais clientes podem conectar, sem nenhum passo no terminal:

- **Hosts de clientes permitidos** — os hosts cujo documento de identidade de cliente publicado o app aceita baixar, separados por vírgula; `claude.ai` e `chatgpt.com` por padrão. Só nome de host exato: sem `https://`, sem porta, sem caminho, sem `*`, e é preciso deixar pelo menos um. O host é o que está na URL que o cliente usa como `client_id`. Salvar substitui a lista inteira, e remover um host recusa aquele cliente na hora, mesmo que o documento dele já esteja em cache.
- **Permitir programas locais (cliente nativo)** — liga o cliente `nextcloud-mcp-native` descrito acima e mostra o `client_id` e os redirect URIs aceitos para copiar.

Os dois controles são exclusivos de administrador, têm proteção CSRF e avisam quando uma gravação é recusada. Uma configuração que você mesmo gravou não é sobrescrita pelos padrões: um valor de `oauth_client_hosts` salvo antes da 0.8.0 continua valendo como está.

### Outros clientes MCP (senha de app)

Crie uma senha de app em *Configurações pessoais → Segurança* e ative a conexão em *Configurações pessoais → MCP for Nextcloud*. Depois configure o cliente com a URL acima e autenticação HTTP Basic (`usuario:senha-de-app`). Teste rápido:

```bash
curl -u 'alice:SENHA-DE-APP' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revogando acesso

- **Usuário**: *Configurações pessoais → MCP for Nextcloud → Desconectar* bloqueia a próxima requisição e revoga os tokens OAuth. A mesma página lista os próprios clientes OAuth (cliente, conectado em, expira em) com **Revogar** em cada um. Senhas de app se revogam em *Segurança*.
- **Admin**: remova a permissão de conectar ou desligue o serviço. Vale a partir da próxima requisição. *Configurações de administração → MCP for Nextcloud → Conexões ativas* lista toda conexão OAuth (usuário, cliente, conectado em, expira em) e revoga uma, ou todas de um usuário, na hora; o usuário pode entrar de novo enquanto tiver permissão para conectar.

## Segurança

- Cada chamada confere de novo, nesta ordem: identidade autenticada, serviço ligado, permissão de conectar, conexão pessoal, liberação do admin para aquela operação e permissões do Nextcloud no recurso concreto.
- OAuth: PKCE S256, redirect URI idêntico (um redirect URI loopback pode usar qualquer porta, RFC 8252 7.3), documentos de identidade de cliente aceitos só dos hosts permitidos que o administrador edita na tela de admin (padrão `claude.ai` e `chatgpt.com`), o método público `none` como único método de autenticação de token aceito, tokens guardados só como hash HMAC, refresh de uso único com rotação.
- Um documento de identidade de cliente é baixado por HTTPS, só de host permitido, sem seguir redirecionamentos, limitado a 64 KiB e mantido em cache por uma hora. Tirar um host da lista bloqueia o cliente na hora, com cache ou sem cache.
- Todo redirecionamento de volta a um cliente carrega o `iss` com o identificador de emissor deste servidor (RFC 9207) — no código de autorização, numa negação e em erros redirecionáveis. Erros que ocorrem antes de o redirect URI ser validado aparecem numa página local, em vez de serem redirecionados.
- O cliente nativo é desligado por padrão. Uma vez ligado, ele é um cliente público, com `client_id` fixo e não secreto, que só pode voltar para um callback loopback, e não concede acesso por si só: login, elegibilidade, conexão pessoal, liberações do administrador, tela de consentimento e PKCE continuam valendo. Desligá-lo também impede a troca de código de autorização e a renovação de token.
- A seção "Clientes OAuth" do admin é exclusiva de administrador, tem proteção CSRF, recusa uma lista de hosts inválida em vez de gravá-la, e mostra o estado efetivo e o erro de gravação.

- Nada de senha, token ou conteúdo de arquivo em log. Os erros devolvidos ao cliente são genéricos.
- A tela de consentimento tem proteção CSRF e não pode ser embutida em frame.
- Encontrou uma vulnerabilidade? Reporte em privado aos mantenedores, e não numa issue pública.

## Desenvolvimento

```bash
cd mcp
composer install
vendor/bin/phpunit          # testes unitários com mocks do OCP, sem precisar de Nextcloud
```

Organização: `lib/Tools/<Módulo>` (um `ToolModule` por app), `lib/OAuth` (servidor de autorização), `lib/Service` (protocolo MCP, política de permissões), `lib/Controller`, `templates`, `js`, `css` e `l10n`. As notas técnicas detalhadas ficam em [`mcp/README.md`](mcp/README.md).

A raiz do repositório também guarda o **protótipo original em Node.js via stdio** (`src/`, `tests/`). É um servidor MCP só de leitura, que serviu de referência de comportamento para o app nativo.

## Histórico de versões

Veja o [`CHANGELOG.md`](CHANGELOG.md) (em inglês).

## Roadmap

- Revisão nativa da tradução para o espanhol
- Publicação na App Store do Nextcloud

## Licença

AGPL-3.0-or-later. Dependência empacotada: [`smalot/pdfparser`](https://github.com/smalot/pdfparser) (LGPL-3.0).
