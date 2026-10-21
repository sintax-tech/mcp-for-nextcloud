<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/logs.md -->
<!-- translation-source-revision: c0f730d97a84cf61317486c67722af52cf30325e -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../../en/reference/tools/logs.md).

# Logs

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Log do servidor** | para administradores e os grupos que eles escolherem: listar as entradas do `nextcloud.log` com filtros e resumi-las (totais, assinaturas frequentes, URLs, user agents, entradas por hora). Desligado por padrão para todos; IPs mascarados, saída marcada como não confiável, toda leitura auditada. Com etiquetas de exclusão configuradas, a opção de admin "Esconder o texto das mensagens do log" (ligada por padrão) esconde o texto das mensagens e das exceções, o user agent e o detalhe da URL e recusa o filtro `contains`; desligada, o log volta completo para diagnóstico | — (somente leitura) |


Consulte `mcp_guide` para o esquema e as operações disponíveis.

| Módulo | Ferramentas | Dependência |
| --- | --- | --- |
| Logs | `logs_list`, `logs_analyze` | só leitura do `nextcloud.log` pelo `ILogFactory`/`IFileBased::getEntries` do core; exige `log_type` em arquivo (padrão); ver [Log do servidor](../../guides/server-logs.md#log-do-servidor-102) |

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/logs.md) · [Español](../../../es/reference/tools/logs.md) · [Català](../../../ca/reference/tools/logs.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/logs.md)
