<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/guides/file-protection.md -->
<!-- translation-source-revision: 0105e8e8a2706d139deb119f87d7d01c0788586c -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../en/guides/file-protection.md).

# Proteção de arquivos

## Restrições de recursos (1.1.0)

Administradores podem restringir independentemente Files a pastas selecionadas, Calendar a calendários selecionados e Tasks a listas de tarefas selecionadas. Eventos (`VEVENT`) e tarefas (`VTODO`) continuam separados mesmo dentro de uma coleção. Uma seleção restrita vazia não permite recursos; uma seleção irrestrita não adiciona filtros. Restrições nunca concedem acesso nativo.

Após admitir conta/política/operação, o escopo de recursos é verificado antes das exclusões por etiquetas e permissões nativas. Um escopo ilegível produz indisponibilidade temporária antes de buscar recursos. Recursos fora do escopo recebem a recusa genérica de recurso inexistente e desaparecem das listas. Restrições se aplicam à próxima operação sem revogar a conexão.

Notes, Contacts, Deck e Talk não têm restrição de recursos por módulo em 1.1.0. Portanto, restringir Files não restringe automaticamente notas armazenadas nessas pastas. Suas próprias permissões e as exclusões por etiquetas aplicáveis continuam em vigor. Anexos e referências estruturadas entre módulos podem exigir o escopo de Files: Talk oculta IDs de anexos inacessíveis, Tasks filtra referências ATTACH protegidas da saída preservando o conteúdo DAV armazenado, Calendar emite campos selecionados em vez de ATTACH bruto e Deck exibe contagens de anexos em vez de URLs de arquivos. Texto livre não tem um filtro geral de prevenção de vazamento de dados.

Enquanto houver exclusões por etiquetas configuradas, pastas visíveis não podem ser compartilhadas, copiadas nem movidas para outro pai pelo MCP; a recusa ocorre antes de enumerar a subárvore ou alterá-la. Renomear no mesmo pai preserva verificações nativas/de bloqueio. Listagens de compartilhamentos de pastas existentes retornam `url: null` sem ler o token público. A saída estruturada também retira links públicos de pastas quando exclusões ou restrições de Files impedem exposição segura. Use a interface nativa de Files para essas operações recusadas.

## Arquivo travado (1.0.5)

Escritas de Notes e Files diante do `files_lock` (app até o Nextcloud 34, embutido no 35). Serviço comum: `mcp/lib/Platform/FileAccess/Locks/LockAwareWrite.php` (`ILockManager::isLockProviderAvailable` + `getLocks`), sem importar classes do files_lock.

- **Quem bloqueia:** trava manual de outra pessoa (`TYPE_USER`), trava de app (`TYPE_APP`, Text ou Office) e trava de cliente WebDAV (`TYPE_TOKEN`). APP e TOKEN recusam até para o próprio dono. A trava manual do próprio usuário deixa escrever e é mantida, como no app Files; na rota do checkout por link, que não tem sessão do usuário, ela também recusa, como o files_lock faz ali. Sem provider, nada muda.
- **Onde:** antes da confirmação de compartilhamento e do backup, e de novo logo antes de cada escrita (`LockAwareWrite::run`): `putContent`, move, rename e delete de nota, cada item de lote e de undo, restauração de versão, upload do checkout, conteúdo de arquivo novo e cópia de backup. A recusa do storage (`ManuallyLockedException`) também é capturada e explicada (`capture`, usado na cópia, que só lê a origem).
- **Na dúvida, recusa:** consulta de trava que falha recusa; pasta que se move é checada com tudo o que o usuário alcança dentro dela, até 5.000 itens (`LockAwareWrite::TREE_LIMIT`), e uma listagem maior que o orçamento restante recusa sem ler a trava dos itens. A listagem de cada pasta é lida inteira antes dessa comparação: o `getDirectoryListing()` não tem limite no 32–35 e o `Folder::search()`, que tem, consulta a cache de arquivos e não reproduz a listagem. Arquivo oculto pelo `VisibilityGuard` não é nomeado e o `lock` do plano traz só `blocking: true`.
- **Plano (`confirm` ausente):** não recusa; acrescenta `lock` e um aviso `file_locked` em `warnings`. Em `files_move_batch` o item travado vai para `denied`, e o lote é recusado inteiro.
- **Restauração de versão:** com provider, lê a versão por `IVersionManager::read()` e grava pelo arquivo, sem o `rollback()` do core, cujo `handleAppLocks` repete a escrita no escopo do Text/Office. A garantia sobre o conteúdo substituído é o backup em `/MCP backups`.
- **Checkout:** trava → 423. Antes de gastar o link, o link continua valendo; depois, a resposta cita o backup e pede novo `files_checkout`.
- **Mensagem:** app ou pessoa (nome de exibição; nunca UID, app id nem token), desde quando, até quando e o que fazer, em en/de/pt_BR/es. Recusa sem correlação segura pelo token fica com responsável não identificado. Log só em `debug`, sem caminho nem token.
- **Limite:** TOKEN só é recusada pela checagem do MCP, que não é atômica com a escrita, e o `getLocks()` do files_lock responde do cache da requisição, inclusive negativo: uma trava TOKEN criada depois da primeira consulta do arquivo na mesma requisição não é vista. Travas USER e APP também são aplicadas pelo storage do files_lock, que lê a trava de novo na escrita, exceto na exclusão ou na renomeação de/para Markdown de um documento do Text: o listener do Text (`BeforeNodeDeletedListener`/`BeforeNodeRenamedListener`) roda antes do storage e faz `resetDocument(..., true)`, soltando a trava APP; uma trava APP do Text criada depois da consulta do MCP é solta assim e não impede a exclusão ou a renomeação. O MCP só recusa a trava que o provider observa. O files_lock não oferece aquisição atômica de trava (consulta e inserção separadas, `file_id` sem índice único), por isso o app não toma trava própria.

