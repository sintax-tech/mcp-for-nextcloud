<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/configuration.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../en/reference/configuration.md).

# Configuração

Use as interfaces de administração e configurações pessoais para alterar a política. Gravar preferências de política diretamente com `occ` ignora verificações de revisão, resolução de grupos e revogação de credenciais; não é uma forma suportada de administrar a política de 1.1.0. Diagnósticos somente leitura, como `occ config:app:get mcp service_enabled`, continuam úteis.

| Armazenamento | Chave ou responsável | Significado |
| --- | --- | --- |
| Aplicativo `mcp` | `service_enabled` | `1` ativado; `0` ou ausente, desativado. Altere pelo comando/interface do serviço. |
| Usuário, aplicativo `mcp` | `eligible` | Exceção individual `1`/`0` de **Pode conectar**; sua ausência permite herança de grupo e depois o padrão desativado. |
| Usuário, aplicativo `mcp` | `connected` | `1` indica conexão pessoal ativa. É diferente de uma credencial OAuth válida. |
| Usuário, aplicativo `mcp` | `grant_<module>_<operation>` | Exceção individual `1`/`0`. Ausência significa herança de grupos confiáveis e depois leitura permitida, exceto logs; outras operações negadas. |
| Tabelas de política | `mcp_policy_state`, `mcp_policy_events` | Revisão/época global de autenticação e eventos persistentes de auditoria, limpeza e recibos. |
| Tabelas de grupos | `mcp_group_grants`, `mcp_group_life` | Política que somente concede permissões e identidade/ciclo de vida de grupo observados. |
| Tabelas de autenticação | `mcp_auth_users`, `mcp_auth_recheck` | Gerações/invalidações de credenciais por usuário e verificações adiadas do ciclo de vida. |
| Tabelas de recursos | `mcp_scope_modes`, `mcp_scope_items` | Restrições independentes de Files, Calendar e Tasks. |
| Tabela de clientes | `mcp_oauth_clients` | Estado autoritativo e gerações de clientes nativos/personalizados gerenciados após migrar. |
| Aplicativo `mcp` | `oauth_client_hosts` | Hosts exatos de clientes publicados; ausente ou vazio usa `claude.ai,chatgpt.com`. Edite em clientes OAuth. |
| Entradas antigas do aplicativo | `oauth_native_client_enabled`, `oauth_custom_clients` | Entradas de migração para a tabela de clientes, não a autoridade atual dos clientes gerenciados. |
| Tabela própria | `mcp_logs_groups` | Grupos JSON canônicos e versão; vazio significa somente administradores. Importa a configuração antiga na primeira leitura. |
| Aplicativo `mcp` | `checkout_max_bytes` | Limite de upload de checkout em bytes; padrão 50 MiB, limitado por `post_max_size` do PHP. |
| Aplicativo `mcp` | `hidden_system_tags` | Lista JSON de IDs de etiquetas de sistema excluídas; `[]` desativa exclusões por etiquetas. |
| Aplicativo `mcp` | `logs_hide_file_names` | `1` padrão: ocultar texto dos logs enquanto houver exclusões; `0` permite o log completo para diagnóstico. |

Essas tabelas descrevem persistência, não uma API pública de mutação SQL. Editar linhas ou restaurar apenas algumas tabelas pode quebrar garantias de revisão, ciclo de vida e revogação. Consulte [permissões](../guides/permissions.md), [OAuth](oauth.md) e as [orientações de atualização/recuperação](../installation.md).

Nextcloud controla `enabled`, `installed_version`, `types`, `levels` e `ocsid`; não são interruptores de política MCP. Em particular, `enabled=yes` do Nextcloud é diferente de `service_enabled=1`.

[Guias de operação](../guides/README.md).

[English](../../en/reference/configuration.md) · [Español](../../es/reference/configuration.md) · [Català](../../ca/reference/configuration.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/reference/configuration.md)
