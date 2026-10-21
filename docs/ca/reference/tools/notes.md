<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/notes.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/notes.md).

# Notes

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Notes** | llista, llegeix i cerca notes (pel títol i el contingut Markdown) | crea, edita, mou entre categories i suprimeix (només si la paperera les pot recuperar) |

| Eina | Permís | Detalls |
| --- | --- | --- |
| `notes_list` `{}` | notes.read | Notes `.md`/`.txt` de la carpeta de l'aplicació Notes (preferència `notesPath`, predeterminat `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Cerca al títol i al contingut Markdown (fins a 1 MiB), amb un fragment contextual del resultat. Es pot limitar a una categoria. |
| `notes_read` `{id}` | notes.read | Nota amb el contingut; límit d'1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Mai no sobreescriu; si el títol ja existeix, fa servir `Títol (2)`. |
| `notes_edit` `{id, content?, title?, etag?}` | notes.edit | Canvia el contingut i/o el títol. |
| `notes_move` `{id, category, etag?}` | notes.move | Canvia la categoria (subcarpeta) sense sobreescriure. |
| `notes_delete` `{id, etag?}` | notes.delete | Requereix `confirm: true`, que la paperera (`files_trashbin`) estigui activa i que cobreixi l'emmagatzematge de la nota; se'n bloqueja la supressió en un emmagatzematge extern sense paperera. |

Consulta `mcp_guide` per obtenir l'esquema autoritatiu i les operacions disponibles.

Les eines de Notes requereixen que l'aplicació Notes estigui activada per a l'usuari. La lectura s'autoritza inicialment; l'administrador ha de concedir els permisos per crear, editar, moure i suprimir. No hi ha cap temps d'espera propi: la lectura es fa a PHP localment i el límit de bytes evita fitxers grans. L'emmagatzematge extern lent està limitat per `max_execution_time` de PHP.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/notes.md) · [Español](../../../es/reference/tools/notes.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/notes.md) · [Português (Portugal)](../../../pt-PT/reference/tools/notes.md)
