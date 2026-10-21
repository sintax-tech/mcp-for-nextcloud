<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Talk** | conversaciones y mensajes (nunca marca nada como leído) | responder (con la opción de citar un mensaje o enlazar una tarjeta de Deck o un evento del calendario visible para el usuario), enviar un mensaje directo a un usuario, un lote de mensajes, compartir o citar un archivo y crear una conversación de grupo (permiso independiente, desactivado de forma predeterminada). **No se envía nada antes de que el usuario lo apruebe: una llamada sin `confirm: true` solo devuelve el plan (destinatario, texto final, adjuntos y elementos que se crearían o compartirían) y no ejecuta ninguna acción; con `confirm: true`, vuelve a comprobar los permisos y envía. El servidor no conserva ningún ID de aprobación, token ni registro.** |

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · Español · [Català](../../../ca/reference/tools/talk.md) · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)
