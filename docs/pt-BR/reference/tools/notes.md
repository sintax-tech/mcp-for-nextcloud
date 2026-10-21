<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/notes.md -->
<!-- translation-source-revision: c0f730d97a84cf61317486c67722af52cf30325e -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../../en/reference/tools/notes.md).

# Notas

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Notas** | listar, ler, buscar (por título e conteúdo Markdown) | criar, editar, mover entre categorias, excluir (só quando a lixeira permite recuperar) |

| Ferramenta | Permissão | Observações |
| --- | --- | --- |
| `notes_list` `{}` | notes.read | Notas `.md`/`.txt` da pasta do app Notes (preferência `notesPath`, padrão `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Busca por título e conteúdo Markdown (até 1 MiB), com trecho contextual da ocorrência. Opcionalmente restrita a uma categoria. |
| `notes_read` `{id}` | notes.read | Nota com conteúdo; limite de 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Nunca sobrescreve; em colisão, usa `Título (2)`. |
| `notes_edit` `{id, content?, title?, etag?}` | notes.edit | Conteúdo e/ou título. |
| `notes_move` `{id, category, etag?}` | notes.move | Troca de categoria (subpasta), sem sobrescrever. |
| `notes_delete` `{id, etag?}` | notes.delete | Exige `confirm: true`, a lixeira (`files_trashbin`) ativa e o storage da nota coberto por ela; em storage externo sem lixeira, a exclusão é bloqueada. |


Consulte `mcp_guide` para o esquema e as operações disponíveis.

Tools de Notes exigem o app Notes habilitado para o usuário. Leitura começa permitida; criação, edição, movimentação e exclusão começam negadas até o administrador liberar. Não há timeout próprio: a leitura é local ao PHP e o limite de bytes protege contra arquivos grandes. Storage externo lento fica limitado ao `max_execution_time` do PHP.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/notes.md) · [Español](../../../es/reference/tools/notes.md) · [Català](../../../ca/reference/tools/notes.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/notes.md)
