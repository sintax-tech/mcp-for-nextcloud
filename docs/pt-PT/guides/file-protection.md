<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/file-protection.md -->
<!-- translation-source-revision: 0105e8e8a2706d139deb119f87d7d01c0788586c -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/guides/file-protection.md).

# Proteção de ficheiros

## Restrições de recursos (1.1.0)

Administradores podem restringir independentemente Files a pastas selecionadas, Calendar a calendários selecionados e Tasks a listas de tarefas selecionadas. Eventos (`VEVENT`) e tarefas (`VTODO`) continuam separados mesmo dentro de uma coleção. Uma seleção restrita vazia não permite recursos; uma seleção irrestrita não adiciona filtros. Restrições nunca concedem acesso nativo.

Após admitir conta/política/operação, o âmbito de recursos é verificado antes das exclusões por etiquetas e permissões nativas. Um âmbito ilegível produz indisponibilidade temporária antes de buscar recursos. Recursos fora do âmbito recebem a recusa genérica de recurso inexistente e desaparecem das listas. Restrições aplicam-se à próxima operação sem revogar a ligação.

Notes, Contacts, Deck e Talk não têm restrição de recursos por módulo em 1.1.0. Portanto, restringir Files não restringe automaticamente notas armazenadas nessas pastas. Suas próprias permissões e as exclusões por etiquetas aplicáveis continuam em vigor. Anexos e referências estruturadas entre módulos podem exigir o âmbito de Files: Talk oculta IDs de anexos inacessíveis, Tasks filtra referências ATTACH protegidas da saída preservando o conteúdo DAV armazenado, Calendar emite campos selecionados em vez de ATTACH bruto e Deck exibe contagens de anexos em vez de URLs de ficheiros. Texto livre não tem um filtro geral de prevenção de fuga de dados.

Enquanto houver exclusões por etiquetas configuradas, pastas visíveis não podem ser partilhadas, copiadas nem movidas para outro pai pelo MCP; a recusa ocorre antes de enumerar a subárvore ou alterá-la. Renomear no mesmo pai preserva verificações nativas/de bloqueio. Listagens de partilhas de pastas existentes devolvem `url: null` sem ler o token público. A saída estruturada também retira ligações públicas de pastas quando exclusões ou restrições de Files impedem exposição segura. Use a interface nativa de Files para essas operações recusadas.

## Ficheiro bloqueado (1.0.5)

Escritas de Notes e Files diante do `files_lock` (app até o Nextcloud 34, integrado no 35). Serviço comum: `mcp/lib/Platform/FileAccess/Locks/LockAwareWrite.php` (`ILockManager::isLockProviderAvailable` + `getLocks`), sem importar classes do files_lock.

- **Quem bloqueia:** bloqueio manual de outra pessoa (`TYPE_USER`), bloqueio de app (`TYPE_APP`, Text ou Office) e bloqueio de cliente WebDAV (`TYPE_TOKEN`). APP e TOKEN recusam até para o próprio dono. O bloqueio manual do próprio utilizador permite a escrita e é mantido, como na aplicação Files; na rota de checkout através de uma ligação, que não tem sessão do utilizador, esse bloqueio também impede a escrita, tal como acontece no files_lock. Sem provider, nada muda.
- **Onde:** antes da confirmação de partilha e do backup, e de novo logo antes de cada escrita (`LockAwareWrite::run`): `putContent`, move, rename e delete de nota, cada item de lote e de undo, restauração de versão, upload do checkout, conteúdo de ficheiro novo e cópia de backup. A recusa do storage (`ManuallyLockedException`) também é capturada e explicada (`capture`, usado na cópia, que só lê a origem).
- **Na dúvida, recusa:** consulta de bloqueio que falha recusa; pasta que se move é checada com tudo o que o utilizador alcança dentro dela, até 5.000 itens (`LockAwareWrite::TREE_LIMIT`), e uma listagem maior que o orçamento restante recusa sem consultar o bloqueio dos itens. A listagem de cada pasta é lida inteira antes dessa comparação: o `getDirectoryListing()` não tem limite no 32–35 e o `Folder::search()`, que tem, consulta a cache de ficheiros e não reproduz a listagem. Ficheiro oculto pelo `VisibilityGuard` não é nomeado e o `lock` do plano traz só `blocking: true`.
- **Plano (`confirm` ausente):** não recusa; acrescenta `lock` e um aviso `file_locked` em `warnings`. Em `files_move_batch` o item bloqueado vai para `denied`, e o lote é recusado inteiro.
- **Restauração de versão:** com provider, lê a versão por `IVersionManager::read()` e grava pelo ficheiro, sem o `rollback()` do core, cujo `handleAppLocks` repete a escrita no escopo do Text/Office. A garantia sobre o conteúdo substituído é o backup em `/MCP backups`.
- **Checkout:** bloqueio → 423. Antes de gastar a ligação, a ligação continua valendo; depois, a resposta cita o backup e pede novo `files_checkout`.
- **Mensagem:** app ou pessoa (nome de exibição; nunca UID, app id nem token), desde quando, até quando e o que fazer, em en/de/pt_BR/es. Recusa sem correlação segura pelo token fica com responsável não identificado. Log só em `debug`, sem caminho nem token.
- **Limite:** os bloqueios TOKEN só são recusados pela verificação do MCP, que não é atómica com a escrita. O `getLocks()` do files_lock responde a partir da cache do pedido, incluindo resultados negativos: um bloqueio TOKEN criado depois da primeira consulta ao ficheiro no mesmo pedido não é detetado. Os bloqueios USER e APP também são aplicados pelo armazenamento do files_lock, que volta a consultar o bloqueio durante a escrita, exceto ao eliminar um documento do Text ou ao renomeá-lo de/para Markdown: o listener do Text (`BeforeNodeDeletedListener`/`BeforeNodeRenamedListener`) é executado antes do armazenamento e chama `resetDocument(..., true)`, libertando o bloqueio APP. Um bloqueio APP do Text criado depois da consulta do MCP é assim libertado e não impede a eliminação ou a mudança de nome. O MCP só recusa os bloqueios que o fornecedor de bloqueios observa. O files_lock não oferece aquisição atómica de bloqueios (consulta e inserção separadas, `file_id` sem índice único), pelo que a aplicação não adquire um bloqueio próprio.

