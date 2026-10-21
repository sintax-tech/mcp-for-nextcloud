<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/configuration.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/reference/configuration.md).

# Configuració

Utilitza les interfícies d'administració i configuració personal per canviar la política. Escriure preferències de política directament amb `occ` evita les comprovacions de revisió, la resolució de grups i la revocació de credencials; no és una manera admesa d'administrar la política de 1.1.0. Els diagnòstics de només lectura, com ara `occ config:app:get mcp service_enabled`, continuen sent útils.

| Emmagatzematge | Clau o responsable | Significat |
| --- | --- | --- |
| Aplicació `mcp` | `service_enabled` | `1` activat; `0` o absent, desactivat. Canvia-ho amb l'ordre/interfície del servei. |
| Usuari, aplicació `mcp` | `eligible` | Excepció individual `1`/`0` de **Pot connectar-se**; l'absència permet herència de grup i després el valor predeterminat desactivat. |
| Usuari, aplicació `mcp` | `connected` | `1` indica connexió personal activa. És diferent d'una credencial OAuth vigent. |
| Usuari, aplicació `mcp` | `grant_<module>_<operation>` | Excepció individual `1`/`0`. Absent significa herència de grups de confiança i després lectura permesa excepte registres; altres operacions denegades. |
| Taules de política | `mcp_policy_state`, `mcp_policy_events` | Revisió/època global d'autenticació i esdeveniments persistents d'auditoria, neteja i rebuts. |
| Taules de grups | `mcp_group_grants`, `mcp_group_life` | Política que només concedeix permisos i identitat/cicle de vida de grup observats. |
| Taules d'autenticació | `mcp_auth_users`, `mcp_auth_recheck` | Generacions/invalidacions de credencials per usuari i comprovacions diferides del cicle de vida. |
| Taules de recursos | `mcp_scope_modes`, `mcp_scope_items` | Restriccions independents de Files, Calendar i Tasks. |
| Taula de clients | `mcp_oauth_clients` | Estat autoritatiu i generacions de clients natius/personalitzats gestionats després de migrar. |
| Aplicació `mcp` | `oauth_client_hosts` | Hosts exactes de clients publicats; absent o buit utilitza `claude.ai,chatgpt.com`. Edita-ho a clients OAuth. |
| Entrades antigues de l'aplicació | `oauth_native_client_enabled`, `oauth_custom_clients` | Entrades de migració per a la taula de clients, no l'autoritat vigent dels clients gestionats. |
| Taula pròpia | `mcp_logs_groups` | Grups JSON canònics i versió; buit significa només administradors. Importa la configuració antiga a la primera lectura. |
| Aplicació `mcp` | `checkout_max_bytes` | Límit de pujada de checkout en bytes; predeterminat 50 MiB, limitat per `post_max_size` de PHP. |
| Aplicació `mcp` | `hidden_system_tags` | Llista JSON d'ID d'etiquetes de sistema excloses; `[]` desactiva les exclusions per etiquetes. |
| Aplicació `mcp` | `logs_hide_file_names` | `1` predeterminat: amaga text dels missatges de registre mentre hi hagi exclusions; `0` permet el registre complet per al diagnòstic. |

Aquestes taules descriuen persistència, no una API pública de mutació SQL. Editar files o restaurar només algunes taules pot trencar les garanties de revisió, cicle de vida i revocació. Consulta [permisos](../guides/permissions.md), [OAuth](oauth.md) i les [indicacions d'actualització/recuperació](../installation.md).

Nextcloud controla `enabled`, `installed_version`, `types`, `levels` i `ocsid`; no són interruptors de política MCP. En particular, `enabled=yes` de Nextcloud és diferent de `service_enabled=1`.

[Guies operatives](../guides/README.md).

[English](../../en/reference/configuration.md) · [Español](../../es/reference/configuration.md) · Català · [Português (Brasil)](../../pt-BR/reference/configuration.md) · [Português (Portugal)](../../pt-PT/reference/configuration.md)
