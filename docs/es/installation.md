<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../en/installation.md).

# Instalación

## Requisitos

- Nextcloud **32 a 35**; cada versión se verifica mediante pruebas del contrato de API frente a la primera compilación de su versión principal (con las versiones correspondientes de Deck y Talk); Deck 1.16+ y Talk 22+ en Nextcloud 32, hasta Deck 1.19 y Talk 25 en Nextcloud 35.
- PHP **8.2+** con `zip`, `mbstring` y `dom`.
- Aplicaciones opcionales, solo para sus propias herramientas (ocultas mientras la aplicación esté desactivada): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versions (`files_versions`, necesario para editar) y Deleted files (`files_trashbin`, necesario para eliminar).
- Deck y Talk son opcionales: sus herramientas solo aparecen cuando la aplicación está habilitada en una versión compatible. Una versión anterior cuenta como deshabilitada; sus herramientas permanecen ocultas y la página de administración indica qué versión las vuelve a habilitar.

La instalación habitual se realiza desde Nextcloud App Store: *Aplicaciones* → busca **MCP for Cloud** → **Descargar y habilitar** (o `occ app:install mcp`). El paquete de la tienda está firmado y se actualiza mediante el actualizador de aplicaciones. La instalación por SSH que aparece abajo es la alternativa para desarrollo o para probar una compilación local.

### Instalar por SSH

Sustituye `<server>`, `<nextcloud>` (directorio raíz de la instalación), `<apps>` (directorio de aplicaciones con permisos de escritura, por ejemplo `custom_apps` o `apps`, según `apps_paths` en `config/config.php`) y `<www>` (usuario del servidor web, por ejemplo `www-data`).

```sh
scp build/mcp-1.1.2.tar.gz <server>:/tmp/
ssh <server>
sudo tar -xzf /tmp/mcp-1.1.2.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

En Docker, ejecuta los comandos `occ` dentro del contenedor como el usuario del servidor web. Si PHP usa OPcache con `validate_timestamps=0`, reinicia PHP-FPM después de copiar los archivos.

Para actualizar una compilación instalada por SSH: `occ app:disable mcp`, elimina `<apps>/mcp`, extrae el paquete nuevo, ejecuta `occ app:enable mcp` y `occ upgrade` si Nextcloud lo solicita. Hay que sustituir la carpeta completa para que no queden archivos antiguos en `vendor/`. Para quitar la aplicación, ejecuta `occ app:remove mcp`.

Las migraciones de 1.1.0 conservan los ID originales e importan los ajustes antiguos de clientes nativos/personalizados a filas autoritativas. Las preferencias y credenciales válidas de 1.0.8 sobreviven a la actualización. Los caminos de validación incluyen upstream 1.0.8 original, el 1.0.8 local migrado, la actualización desde upstream 1.1.0 y 1.1.1 y la sustitución de una instalación upstream 1.1.2 ya migrada. La sustitución de la misma versión no fuerza una actualización ni repite migraciones para restablecer la política.

## Actualización y recuperación

Antes de actualizar, prepara una copia coherente de aplicación, base de datos y configuración/datos necesarios de Nextcloud, siguiendo el procedimiento del operador. El modo de mantenimiento impide el tráfico durante la sustitución sin cambiar la política de servicio MCP ni revocar credenciales por una actualización rutinaria. Sustituye todo el directorio de la aplicación, ejecuta la actualización nativa necesaria, limpia/reinicia las cachés PHP cuando corresponda y comprueba historial de migraciones, recursos de interfaz, permisos y conexiones existentes antes de reabrir el tráfico.

Prioriza reparar hacia delante tras una actualización fallida. Revertir solo los archivos de aplicación a 1.0.8 es inseguro: ese código no aplica las nuevas revocaciones por época/generación ni restricciones de recursos. Una copia de base de datos también puede contener credenciales revocadas después de crearla.

Para una recuperación coordinada, mantén Nextcloud en mantenimiento mientras restauras estados correspondientes de aplicación y base de datos y la configuración/datos necesarios. Antes de reabrir MCP, invalida códigos/tokens OAuth restaurados mediante los comandos de política admitidos, desconecta explícitamente las cuentas que deban reconectarse y revisa permisos efectivos y restricciones de recursos. Revisa/revoca por separado las contraseñas de aplicación de Nextcloud en Seguridad. Volver a habilitar el servicio no sustituye esta revisión. Una versión antigua que no aplique las restricciones necesarias no puede reabrirse para esos usuarios.

La suite nativa desechable ensaya restaurar aplicación/SQLite de la misma versión, rechazar credenciales antiguas y reconectar explícitamente. No certifica las herramientas de copia del operador, una reversión solo de archivos ni todas las configuraciones de almacenamiento de producción.

[Guías operativas](guides/README.md).

[English](../en/installation.md) · Español · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