## Etiquetas ocultas (1.0.6)

O `VisibilityGuard` aplica a ocultação por system tags em todos os caminhos de acesso do conector: leitura, pesquisa, listagem, árvore, imagens, versões, notas, Talk, recursos, checkout, escritas e anular.

- **Herança:** a etiqueta no ficheiro ou pasta vale para todos os descendentes. Um item alcançado por partilha local herda as etiquetas da cadeia da pasta de origem do dono (storage `OCP\Files\Storage\ISharedStorage` + id no filecache, resolvido uma vez por raiz partilhada). Team folders, storage externo e shares federados seguem pelo caminho montado, sem consultar o dono; um federado é verificado só pelas etiquetas locais — as do servidor remoto não são legíveis daqui.
- **Fail-closed:** uma configuração que não pode ser validada (o manager falhando enquanto a lista é salva, ou um valor gravado ilegível ou inválido) preserva a configuração anterior e recusa o acesso, em vez de desligar a proteção; o ecrã de administração avisa e oferece o botão de reparo, e guardar a lista vazia pede confirmação. As decisões de visibilidade são invalidadas depois de mover, criar ou copiar, para não responder com estado antigo.
- **Logs:** com etiquetas configuradas e a opção **Esconder o texto das mensagens do log** ligada (por predefinição; chave `logs_hide_file_names`), as ferramentas de log escondem o texto das mensagens e das exceções, o user agent e o method fora dos verbos HTTP/WebDAV conhecidos, reduzem a URL aos prefixos fixos de rota e recusam o filtro `contains`, que bateria no texto escondido. Desligada, o log volta completo para diagnóstico — a opção existe porque equipas de TI leem exatamente esses detalhes para mapear problemas. O log de erros da própria aplicação grava só a classe e o código da exceção, nunca a mensagem, em qualquer instalação.
- **Limites:** a proteção se aplica a este conector MCP, não a outras IAs ou conectores que leiam o mesmo Nextcloud; não trata nomes livres escritos por outras aplicações em campos estruturados de registos. Testes nativos cobrem partilhas locais, groupfolders e montagens externas locais em instâncias descartáveis do Nextcloud. Federação e ACLs avançadas de groupfolders exigem revisão separada em um servidor; a matriz não as certifica.

- **Atualização e limites de armazenamento:** outro processo/nó pode alterar a configuração de exclusões enquanto um nó com cache serve o valor anterior por aproximadamente os três segundos de TTL do núcleo mais o tempo da solicitação. Montagens externas restritas distintas podem atribuir IDs diferentes; uma etiqueta em um armazenamento não se aplica automaticamente a outro. Listas de backends de grupos, caches positivos de existência de grupos, disponibilidade de aplicações e utilizadores memorizados também têm limites nativos de atualização em processos longos. Limpar os caches oficiais de política não garante atualizar todos os caches nativos.

- A proteção vale só para este conector MCP: não cobre outras ferramentas de IA nem outros conectores que leiam o mesmo Nextcloud, e nada impede que alguém envie um ficheiro manualmente para uma IA.

[Guias de operação](README.md).

[English](../../en/guides/file-protection.md) · [Español](../../es/guides/file-protection.md) · [Català](../../ca/guides/file-protection.md) · [Português (Brasil)](../../pt-BR/guides/file-protection.md) · Português (Portugal)