## Etiquetas ocultas (1.0.6)

O `VisibilityGuard` aplica a ocultação por system tags em todos os caminhos de acesso do conector: leitura, busca, listagem, árvore, imagens, versões, notas, Talk, recursos, checkout, escritas e desfazer.

- **Herança:** a etiqueta no arquivo ou pasta vale para todos os descendentes. Um item alcançado por compartilhamento local herda as etiquetas da cadeia da pasta de origem do dono (storage `OCP\Files\Storage\ISharedStorage` + id no filecache, resolvido uma vez por raiz compartilhada). Team folders, storage externo e shares federados seguem pelo caminho montado, sem consultar o dono; um federado é verificado só pelas etiquetas locais — as do servidor remoto não são legíveis daqui.
- **Fail-closed:** uma configuração que não pode ser validada (o manager falhando enquanto a lista é salva, ou um valor gravado ilegível ou inválido) preserva a configuração anterior e recusa o acesso, em vez de desligar a proteção; a tela de admin avisa e oferece o botão de reparo, e salvar a lista vazia pede confirmação. As decisões de visibilidade são invalidadas depois de mover, criar ou copiar, para não responder com estado antigo.
- **Logs:** com etiquetas configuradas e a opção **Esconder o texto das mensagens do log** ligada (padrão; chave `logs_hide_file_names`), as ferramentas de log escondem o texto das mensagens e das exceções, o user agent e o method fora dos verbos HTTP/WebDAV conhecidos, reduzem a URL aos prefixos fixos de rota e recusam o filtro `contains`, que bateria no texto escondido. Desligada, o log volta completo para diagnóstico — a opção existe porque equipes de TI leem exatamente esses detalhes para mapear problemas. O log de erros do próprio app grava só a classe e o código da exceção, nunca a mensagem, em qualquer instalação.
- **Limites:** a proteção se aplica a este conector MCP, não a outras IAs ou conectores que leiam o mesmo Nextcloud; não trata nomes livres escritos por outros aplicativos em campos estruturados de logs. Testes nativos cobrem compartilhamentos locais, groupfolders e montagens externas locais em instâncias descartáveis do Nextcloud. Federação e ACLs avançadas de groupfolders exigem revisão separada em um servidor; a matriz não as certifica.

- **Atualização e limites de armazenamento:** outro processo/nó pode alterar a configuração de exclusões enquanto um nó com cache serve o valor anterior por aproximadamente os três segundos de TTL do núcleo mais o tempo da solicitação. Montagens externas restritas distintas podem atribuir IDs diferentes; uma etiqueta em um armazenamento não se aplica automaticamente a outro. Listas de backends de grupos, caches positivos de existência de grupos, disponibilidade de aplicativos e usuários memorizados também têm limites nativos de atualização em processos longos. Limpar os caches oficiais de política não garante atualizar todos os caches nativos.

- A proteção vale só para este conector MCP: não cobre outras ferramentas de IA nem outros conectores que leiam o mesmo Nextcloud, e nada impede que alguém envie um arquivo manualmente para uma IA.

[Guias de operação](README.md).

[English](../../en/guides/file-protection.md) · [Español](../../es/guides/file-protection.md) · [Català](../../ca/guides/file-protection.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/guides/file-protection.md)
