<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/resources.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/reference/resources.md).

# Recursos (recursos MCP)

El servidor anuncia la capacitat `resources: {}` tant en el flux antic `initialize` com en el flux modern `server/discover`.

- **`resources/list`**: exposa `mcp://guide` (Guia d'eines), que retorna tota la documentació en Markdown, renderitzada dinàmicament segons els permisos concedits i les aplicacions actives per a l'usuari.
- **`resources/templates/list`**: exposa plantilles d'URI parametritzades:
  - `nc://files/{path}`: fitxer de l'emmagatzematge de l'usuari (requereix el permís `files.read`).
  - `nc://notes/{id}`: nota identificada pel seu identificador numèric (requereix el permís `notes.read` i l'aplicació Notes activada).
- **`resources/read`**: lectura de recursos:
  - `mcp://guide`: proporciona la guia formatada com a `text/markdown`.
  - `nc://files/{path}`: llegeix fitxers de text (`text/*`, PDF, DOCX, ODT mitjançant l'extracció de text idèntica a `files_read`, amb un límit de 100.000 caràcters) o fitxers binaris petits com a `blob` (base64) fins a 512 KiB. Els fitxers binaris més grans es rebutgen amb un error.
  - `nc://notes/{id}`: proporciona el contingut de la nota com a `text/markdown` (límit d'1 MiB).
  - Els recursos inexistents, no autoritzats o ocults per etiquetes (`VisibilityGuard`) retornen l'error estàndard (`-32602` en l'era moderna, `-32002` en l'era antiga) i mai no retornen una matriu de contingut buida.

[Guies operatives](../guides/README.md).

[English](../../en/reference/resources.md) · [Español](../../es/reference/resources.md) · Català · [Português (Brasil)](../../pt-BR/reference/resources.md) · [Português (Portugal)](../../pt-PT/reference/resources.md)
