<div align="center">

# MCP for Nextcloud

**App nativo do Nextcloud que transforma o seu Nextcloud em um servidor MCP.**
Deixe o Claude e outros clientes MCP trabalharem com seus arquivos, notas, calendários, quadros do Deck e conversas do Talk. Cada usuário usa as próprias permissões, e nada sai do servidor sem liberação do admin.

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

## Funcionalidades

| Módulo | Leitura | Escrita (cada uma exige liberação do admin) |
|---|---|---|
| **Arquivos** | listar, buscar por nome, buscar imagens por tag/data, ver imagens e prévias em lote (JPEG, PNG, WebP, GIF, TIFF, PDF), ler texto (TXT/MD, PDF, DOCX, ODT; um escaneado sem texto retorna `text_layer: false` e, com o app opcional Workflow OCR ativo, ganha texto em segundo plano), listar e ler versões do arquivo | editar com diff e substituição de trecho, com backup conferido em `/MCP backups` e versão do Nextcloud antes de cada gravação; restaurar uma versão guardada; retirar um arquivo para um agente com terminal por um link de uso único; reorganizar pastas: varrer a árvore, criar pasta, copiar, mover, mover um lote e desfazer. **Nunca exclui.** |
| **Notas** | listar, ler | criar, editar, mover entre categorias, excluir (só quando a lixeira permite recuperar) |
| **Calendário** | listar calendários e eventos (recorrência, fusos, dia inteiro) | criar, editar, mover, excluir e transferir eventos pelo pipeline CalDAV de verdade, com participantes. As escritas ficam desligadas até o administrador liberá-las na matriz de permissões (sem passo no terminal), e cada uma devolve antes um plano: **nada é gravado antes de o usuário aprovar.** |
| **Deck** | quadros, listas, cards e **acompanhamento** dos quadros que você gerencia: cards por responsável, com prazo, indicação de atraso (no seu fuso) e link do card | criar, editar, mover e excluir cards |
| **Talk** | conversas e mensagens (nunca marca como lido) | responder (podendo citar uma mensagem ou incluir o link de um card do Deck ou evento que você pode ver), mensagem direta para um usuário, lote de mensagens, compartilhar ou citar arquivo, criar conversa em grupo (permissão própria, desligada por padrão). **Nada é enviado antes de o usuário aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou compartilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registro no servidor.** |

E também:

- **Confirmação conversacional antes de qualquer escrita (`confirm: true`)**: qualquer ferramenta que altera dados (`operation != 'read'`) só executa quando chamada com `confirm: true`. Sem isso, o servidor apenas devolve o plano (`requiresConfirmation: true`) sem modificar nada. O assistente deve apresentar o plano ao usuário e só repetir a chamada com `confirm: true` após aprovação explícita. Nenhum estado ou token de aprovação é gravado no servidor.
- **Guia das ferramentas** (`mcp_guide`): o modelo pergunta ao servidor o que cada ferramenta faz, seus parâmetros, limites e se exige confirmação. O guia é montado das mesmas definições que o `tools/list` entrega, filtrado pelas permissões e apps do usuário. Ele é escrito em inglês e o modelo repassa no idioma do usuário.
- **Nomes amigáveis das ferramentas** no cliente ("Buscar arquivos", "Listar calendários") e anotações MCP (`readOnlyHint`, `destructiveHint`), para o cliente pedir confirmação em ações de risco.
- **Confirmação de segurança** em recursos de outras pessoas: pastas compartilhadas, pastas de time, quadros do Deck e calendários de outro dono. O servidor recusa a primeira chamada e devolve uma mensagem pronta. O assistente precisa perguntar ao usuário antes de repetir com `confirm_shared: true`. *(Deck e Calendário desde a 0.6.6; Arquivos e Notas desde a 0.7.0)*
- **Apps opcionais respeitados**: as tools e as colunas da matriz de admin de um app desativado (para todos ou para um usuário) ficam ocultas.
- **Interface traduzida**: a matriz de admin, a página pessoal, a tela de consentimento e toda mensagem mostrada por uma tool seguem o idioma da conta Nextcloud do usuário. Todos os módulos estão cobertos (Arquivos, Notas, Calendário, Deck, Talk, as mensagens compartilhadas, as páginas de checkout e o selftest do calendário). Inglês, português do Brasil e espanhol vêm incluídos (o espanhol é uma primeira tradução e ainda precisa de revisão de um nativo); as descrições das tools ficam em inglês, porque quem as lê é o modelo.
- **As duas gerações do MCP** no mesmo endpoint: `2026-07-28`, sem estado (`server/discover`), e o fluxo clássico com `initialize` (`2025-06-18` e anteriores).

## Requisitos

- Nextcloud **33**
- PHP **8.2+** com `zip`, `mbstring` e `dom`
- Apps opcionais, só para as próprias ferramentas (ocultas enquanto o app estiver desativado): Notes, Calendar, Deck, Talk (`spreed`), Versões (`files_versions`, obrigatório para editar), Arquivos excluídos (`files_trashbin`, obrigatório para excluir)

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

3. **Configure** em *Configurações de administração → Configurações adicionais → MCP*: ligue o serviço, marque quem pode conectar e libere as permissões de escrita necessárias.

## Conectando um cliente

### claude.ai / Claude Desktop (recomendado)

1. *Configurações → Conectores → Adicionar conector personalizado*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Autenticação: **Entrar agora** (OAuth). Cliente: **identidade publicada do Claude** (CIMD). Não precisa de cabeçalho.
4. **Vincular**: o seu Nextcloud abre, você faz login e clica em **Permitir**.

### Outros clientes MCP (senha de app)

Crie uma senha de app em *Configurações pessoais → Segurança* e ative a conexão em *Configurações pessoais → MCP*. Depois configure o cliente com a URL acima e autenticação HTTP Basic (`usuario:senha-de-app`). Teste rápido:

```bash
curl -u 'alice:SENHA-DE-APP' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revogando acesso

- **Usuário**: *Configurações pessoais → MCP → Desconectar* bloqueia a próxima requisição e revoga os tokens OAuth. Senhas de app se revogam em *Segurança*.
- **Admin**: remova a permissão de conectar ou desligue o serviço. Vale a partir da próxima requisição.

## Segurança

- Cada chamada confere de novo, nesta ordem: identidade autenticada, serviço ligado, permissão de conectar, conexão pessoal, liberação do admin para aquela operação e permissões do Nextcloud no recurso concreto.
- OAuth: PKCE S256, redirect URI idêntico, hosts de cliente CIMD em lista permitida (padrão `claude.ai`), tokens guardados só como hash HMAC, refresh de uso único com rotação.
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

- Publicação na App Store do Nextcloud

## Licença

AGPL-3.0-or-later. Dependência empacotada: [`smalot/pdfparser`](https://github.com/smalot/pdfparser) (LGPL-3.0).
