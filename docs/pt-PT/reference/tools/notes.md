<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/notes.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/notes.md).

# Notes

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Notas** | listar, ler, pesquisar (por título e conteúdo Markdown) | criar, editar, mover entre categorias, excluir (só quando o lixo permite recuperar) |

| Tool | Permissão | Notas |
| --- | --- | --- |
| `notes_list` `{}` | notes.read | Notas `.md`/`.txt` da pasta da aplicação Notes (preferência `notesPath`, padrão `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Busca por título e conteúdo Markdown (até 1 MiB), com trecho contextual da ocorrência. Opcionalmente restrita a uma categoria. |
| `notes_read` `{id}` | notes.read | Nota com conteúdo; limite de 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Nunca sobrescreve; em colisão, usa `Title (2)`. |
| `notes_edit` `{id, content?, title?, etag?}` | notes.edit | Conteúdo e/ou título. |
| `notes_move` `{id, category, etag?}` | notes.move | Troca de categoria (subpasta), sem sobrescrever. |
| `notes_delete` `{id, etag?}` | notes.delete | Exige `confirm: true`, a aplicação de ficheiros eliminados (`files_trashbin`) ativa e o armazenamento da nota coberto por ela; em storage externo sem lixo, a exclusão é bloqueada. |

Consulte `mcp_guide` para o esquema e as operações disponíveis.

Tools de Notes exigem a aplicação Notes ativada para o utilizador. Leitura começa permitida; criação, edição, movimentação e exclusão começam negadas até o administrador liberar. Não há timeout próprio: a leitura é local ao PHP e o limite de bytes protege contra ficheiros grandes. Storage externo lento fica limitado ao `max_execution_time` do PHP.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/notes.md) · [Español](../../../es/reference/tools/notes.md) · [Català](../../../ca/reference/tools/notes.md) · [Português (Brasil)](../../../pt-BR/reference/tools/notes.md) · Português (Portugal)
