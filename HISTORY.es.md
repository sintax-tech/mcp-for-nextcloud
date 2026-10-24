# Historial

[English](HISTORY.md) · [Português (Brasil)](HISTORY.pt-BR.md) · [Deutsch](HISTORY.de.md) · Español

Este historial empieza en la 1.2, la primera versión con README en español. Las versiones anteriores (1.1 y 1.0) se describen en el historial [en inglés](HISTORY.md) y [en portugués](HISTORY.pt-BR.md).

## Novedades de la 1.2

Consulta [el changelog](CHANGELOG.md#120) para ver todos los cambios y el aviso sobre vencimientos antiguos de Deck.

- **Deck:** etiquetas, dependencias nativas entre tarjetas en Nextcloud 34/35 y archivos visibles asociados a las tarjetas: vincular un archivo existente o desvincularlo sin borrar el original. Los planes explican el acceso de lectura para miembros actuales y futuros del tablero y los límites de cambios posteriores en las políticas de uso compartido. El vencimiento admite hora; `dueAt` la devuelve con el desplazamiento de tu zona horaria. Las etiquetas y los archivos de tarjetas funcionan en Nextcloud 32–35.
- **Política opcional de tipos de archivo:** desactivada por defecto. La administración puede permitir extensiones en *Protección de datos*. Se aplica a las escrituras MCP, incluidas cargas, restauración de versiones, copias/movimientos, lotes, deshacer y Notes; las copias internas obligatorias siguen disponibles. Comprueba nombres, no contenido, y no elimina la inyección de instrucciones. Las copias de carpetas con la política activa usan una lista validada e informan de cualquier copia parcial.
- **Ediciones menores:** añade texto a una nota o sustituye un fragmento único con `notes_edit`; `files_replace` comprueba el tamaño declarado antes de leer y rechaza coincidencias superpuestas por ser ambiguas. El mensaje no revela el número de coincidencias; `old` tiene un límite de 64 KiB.
- **Conexiones y filtros de tareas:** corregida la navegación tras el consentimiento OAuth en el flujo de Mistral comunicado. Las categorías siguen siendo filtros literales; una lista filtrada vacía explica cómo quitar el filtro o incluir tareas completadas.
- **Revisa los vencimientos antiguos de Deck:** las fechas sin hora escritas por MCP antes de esta corrección para cuentas al oeste de UTC pueden aparecer un día antes. Las tarjetas existentes no se modifican automáticamente; comprueba el día previsto y corrige las afectadas expresamente.
