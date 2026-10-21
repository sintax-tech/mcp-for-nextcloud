<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: README.md -->
<!-- translation-source-revision: 31b9e8ecfa245b7606e4ed67b32d750cdc4eed67 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](README.md).

<div align="center">

# MCP for Cloud

**Una aplicació nativa de Nextcloud que converteix el teu Nextcloud en un servidor MCP.**
Permet que Claude i altres clients MCP treballin amb fitxers, notes, calendaris, contactes, tasques, taulers de Deck i converses de Talk, subjectes a la política MCP, les restriccions de recursos i els permisos natius de Nextcloud de cada usuari.

[![Tests](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![Nextcloud App Store](https://img.shields.io/badge/App%20Store-mcp-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/mcp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue)](#llicència)

</div>

---

## Per què

Un únic punt d'accés dins de Nextcloud, amb els permisos de cada usuari. No cal Node, Docker ni cap servei addicional en temps d'execució.

## Novetats de la versió 1.1.2

Les restriccions de Guests s'apliquen ara a les peticions amb contrasenya d'aplicació o OAuth i als enllaços de checkout: aplicacions permeses, emmagatzematge extern ocult i compartició limitada als grups del convidat. Els comptes que no són convidats no canvien; no s'afegeix cap migració de base de dades. Consulta els [detalls de la versió](CHANGELOG.md#112).


## Novetats de la versió 1.1.1

Les pantalles de permisos tracten les respostes de desament il·legibles com a resultats sense confirmar i tornen a carregar els permisos emmagatzemats sense repetir l’escriptura. No s’afegeix cap migració de base de dades. Consulta els [detalls de la versió](CHANGELOG.md#111).

## Novetats de la versió 1.1.0

Permisos de grup, restriccions de recursos per usuari, inspecció d’accés, clients OAuth registrats i revocació reforçada. Consulta el [registre de canvis](CHANGELOG.md) i la [guia de permisos](docs/ca/guides/permissions.md).
## Funcions

[Funcions](docs/ca/reference/tools.md).

## Contactes i tasques

[Contactes i tasques](docs/ca/reference/tools/contacts-tasks.md).

## Requisits

[Requisits](docs/ca/installation.md).

## Instal·lació

Instal·la **MCP for Cloud** des de les aplicacions de Nextcloud, activa el servei i l'elegibilitat dels usuaris a l'administració i, després, connecta un client amb el compte del mateix usuari.

[Instal·lació](docs/ca/installation.md).

## Connectar un client

[Connectar un client](docs/ca/guides/clients.md).

### Claude (claude.ai, Claude Desktop, Cowork i Claude Code — recomanat)

[Claude (claude.ai, Claude Desktop, Cowork i Claude Code — recomanat)](docs/ca/guides/clients.md).

### ChatGPT

[ChatGPT](docs/ca/guides/clients.md).

### Gemini CLI

[Gemini CLI](docs/ca/guides/clients.md).

### Administradors: clients OAuth

[Administradors: clients OAuth](docs/ca/guides/clients.md).

### Altres clients MCP (contrasenya d'aplicació)

[Altres clients MCP (contrasenya d'aplicació)](docs/ca/guides/clients.md).

### Revocar l'accés

[Revocar l'accés](docs/ca/guides/clients.md).

## Seguretat

[Seguretat](docs/ca/guides/permissions.md).

### Exclosos de l'accés MCP

[Exclosos de l'accés MCP](docs/ca/guides/permissions.md).

## Desenvolupament

[Desenvolupament (en anglès)](docs/en/development/setup.md).

## Registre de canvis

[Registre de canvis](CHANGELOG.md).

## Full de ruta

- Revisió nativa de la traducció al castellà

## Llicència

AGPL-3.0-or-later, amb el terme addicional d'atribució de la secció 7(b) a [NOTICE](NOTICE). La llicència completa és a [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). En redistribuir o oferir versions modificades a través d'una xarxa, conserva l'atribució de Sintax a la pàgina d'administració, a la documentació i als avisos de drets d'autor.

## Crèdits

El paquet inclou Smalot PdfParser, Symfony Polyfill Mbstring i l'autoloader de Composer. Consulta les versions, llicències, drets d'autor i dependències de desenvolupament a [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Col·laboradors.** Gràcies a @NightDragon1 (#5) per la comprovació de visibilitat de l'arrel i a @matt-ek (#4) per l'informe que va donar lloc a l'auditoria d'etiquetes ocultes. Gràcies també a @matt-ek per la traducció alemanya de la interfície (#10) i a @Zegorax pels clients OAuth registrats per administradors (#8, #7).

## Sobre Sintax

Desenvolupat per [Sintax](https://sintax.tech) · sintax.tech.

- Lloc web: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contacte: contato@sintax.tech
- Altres aplicacions de Nextcloud de Sintax: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)

[English](README.md) · [Español](README.es.md) · Català · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)
