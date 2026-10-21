<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Deck

| Module | Read | Write |
| --- | --- | --- |
| **Deck** | boards, stacks, cards, and a **follow-up** of the boards you manage: cards grouped by assignee, with due date, overdue flag (in your own time zone) and a link to the card | create, edit, move and delete cards, and assign or unassign the board members; create a board with its lists and first cards, or a list in a board you manage; delete a board or a list only when it is empty, straight to the Deck trash |

## Deck: cards, boards and lists


The Deck writes use the grants `deck.create`, `deck.edit`, `deck.move` and `deck.delete`, all off by default, and `confirm_shared: true` on a board of another owner. The app always confirms with Deck whether the write was saved, even after a Deck error.

| Write tool | Grant | Behavior |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Creates a board of the user themselves with its lists and first cards (up to 20 lists and 100 cards), with an optional color. On a new board the assignees are the user themselves, because no one else has access. The plan shows the whole tree; if a step fails midway, nothing is undone and the result carries `created`, `failed` (with a safe reason) and `warnings`, so a retry does not duplicate anything. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Adds a list to a board the user manages, at the end or at a position. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Deletes a list **only when it is empty**: any card, active or archived, refuses the call saying how many remain, and the count is redone at the confirm. It goes to the Deck trash bin. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Same for the board, and only the owner can delete. If a card appears between the count and the deletion, the board comes back with Deck's own undo and the call is refused saying that nothing was deleted. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` and the assignment of assignees use the same grants and the same confirmation; the assignees are board members.

After an error coming from Deck, every write re-reads the real state before answering: if the item was saved, the response is a success with the notice "Saved; Deck reported an error afterwards (notification or activity)"; if not, it is the usual error; and if the state cannot be read, the response is not an error and tells the assistant to read with `deck_list_cards`, `deck_read_card`, `deck_list_stacks` or `deck_list_boards`. An item found again only by similarity (same owner, same title, created a moment ago) comes as `confirmed: "probable"`, with a notice to check, and nothing else is written to it. Only the refusals the app itself makes before calling Deck (tool rules, session, conflicts, arguments) skip this re-read.

The "only when empty" deletion is not atomic: Deck does not look at the list's `deleted_at` when creating a card, so a card created by another request right after the second count may go to the trash bin together with the list or the board, from where it is recoverable. This is in the tool description and in the gateway.


See the live `mcp_guide` for the authoritative schema and available operations.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/deck.md) · [Català](../../../ca/reference/tools/deck.md) · [Português (Brasil)](../../../pt-BR/reference/tools/deck.md) · [Português (Portugal)](../../../pt-PT/reference/tools/deck.md)
