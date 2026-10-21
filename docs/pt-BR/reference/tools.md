<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools.md -->
<!-- translation-source-revision: c0f730d97a84cf61317486c67722af52cf30325e -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../en/reference/tools.md).

# Ferramentas

Cada tool só aparece em `tools/list` e só pode ser chamada quando o usuário tem o grant da operação, e o app exigido está habilitado para ele. Grant e app são verificados de novo a cada chamada. Tudo roda como o usuário autenticado, pela pasta dele no Nextcloud, e as permissões e compartilhamentos do Nextcloud continuam valendo.

### Guia das ferramentas (`mcp_guide`)

`mcp_guide` (título **Guia das ferramentas**) descreve as ferramentas ao próprio modelo, para que ele não precise adivinhar o que cada uma faz:

- **sem argumento** — uma linha por módulo com os títulos das ferramentas, apenas dos módulos disponíveis **para aquele usuário** (o mesmo filtro de grant e app do `tools/list`: módulo desabilitado ou sem grant não aparece);
- **com `module`** (`files`, `notes`, `calendar`, `contacts`, `tasks`, `deck`, `talk`, `people`, `logs`) — cada ferramenta do módulo: descrição, se lê ou escreve, parâmetros com tipo, obrigatoriedade, descrição, `enum` e limites, e as confirmações que exige;
- **com `tool`** — uma só ferramenta, com o módulo a que pertence.

O guia é escrito em inglês e o modelo repassa no idioma do usuário.

O texto vem das definições reais (`ToolModule::definitions()`, `inputSchema`, `annotations` e `ToolPresentation`), então mudar o schema de uma ferramenta muda o guia: não existe uma segunda cópia da documentação para manter em dia. O que o schema não carrega — como encontrar as coisas, com quais caminhos e IDs, e onde as escritas param — entra em `ToolGuideNotes::guideNotes()`, uma nota curta por módulo, escrita ao lado do módulo. A resposta traz o mesmo conteúdo em markdown e em `structuredContent`, e a tool é read-only: ela lê definições, nunca executa uma ferramenta.

Módulo ou ferramenta que o usuário não pode ver é recusado com uma mensagem que lista o que ele pode usar. Não há aprovação gravada no servidor: a regra de confirmar antes de escrever está nas `instructions` do `initialize` e vale para toda tool que altera algo.

E também:

- **Confirmação conversacional antes de qualquer escrita (`confirm: true`)**: qualquer ferramenta que altera dados (`operation != 'read'`) só executa quando chamada com `confirm: true`. Sem isso, o servidor apenas devolve o plano (`requiresConfirmation: true`) sem modificar nada. O assistente deve apresentar o plano ao usuário e só repetir a chamada com `confirm: true` após aprovação explícita. Nenhum estado ou token de aprovação é gravado no servidor.
- **OCR pelo Workflow OCR**: com o app Workflow OCR instalado, os PDFs escaneados ganham uma camada de texto que o `files_read` lê normalmente. Sem ele, um PDF ou imagem sem texto volta com `text_layer: false` e um aviso, e a página de admin mostra o status do OCR com o link para instalar. Nada fica bloqueado.
- **Nomes amigáveis das ferramentas** no cliente ("Buscar arquivos", "Listar calendários") e anotações MCP (`readOnlyHint`, `destructiveHint`), para o cliente pedir confirmação em ações de risco.
- **Confirmação de segurança** em recursos de outras pessoas: pastas compartilhadas, pastas de time, quadros do Deck e calendários de outro dono. O servidor recusa a primeira chamada e devolve uma mensagem pronta. O assistente precisa perguntar ao usuário antes de repetir com `confirm_shared: true`. *(Deck e Calendário desde a 0.6.6; Arquivos e Notas desde a 0.7.0)*
- **Apps opcionais respeitados**: as tools e as colunas da matriz de admin de um app desativado (para todos ou para um usuário) ficam ocultas.
- **Interface traduzida**: a matriz de admin, a página pessoal, a tela de consentimento e toda mensagem mostrada por uma tool seguem o idioma da conta Nextcloud do usuário. Todos os módulos estão cobertos (Arquivos, Notas, Calendário, Contatos, Tarefas, Deck, Talk, as mensagens compartilhadas, as páginas de checkout e o selftest do calendário). Inglês, português do Brasil e espanhol vêm incluídos (o espanhol é uma primeira tradução e ainda precisa de revisão de um nativo); as descrições das tools ficam em inglês, porque quem as lê é o modelo.
- **As duas gerações do MCP** no mesmo endpoint: `2026-07-28`, sem estado (`server/discover`), e o fluxo clássico com `initialize` (`2025-06-18` e anteriores).

- [Arquivos](tools/files.md)
- [Notas](tools/notes.md)
- [Calendário](tools/calendar.md)
- [Contatos](tools/contacts.md)
- [Tarefas](tools/tasks.md)
- [Deck](tools/deck.md)
- [Logs](tools/logs.md)
- [Talk](tools/talk.md)
- [Pessoas](tools/people.md)

`mcp_status`: diagnóstico; não lê dados do usuário.

[Guias de operação](../guides/README.md).

[English](../../en/reference/tools.md) · [Español](../../es/reference/tools.md) · [Català](../../ca/reference/tools.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/reference/tools.md)
