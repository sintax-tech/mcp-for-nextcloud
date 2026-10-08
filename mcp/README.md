# App MCP para Nextcloud

Versão de teste interno para **Nextcloud 33** (o `info.xml` declara só o 33; o código já roda no 31 e no 32, com contrato de API e fixtures para as três versões). O endpoint atende clientes MCP 2026-07-28, sem estado, via `server/discover` e `_meta` por requisição, e também clientes anteriores, que usam `initialize` com 2025-06-18. Expõe MCP `2025-06-18` via Streamable HTTP sem sessão e sem SSE, na rota do próprio app:

- `https://<instância>/apps/mcp/` com URLs limpas;
- `https://<instância>/index.php/apps/mcp/` sem URLs limpas.

A URL exata da instância aparece nas páginas de administração e pessoal.

## Contatos e Tarefas (0.8.0)

| Módulo | Tools | Dependência |
|---|---|---|
| Contacts | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | app Contacts habilitado; core CardDAV |
| People | `users_search` | busca de contas — e, com `include_groups: true`, de grupos, devolvendo `type` `user` ou `group` — pelo compartilhamento do Nextcloud (respeita as restrições do admin); sem app opcional |
| Logs | `logs_list`, `logs_analyze` | só leitura do `nextcloud.log` pelo `ILogFactory`/`IFileBased::getEntries` do core; exige `log_type` em arquivo (padrão); ver [Log do servidor](#log-do-servidor-102) |
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
| `files_search` `{query, limit=25, mode="auto"}` | files.read | Busca por conteúdo quando `fulltextsearch` e `files_fulltextsearch` estão ativos e indexados (com trechos destacados); senão ou em erro, busca por nome (`%`, `_` e `\` são literais), de 1 a 100 resultados. Parâmetro `mode` (`auto` ou `name`) permite forçar busca por nome. |
| `files_read` `{path}` | files.read | Texto puro, PDF, DOCX e ODT. Arquivo acima de 20 MiB é recusado antes da leitura, e o texto é truncado em 100 000 caracteres. PDF ou imagem sem camada de texto retorna `text_layer: false` com um aviso (não é erro); se o app opcional **Workflow OCR** (`workflow_ocr`, exige `ocrmypdf` no servidor) estiver ativo, ele grava o texto no PDF em segundo plano e uma nova leitura já o traz. A tela de admin mostra o status do Workflow OCR. |
| `files_tree` `{path="/", depth=5, limit=2000}` | files.read | Árvore da pasta com o dono de cada item, para planejar reorganização sem uma chamada por pasta. Respeita profundidade (até 5) e contagem (até 2000) e avisa quando trunca. |
| `files_image_view` `{path, max_size=1568}` | files.read | Preview reduzido em base64 (JPEG/PNG/WebP/GIF/TIFF/PDF) sob limite de bytes (padrão 1 MB), com fallback ao original para formatos seguros. Inclui metadados breves. |
| `files_images_view` `{paths?, folder?, limit=6, max_size=1568}` | files.read | Pré-visualização de várias imagens (até 6) sob orçamento total de bytes (padrão 4 MB); itens descartados são informados no sumário. |
| `files_image_search` `{query?, folder?, modified_after?, modified_before?, tag?, limit=25}` | files.read | Busca exclusiva por arquivos de imagem (`image/%`), com filtros de nome, pasta, datas ISO e system tags (inclui tags automáticas do Recognize). |
| `files_edit` `{path, content, etag?}` | files.edit | Só arquivo de texto existente, com no máximo 10 MiB. Antes de gravar, exige o `files_versions` ativo e copia o original para `/MCP backups/<caminho>/<nome>.<AAAAmmdd-HHMMSS>.bak`; se qualquer passo falhar, nada é gravado. Com `etag` divergente, nada é gravado. Não cria, move nem exclui. Fora da pasta pessoal, exige `confirm_shared: true` depois de perguntar ao usuário. |
| `files_replace` `{path, old, new, etag?}` | files.edit | Troca um trecho que precisa aparecer exatamente uma vez e devolve o diff. Mesmas exigências do `files_edit`: versionamento ativo, backup em `/MCP backups` e `etag` divergente não grava nada. Fora da pasta pessoal, exige `confirm_shared: true`. |
| `files_checkout` `{path}` | files.edit | Aceita qualquer tipo de arquivo (DOCX, XLSX, PDF, imagens) até o limite de envio; `files_edit`/`files_replace` seguem só para texto. Devolve dois links de uso único para editar com ferramenta local (curl) sem passar o conteúdo pelo modelo: download válido por 5 minutos e upload por 15. Cada link é a credencial, preso ao usuário, ao arquivo e ao ETag vigente; se o arquivo mudar antes do upload, nada é gravado. O upload cria o mesmo backup do `files_edit`. Fora da pasta pessoal, só emite os links com `confirm_shared: true`. |
| `files_upload` `{path, size?}` | files.create | Cria um arquivo **novo** de qualquer tipo (DOCX, XLSX, PDF, imagem) gerado localmente pelo cliente, sem passar o conteúdo pelo modelo. O plano mostra o nome, a pasta, a validade do link e o limite de envio (o mesmo do `files_checkout`); `size` acima do limite é recusado antes de emitir o link. O confirm devolve `uploadUrl`, link de uso único válido por 15 minutos, preso ao usuário e ao caminho, e o comando `curl -sS -T arquivo -X PUT -H "Content-Type: application/octet-stream" "<uploadUrl>"`; a resposta do PUT (201) traz `path`, `size`, `etag` e `fileId`. Nunca sobrescreve arquivo com conteúdo: nome existente é recusado no plano, no confirm e no envio; se outro cliente cria o nome com conteúdo no meio, ou muda o arquivo entre a criação e a gravação, a resposta é 409 e nada é gravado (um arquivo vazio criado no mesmo instante é assumido). Corpo vazio cria arquivo vazio, salvo quando o plano declarou `size` > 0 (400). Link inexistente, gasto ou expirado é recusado antes de o corpo ir para o disco. Corpo multipart (400), acima do limite (413), nome já existente (409), pasta removida (404), pasta que passou a ser compartilhada (409) ou sem permissão (403) não gastam o link. A pasta precisa existir (`files_mkdir`); pasta oculta responde como inexistente. Fora da pasta pessoal, exige `confirm_shared: true`. Não há backup: não existe original. |
| `files_create` `{path, content}` | files.create | Cria um arquivo de texto **novo** com o conteúdo inline (`.md`, `.txt`, `.csv`, `.json`, `.html`, `.xml`, `.yaml`, `.yml`), até 1 MB em UTF-8. O plano mostra nome, pasta, tamanho e o começo do texto citado. Mesmas regras do `files_upload`: nunca sobrescreve arquivo com conteúdo, nomes que o Nextcloud recusa (`IFilenameValidator`) são erro de argumento sem ecoar o valor, a pasta precisa existir, `confirm_shared` fora da pasta pessoal. Para outro tipo ou arquivo maior, `files_upload`; para substituir um arquivo existente, `files_checkout`. |
| `files_mkdir` `{path}` | files.create | Cria pasta, incluindo níveis intermediários. Recusa destino já existente e nunca apaga conteúdo. Fora da pasta pessoal, exige `confirm_shared: true`. |
| `files_copy` `{from, to, etag?}` | files.create | Copia arquivo ou pasta para outro caminho, criando um nó novo com id novo; nunca sobrescreve o destino. A cópia não preserva id, versões nem compartilhamentos. Limite de 2000 itens e 1 GiB, medido antes de copiar. Fora da pasta pessoal, origem ou destino exigem `confirm_shared: true`. |
| `files_move` `{from, to, etag?}` | files.move | Move ou renomeia dentro do mesmo storage, sem sobrescrever o destino. Devolve o id antes e depois, e as contagens de versão e compartilhamento quando os apps estão ativos. Entre storages o Nextcloud trata como cópia e o id muda. Fora da pasta pessoal, origem ou destino exigem `confirm_shared: true`. |
| `files_move_batch` `{moves, mkdirs?, confirm?}` | files.move | Até 200 movimentações, com pastas criadas antes na ordem dada. Sem `confirm: true` não grava e devolve o plano: o que passaria, os conflitos, o que o Nextcloud nega e o que pertence a outra pessoa. Com `confirm: true` executa e devolve o `batch_id` do `files_undo_batch`. Um item que falhar no meio interrompe o lote, e o que já foi movido fica registrado para desfazer. Itens de outra pessoa exigem `confirm_shared: true`. |
| `files_undo_batch` `{batch_id, confirm: true}` | files.move | Desfaz um `files_move_batch` na ordem inversa. Antes de mudar alguma coisa confere o lote inteiro: se algum item não for mais o que o lote colocou no destino, se o caminho de origem já estiver ocupado ou se as permissões mudaram, nada é desfeito e a resposta aponta o item que travou. Só remove pastas vazias criadas pelo próprio lote; pasta com conteúdo é preservada e vai em `kept_dirs`. É o único lugar em que Files remove alguma coisa. |
| `files_versions_list` `{path, limit=50}` | files.read | Versões do arquivo pelo app `files_versions`, da mais nova para a mais antiga. |
| `files_version_read` `{path, version}` | files.read | Texto de uma versão anterior, com o mesmo limite e a mesma extração de texto do `files_read`. |
| `files_version_restore` `{path, version, confirm: true}` | files.restore | Restaura uma versão guardada, criando antes uma cópia do conteúdo atual em `/MCP backups` e uma versão nova no Nextcloud. Exige `confirm: true` e recusa a própria pasta de backup, como a edição já fazia. Fora da pasta pessoal, exige também `confirm_shared: true`. |
| `files_list_shares` `{path?, offset=0}` | files.read | Compartilhamentos que o próprio usuário criou. Com `path`, os do arquivo ou pasta dele (nó de outro dono é recusado; nó oculto é tratado como inexistente). Sem `path`, todos, 50 por página, com `offset` até 1000. Cada item traz `shareId`, `type` (`user`, `group`, `link` ou `room`), `with` (id e nome de exibição), `permission` (`view`, `edit` ou `custom`), `reshare`, `expires`, `hasPassword`, `url` (só link), `removable` e `path`. A senha nunca sai. Anexo do Talk (`room`) aparece com `removable: false` e se remove pelo Talk. Criar e remover compartilhamentos exigem as permissões `files.share` (pessoas e grupos) e `files.link` (link público), negadas por padrão. |
| `files_share` `{path, with, permission="view", expires?, note?, password?, confirm: true, plan_state}` | files.share (pessoa/grupo) ou files.link (link) | Compartilha um arquivo ou pasta do próprio usuário com uma pessoa (`user:<uid>`) ou um grupo (`group:<gid>`); ache o id com `users_search` (`include_groups: true` para grupos). Compartilhar de novo com o mesmo destinatário altera o compartilhamento existente, e o plano mostra antes → depois, inclusive o recompartilhamento que um compartilhamento feito pela web perde. Se nada muda, o plano diz "nada a alterar" e o confirm não grava. `view` = ver e baixar; `edit` = editar (em pasta, também adicionar e apagar); nunca dá direito de repassar. `expires` (AAAA-MM-DD, no fuso da conta, a partir de amanhã) respeita o máximo do admin; sem ele, um compartilhamento novo recebe o padrão do admin. As regras do admin (compartilhamento desligado, só com membros dos grupos, grupos desligados) são explicadas antes de gravar; recusas do core viram mensagens traduzidas, e o log guarda só a classe. O Nextcloud avisa quem recebe. Link público (`with: "link"`, permissão `files.link`): um por arquivo, só `view`, e o plano avisa que qualquer pessoa com o link pode abrir. Recusa quando o admin desligou os links e respeita a validade máxima e o padrão de links. A senha é gerada pelo servidor (evento `GenerateSecurePasswordEvent` do `password_policy`; sem ele, 16 caracteres do `ISecureRandom` com letras, dígitos e símbolos), validada pela política antes de gravar e criada quando se pede `password: true` ou quando o admin exige senha. Ela nunca aparece no plano, no log nem no `files_list_shares`: só no resultado da execução que a gerou, uma única vez. Sem `password: true`, a senha atual é mantida e não é mostrada. O plano traz `plan_state` (impressão opaca da ação, do compartilhamento e dos campos antes/depois, sem senha), que o confirm devolve: se o compartilhamento mudou desde o plano, nada é gravado e a resposta é o plano novo, com o aviso para conferir de novo; confirm sem `plan_state` é erro de argumento. |
| `files_unshare` `{shareId}` ou `{path, with}` + `confirm: true` e `plan_state` | files.share (pessoa/grupo) ou files.link (link), conforme o tipo removido | Remove um compartilhamento que o próprio usuário criou de um arquivo ou pasta dele; o arquivo nunca é tocado. Nomeia-se o compartilhamento pelo `shareId` de `files_list_shares` (por exemplo `ocinternal:123`) ou por `path` com `with` (`user:<uid>`, `group:<gid>` ou `link`). O plano diz quem perde o acesso ("Fulano deixará de ter acesso a X", "todos os membros do grupo G perderão o acesso", "o link de X deixará de funcionar"). Compartilhamento de outra pessoa, de arquivo de outro dono, de nó oculto, de tipo não gerenciado ou id inexistente respondem todos "não encontrado", sem revelar nada; só o anexo do Talk do próprio usuário recebe a mensagem de que se remove pelo Talk. O grant do tipo é conferido depois da posse, e tudo é conferido de novo no confirm. Como no `files_share`, o confirm devolve o `plan_state` do plano; se o compartilhamento mudou ou foi refeito desde o plano, nada é removido e a resposta é o plano novo. |
| `notes_list` `{}` | notes.read | Notas `.md`/`.txt` da pasta do app Notes (preferência `notesPath`, padrão `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Busca por título e conteúdo Markdown (até 1 MiB), com trecho contextual da ocorrência. Opcionalmente restrita a uma categoria. |
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

### Deck: cartões, quadros e listas

As escritas do Deck usam os grants `deck.create`, `deck.edit`, `deck.move` e `deck.delete`, todas desligadas por padrão, e `confirm_shared: true` em quadro de outro dono. O app sempre confirma com o Deck se a escrita foi gravada, mesmo depois de um erro do Deck.

| Tool de escrita | Grant | Comportamento |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Cria um quadro do próprio usuário com suas listas e primeiros cards (até 20 listas e 100 cards), com cor opcional. Num quadro novo os responsáveis são o próprio usuário, porque mais ninguém tem acesso. O plano mostra a árvore inteira; se um passo falhar no meio, nada é desfeito e o resultado traz `created`, `failed` (com motivo seguro) e `warnings`, para a repetição não duplicar nada. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Acrescenta uma lista a um quadro que o usuário gerencia, no fim ou numa posição. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Exclui uma lista **somente quando está vazia**: qualquer card, ativo ou arquivado, recusa a chamada dizendo quantos restam, e a contagem é refeita no confirm. Vai para a lixeira do Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Igual para o quadro, e só o dono pode excluir. Se um card aparecer entre a contagem e a exclusão, o quadro volta com o próprio undo do Deck e a chamada é recusada dizendo que nada foi excluído. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` e a atribuição de responsáveis usam os mesmos grants e a mesma confirmação; os responsáveis são membros do quadro.

Depois de um erro vindo do Deck, toda escrita relê o estado real antes de responder: se o item foi gravado, a resposta é sucesso com o aviso "Saved; Deck reported an error afterwards (notification or activity)"; se não foi, é o erro de sempre; e se o estado não pode ser lido, a resposta não é erro e manda ler com `deck_list_cards`, `deck_read_card`, `deck_list_stacks` ou `deck_list_boards`. Um item reencontrado só por semelhança (mesmo dono, mesmo título, criado há pouco) vem como `confirmed: "probable"`, com aviso para conferir, e nada mais é escrito nele. Só as recusas que o próprio app faz antes de chamar o Deck (regras da tool, sessão, conflitos, argumentos) pulam essa releitura.

A exclusão "somente quando vazia" não é atômica: o Deck não olha o `deleted_at` da lista ao criar um card, então um card criado por outra requisição logo após a segunda contagem pode ir para a lixeira junto com a lista ou o quadro, de onde é recuperável. Isso está na descrição da tool e no gateway.

### Diagnóstico opcional do Calendar (`occ mcp:calendar-selftest`)

Não é pré-requisito: nada depende dele para as tools aparecerem ou funcionarem, e ele não grava nem esconde nada. É uma ferramenta de diagnóstico opcional e avançada para quem administra o servidor e quer ver, passo a passo, o CalDAV real respondendo. Rode na raiz do Nextcloud como o usuário do servidor web. O UID organizador precisa ter conta habilitada, e-mail válido e Calendar habilitado; a retenção `dav/calendarRetentionObligation` não pode ser `0` (a limpeza seria permanente, então ele falha antes de criar qualquer objeto).

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

Gera `build/mcp-<versão>.tar.gz` com raiz `mcp/` contendo `appinfo/`, `lib/`, `templates/`, as pastas de assets que existirem (`js/`, `css/`, `img/`, `l10n/`), este README e um `vendor/` só de produção, criado com `composer install --no-dev`. Testes e dependências de desenvolvimento ficam fora. O script falha, e não deixa pacote, quando um `Util::addScript('mcp', X)` ou `Util::addStyle('mcp', X)` em `templates/` ou `lib/` aponta para `js/X.js` ou `css/X.css` ausente do pacote. O script precisa de `composer` e `php` na máquina que empacota.

### Regra do pacote: sem metadados do macOS

Um pacote com `._*` (AppleDouble), `.DS_Store` ou cabeçalhos PAX com xattrs `com.apple.*` é recusado pela App Store e pode quebrar a instalação. Por isso:

- o tar roda como `COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' --exclude='.DS_Store'`. O `--no-xattrs` foi acrescentado porque, sem ele, o bsdtar grava `com.apple.provenance` em cabeçalhos PAX de todas as entradas;
- a conferência é feita por `scripts/check_package.php`, que lê o tar com um leitor próprio em PHP, porque o `tar -tzf` do macOS esconde as entradas `._*` e o `PharData` não expõe os cabeçalhos PAX;
- o pacote é recusado, apagado, e o script termina com código 1, se tiver:
  - entrada `._*` ou `.DS_Store`;
  - entrada PaxHeader ou cabeçalho PAX estendido;
  - entrada de topo diferente de `mcp/`;
  - pacote em `vendor/` fora das dependências de runtime (`smalot/`, `symfony/polyfill-mbstring/`, `composer/`, `autoload.php`), como `sabre/*` ou `symfony/console`;
  - script ou estilo referenciado por `Util::addScript('mcp', X)`/`Util::addStyle('mcp', X)` ausente do pacote.

Para conferir um pacote avulso: `php scripts/check_package.php build/mcp-<versão>.tar.gz`.

### Dependências de runtime

| Pacote | Uso | Licença |
| --- | --- | --- |
| `smalot/pdfparser` | Extração de texto de PDF em `files_read` | LGPL-3.0 |
| `symfony/polyfill-mbstring` | Dependência do pdfparser; inativo quando a extensão `mbstring` existe | MIT |

Os arquivos de licença acompanham cada pacote em `vendor/`. O `vendor/autoload.php` é carregado pelo `lib/AppInfo/Application.php`.

## Instalar via SSH

Substitua `<servidor>`, `<nextcloud>` (raiz da instalação), `<apps>` (diretório de apps gravável, por exemplo `custom_apps` ou `apps`, conforme `apps_paths` em `config/config.php`) e `<www>` (usuário do servidor web, por exemplo `www-data`).

```sh
scp build/mcp-1.0.1.tar.gz <servidor>:/tmp/
ssh <servidor>
sudo tar -xzf /tmp/mcp-1.0.1.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

Em Docker, rode os comandos `occ` dentro do container como o usuário do servidor web. Se o PHP usa OPcache com `validate_timestamps=0`, reinicie o PHP-FPM após copiar os arquivos.

Este é o único ponto do app que ainda exige terminal: a instalação por SSH. Quando houver publicação na App Store, instalar por lá dispensa qualquer comando, e a configuração passa a ser só a tela de admin.

Para atualizar: `occ app:disable mcp`, remover `<apps>/mcp`, extrair o novo pacote, `occ app:enable mcp` e `occ upgrade` se o Nextcloud pedir. A pasta precisa ser trocada inteira, para não sobrarem arquivos antigos em `vendor/`. Para remover: `occ app:remove mcp`.

## Log do servidor (1.0.2)

Módulo `logs`, só leitura e só para TI: `logs_list` lista as entradas do `nextcloud.log` da mais nova para a mais antiga e `logs_analyze` resume a mesma janela.

- **Fonte única:** `ILogFactory::get('file')` e, se o writer for `IFileBased`, `getEntries($limit, $offset)`, o mesmo caminho do leitor de log do core. O app não abre arquivo, não usa shell nem `occ`. O `getEntries` só devolve o que está no `loglevel` do servidor ou acima, e linha malformada vira `null` (contada em `scan.malformed`).
- **Portão duplo:** `LogsAccess` (`RestrictedModule`, checado pelo `ToolRegistry` antes do grant, no `tools/list` e em cada `tools/call`) aceita admin do Nextcloud ou membro de um grupo em `logs_groups`; depois o grant `logs.read`, que nasce desligado. Com `log_type` syslog/errorlog/systemd as tools somem e a coluna sai da matriz; o bloco do admin explica.
- **Teto e orçamento:** a API do core reabre o arquivo e o percorre a partir do fim, caractere a caractere, passando também pelas linhas abaixo do `loglevel`, e não oferece orçamento físico. Por isso cada resposta faz **uma única** chamada `getEntries`, com janela = min(5.000, 20.000 − `offset`) (sem filtro, só a página): uma segunda chamada pagaria a caminhada de novo e, com o log crescendo no meio, devolveria a mesma entrada duas vezes. O `readBudget` (20.000) é a profundidade em **entradas elegíveis** (no `loglevel` ou acima) a partir do fim, não um teto físico de linhas. A resposta traz `scan.scanned`, `truncated`, `budgetReached`, `readBudget`, `oldestScanned` e `nextOffset` (para continuar mais para trás); o `.1` rotacionado não é lido. Tudo continua pelo OCP: o app nunca abre o arquivo.
- **`since` não encerra a leitura:** o core carimba cada entrada com o `microtime` de quando a monta, e workers concorrentes gravam em qualquer ordem. Uma entrada anterior ao `since` é pulada e a janela segue até o fim; enquanto houver orçamento, `nextOffset` aponta para o fim da janela, e a resposta nunca afirma que não existe nada mais antigo.
- **Datas:** `since`/`until` em ISO 8601; o `time` da entrada é lido no `logdateformat`/`logtimezone`; se não casar, a entrada fica de fora sob filtro de data e conta em `scan.unparsed`.
- **Saída:** envelope `{"untrusted_log_data": true, "notice": …}` depois de um aviso fixo; controles, ANSI e marcas invisíveis removidos; IP mascarado (`a.b.x.x`, IPv6 /48) também dentro das mensagens; mensagem cortada em 2.000 caracteres; exceção com classe, mensagem, local e 10 frames `class->function` + `arquivo:linha`, sem `args`.
- **Análise:** totais por nível/app/usuário, top 20 assinaturas (aspas, caminhos, UUIDs, hashes e números viram `#`), top 10 caminhos de URL (sem query; caminho WebDAV dobrado no endpoint + conta), top 10 user agents e histograma por hora.
- **Auditoria:** o `ToolRegistry` entrega toda chamada a um `RestrictedModule` com o resultado (`denied` por papel ou grant, `invalid`, `read_error`, `success`), e o módulo dispara `CriticalActionPerformedEvent`, gravado pelo admin_audit quando ativo: `MCP logs read by %s: tool=%s filters=%s` só para leitura feita e `MCP logs request %s for %s: tool=%s filters=%s` para as demais, nunca uma negação como leitura. Dos argumentos ficam só os filtros conhecidos, escalares e cortados. No log do app, negação é `warning` e o resto `info`.
- **API do admin:** `GET`/`PUT /apps/mcp/api/logs-access` (`{groups: list<string>}`), admin-only com CSRF; grupo inexistente dá 400 sem gravar.

## Configurar

1. **Administração → MCP for Nextcloud** (entrada própria, com ícone, no menu de configurações): no bloco **Status**, marcar *Serviço MCP ativado*; no bloco **Permissões**, usar a matriz de usuários × permissões.
   - O bloco **Status** mostra o endpoint (com copiar), o serviço, a versão do app, quantos usuários podem conectar e quantos estão conectados, as conexões OAuth ativas e o **limite de envio do checkout** (`files_checkout`), editável em MiB, com o limite efetivo e o teto `post_max_size` do PHP ao lado. Antes isso só mudava por `occ`.
   - Os demais blocos, na ordem: **Clientes OAuth**, **Arquivos e etiquetas ocultas**, **OCR**, **Permissões**, **Log do servidor** e **Conexões ativas**.
   - A busca procura por nome, ID ou e-mail, e há filtro por grupo e por "só quem pode conectar / só conectados" (feito no servidor, com total exato); a tabela mostra 50 usuários por página, com contador e paginação em cima e embaixo. Há linhas compactas opcionais (lembradas no navegador).
   - Cada checkbox salva na hora: *Pode conectar* libera o usuário, e as demais colunas são as operações por módulo; o cabeçalho fica fixo ao rolar, os módulos se alternam em faixas e cada operação tem dica do que permite.
   - O menu **Todos** no cabeçalho de cada módulo (e de *Pode conectar*) permite ou nega uma operação, ou todas do módulo, para os usuários ativos da página, com confirmação. Usuários conectados aparecem com a marca *conectado*.
   - Usuários desativados aparecem marcados e não podem ser editados. Módulos cujo app não está disponível no servidor não aparecem na matriz; para um usuário sem acesso ao app, a célula fica desativada. As permissões salvas são preservadas.
   - O e-mail só serve para a busca e nunca é exibido.
2. **Arquivos e etiquetas ocultas**: selecione etiquetas de sistema para que arquivos e pastas etiquetados fiquem totalmente invisíveis às ferramentas do MCP (leitura, busca, listagem, imagens, notas, talk e checkout). Recomenda-se o uso de etiquetas restritas ou invisíveis.
   - Serviço, elegibilidade e conexão pessoal começam desligados para todos, inclusive administradores. Leitura começa permitida; escrita, exclusão, transferência e compartilhamento começam negadas. Em Arquivos, `files.share` (compartilhar com pessoa ou grupo) e `files.link` (link público) são duas operações separadas, para o admin liberar uma sem a outra; as duas vêm desligadas. Listar compartilhamentos continua sendo leitura.
   - A página usa a API JSON admin-only `GET /apps/mcp/api/grants` (`search`, `group`, `filter` = `eligible`/`connected`, `page`), `PUT /apps/mcp/api/grants/{uid}`, `POST /apps/mcp/api/grants/bulk`, `PUT /apps/mcp/api/service`, `GET`/`PUT /apps/mcp/api/checkout-limit` (`{mib}` inteiro ≥ 1) e, para as conexões, `GET /apps/mcp/api/connections`, `DELETE /apps/mcp/api/connections/{id}` e `DELETE /apps/mcp/api/connections/users/{uid}`. Nenhuma resposta traz hash de token.
2. **Clientes OAuth**: o bloco *OAuth clients* da seção **MCP for Nextcloud** edita os hosts de cliente permitidos e liga o cliente nativo para programas locais, sem passo de terminal. A API é `GET /apps/mcp/api/oauth-clients` e `PUT /apps/mcp/api/oauth-clients` (admin-only, com CSRF do Nextcloud), aceita `hosts` e/ou `nativeClientEnabled`, valida tudo antes de gravar e recusa uma lista de hosts inválida em vez de escrevê-la. Desligar o cliente nativo revoga na hora os tokens já emitidos para ele.
3. **Configurações pessoais → MCP for Nextcloud**: o próprio usuário clica em *Connect*. A página mostra o estado (serviço, permissão do administrador, conexão) e a lista **Seus clientes conectados** (cliente, conectado em, expira em) com *Revogar*, pela API `GET /apps/mcp/api/my/connections` e `DELETE /apps/mcp/api/my/connections/{id}`, restrita às conexões do próprio usuário.
4. Em **Configurações pessoais → Segurança**, o usuário cria uma senha de app. O cliente MCP usa autenticação HTTP Basic com o ID do usuário e essa senha de app. O app nunca pede nem guarda a senha principal.

Risco residual nas escritas: colisões com destinos ocultos usam a mesma recusa genérica de destinos sem permissão, mas a diferença entre recusa e criação possível ainda permite inferir que um caminho está indisponível.

O login pelo navegador via OAuth está descrito em [Conectar pelo claude.ai (OAuth)](#conectar-pelo-claudeai-oauth) e, para ChatGPT e Gemini CLI, em [Conectar pelo ChatGPT e pelo Gemini CLI (OAuth)](#conectar-pelo-chatgpt-e-pelo-gemini-cli-oauth).

### Chaves de configuração

| Escopo | Chave | Valores |
| --- | --- | --- |
| app `mcp` | `service_enabled` | `1` ligado, `0` ou ausente desligado |
| usuário, app `mcp` | `eligible` | `1` liberado pelo administrador |
| usuário, app `mcp` | `connected` | `1` conexão pessoal ativa |
| usuário, app `mcp` | `grant_<módulo>_<operação>` | `1`/`0`; ausente vale `1` para `read` (exceto `logs`, que vale `0`) e `0` para as demais |
| app `mcp` | `logs_groups` | lista JSON de ids de grupo cujos membros passam no portão do módulo de logs; ausente ou vazia = só administradores; editável no bloco **Log do servidor** |
| app `mcp` | `oauth_client_hosts` | lista de hosts separados por vírgula; ausente ou vazia vale o padrão `claude.ai,chatgpt.com` |
| app `mcp` | `oauth_native_client_enabled` | `1` habilita o cliente nativo local `nextcloud-mcp-native`, `0` ou ausente desabilitado |
| app `mcp` | `checkout_max_bytes` | limite de envio do `files_checkout` em bytes; padrão 50 MiB; editável no bloco Status em MiB; nunca acima do `post_max_size` do PHP |

As duas chaves OAuth são editáveis pela seção **Clientes OAuth** da tela de admin e não precisam de `occ`; ver [Conectar pelo claude.ai (OAuth)](#conectar-pelo-claudeai-oauth).

A chave `enabled` do app `mcp` pertence ao Nextcloud, que a usa para marcar o app como habilitado (`yes`), e não é o liga/desliga do MCP. O app nunca lê nem grava `enabled`, `installed_version`, `types`, `levels` ou `ocsid`. Para diagnóstico: `occ config:app:get mcp service_enabled`.

## Conectar pelo claude.ai (OAuth)

No claude.ai, em **Personalizar > Conectores > Adicionar conector personalizado**, cole a URL exibida na página pessoal (ex.: `https://<instância>/apps/mcp/`), com a barra final, e deixe o cliente OAuth em **identidade publicada do Claude (CIMD)**. O Claude abre o login do Nextcloud e a tela "Permitir"; permitir equivale a ativar a conexão pessoal. Tudo fica sob `/apps/mcp`, sem mudança de Apache/DNS:

- `401` com `WWW-Authenticate: Bearer resource_metadata=".../apps/mcp/.well-known/oauth-protected-resource"`;
- metadata do servidor de autorização em `/apps/mcp/.well-known/openid-configuration` e `/apps/mcp/.well-known/oauth-authorization-server` (issuer `https://<instância>/apps/mcp`);
- `oauth/authorize` (consentimento) e `oauth/token` (PKCE S256, refresh com rotação). Access token 1 h, refresh 30 dias; só hashes vão ao banco.

Só clientes CIMD com host na allowlist são aceitos. A allowlist padrão é `claude.ai,chatgpt.com` e é editada sem terminal em **Administração → MCP for Nextcloud → Clientes OAuth → Hosts de clientes permitidos** (host exato, sem `https://`, porta, caminho ou `*`, ao menos um; salvar substitui a lista inteira e remover um host recusa o cliente na hora, mesmo com o documento em cache). A chave `oauth_client_hosts` não precisa ser gravada à mão: um valor salvo antes da 0.8.0 é preservado e o padrão só vale quando a chave está ausente ou vazia. A mesma seção liga **Permitir programas locais (cliente nativo)**, que habilita o `client_id` público fixo `nextcloud-mcp-native` (desligado por padrão) para programas locais como o Gemini CLI, e mostra os redirect URIs loopback aceitos.

Todo redirecionamento de volta a um cliente carrega `iss` com o emissor (RFC 9207), inclusive na negação e em erros redirecionáveis; erros anteriores à validação do redirect continuam locais. Clientes sem documento de identidade próprio, como o Gemini CLI, são recusados com `invalid_client` enquanto o cliente nativo estiver desligado.

Disconnect pessoal, perda de elegibilidade (inclusive em lote por grupo), serviço desligado e conta desabilitada ou apagada excluem imediatamente os tokens access/refresh e códigos pendentes. Reativar o serviço ou a elegibilidade não restaura credenciais antigas; conecte novamente. O Claude sai de `160.79.104.0/21`; se a proteção brute force do Nextcloud atrasar o endpoint de token, libere essa faixa. Basic + app password continua valendo para outros clientes.

## Conectar pelo ChatGPT e pelo Gemini CLI (OAuth)

Passos para o usuário, com a mesma URL e a mesma tela "Permitir", estão no README da raiz, nas seções ChatGPT e Gemini CLI. Resumo do que o servidor exige de cada um:

- **ChatGPT**: CIMD em `chatgpt.com`, aceito pela allowlist padrão. O documento real traz `token_endpoint_auth_methods_supported: [none, private_key_jwt]` e o singular `private_key_jwt`; o plural vence, e `none` está na lista, então o cliente é aceito como público.
- **Gemini CLI**: não publica documento de identidade, então usa o cliente nativo estático. O usuário cola o `client_id` exibido na seção do admin em `~/.gemini/settings.json` → `mcpServers.<nome>.oauth.clientId` e roda `/mcp auth`. O redirect `http://localhost:<porta>/oauth/callback` casa porque `RedirectUriMatcher` ignora a porta em loopback (RFC 8252 7.3).

Ambos estão **compatíveis por código, ainda não comprovados em cliente real**: não existe instalação de teste, e o smoke test real só acontece depois do deploy da 0.8.0. Até lá, `iss` é obrigatório porque o Gemini CLI rejeita retorno sem ele.

## Revogar

*Disconnect* na página pessoal bloqueia a próxima requisição MCP, inclusive com a mesma senha de app. A senha de app continua válida no Nextcloud e deve ser revogada em **Segurança**. Na mesma página, *Revogar* corta um cliente OAuth específico do próprio usuário. O administrador também pode remover a elegibilidade do usuário ou desativar o serviço; ambos valem na próxima requisição. No bloco **Conexões ativas**, o administrador revoga uma conexão OAuth específica, ou todas de um usuário, na hora; o usuário continua habilitado e pode entrar de novo.

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
