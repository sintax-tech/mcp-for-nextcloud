# App MCP para Nextcloud

Versão de teste interno para **Nextcloud 33**. Expõe MCP `2025-06-18` via Streamable HTTP sem sessão e sem SSE, na rota do próprio app:

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
| `calendar_create_event` | calendar.create | Evento simples, sem recorrência, em calendário com permissão de escrita. |
| `calendar_update_event` | calendar.edit | Título, local, descrição ou datas; datas de série recorrente não mudam. |
| `calendar_move_event` | calendar.move | Para outro calendário do mesmo dono, sem sobrescrever. |
| `calendar_delete_event` | calendar.delete | Série inteira para a lixeira do calendário; exige `confirm: true`. |
| `calendar_transfer_event` | calendar.transfer | Para um calendário de outro usuário, compartilhado com permissão de escrita; exige `confirm: true`. |

Tools de Calendar exigem o app Calendar habilitado para o usuário e usam o `CalDavBackend` do app DAV do core, carregado só quando uma tool de calendário é chamada. Escritas não enviam convites nem notificações aos participantes. `sabre/vobject` vem do core e não está no `vendor/` do pacote.

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
scp build/mcp-0.3.0.tar.gz <servidor>:/tmp/
ssh <servidor>
sudo tar -xzf /tmp/mcp-0.3.0.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

Em Docker, rode os comandos `occ` dentro do container como o usuário do servidor web. Se o PHP usa OPcache com `validate_timestamps=0`, reinicie o PHP-FPM após copiar os arquivos.

Para atualizar: `occ app:disable mcp`, remover `<apps>/mcp`, extrair o novo pacote, `occ app:enable mcp` e `occ upgrade` se o Nextcloud pedir. A pasta precisa ser trocada inteira, para não sobrarem arquivos antigos em `vendor/`. Para remover: `occ app:remove mcp`.

## Configurar

1. **Administração → Configurações adicionais → MCP**: ativar o serviço, carregar o ID do usuário e clicar em *Allow connection*. A matriz de grants por módulo e operação fica na mesma tela. Para usar edição de arquivos ou escrita em notas, libere `files.edit` e `notes.create/edit/move/delete` para o usuário. Serviço, elegibilidade e conexão pessoal começam desligados para todos, inclusive administradores. Leitura começa permitida e escrita, exclusão e transferência começam negadas.
2. **Configurações pessoais → Informações pessoais → MCP connection**: o próprio usuário clica em *Connect*.
3. Em **Configurações pessoais → Segurança**, o usuário cria uma senha de app. O cliente MCP usa autenticação HTTP Basic com o ID do usuário e essa senha de app. O app nunca pede nem guarda a senha principal.

Login pelo navegador (Login Flow v2/OAuth) ainda não está disponível.

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
| Falta `MCP-Protocol-Version: 2025-06-18` depois do `initialize`, ou versão diferente | 400 |
| JSON inválido (`-32700`), lote ou envelope inválido (`-32600`) | 400 |
| Método desconhecido (`-32601`) ou parâmetros inválidos (`-32602`) | 200 com erro JSON-RPC |
| GET ou DELETE | 405 |

## Desenvolvimento

```sh
composer install
vendor/bin/phpunit
```

Os testes são unitários, com mocks das interfaces OCP (`nextcloud/ocp` stable33) e uma árvore de arquivos em memória (`tests/Unit/Tools/FakeTree.php`). Eles não substituem a instalação num Nextcloud real, que é o único lugar onde se verificam ACL de compartilhamento, criação de versão, lixeira e storages externos.
