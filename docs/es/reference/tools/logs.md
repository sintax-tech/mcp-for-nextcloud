<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/logs.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/logs.md).

# Registros

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Registro del servidor** | Para administradores y los grupos que seleccionen: listar entradas de `nextcloud.log` con filtros y resumirlas (totales, firmas frecuentes, URL, agentes de usuario y entradas por hora). Desactivado para todos de forma predeterminada; las IP se enmascaran, la salida se marca como no confiable y cada lectura queda auditada. Mientras haya etiquetas de exclusión configuradas, la opción de administración «Ocultar el texto de los mensajes del registro» (activada de forma predeterminada) oculta el texto de los mensajes y las excepciones, el agente de usuario y los detalles de la URL, y rechaza el filtro `contains`; al desactivarla, vuelve a mostrarse el registro completo para diagnosticar problemas. | — (solo lectura) |

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

| Módulo | Herramientas | Dependencia |
| --- | --- | --- |
| Registros | `logs_list`, `logs_analyze` | acceso de solo lectura a `nextcloud.log` mediante `ILogFactory`/`IFileBased::getEntries` del núcleo; requiere un `log_type` basado en archivos (el valor predeterminado); consulta [Registro del servidor](../../guides/server-logs.md#registro-del-servidor-102) |

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/logs.md) · Español · [Català](../../../ca/reference/tools/logs.md) · [Português (Brasil)](../../../pt-BR/reference/tools/logs.md) · [Português (Portugal)](../../../pt-PT/reference/tools/logs.md)
