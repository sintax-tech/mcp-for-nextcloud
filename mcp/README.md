# App MCP para Nextcloud

Versão de teste interno para **Nextcloud 33**. O endpoint atende clientes MCP 2026-07-28, sem estado, via `server/discover` e `_meta` por requisição, e também clientes anteriores, que usam `initialize` com 2025-06-18. Expõe MCP `2025-06-18` via Streamable HTTP sem sessão e sem SSE, na rota do próprio app:

- `https://<instância>/apps/mcp/` com URLs limpas;
- `https://<instância>/index.php/apps/mcp/` sem URLs limpas.

A URL exata da instância aparece nas páginas de administração e pessoal.

## Contatos e Tarefas (0.8)

| Módulo | Tools | Dependência |
|---|---|---|
| Contacts | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | app Contacts habilitado; core CardDAV |
| Tasks | `tasks_list_calendars`, `tasks_list_tasks`, `tasks_read_task`, `tasks_create_task`, `tasks_edit_task`, `tasks_complete_task`, `tasks_delete_task` | core DAV; não exige app Tasks ou Calendar |

Os catálogos de Contatos são próprios/compartilhados, filtrando explicitamente qualquer principal de sistema. Leituras usam adapters lazy do backend nativo, depois do filtro de acesso; escritas passam pelo Sabre do core (dispatcher CardDAV separado e o `EmbeddedDavDispatcher` existente para VTODO). A sessão precisa corresponder ao ator e o principal é fixado na execução. `CalendarAccess` mantém VEVENT como padrão e recebe VTODO apenas no módulo Tasks.

`GrantPolicy`/`GrantMatrix` incluem `contacts` e `tasks` com read/create/edit/delete, escritas desligadas por padrão. Concluir tarefa usa edit. `PreviewsWrites` produz o plano sem efeitos colaterais; `WriteGate` exige confirm:true; shares exigem confirm_shared; ETag fornecido é conferido e If-Match usa o relido. Edição de vCard preserva campos desconhecidos, parâmetros, grupos e versão; o plano inclui todas as propriedades antes/depois e o vCard completo.

**Excluir contato é PERMANENTE no Nextcloud 33, sem lixeira.** Só depois de confirmar, `ContactBackup` salva o vCard original completo em `/MCP backups/Contacts/<catálogo>/<nome-ou-uid>.<AAAAmmdd-HHMMSS>-<sufixo-único>.vcf`, no fuso da conta e na pasta do ator. Usa mutex de aplicativo, sufixo aleatório e checagem de colisão para não selecionar arquivos existentes, e confere a cópia relendo os bytes. Falha impede DELETE CardDAV; o resultado informa backupPath. Reimporte pela interface Contacts para recuperar. Nenhum backup é criado no preview.

Tarefas usam VTODO, incluem tarefas sem data, ocultam PRIVATE/CONFIDENTIAL de outro dono e excluem objetos em lixeira. Ler recorrência é permitido; escritas em séries ou tarefas com participantes são recusadas. Campos não alterados permanecem no iCalendar; completar grava STATUS/PERCENT-COMPLETE/COMPLETED, e reduzir progresso reabre a tarefa. Excluir usa a lixeira CalDAV, recusa retenção zero e verifica deleted-at após o DELETE. Listagens usam limit/offset/nextOffset; concluídas exigem include_completed.

Não há migration, approval_id, registro de aprovação, requisito de occ, serviço extra ou banco auxiliar. Descrições/ToolGuideNotes ficam em inglês; títulos e mensagens usam Translator com paridade pt_BR/es. Testes cobrem planos, backup, ACL, grants, ETag, preservação e dispatch real Sabre; não provam os plugins internos em uma instalação Nextcloud real.

## Tools

Cada tool só aparece em `tools/list` e só pode ser chamada quando o usuário tem o grant da operação, e o app exigido está habilitado para ele. Grant e app são verificados de novo a cada chamada. Tudo roda como o usuário autenticado, pela pasta dele no Nextcloud, e as permissões e compartilhamentos do Nextcloud continuam valendo.

### Guia das ferramentas (`mcp_guide`)

`mcp_guide` (título **Guia das ferramentas**) descreve as ferramentas ao próprio modelo, para que ele não precise adivinhar o que cada uma faz:

