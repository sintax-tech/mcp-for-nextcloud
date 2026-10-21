<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/resources.md -->
<!-- translation-source-revision: c0f730d97a84cf61317486c67722af52cf30325e -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../en/reference/resources.md).

# Recursos (MCP Resources)

O servidor anuncia a capability `resources: {}` tanto no fluxo legado `initialize` quanto no fluxo moderno `server/discover`.

- **`resources/list`**: expõe `mcp://guide` (Guia das ferramentas), retornando a documentação completa em Markdown renderizada dinamicamente com base nas permissões concedidas e apps ativos para o usuário.
- **`resources/templates/list`**: expõe templates de URI parametrizados:
  - `nc://files/{path}`: arquivo no armazenamento do usuário (requer grant `files.read`).
  - `nc://notes/{id}`: nota identificada pelo id numérico (requer grant `notes.read` e app Notes habilitado).
- **`resources/read`**: leitura de recursos:
  - `mcp://guide`: entrega o guia formatado em `text/markdown`.
  - `nc://files/{path}`: lê arquivos de texto (`text/*`, PDF, DOCX, ODT via extração de texto idêntica ao `files_read`, com limite de 100.000 caracteres) ou pequenos arquivos binários como `blob` (base64) até 512 KiB. Arquivos binários maiores são recusados com erro.
  - `nc://notes/{id}`: entrega o conteúdo da nota em `text/markdown` (limite de 1 MiB).
  - Recursos inexistentes, não permitidos ou ocultados por etiquetas (`VisibilityGuard`) retornam erro padrão (`-32602` na era moderna, `-32002` na era legada) e nunca um array de conteúdo vazio.

[Guias de operação](../guides/README.md).

[English](../../en/reference/resources.md) · [Español](../../es/reference/resources.md) · [Català](../../ca/reference/resources.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/reference/resources.md)
