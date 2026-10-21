<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<div align="center">

# MCP for Cloud

**A native Nextcloud app that turns your Nextcloud into an MCP server.**
Let Claude and other MCP clients work with your files, notes, calendars, contacts, tasks, Deck boards and Talk conversations, subject to MCP policy, resource restrictions and each user's native Nextcloud permissions.

[![Tests](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![Nextcloud App Store](https://img.shields.io/badge/App%20Store-mcp-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/mcp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue)](#license)


</div>

---

## Why

One endpoint inside Nextcloud, with each user’s permissions. No Node, Docker or extra service is required at runtime.

## What's new in 1.1.2

Guests restrictions now apply to app-password and OAuth requests and to checkout links: allowed applications, hidden external storage and group-only sharing. Accounts that are not guests are unaffected; no database migration is added. See [release details](CHANGELOG.md#112).


## What's new in 1.1.1

Permission screens treat unreadable save replies as unconfirmed and reload stored permissions without repeating the write. No database migration is added. See [release details](CHANGELOG.md#111).

## What's new in 1.1.0

Group grants, per-user resource restrictions, access inspection, registered OAuth clients and stronger revocation. See the [changelog](CHANGELOG.md) and [permissions guide](docs/en/guides/permissions.md).

## Features

[Features](docs/en/reference/tools.md).

## Contacts and tasks

[Contacts and tasks](docs/en/reference/tools/contacts-tasks.md).

## Requirements

[Requirements](docs/en/installation.md).

## Installation

Install **MCP for Cloud** from Nextcloud Apps, enable the service and user eligibility in administration, then connect a client with the user’s own account.

[Installation](docs/en/installation.md).

## Connecting a client

[Connecting a client](docs/en/guides/clients.md).

### Claude (claude.ai, Claude Desktop, Cowork and Claude Code — recommended)

[Claude (claude.ai, Claude Desktop, Cowork and Claude Code — recommended)](docs/en/guides/clients.md).

### ChatGPT

[ChatGPT](docs/en/guides/clients.md).

### Gemini CLI

[Gemini CLI](docs/en/guides/clients.md).

### Administrators: OAuth clients

[Administrators: OAuth clients](docs/en/guides/clients.md).

### Other MCP clients (app password)

[Other MCP clients (app password)](docs/en/guides/clients.md).

### Revoking access

[Revoking access](docs/en/guides/clients.md).

## Security

[Security](docs/en/guides/permissions.md).

### Excluded from MCP access

[Excluded from MCP access](docs/en/guides/permissions.md).

## Development

[Development](docs/en/development/setup.md).

## Changelog

[Changelog](CHANGELOG.md).

## Roadmap

- Native review of the Spanish translation

## License

AGPL-3.0-or-later, with the additional section 7(b) attribution term in [NOTICE](NOTICE). The complete license is in [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve the Sintax attribution in the administration page, documentation and copyright notices when redistributing or making modified versions available over a network.

## Credits

The package includes Smalot PdfParser, Symfony Polyfill Mbstring and the Composer autoloader. See versions, licenses, copyright and development dependencies in [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contributors.** Thanks to @NightDragon1 (#5) for the root visibility check and to @matt-ek (#4) for the report behind the hidden-tags audit. Thanks also to @matt-ek for the German UI translation (#10) and @Zegorax for administrator-registered OAuth clients (#8, #7).

## About Sintax

Developed by [Sintax](https://sintax.tech) · sintax.tech.

- Website: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contact: contato@sintax.tech
- Other Nextcloud apps by Sintax: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)

English · [Español](README.es.md) · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)