- **sem argumento** — uma linha por módulo com os títulos das ferramentas, apenas dos módulos disponíveis **para aquele usuário** (o mesmo filtro de grant e app do `tools/list`: módulo desabilitado ou sem grant não aparece);
- **com `module`** (`files`, `notes`, `calendar`, `deck`, `talk`) — cada ferramenta do módulo: descrição, se lê ou escreve, parâmetros com tipo, obrigatoriedade, descrição, `enum` e limites, e as confirmações que exige;
- **com `tool`** — uma só ferramenta, com o módulo a que pertence.

O texto vem das definições reais (`ToolModule::definitions()`, `inputSchema`, `annotations` e `ToolPresentation`), então mudar o schema de uma ferramenta muda o guia: não existe uma segunda cópia da documentação para manter em dia. O que o schema não carrega — como encontrar as coisas, com quais caminhos e IDs, e onde as escritas param — entra em `ToolGuideNotes::guideNotes()`, uma nota curta por módulo, escrita ao lado do módulo. A resposta traz o mesmo conteúdo em markdown e em `structuredContent`, e a tool é read-only: ela lê definições, nunca executa uma ferramenta.

Módulo ou ferramenta que o usuário não pode ver é recusado com uma mensagem que lista o que ele pode usar. Não há aprovação gravada no servidor: a regra de confirmar antes de escrever está nas `instructions` do `initialize` e vale para toda tool que altera algo.

