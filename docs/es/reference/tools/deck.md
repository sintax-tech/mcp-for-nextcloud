<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/deck.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/deck.md).

# Deck

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Deck** | tableros, pilas y tarjetas, además de un **seguimiento** de los tableros que administras: tarjetas agrupadas por persona asignada, con fecha de vencimiento, indicador de retraso (según tu propia zona horaria) y enlace a la tarjeta | crear, editar, mover y eliminar tarjetas, y asignar o desasignar miembros del tablero; crear un tablero con sus listas y tarjetas iniciales, o crear una lista en un tablero que administres; eliminar un tablero o una lista solo cuando esté vacío, enviándolo directamente a la papelera de Deck |

## Deck: tarjetas, tableros y listas

Las escrituras de Deck usan los permisos `deck.create`, `deck.edit`, `deck.move` y `deck.delete`, todos desactivados de forma predeterminada, y `confirm_shared: true` en un tablero de otro propietario. La aplicación siempre comprueba con Deck si se guardó la escritura, incluso después de recibir un error de Deck.

| Herramienta de escritura | Permiso | Comportamiento |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Crea un tablero propio con sus listas y primeras tarjetas (hasta 20 listas y 100 tarjetas), con un color opcional. En el tablero nuevo, las personas asignadas son el usuario actual porque nadie más tiene acceso. El plan muestra todo el árbol; si falla un paso a mitad de camino, no se revierte nada y el resultado incluye `created`, `failed` (con un motivo seguro) y `warnings`, para que reintentar no duplique elementos. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Añade una lista al tablero que administra el usuario, al final o en una posición determinada. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Elimina una lista **solo si está vacía**: cualquier tarjeta, activa o archivada, rechaza la llamada e indica cuántas quedan; el recuento se repite al confirmar. La lista va a la papelera de Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Aplica la misma regla al tablero, que solo puede eliminar su propietario. Si aparece una tarjeta entre el recuento y la eliminación, el tablero se restaura con la propia función de deshacer de Deck y se rechaza la llamada, indicando que no se eliminó nada. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` y la asignación de personas usan los mismos permisos y requieren la misma confirmación; las personas asignadas deben ser miembros del tablero.

Después de un error de Deck, todas las escrituras vuelven a leer el estado real antes de responder: si el elemento se guardó, la respuesta indica éxito con el aviso «Saved; Deck reported an error afterwards (notification or activity)»; si no, devuelve el error habitual; y si no se puede leer el estado, no devuelve un error y pide al asistente que lea con `deck_list_cards`, `deck_read_card`, `deck_list_stacks` o `deck_list_boards`. Si el elemento solo vuelve a encontrarse por similitud (mismo propietario y título, creado hace poco), se informa `confirmed: "probable"`, con un aviso para comprobarlo, y no se le hacen más cambios. Solo se omite esta relectura cuando la propia aplicación rechaza la llamada antes de usar Deck (reglas de herramientas, sesión, conflictos o argumentos).

La regla de eliminar «solo si está vacío» no es atómica: Deck no consulta `deleted_at` de la lista al crear una tarjeta. Por tanto, una tarjeta creada por otra solicitud justo después del segundo recuento puede ir a la papelera junto con la lista o el tablero, desde donde se puede recuperar. Esto figura en la descripción de la herramienta y en el gateway.

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/deck.md) · Español · [Català](../../../ca/reference/tools/deck.md) · [Português (Brasil)](../../../pt-BR/reference/tools/deck.md) · [Português (Portugal)](../../../pt-PT/reference/tools/deck.md)
