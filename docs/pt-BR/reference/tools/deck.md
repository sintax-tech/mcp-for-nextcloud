<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/deck.md -->
<!-- translation-source-revision: c0f730d97a84cf61317486c67722af52cf30325e -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../../en/reference/tools/deck.md).

# Deck

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Deck** | quadros, listas, cards e **acompanhamento** dos quadros que você gerencia: cards por responsável, com prazo, indicação de atraso (no seu fuso) e link do card | criar, editar, mover e excluir cards, e atribuir ou remover responsáveis; criar um quadro com suas listas e primeiros cards, ou uma lista em um quadro que você gerencia; excluir um quadro ou uma lista somente quando estiver vazio, direto para a lixeira do Deck |

## Deck: cartões, quadros e listas


As escritas do Deck usam os grants `deck.create`, `deck.edit`, `deck.move` e `deck.delete`, todas desligadas por padrão, e `confirm_shared: true` em quadro de outro dono. O app sempre confirma com o Deck se a escrita foi gravada, mesmo depois de um erro do Deck.

| Ferramenta de escrita | Permissão | Comportamento |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Cria um quadro do próprio usuário com suas listas e primeiros cards (até 20 listas e 100 cards), com cor opcional. Num quadro novo os responsáveis são o próprio usuário, porque mais ninguém tem acesso. O plano mostra a árvore inteira; se um passo falhar no meio, nada é desfeito e o resultado traz `created`, `failed` (com motivo seguro) e `warnings`, para a repetição não duplicar nada. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Acrescenta uma lista a um quadro que o usuário gerencia, no fim ou numa posição. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Exclui uma lista **somente quando está vazia**: qualquer card, ativo ou arquivado, recusa a chamada dizendo quantos restam, e a contagem é refeita no confirm. Vai para a lixeira do Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Igual para o quadro, e só o dono pode excluir. Se um card aparecer entre a contagem e a exclusão, o quadro volta com o próprio undo do Deck e a chamada é recusada dizendo que nada foi excluído. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` e a atribuição de responsáveis usam os mesmos grants e a mesma confirmação; os responsáveis são membros do quadro.

Depois de um erro vindo do Deck, toda escrita relê o estado real antes de responder: se o item foi gravado, a resposta é sucesso com o aviso "Saved; Deck reported an error afterwards (notification or activity)"; se não foi, é o erro de sempre; e se o estado não pode ser lido, a resposta não é erro e manda ler com `deck_list_cards`, `deck_read_card`, `deck_list_stacks` ou `deck_list_boards`. Um item reencontrado só por semelhança (mesmo dono, mesmo título, criado há pouco) vem como `confirmed: "probable"`, com aviso para conferir, e nada mais é escrito nele. Só as recusas que o próprio app faz antes de chamar o Deck (regras da tool, sessão, conflitos, argumentos) pulam essa releitura.

A exclusão "somente quando vazia" não é atômica: o Deck não olha o `deleted_at` da lista ao criar um card, então um card criado por outra requisição logo após a segunda contagem pode ir para a lixeira junto com a lista ou o quadro, de onde é recuperável. Isso está na descrição da tool e no gateway.


Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/deck.md) · [Español](../../../es/reference/tools/deck.md) · [Català](../../../ca/reference/tools/deck.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/deck.md)
