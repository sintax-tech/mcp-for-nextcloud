<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/logs.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/logs.md).

# Logs

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Log do servidor** | para os administradores e os grupos que eles escolherem: listar as entradas do `nextcloud.log` com filtros e resumi-las (totais, assinaturas frequentes, URLs, user agents, entradas por hora). Desligado por predefinição para todos; IPs mascarados, saída marcada como não confiável, toda leitura auditada. Com etiquetas de exclusão configuradas, a opção de administração "Esconder o texto das mensagens do log" (ligada por predefinição) esconde o texto das mensagens e das exceções, o user agent e o detalhe da URL e recusa o filtro `contains`; desligada, o log volta completo para diagnóstico | — (somente leitura) |

Consulte `mcp_guide` para o esquema e as operações disponíveis.

| Módulo | Tools | Dependência |
| --- | --- | --- |
| Logs | `logs_list`, `logs_analyze` | só leitura do `nextcloud.log` pelo `ILogFactory`/`IFileBased::getEntries` do core; exige `log_type` em ficheiro (predefinição); ver [Log do servidor](../../guides/server-logs.md#log-do-servidor-102) |

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/logs.md) · [Español](../../../es/reference/tools/logs.md) · [Català](../../../ca/reference/tools/logs.md) · [Português (Brasil)](../../../pt-BR/reference/tools/logs.md) · Português (Portugal)
