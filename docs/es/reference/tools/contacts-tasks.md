<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/contacts-tasks.md -->
<!-- translation-source-revision: 0105e8e8a2706d139deb119f87d7d01c0788586c -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/contacts-tasks.md).

# Contactos y tareas (0.8.0)

| Módulo | Herramientas | Dependencia |
|---|---|---|
| Contactos | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | aplicación Contacts habilitada; CardDAV del núcleo |
| Tareas | `tasks_list_calendars`, `tasks_list_tasks`, `tasks_read_task`, `tasks_create_task`, `tasks_edit_task`, `tasks_complete_task`, `tasks_delete_task` | DAV del núcleo; no requiere la aplicación Tasks ni Calendar |

Los catálogos de Contacts son propios o compartidos y filtran explícitamente cualquier principal del sistema. Las lecturas usan adaptadores diferidos del backend nativo, después del filtro de acceso; las escrituras pasan por Sabre del núcleo (un dispatcher CardDAV independiente y el `EmbeddedDavDispatcher` existente para VTODO). La sesión debe corresponder al actor y el principal se fija al ejecutar. Las consultas a colecciones Calendar usan VEVENT de forma predeterminada; Tasks selecciona VTODO.

`GrantPolicy`/`GrantMatrix` incluyen `contacts` y `tasks` con operaciones read/create/edit/delete; las escrituras están desactivadas de forma predeterminada. Completar una tarea requiere edit. `PreviewsWrites` genera el plan sin efectos secundarios; `WriteGate` exige `confirm:true`; los recursos compartidos requieren `confirm_shared`; se comprueba el ETag proporcionado y `If-Match` usa el valor vuelto a leer. Al editar una vCard se conservan los campos, parámetros, grupos y versión desconocidos; el plan incluye todas las propiedades anteriores y posteriores y la vCard completa.

**En Nextcloud 33, eliminar un contacto es PERMANENTE y no hay papelera.** Solo después de confirmar, `ContactBackup` guarda la vCard original completa en `/MCP backups/Contacts/<catalog>/<name-or-uid>.<YYYYmmdd-HHMMSS>-<unique-suffix>.vcf`, en la zona horaria de la cuenta y en la carpeta del actor. Usa un mutex de aplicación, un sufijo aleatorio y una comprobación de colisión para no seleccionar archivos existentes, y verifica la copia volviendo a leer los bytes. Si falla, se impide la solicitud DELETE de CardDAV y el resultado indica `backupPath`. Para recuperarla, vuelve a importar desde la interfaz de Contacts. La vista previa no crea una copia de seguridad.

Las tareas usan VTODO, incluyen las que no tienen fecha, ocultan los elementos PRIVATE/CONFIDENTIAL de otro propietario y excluyen objetos de la papelera. Se permite leer recurrencias; se rechazan las escrituras sobre series o tareas con asistentes. Los campos que no han cambiado permanecen en iCalendar; completar escribe STATUS/PERCENT-COMPLETE/COMPLETED y reducir el progreso vuelve a abrir la tarea. Para eliminar se usa la papelera de CalDAV, se rechaza la retención cero y se comprueba `deleted-at` después de DELETE. Los listados usan limit/offset/nextOffset; para las tareas completadas se requiere `include_completed`. Las categorías (`CATEGORIES`) son texto libre: la lectura y el listado devuelven `categories`, combinadas a partir de todas las líneas `CATEGORIES` (se respetan las comas escapadas), con espacios recortados y duplicados eliminados sin distinguir mayúsculas Unicode, conservando la primera grafía. Create y edit aceptan `categories` (hasta 50 elementos de 1–255 caracteres después de quitar espacios, sin caracteres de control; se validan todos antes de escribir): si se omite, no se modifica ninguna línea; `[]` las elimina y una lista las reemplaza por una línea de varios valores. `tasks_list_tasks` filtra por `category` (coincidencia exacta sin distinguir mayúsculas) antes de aplicar `offset`/`limit`, junto con `include_completed`; el plan muestra cada nombre entre comillas, con sus valores anteriores y posteriores. Nunca se leen, filtran ni muestran las categorías de tareas ocultas por CLASS.

No hay migración, `approval_id`, registro de aprobación, requisito de `occ`, servicio adicional ni base de datos auxiliar. Las descripciones y `ToolGuideNotes` siguen en inglés; los títulos y mensajes usan Translator con paridad de/pt_BR/es. Las pruebas cubren planes, copia de seguridad, ACL, permisos, ETag, conservación y despacho real de Sabre; no demuestran los complementos internos en una instalación real de Nextcloud.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/contacts-tasks.md) · Español · [Català](../../../ca/reference/tools/contacts-tasks.md) · [Português (Brasil)](../../../pt-BR/reference/tools/contacts-tasks.md) · [Português (Portugal)](../../../pt-PT/reference/tools/contacts-tasks.md)
