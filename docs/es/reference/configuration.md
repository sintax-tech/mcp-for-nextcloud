<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/configuration.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/reference/configuration.md).

# Configuración

Usa las interfaces de administración y ajustes personales para cambiar la política. Escribir preferencias de política directamente con `occ` elude las comprobaciones de revisión, la resolución de grupos y la revocación de credenciales; no es una forma admitida de administrar la política de 1.1.0. Los diagnósticos de solo lectura, como `occ config:app:get mcp service_enabled`, siguen siendo útiles.

| Almacenamiento | Clave o responsable | Significado |
| --- | --- | --- |
| Aplicación `mcp` | `service_enabled` | `1` activado; `0` o ausente, desactivado. Cambiar mediante el comando/interfaz del servicio. |
| Usuario, aplicación `mcp` | `eligible` | Excepción individual `1`/`0` de **Puede conectarse**; su ausencia permite herencia de grupo y después el valor predeterminado desactivado. |
| Usuario, aplicación `mcp` | `connected` | `1` indica conexión personal activa. Es distinto de una credencial OAuth vigente. |
| Usuario, aplicación `mcp` | `grant_<module>_<operation>` | Excepción individual `1`/`0`. Ausente significa herencia de grupos de confianza y después lectura permitida salvo registros; otras operaciones denegadas. |
| Tablas de política | `mcp_policy_state`, `mcp_policy_events` | Revisión/época global de autenticación y eventos persistentes de auditoría, limpieza y recibos. |
| Tablas de grupos | `mcp_group_grants`, `mcp_group_life` | Política que solo concede permisos e identidad/ciclo de vida de grupo observados. |
| Tablas de autenticación | `mcp_auth_users`, `mcp_auth_recheck` | Generaciones/invalidaciones de credenciales por usuario y comprobaciones diferidas del ciclo de vida. |
| Tablas de recursos | `mcp_scope_modes`, `mcp_scope_items` | Restricciones independientes de Files, Calendar y Tasks. |
| Tabla de clientes | `mcp_oauth_clients` | Estado autoritativo y generaciones de clientes nativos/personalizados gestionados tras la migración. |
| Aplicación `mcp` | `oauth_client_hosts` | Hosts exactos de clientes publicados; ausente o vacío usa `claude.ai,chatgpt.com`. Editar en clientes OAuth. |
| Entradas antiguas de la aplicación | `oauth_native_client_enabled`, `oauth_custom_clients` | Entradas de migración de la tabla de clientes, no la autoridad vigente de clientes gestionados. |
| Tabla propia | `mcp_logs_groups` | Grupos JSON canónicos y versión; vacío significa solo administradores. Importa la configuración antigua en la primera lectura. |
| Aplicación `mcp` | `checkout_max_bytes` | Límite de subida de checkout en bytes; predeterminado 50 MiB, limitado por `post_max_size` de PHP. |
| Aplicación `mcp` | `hidden_system_tags` | Lista JSON de ID de etiquetas de sistema excluidas; `[]` desactiva las exclusiones por etiquetas. |
| Aplicación `mcp` | `logs_hide_file_names` | `1` predeterminado: ocultar texto de mensajes de registro mientras haya exclusiones; `0` permite el registro completo para diagnóstico. |

Estas tablas describen persistencia, no una API pública de mutación SQL. Editar filas o restaurar solo algunas tablas puede romper las garantías de revisión, ciclo de vida y revocación. Consulta [permisos](../guides/permissions.md), [OAuth](oauth.md) y las [indicaciones de actualización/recuperación](../installation.md).

Nextcloud controla `enabled`, `installed_version`, `types`, `levels` y `ocsid`; no son interruptores de política MCP. En particular, `enabled=yes` de Nextcloud es distinto de `service_enabled=1`.

[Guías operativas](../guides/README.md).

[English](../../en/reference/configuration.md) · Español · [Català](../../ca/reference/configuration.md) · [Português (Brasil)](../../pt-BR/reference/configuration.md) · [Português (Portugal)](../../pt-PT/reference/configuration.md)
