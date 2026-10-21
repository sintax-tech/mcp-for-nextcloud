<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/calendar.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/calendar.md).

# Calendar

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Calendar** | listar calendarios y eventos (recurrencias, zonas horarias y eventos de día completo) | crear, editar, mover, eliminar y transferir eventos mediante el flujo real de CalDAV, con participantes. El administrador debe conceder permisos de escritura en la matriz de administración (no se necesita usar el terminal); primero se devuelve un plan: **no se escribe nada hasta que el usuario lo aprueba.** |

| Herramienta | Permiso | Notas |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendarios visibles para el usuario, propios y compartidos. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Eventos del intervalo (predeterminado: los próximos 7 días), con recurrencias expandidas. |
| `calendar_create_event` | calendar.create | Evento sencillo; `attendees` son UID internos que se resuelven al correo de la cuenta. |
| `calendar_update_event` | calendar.edit | Actualiza texto y fechas y reemplaza los asistentes; `attendees: []` los elimina a todos. Se rechaza cambiar asistentes en una serie o en un evento organizado por otra persona. |
| `calendar_move_event` | calendar.move | MOVE dentro del mismo propietario; no se notifica a los asistentes, como sucede en la aplicación Calendar. |
| `calendar_delete_event` | calendar.delete | Elimina la serie y la envía a la papelera; requiere que la retención esté activa. |
| `calendar_transfer_event` | calendar.transfer | MOVE a un calendario de otro propietario que se haya compartido con permiso de escritura; rechaza eventos con asistentes. |

Calendar ofrece lectura y cinco operaciones de escritura, sin necesidad de usar el terminal. Estas operaciones empiezan desactivadas en la matriz de administración (Administración → MCP) y solo aparecen para quienes tengan el permiso; todas requieren también `confirm: true`, ACL de Nextcloud (y `confirm_shared` en el calendario de otra persona) y, opcionalmente, un ETag. Compatibilidad: en `info.xml`, la aplicación declara `min-version` 32 y `max-version` 35; las pruebas cubren la integración interna (`EmbeddedCalDavServer`) del núcleo y ese intervalo. Una nueva versión de Nextcloud requiere una nueva versión de la aplicación. El usuario debe tener habilitada la aplicación Calendar. Sabre y Symfony Console proceden del núcleo Nextcloud y no se incluyen en `vendor/` del paquete.

| Herramienta de escritura | Permiso | Comportamiento |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Evento sencillo; `attendees` son UID internos que se resuelven al correo de la cuenta. |
| `calendar_update_event` | calendar.edit | Actualiza texto y fechas y reemplaza los asistentes; `attendees: []` los elimina a todos. Se rechaza cambiar asistentes en una serie o en un evento organizado por otra persona. |
| `calendar_move_event` | calendar.move | MOVE dentro del mismo propietario; no se notifica a los asistentes, como sucede en la aplicación Calendar. |
| `calendar_delete_event` | calendar.delete | Elimina la serie y la envía a la papelera; requiere que la retención esté activa. |
| `calendar_transfer_event` | calendar.transfer | MOVE a un calendario de otro propietario que se haya compartido con permiso de escritura; rechaza eventos con asistentes. |

De forma predeterminada, create, update y delete usan `send_invitations: false` (`x-nc-scheduling: false`). El valor `true` entrega las invitaciones al planificador CalDAV de Nextcloud, incluidos los invitados existentes y CANCEL. El plan y el resultado informan `imipEnabled`, leído del ajuste nativo `dav/sendInvitations`: si está desactivado, el servidor no envía correo (el iTIP interno sí llega a la bandeja del invitado), y el asistente debe comunicárselo al usuario. La aplicación nunca confirma la entrega del correo. Las escrituras en calendarios de otras personas siguen necesitando `confirm_shared: true` después de que el usuario las apruebe.

**Confirmación antes de cada escritura (el servidor no guarda ningún estado).** Las cinco operaciones de escritura, si se llaman sin `confirm: true`, solo devuelven un plan (`requiresConfirmation: true`): valores actuales y propuestos, asistentes añadidos/eliminados, destino, consecuencias para invitaciones/CANCEL/papelera y el `etag` actual. No se escribe ni se programa nada. El asistente debe mostrar el plan, pedir aprobación al usuario y repetir la llamada con `confirm: true` y los mismos argumentos solo después de recibir un sí explícito. Debe volver a enviar el `etag` del plan (es opcional; si se incluye y ha cambiado, se rechaza la escritura y hay que solicitar un nuevo plan). No hay tabla, token ni registro de aprobación: el servidor no comprueba que la persona haya dado su aprobación, aunque siguen aplicándose los permisos, ACL, autorizaciones y `confirm_shared`. La prueba propia usa los controladores directamente y no recorre este flujo.

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/calendar.md) · Español · [Català](../../../ca/reference/tools/calendar.md) · [Português (Brasil)](../../../pt-BR/reference/tools/calendar.md) · [Português (Portugal)](../../../pt-PT/reference/tools/calendar.md)