| Tool | Grant | Observações |
| --- | --- | --- |
| `mcp_status` | — | Diagnóstico; não lê dados do usuário. |
| `files_list` `{path="/"}` | files.read | Filhos diretos da pasta, no formato `{name, path, isDir, size, mtime, contentType}`. |
| `files_search` `{query, limit=25}` | files.read | Busca por nome (`%`, `_` e `\` são literais), de 1 a 100 resultados; o limite vai para a própria consulta ao cache de arquivos. |
| `files_read` `{path}` | files.read | Texto puro, PDF, DOCX e ODT. Arquivo acima de 20 MiB é recusado antes da leitura, e o texto é truncado em 100 000 caracteres. PDF ou imagem sem camada de texto retorna `text_layer: false` com um aviso (não é erro); se o app opcional **Workflow OCR** (`workflow_ocr`, exige `ocrmypdf` no servidor) estiver ativo, ele grava o texto no PDF em segundo plano e uma nova leitura já o traz. A tela de admin mostra o status do Workflow OCR. |
| `files_tree` `{path="/", depth=5, limit=2000}` | files.read | Árvore da pasta com o dono de cada item, para planejar reorganização sem uma chamada por pasta. Respeita profundidade (até 5) e contagem (até 2000) e avisa quando trunca. |
| `files_image_view` `{path, max_size=1568}` | files.read | Preview reduzido em base64 (JPEG/PNG/WebP/GIF/TIFF/PDF) sob limite de bytes (padrão 1 MB), com fallback ao original para formatos seguros. Inclui metadados breves. |
| `files_images_view` `{paths?, folder?, limit=6, max_size=1568}` | files.read | Pré-visualização de várias imagens (até 6) sob orçamento total de bytes (padrão 4 MB); itens descartados são informados no sumário. |
| `files_image_search` `{query?, folder?, modified_after?, modified_before?, tag?, limit=25}` | files.read | Busca exclusiva por arquivos de imagem (`image/%`), com filtros de nome, pasta, datas ISO e system tags (inclui tags automáticas do Recognize). |
| `files_edit` `{path, content, etag?}` | files.edit | Só arquivo de texto existente, com no máximo 10 MiB. Antes de gravar, exige o `files_versions` ativo e copia o original para `/MCP backups/<caminho>/<nome>.<AAAAmmdd-HHMMSS>.bak`; se qualquer passo falhar, nada é gravado. Com `etag` divergente, nada é gravado. Não cria, move nem exclui. Fora da pasta pessoal, exige `confirm_shared: true` depois de perguntar ao usuário. |
| `files_replace` `{path, old, new, etag?}` | files.edit | Troca um trecho que precisa aparecer exatamente uma vez e devolve o diff. Mesmas exigências do `files_edit`: versionamento ativo, backup em `/MCP backups` e `etag` divergente não grava nada. Fora da pasta pessoal, exige `confirm_shared: true`. |
| `files_checkout` `{path}` | files.edit | Devolve dois links de uso único para editar com ferramenta local (curl) sem passar o conteúdo pelo modelo: download válido por 5 minutos e upload por 15. Cada link é a credencial, preso ao usuário, ao arquivo e ao ETag vigente; se o arquivo mudar antes do upload, nada é gravado. O upload cria o mesmo backup do `files_edit`. Fora da pasta pessoal, só emite os links com `confirm_shared: true`. |
| `files_mkdir` `{path}` | files.create | Cria pasta, incluindo níveis intermediários. Recusa destino já existente e nunca apaga conteúdo. Fora da pasta pessoal, exige `confirm_shared: true`. |
| `files_copy` `{from, to, etag?}` | files.create | Copia arquivo ou pasta para outro caminho, criando um nó novo com id novo; nunca sobrescreve o destino. A cópia não preserva id, versões nem compartilhamentos. Limite de 2000 itens e 1 GiB, medido antes de copiar. Fora da pasta pessoal, origem ou destino exigem `confirm_shared: true`. |
| `files_move` `{from, to, etag?}` | files.move | Move ou renomeia dentro do mesmo storage, sem sobrescrever o destino. Devolve o id antes e depois, e as contagens de versão e compartilhamento quando os apps estão ativos. Entre storages o Nextcloud trata como cópia e o id muda. Fora da pasta pessoal, origem ou destino exigem `confirm_shared: true`. |
| `files_move_batch` `{moves, mkdirs?, confirm?}` | files.move | Até 200 movimentações, com pastas criadas antes na ordem dada. Sem `confirm: true` não grava e devolve o plano: o que passaria, os conflitos, o que o Nextcloud nega e o que pertence a outra pessoa. Com `confirm: true` executa e devolve o `batch_id` do `files_undo_batch`. Um item que falhar no meio interrompe o lote, e o que já foi movido fica registrado para desfazer. Itens de outra pessoa exigem `confirm_shared: true`. |
| `files_undo_batch` `{batch_id, confirm: true}` | files.move | Desfaz um `files_move_batch` na ordem inversa. Antes de mudar alguma coisa confere o lote inteiro: se algum item não for mais o que o lote colocou no destino, se o caminho de origem já estiver ocupado ou se as permissões mudaram, nada é desfeito e a resposta aponta o item que travou. Só remove pastas vazias criadas pelo próprio lote; pasta com conteúdo é preservada e vai em `kept_dirs`. É o único lugar em que Files remove alguma coisa. |
| `files_versions_list` `{path, limit=50}` | files.read | Versões do arquivo pelo app `files_versions`, da mais nova para a mais antiga. |
| `files_version_read` `{path, version}` | files.read | Texto de uma versão anterior, com o mesmo limite e a mesma extração de texto do `files_read`. |
| `files_version_restore` `{path, version, confirm: true}` | files.restore | Restaura uma versão guardada, criando antes uma cópia do conteúdo atual em `/MCP backups` e uma versão nova no Nextcloud. Exige `confirm: true` e recusa a própria pasta de backup, como a edição já fazia. Fora da pasta pessoal, exige também `confirm_shared: true`. |
| `notes_list` `{}` | notes.read | Notas `.md`/`.txt` da pasta do app Notes (preferência `notesPath`, padrão `Notes`). |
| `notes_read` `{id}` | notes.read | Nota com conteúdo; limite de 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Nunca sobrescreve; em colisão, usa `Título (2)`. |
| `notes_edit` `{id, content?, title?, etag?}` | notes.edit | Conteúdo e/ou título. |
| `notes_move` `{id, category, etag?}` | notes.move | Troca de categoria (subpasta), sem sobrescrever. |
| `notes_delete` `{id, etag?}` | notes.delete | Exige `confirm: true`, a lixeira (`files_trashbin`) ativa e o storage da nota coberto por ela; em storage externo sem lixeira, a exclusão é bloqueada. |

| `calendar_list_calendars` `{}` | calendar.read | Calendários visíveis ao usuário, próprios e compartilhados. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Eventos no intervalo (padrão: próximos 7 dias), com recorrência expandida. |

Calendar expõe leitura e as cinco escritas, sem nenhum passo no terminal. As escritas nascem desligadas na matriz do admin (Administração → MCP) e só aparecem para quem recebeu o grant; toda escrita exige ainda `confirm: true`, a ACL do Nextcloud (e `confirm_shared` em agenda alheia) e o ETag opcional. Compatibilidade: o app declara `min-version`/`max-version` 33 em `info.xml`; a parte interna (`EmbeddedCalDavServer` do core) é coberta pelos testes e por essa faixa, e uma versão nova do Nextcloud exige uma nova versão do app. O app Calendar precisa estar habilitado para o usuário. Sabre e Symfony Console vêm do core do Nextcloud e ficam fora do `vendor/` do pacote.

| Tool de escrita | Grant | Comportamento |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Evento simples; `attendees` são UIDs internos resolvidos para o e-mail da conta. |
| `calendar_update_event` | calendar.edit | Atualiza texto/datas e substitui participantes; `attendees: []` remove todos. Mudança de participantes em série ou por outro organizador é recusada. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como no app Calendar. |
| `calendar_delete_event` | calendar.delete | Exclui a série na lixeira; exige retenção ativa. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro dono compartilhada com escrita; recusa participantes. |

Create, update e delete usam `send_invitations: false` por padrão (`x-nc-scheduling: false`). `true` entrega o convite ao agendamento CalDAV do Nextcloud, inclusive para convidados existentes e CANCEL. O plano e o resultado informam `imipEnabled`, lido da configuração nativa `dav/sendInvitations`: com ela desligada o servidor não envia e-mail (o iTIP interno ainda chega à caixa do convidado) e o assistente deve dizer isso ao usuário. O app nunca confirma recebimento de e-mail. Escritas em agenda de outra pessoa ainda exigem `confirm_shared: true` após confirmação do usuário.

**Confirmação antes de toda escrita (sem estado no servidor).** As cinco escritas, chamadas sem `confirm: true`, só devolvem um plano (`requiresConfirmation: true`): valores atuais e propostos, participantes adicionados/removidos, destino, consequência para convites/CANCEL/lixeira e o `etag` atual. Nada é escrito nem agendado. O assistente deve mostrar o plano, perguntar ao usuário e só após um sim explícito repetir a chamada com `confirm: true` e os mesmos argumentos, reenviando o `etag` do plano (opcional; se vier e divergir, a escrita é recusada e um novo plano é necessário). Não há tabela, token nem registro de aprovação: o servidor não prova o sim humano, mas permissões, ACL, grants e `confirm_shared` seguem valendo. O selftest usa os handlers diretamente e não passa por esse fluxo.

### Diagnóstico opcional do Calendar (`occ mcp:calendar-selftest`)

Não é pré-requisito: nada depende dele para as tools aparecerem ou funcionarem, e ele não grava nem esconde nada. É uma ferramenta de diagnóstico para quem administra o servidor e quer ver, passo a passo, o CalDAV real respondendo. Rode na raiz do Nextcloud como o usuário do servidor web. O UID organizador precisa ter conta habilitada, e-mail válido e Calendar habilitado; a retenção `dav/calendarRetentionObligation` não pode ser `0` (a limpeza seria permanente, então ele falha antes de criar qualquer objeto).

```sh
sudo -u www-data php occ mcp:calendar-selftest UID_ORGANIZADOR
```

Cada execução cria dois calendários com URIs `mcp-selftest-...-a`/`-b`, toca somente eventos criados nela e move esses calendários para a lixeira ao terminar. Sem opções, as três provas opcionais mostram `NOT TESTED`. Para exercitar convites internos, transferência e ACL:

```sh
sudo -u www-data php occ mcp:calendar-selftest UID_ORGANIZADOR \
  --attendee-uid UID_PARTICIPANTE \
  --shared-calendar '/remote.php/dav/calendars/UID_ORGANIZADOR/URI_COMPARTILHADO/' \
  --acl-probe-user UID_SEM_ACESSO
```

Essa execução gera efeitos nos dois usuários (convite interno, CANCEL de limpeza, Activity na agenda compartilhada) e remove somente itens com o UID gerado pelo teste. Não é prova de SMTP.

| Etapa do relatório | Resultado esperado |
| --- | --- |
| `preflight`, `calendars` | Conta/app/retenção válidos; dois MKCALENDAR HTTP 201 visíveis. |
| `create` | HTTP 201, UID e ETag relidos, sync-token aumentou. |
| `suppression-create`, `suppression-update` | PUT 201/204, ATTENDEE `@example.invalid` sem SCHEDULE-STATUS; SEQUENCE subiu. |
| `stale-tool`, `stale-dav` | Conflito no handler e HTTP 412 no DAV; dados e ETag intactos. |
| `move`, `overwrite` | MOVE 201 com UID só no destino; Overwrite F retorna 412 e preserva os dois objetos. |
| `delete` | HTTP 204, UID fora da leitura e na lixeira. |
| `invitations`, `transfer`, `acl` | `OK` se solicitadas e comprovadas; `NOT TESTED` sem as opções. |
| `cleanup`, `result` | Limpeza HTTP 204 confirmada, sessão restaurada e resultado final do diagnóstico. |

Qualquer `FAIL` retorna código não zero. Se a limpeza falhar, `cleanup-resource` indica o path/UID a conferir no servidor. O resultado não altera quais tools existem.

Tools de Notes exigem o app Notes habilitado para o usuário. Leitura começa permitida; criação, edição, movimentação e exclusão começam negadas até o administrador liberar. Não há timeout próprio: a leitura é local ao PHP e o limite de bytes protege contra arquivos grandes. Storage externo lento fica limitado ao `max_execution_time` do PHP.

### Imagens (`files_image_view`, `files_images_view`, `files_image_search`)

Ferramentas somente leitura (`files.read`) que respeitam a pasta do usuário e as permissões nativas do Nextcloud:

- **`files_image_view`**: Gera uma pré-visualização reduzida da imagem via `OCP\IPreview` com dimensão máxima configurável (`max_size`, padrão 1568 px). Se o preview exceder o limite de bytes (configurável em `image_single_max_bytes`, padrão 1 MB), a resolução é reduzida progressivamente até caber. Se não houver preview disponível para o formato, recorre ao arquivo original para formatos comuns (JPEG, PNG, WebP, GIF) desde que esteja dentro do limite de bytes. Retorna bloco `image` (base64) e bloco `text` com metadados (`path`, `mime`, dimensões originais se disponíveis, `size`, `mtime`, `access`, `captured_at`).
- **`files_images_view`**: Recebe uma lista de `paths` (até 6) ou uma pasta `folder` (com `limit`, padrão 6) e retorna múltiplos blocos de imagem sob um orçamento compartilhado (`image_batch_max_bytes`, padrão 4 MB). Arquivos que excedem o orçamento ou falham são listados no sumário `skipped`.
- **`files_image_search`**: Busca arquivos com mimetype `image/%`, com filtros opcionais por texto (`query`), pasta (`folder`), intervalo de modificação ISO 8601 (`modified_after`/`modified_before`) e tag de sistema (`tag`, incluindo tags automáticas geradas pelo app Recognize). Retorna apenas metadados ordenados por `mtime` decrescente.

Argumentos inválidos, tool inexistente ou sem grant retornam o erro JSON-RPC `-32602`, sem distinguir o motivo. Falhas de execução retornam `isError: true` com uma mensagem genérica, sem caminho físico, conteúdo ou stack trace.

## Recursos (MCP Resources)

O servidor anuncia a capability `resources: {}` tanto no fluxo legado `initialize` quanto no fluxo moderno `server/discover`.

- **`resources/list`**: expõe `mcp://guide` (Guia das ferramentas), retornando a documentação completa em Markdown renderizada dinamicamente com base nas permissões concedidas e apps ativos para o usuário.
- **`resources/templates/list`**: expõe templates de URI parametrizados:
  - `nc://files/{path}`: arquivo no armazenamento do usuário (requer grant `files.read`).
  - `nc://notes/{id}`: nota identificada pelo id numérico (requer grant `notes.read` e app Notes habilitado).
- **`resources/read`**: leitura de recursos:
  - `mcp://guide`: entrega o guia formatado em `text/markdown`.
  - `nc://files/{path}`: lê arquivos de texto (`text/*`, PDF, DOCX, ODT via extração de texto idêntica ao `files_read`, com limite de 100.000 caracteres) ou pequenos arquivos binários como `blob` (base64) até 512 KiB. Arquivos binários maiores são recusados com erro.
  - `nc://notes/{id}`: entrega o conteúdo da nota em `text/markdown` (limite de 1 MiB).
  - Recursos inexistentes, não permitidos ou ocultados por etiquetas (`VisibilityGuard`) retornam erro padrão (`-32602` na era moderna, `-32002` na era legada) e nunca um array de conteúdo vazio.

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
scp build/mcp-0.7.0.tar.gz <servidor>:/tmp/
ssh <servidor>
sudo tar -xzf /tmp/mcp-0.7.0.tar.gz -C <nextcloud>/<apps>/
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
