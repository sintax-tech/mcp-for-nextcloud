<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/reference/tools.md).

# Ferramentas

Cada ferramenta só aparece em `tools/list` e só pode ser chamada quando o utilizador tem a permissão da operação e a aplicação necessária está ativada para esse utilizador. A permissão e a aplicação são verificadas novamente em cada chamada. Tudo é executado como o utilizador autenticado, através da sua pasta no Nextcloud, mantendo as permissões e partilhas do Nextcloud.

### Guia das ferramentas (`mcp_guide`)

`mcp_guide` (título **Guia das ferramentas**) descreve as ferramentas ao próprio modelo, para que não tenha de adivinhar o que cada uma faz:

- **sem argumento** — uma linha por módulo com os títulos das ferramentas, apenas dos módulos disponíveis **para aquele utilizador** (o mesmo filtro de grant e app do `tools/list`: módulo desativado ou sem grant não aparece);
- **com `module`** (`files`, `notes`, `calendar`, `contacts`, `tasks`, `deck`, `talk`, `people`, `logs`) — cada ferramenta do módulo: descrição, se lê ou escreve, parâmetros com tipo, indicação de obrigatoriedade, descrição, `enum` e limites, e confirmações exigidas;
- **com `tool`** — uma única ferramenta e o módulo a que pertence.

O guia é escrito em inglês; o modelo transmite a informação no idioma do utilizador.

O texto provém das definições reais (`ToolModule::definitions()`, `inputSchema`, `annotations` e `ToolPresentation`); por isso, alterar o esquema de uma ferramenta atualiza o guia e não é necessário manter uma segunda cópia da documentação. A informação que não consta do esquema — como encontrar itens, que caminhos e IDs usar e onde terminam as operações de escrita — fica em `ToolGuideNotes::guideNotes()`, uma nota curta por módulo, junto do próprio módulo. A resposta contém o mesmo conteúdo em Markdown e em `structuredContent`. A ferramenta é apenas de leitura: consulta definições, mas nunca executa ferramentas.

Se o utilizador não puder ver um módulo ou uma ferramenta, o pedido é recusado com uma mensagem que indica o que pode usar. O servidor não guarda aprovações: a instrução para confirmar antes de escrever está em `instructions` no `initialize` e aplica-se a todas as ferramentas que alteram dados.

Além disso:

- **Confirmação conversacional antes de qualquer escrita (`confirm: true`)**: qualquer ferramenta que altera dados (`operation != 'read'`) só executa quando chamada com `confirm: true`. Sem isso, o servidor apenas devolve o plano (`requiresConfirmation: true`) sem modificar nada. O assistente deve apresentar o plano ao utilizador e só repetir a chamada com `confirm: true` após aprovação explícita. O servidor não guarda qualquer estado de aprovação nem tokens de aprovação.
- **OCR pelo Workflow OCR**: com a aplicação Workflow OCR instalada, os PDFs escaneados ganham uma camada de texto que o `files_read` lê normalmente. Sem ele, um PDF ou imagem sem texto volta com `text_layer: false` e um aviso, e a página de administração mostra o estado do OCR com a ligação para a instalação. Nada fica bloqueado.
- **Nomes amigáveis das ferramentas** no cliente ("Pesquisar ficheiros", "Listar calendários") e anotações MCP (`readOnlyHint`, `destructiveHint`), para o cliente pedir confirmação em ações de risco.
- **Confirmação de segurança** em recursos de outras pessoas: pastas partilhadas, pastas de equipa, quadros do Deck e calendários de outro dono. O servidor recusa a primeira chamada e devolve uma mensagem pronta. O assistente precisa perguntar ao utilizador antes de repetir com `confirm_shared: true`. *(Deck e Calendário desde a 0.6.6; Ficheiros e Notas desde a 0.7.0)*
- **Aplicações opcionais respeitadas**: as ferramentas e as colunas da matriz de administração de uma aplicação desativada (para todos ou para um utilizador) ficam ocultas.
- **Interface traduzida**: a matriz de administração, a página pessoal, o ecrã de consentimento e toda mensagem mostrada por uma tool seguem o idioma da conta Nextcloud do utilizador. Todos os módulos estão cobertos (Ficheiros, Notas, Calendário, Contactos, Tarefas, Deck, Talk, as mensagens partilhadas, as páginas de checkout e o selftest do calendário). Estão incluídos inglês, português do Brasil e espanhol (o espanhol é uma primeira tradução e ainda precisa de revisão de um nativo); as descrições das tools ficam em inglês, porque são lidas pelo modelo.
- **As duas gerações do MCP** no mesmo endpoint: `2026-07-28`, sem estado (`server/discover`), e o fluxo clássico com `initialize` (`2025-06-18` e anteriores).

- [Files](tools/files.md)
- [Notes](tools/notes.md)
- [Calendário](tools/calendar.md)
- [Contactos](tools/contacts.md)
- [Tarefas](tools/tasks.md)
- [Deck](tools/deck.md)
- [Logs](tools/logs.md)
- [Talk](tools/talk.md)
- [People](tools/people.md)

`mcp_status` fornece diagnósticos sem ler dados do utilizador.

[Guias de operação](../guides/README.md).

[English](../../en/reference/tools.md) · [Español](../../es/reference/tools.md) · [Català](../../ca/reference/tools.md) · [Português (Brasil)](../../pt-BR/reference/tools.md) · Português (Portugal)
