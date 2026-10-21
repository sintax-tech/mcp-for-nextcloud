<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/logs.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/logs.md).

# Registres

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Registre del servidor** | per als administradors i els grups que triïn: enumera les entrades de `nextcloud.log` amb filtres i les resumeix (totals, signatures freqüents, URL, agents d'usuari i entrades per hora). Desactivat inicialment per a tothom; les IP s'emmascaren, la sortida es marca com a no fiable i cada lectura s'audita. Si hi ha etiquetes d'exclusió configurades, l'opció d'administració «Amaga el text dels missatges del registre» (activada inicialment) amaga el text dels missatges i les excepcions, l'agent d'usuari i els detalls de l'URL, i rebutja el filtre `contains`; si es desactiva, els registres es mostren sencers per al diagnòstic | — (només lectura) |

Consulta `mcp_guide` per obtenir l'esquema autoritatiu i les operacions disponibles.

| Mòdul | Eines | Dependència |
| --- | --- | --- |
| Registres | `logs_list`, `logs_analyze` | accés només de lectura a `nextcloud.log` mitjançant `ILogFactory`/`IFileBased::getEntries` del nucli; requereix un `log_type` basat en fitxer (el valor predeterminat); consulta [Registre del servidor](../../guides/server-logs.md#registre-del-servidor-102) |

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/logs.md) · [Español](../../../es/reference/tools/logs.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/logs.md) · [Português (Portugal)](../../../pt-PT/reference/tools/logs.md)
