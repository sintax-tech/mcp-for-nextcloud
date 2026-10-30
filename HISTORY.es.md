# Historial

[English](HISTORY.md) · [Português (Brasil)](HISTORY.pt-BR.md) · [Deutsch](HISTORY.de.md) · Español

Este historial empieza en la 1.2, la primera versión con README en español. Las versiones anteriores (1.1 y 1.0) se describen en el historial [en inglés](HISTORY.md) y [en portugués](HISTORY.pt-BR.md).

## Novedades de 1.2.1

Consulta [el changelog](CHANGELOG.md#121) para ver todos los cambios.

- **Configuración de copias ([#17](https://github.com/sintax-tech/mcp-for-nextcloud/issues/17)):** en *Protección de datos*, elige un nombre de carpeta directamente en la raíz personal de quien ejecuta la acción (predeterminado `MCP backups`; se admite `.mcp-backups`). La carpeta aparece con la primera copia. Cambiar el nombre no mueve ni borra contenido; todos los nombres anteriores, incluido el predeterminado antiguo, siguen protegidos contra escrituras MCP y mantienen las reglas de visibilidad de las copias espejo.
- **Copias de Archivos:** activadas por defecto. Un administrador puede suspender las copias previas MCP solo con `files_versions` nativo activo. Se comprueba de nuevo su disponibilidad para quien actúa; sin versiones las copias son obligatorias y las operaciones existentes siguen rechazadas si falta su requisito Versions. No se garantiza una versión nativa para cada archivo/backend. Eliminar contactos siempre exige una copia verificada en la carpeta configurada, incluso con copias de Archivos suspendidas. Las copias antiguas se conservan.
- **Mail ([#1](https://github.com/sintax-tech/mcp-for-nextcloud/issues/1)):** `mail_list_accounts` y `mail_list_mailboxes` listan solo cuentas propias y carpetas/contadores en caché. Mail es opcional y exige `mail.read`. Estas llamadas no sincronizan IMAP, no leen mensajes ni cambian el estado del correo. Abre la cuenta una vez en Mail si la caché está vacía; una caché antigua puede devolver información antigua. La celda de integración opt-in usa Dovecot y Mail desechables, no un servidor de producción.
- **Subtareas ([#18](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18)):** las tareas devuelven su `parent`; crear y editar la definen en el mismo calendario o la quitan con `null`, y `tasks_list_tasks` lista las subtareas directas de una tarea. Se rechazan ciclos y anidamientos de más de 64 niveles; las escrituras confirmadas del MCP que definen la tarea principal se ejecutan de una en una y requieren el bloqueo de archivos del servidor. Contribución de @mturgeonferland.
- **Visibilidad:** una carpeta común anidada llamada `MCP backups` deja de confundirse con la carpeta de copias en la raíz personal. Siguen vigentes el ámbito, las etiquetas de exclusión y el rechazo ante errores.




## Novedades de la 1.2

Consulta [el changelog](CHANGELOG.md#120) para ver todos los cambios y el aviso sobre vencimientos antiguos de Deck.

- **Deck:** etiquetas, dependencias nativas entre tarjetas en Nextcloud 34/35 y archivos visibles asociados a las tarjetas: vincular un archivo existente o desvincularlo sin borrar el original. Los planes explican el acceso de lectura para miembros actuales y futuros del tablero y los límites de cambios posteriores en las políticas de uso compartido. El vencimiento admite hora; `dueAt` la devuelve con el desplazamiento de tu zona horaria. Las etiquetas y los archivos de tarjetas funcionan en Nextcloud 32–35.
- **Política opcional de tipos de archivo:** desactivada por defecto. La administración puede permitir extensiones en *Protección de datos*. Se aplica a las escrituras MCP, incluidas cargas, restauración de versiones, copias/movimientos, lotes, deshacer y Notes; las copias internas obligatorias siguen disponibles. Comprueba nombres, no contenido, y no elimina la inyección de instrucciones. Las copias de carpetas con la política activa usan una lista validada e informan de cualquier copia parcial.
- **Ediciones menores:** añade texto a una nota o sustituye un fragmento único con `notes_edit`; `files_replace` comprueba el tamaño declarado antes de leer y rechaza coincidencias superpuestas por ser ambiguas. El mensaje no revela el número de coincidencias; `old` tiene un límite de 64 KiB.
- **Conexiones y filtros de tareas:** corregida la navegación tras el consentimiento OAuth en el flujo de Mistral comunicado. Las categorías siguen siendo filtros literales; una lista filtrada vacía explica cómo quitar el filtro o incluir tareas completadas.
- **Revisa los vencimientos antiguos de Deck:** las fechas sin hora escritas por MCP antes de esta corrección para cuentas al oeste de UTC pueden aparecer un día antes. Las tarjetas existentes no se modifican automáticamente; comprueba el día previsto y corrige las afectadas expresamente.
