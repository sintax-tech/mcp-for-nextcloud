# App MCP para Nextcloud

Versão de teste interno para **Nextcloud 33**. O endpoint atende clientes MCP 2026-07-28, sem estado, via `server/discover` e `_meta` por requisição, e também clientes anteriores, que usam `initialize` com 2025-06-18. Expõe MCP `2025-06-18` via Streamable HTTP sem sessão e sem SSE, na rota do próprio app:

- `https://<instância>/apps/mcp/` com URLs limpas;
- `https://<instância>/index.php/apps/mcp/` sem URLs limpas.

A URL exata da instância aparece nas páginas de administração e pessoal.

## Tools

Cada tool só aparece em `tools/list` e só pode ser chamada quando o usuário tem o grant da operação, e o app exigido está habilitado para ele. Grant e app são verificados de novo a cada chamada. Tudo roda como o usuário autenticado, pela pasta dele no Nextcloud, e as permissões e compartilhamentos do Nextcloud continuam valendo.

| Tool | Grant | Observações |
| --- | --- | --- |
| `mcp_status` | — | Diagnóstico; não lê dados do usuário. |
| `files_list` `{path="/"}` | files.read | Filhos diretos da pasta, no formato `{name, path, isDir, size, mtime, contentType}`. |
| `files_search` `{query, limit=25}` | files.read | Busca por nome (`%`, `_` e `\` são literais), de 1 a 100 resultados; o limite vai para a própria consulta ao cache de arquivos. |
| `files_read` `{path}` | files.read | Texto puro, PDF, DOCX e ODT. Arquivo acima de 20 MiB é recusado antes da leitura, e o texto é truncado em 100 000 caracteres. |
| `files_edit` `{path, content, etag?}` | files.edit | Só arquivo de texto existente, com no máximo 10 MiB. Antes de gravar, exige o `files_versions` ativo e copia o original para `/MCP backups/<caminho>/<nome>.<AAAAmmdd-HHMMSS>.bak`; se qualquer passo falhar, nada é gravado. Com `etag` divergente, nada é gravado. Não cria, move nem exclui. |
| `notes_list` `{}` | notes.read | Notas `.md`/`.txt` da pasta do app Notes (preferência `notesPath`, padrão `Notes`). |
| `notes_read` `{id}` | notes.read | Nota com conteúdo; limite de 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Nunca sobrescreve; em colisão, usa `Título (2)`. |
| `notes_edit` `{id, content?, title?, etag?}` | notes.edit | Conteúdo e/ou título. |
| `notes_move` `{id, category, etag?}` | notes.move | Troca de categoria (subpasta), sem sobrescrever. |
| `notes_delete` `{id, confirm: true, etag?}` | notes.delete | Exige `confirm: true`, a lixeira (`files_trashbin`) ativa e o storage da nota coberto por ela; em storage externo sem lixeira, a exclusão é bloqueada. |

| `calendar_list_calendars` `{}` | calendar.read | Calendários visíveis ao usuário, próprios e compartilhados. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Eventos no intervalo (padrão: próximos 7 dias), com recorrência expandida. |

Por enquanto, Calendar oferece apenas leitura: as cinco tools de escrita estão ocultas de `tools/list`, chamadas diretas falham fechadas e suas colunas não aparecem na matriz administrativa até ser provado o caminho oficial DAV do Nextcloud. Os grants de escrita já salvos são preservados internamente para eventual reativação depois da validação. As tools de leitura exigem o app Calendar habilitado para o usuário e usam o backend CalDAV do app DAV, carregado só quando uma tool de calendário é chamada. O runtime/plugins do Nextcloud 33 não foram validados; antes de reativar escrita, é obrigatório testar PUT/DELETE/MOVE, ACL compartilhada, convites e efeitos num ambiente de integração Nextcloud 33 real. `sabre/vobject` vem do core e não está no `vendor/` do pacote.

Tools de Notes exigem o app Notes habilitado para o usuário. Leitura começa permitida; criação, edição, movimentação e exclusão começam negadas até o administrador liberar. Não há timeout próprio: a leitura é local ao PHP e o limite de bytes protege contra arquivos grandes. Storage externo lento fica limitado ao `max_execution_time` do PHP.

Argumentos inválidos, tool inexistente ou sem grant retornam o erro JSON-RPC `-32602`, sem distinguir o motivo. Falhas de execução retornam `isError: true` com uma mensagem genérica, sem caminho físico, conteúdo ou stack trace.

## Empacotar

No diretório `mcp/`:

```sh
./scripts/package.sh
```

Gera `build/mcp-<versão>.tar.gz` com raiz `mcp/` contendo `appinfo/`, `lib/`, `templates/`, as pastas de assets que existirem (`js/`, `css/`, `img/`, `l10n/`), este README e um `vendor/` só de produção, criado com `composer install --no-dev`. Testes e dependências de desenvolvimento ficam fora. O script falha, e não deixa pacote, quando um `Util::addScript('mcp', X)` ou `Util::addStyle('mcp', X)` em `templates/` ou `lib/` aponta para `js/X.js` ou `css/X.css` ausente do pacote. O script precisa de `composer` na máquina que empacota.

### Regra do pacote: sem metadados do macOS

Um pacote com `._*` (AppleDouble), `.DS_Store` ou cabeçalhos PAX com xattrs `com.apple.*` é recusado pela App Store e pode quebrar a instalação. Por isso:

- o tar roda como `COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' --exclude='.DS_Store'`. O `--no-xattrs` foi acrescentado porque, sem ele, o bsdtar grava `com.apple.provenance` em cabeçalhos PAX de todas as entradas;
- a conferência é feita por `scripts/check_package.py`, com o `tarfile` do Python, porque o `tar -tzf` do macOS esconde as entradas `._*`;
- o pacote é recusado, apagado, e o script termina com código 1, se tiver:
  - entrada `._*` ou `.DS_Store`;
  - entrada PaxHeader ou cabeçalho PAX estendido;
  - entrada de topo diferente de `mcp/`;
  - script ou estilo referenciado por `Util::addScript('mcp', X)`/`Util::addStyle('mcp', X)` ausente do pacote.

Para conferir um pacote avulso: `python3 scripts/check_package.py build/mcp-<versão>.tar.gz`.

### Dependências de runtime

| Pacote | Uso | Licença |
| --- | --- | --- |
| `smalot/pdfparser` | Extração de texto de PDF em `files_read` | LGPL-3.0 |
| `symfony/polyfill-mbstring` | Dependência do pdfparser; inativo quando a extensão `mbstring` existe | MIT |

Os arquivos de licença acompanham cada pacote em `vendor/`. O `vendor/autoload.php` é carregado pelo `lib/AppInfo/Application.php`.

## Instalar via SSH

Substitua `<servidor>`, `<nextcloud>` (raiz da instalação), `<apps>` (diretório de apps gravável, por exemplo `custom_apps` ou `apps`, conforme `apps_paths` em `config/config.php`) e `<www>` (usuário do servidor web, por exemplo `www-data`).

```sh
scp build/mcp-0.6.8.tar.gz <servidor>:/tmp/
ssh <servidor>
sudo tar -xzf /tmp/mcp-0.6.8.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

Em Docker, rode os comandos `occ` dentro do container como o usuário do servidor web. Se o PHP usa OPcache com `validate_timestamps=0`, reinicie o PHP-FPM após copiar os arquivos.

Para atualizar: `occ app:disable mcp`, remover `<apps>/mcp`, extrair o novo pacote, `occ app:enable mcp` e `occ upgrade` se o Nextcloud pedir. A pasta precisa ser trocada inteira, para não sobrarem arquivos antigos em `vendor/`. Para remover: `occ app:remove mcp`.

## Configurar

1. **Administração → Configurações adicionais → MCP**: marcar *Serviço MCP ativado* e usar a matriz de usuários × permissões.
   - A busca procura por nome, ID ou e-mail, e há um filtro por grupo; a tabela mostra 50 usuários por página.
   - Cada checkbox salva na hora: *Pode conectar* libera o usuário, e as demais colunas são as operações por módulo.
   - Os botões ✓/✕ no cabeçalho aplicam a coluna aos usuários ativos da página, com confirmação.
   - Usuários desativados aparecem marcados e não podem ser editados. Módulos cujo app não está disponível no servidor não aparecem na matriz; para um usuário sem acesso ao app, a célula fica desativada. As permissões salvas são preservadas.
   - O e-mail só serve para a busca e nunca é exibido.
   - Serviço, elegibilidade e conexão pessoal começam desligados para todos, inclusive administradores. Leitura começa permitida; escrita, exclusão e transferência começam negadas.
   - A página usa a API JSON admin-only `GET /apps/mcp/api/grants`, `PUT /apps/mcp/api/grants/{uid}`, `POST /apps/mcp/api/grants/bulk` e `PUT /apps/mcp/api/service`.
2. **Configurações pessoais → Informações pessoais → MCP connection**: o próprio usuário clica em *Connect*.
3. Em **Configurações pessoais → Segurança**, o usuário cria uma senha de app. O cliente MCP usa autenticação HTTP Basic com o ID do usuário e essa senha de app. O app nunca pede nem guarda a senha principal.

O login pelo navegador via OAuth está descrito em [Conectar pelo claude.ai (OAuth)](#conectar-pelo-claudeai-oauth).

### Chaves de configuração

| Escopo | Chave | Valores |
| --- | --- | --- |
| app `mcp` | `service_enabled` | `1` ligado, `0` ou ausente desligado |
| usuário, app `mcp` | `eligible` | `1` liberado pelo administrador |
| usuário, app `mcp` | `connected` | `1` conexão pessoal ativa |
| usuário, app `mcp` | `grant_<módulo>_<operação>` | `1`/`0`; ausente vale `1` para `read` e `0` para as demais |

A chave `enabled` do app `mcp` pertence ao Nextcloud, que a usa para marcar o app como habilitado (`yes`), e não é o liga/desliga do MCP. O app nunca lê nem grava `enabled`, `installed_version`, `types`, `levels` ou `ocsid`. Para diagnóstico: `occ config:app:get mcp service_enabled`.

## Conectar pelo claude.ai (OAuth)

No claude.ai, em **Personalizar > Conectores > Adicionar conector personalizado**, cole a URL exibida na página pessoal (ex.: `https://<instância>/apps/mcp/`), com a barra final, e deixe o cliente OAuth em **identidade publicada do Claude (CIMD)**. O Claude abre o login do Nextcloud e a tela "Permitir"; permitir equivale a ativar a conexão pessoal. Tudo fica sob `/apps/mcp`, sem mudança de Apache/DNS:

- `401` com `WWW-Authenticate: Bearer resource_metadata=".../apps/mcp/.well-known/oauth-protected-resource"`;
- metadata do servidor de autorização em `/apps/mcp/.well-known/openid-configuration` e `/apps/mcp/.well-known/oauth-authorization-server` (issuer `https://<instância>/apps/mcp`);
- `oauth/authorize` (consentimento) e `oauth/token` (PKCE S256, refresh com rotação). Access token 1 h, refresh 30 dias; só hashes vão ao banco.

Só clientes CIMD com host na allowlist são aceitos (padrão `claude.ai`): `occ config:app:set mcp oauth_client_hosts --value="claude.ai"`. Disconnect pessoal, perda de elegibilidade ou serviço desligado revogam access e refresh. O Claude sai de `160.79.104.0/21`; se a proteção brute force do Nextcloud atrasar o endpoint de token, libere essa faixa. Basic + app password continua valendo para outros clientes.

## Revogar

*Disconnect* na página pessoal bloqueia a próxima requisição MCP, inclusive com a mesma senha de app. A senha de app continua válida no Nextcloud e deve ser revogada em **Segurança**. O administrador também pode remover a elegibilidade do usuário ou desativar o serviço; ambos valem na próxima requisição.

## Teste manual após a instalação

```sh
URL=https://<instância>/apps/mcp/
AUTH='<usuário>:<senha-de-app>'
H='-H Content-Type:application/json -H Accept:application/json,text/event-stream'

curl -s -u "$AUTH" $H -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' "$URL"
curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$URL"   # 202
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' "$URL"
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp_status","arguments":{}}}' "$URL"
```

Respostas esperadas:

| Situação | HTTP |
| --- | --- |
| Sem credencial, credencial inválida ou usuário desativado | 401 com `WWW-Authenticate: Basic` |
| Serviço desligado, usuário inelegível ou desconectado | 403 |
| `Origin` de outro host | 403 |
| Faltando `Accept: application/json, text/event-stream` ou `Content-Type: application/json` | 406 |
| `MCP-Protocol-Version` desconhecida ou malformada: responde `UnsupportedProtocolVersionError` (`-32022`, `data.supported`). As versões legadas aceitas depois do `initialize` são 2025-03-26, 2025-06-18 e 2025-11-25; header ausente vale 2025-03-26, e no `initialize` o header é ignorado. Também dá 400 quando `MCP-Protocol-Version`, `Mcp-Method` ou `Mcp-Name` divergem do corpo (`-32020`). O motivo de cada 400 vai para o log em nível debug. | 400 |
| JSON inválido (`-32700`), lote ou envelope inválido (`-32600`) | 400 |
| Método desconhecido (`-32601`) ou parâmetros inválidos (`-32602`) | 200 com erro JSON-RPC |
| GET ou DELETE | 405 |

## Desenvolvimento

```sh
composer install
vendor/bin/phpunit
```

Os testes são unitários, com mocks das interfaces OCP (`nextcloud/ocp` stable33) e uma árvore de arquivos em memória (`tests/Unit/Tools/FakeTree.php`). Eles não substituem a instalação num Nextcloud real, que é o único lugar onde se verificam ACL de compartilhamento, criação de versão, lixeira e storages externos.
