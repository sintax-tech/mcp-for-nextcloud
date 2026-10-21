<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/deck.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/deck.md).

# Deck

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Deck** | taulers, piles, targetes i un **seguiment** dels taulers que gestiones: targetes agrupades per responsable, amb data límit, indicador de retard (en la teva zona horària) i enllaç a la targeta | crea, edita, mou i suprimeix targetes, assigna o treu l'assignació als membres del tauler; crea un tauler amb les seves llistes i les primeres targetes, o una llista en un tauler que gestiones; suprimeix un tauler o una llista només si està buit, directament a la paperera de Deck |

## Deck: targetes, taulers i llistes

Les escriptures de Deck utilitzen els permisos `deck.create`, `deck.edit`, `deck.move` i `deck.delete`, tots desactivats inicialment, i `confirm_shared: true` en un tauler d'un altre propietari. L'aplicació sempre confirma amb Deck si l'escriptura s'ha desat, fins i tot després d'un error de Deck.

| Eina d'escriptura | Permís | Comportament |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Crea un tauler del mateix usuari, amb les seves llistes i les primeres targetes (fins a 20 llistes i 100 targetes), i un color opcional. En un tauler nou, les persones assignades són el mateix usuari perquè ningú més no hi té accés. El pla mostra l'arbre sencer; si falla un pas a mig procés, no es desfà res i el resultat inclou `created`, `failed` (amb un motiu segur) i `warnings`, perquè repetir l'acció no dupliqui res. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Afegeix una llista a un tauler que gestiona l'usuari, al final o en una posició concreta. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Suprimeix una llista **només si està buida**: qualsevol targeta, activa o arxivada, rebutja la crida i indica quantes en queden; en confirmar, es torna a calcular el recompte. La llista passa a la paperera de Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Igual per a un tauler; només el propietari el pot suprimir. Si apareix una targeta entre el recompte i la supressió, es restaura el tauler amb la funció de desfer de Deck i es rebutja l'acció indicant que no s'ha suprimit res. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` i l'assignació de responsables fan servir els mateixos permisos i requereixen la mateixa confirmació; les persones assignades han de ser membres del tauler.

Després d'un error de Deck, cada escriptura torna a llegir l'estat real abans de respondre: si l'element s'ha desat, la resposta indica que l'acció s'ha completat i avisa «S'ha desat; Deck ha informat d'un error posterior (notificació o activitat)»; si no, retorna l'error habitual; i si no es pot llegir l'estat, no retorna un error i demana a l'assistent que llegeixi amb `deck_list_cards`, `deck_read_card`, `deck_list_stacks` o `deck_list_boards`. Si només es troba un element per semblança (mateix propietari, mateix títol, creat fa un moment), es retorna `confirmed: "probable"` amb un avís perquè es comprovi; no s'hi escriu res més. Només s'omet aquesta relectura en els rebutjos que l'aplicació fa abans de cridar Deck (regles d'eina, sessió, conflictes i arguments).

La supressió «només si està buida» no és atòmica: Deck no comprova `deleted_at` de la llista quan crea una targeta. Per tant, una targeta creada per una altra petició just després del segon recompte podria anar a la paperera juntament amb la llista o el tauler, des d'on es pot recuperar. Això s'indica a la descripció de l'eina i al gateway.

Consulta `mcp_guide` en viu per a l'esquema autoritatiu i les operacions disponibles.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/deck.md) · [Español](../../../es/reference/tools/deck.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/deck.md) · [Português (Portugal)](../../../pt-PT/reference/tools/deck.md)
