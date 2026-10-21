<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/resources.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/reference/resources.md).

# Recursos (recursos MCP)

El servidor anuncia la capacidad `resources: {}` tanto en el flujo heredado `initialize` como en el flujo moderno `server/discover`.

- **`resources/list`**: expone `mcp://guide` (guía de herramientas), que devuelve toda la documentación en Markdown, generada dinámicamente según los permisos concedidos y las aplicaciones activas para el usuario.
- **`resources/templates/list`**: expone plantillas de URI con parámetros:
  - `nc://files/{path}`: archivo del almacenamiento del usuario (requiere el permiso `files.read`).
  - `nc://notes/{id}`: nota identificada por su ID numérico (requiere el permiso `notes.read` y que la aplicación Notes esté habilitada).
- **`resources/read`**: lectura de recursos:
  - `mcp://guide`: entrega la guía con formato `text/markdown`.
  - `nc://files/{path}`: lee archivos de texto (`text/*`, PDF, DOCX y ODT mediante la misma extracción de texto que `files_read`, con un límite de 100 000 caracteres) o archivos binarios pequeños como `blob` (base64), hasta 512 KiB. Los archivos binarios más grandes se rechazan con un error.
  - `nc://notes/{id}`: entrega el contenido de la nota como `text/markdown` (límite de 1 MiB).
  - Los recursos inexistentes, no permitidos u ocultos por etiquetas (`VisibilityGuard`) devuelven el error estándar (`-32602` en el modo moderno, `-32002` en el heredado); nunca devuelven una matriz de contenido vacía.

[Guías operativas](../guides/README.md).

[English](../../en/reference/resources.md) · Español · [Català](../../ca/reference/resources.md) · [Português (Brasil)](../../pt-BR/reference/resources.md) · [Português (Portugal)](../../pt-PT/reference/resources.md)
