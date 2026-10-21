<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: README.md -->
<!-- translation-source-revision: 31b9e8ecfa245b7606e4ed67b32d750cdc4eed67 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](README.md).

<div align="center">

# MCP for Cloud

**Una aplicación nativa de Nextcloud que convierte tu Nextcloud en un servidor MCP.**
Permite que Claude y otros clientes MCP trabajen con archivos, notas, calendarios, contactos, tareas, tableros de Deck y conversaciones de Talk, sujetos a la política MCP, las restricciones de recursos y los permisos nativos de Nextcloud de cada usuario.

[![Tests](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![Nextcloud App Store](https://img.shields.io/badge/App%20Store-mcp-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/mcp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue)](#licencia)

</div>

---

## Motivo

Un endpoint dentro de Nextcloud que respeta los permisos de cada usuario. No se necesita Node, Docker ni ningún servicio adicional en tiempo de ejecución.

## Novedades de 1.1.2

Las restricciones de Guests se aplican ahora a las peticiones con contraseña de aplicación u OAuth y a los enlaces de checkout: aplicaciones permitidas, almacenamiento externo oculto y uso compartido limitado a los grupos del invitado. Las cuentas que no son invitadas no cambian; no se añade ninguna migración de base de datos. Consulta los [detalles de la versión](CHANGELOG.md#112).


## Novedades de 1.1.1

Las pantallas de permisos tratan las respuestas de guardado ilegibles como resultados sin confirmar y vuelven a cargar los permisos almacenados sin repetir la escritura. No se añade ninguna migración de base de datos. Consulta los [detalles de la versión](CHANGELOG.md#111).

## Novedades de 1.1.0

Permisos de grupo, restricciones de recursos por usuario, inspección de acceso, clientes OAuth registrados y revocación reforzada. Consulta el [registro de cambios](CHANGELOG.md) y la [guía de permisos](docs/es/guides/permissions.md).
## Funciones

[Funciones](docs/es/reference/tools.md).

## Contactos y tareas

[Contactos y tareas](docs/es/reference/tools/contacts-tasks.md).

## Requisitos

[Requisitos](docs/es/installation.md).

## Instalación

Instala **MCP for Cloud** desde Nextcloud Apps, habilita el servicio y el acceso de los usuarios en administración y, después, conecta un cliente con la cuenta propia del usuario.

[Instalación](docs/es/installation.md).

## Conectar un cliente

[Conectar un cliente](docs/es/guides/clients.md).

### Claude (claude.ai, Claude Desktop, Cowork y Claude Code — recomendado)

[Claude (claude.ai, Claude Desktop, Cowork y Claude Code — recomendado)](docs/es/guides/clients.md).

### ChatGPT

[ChatGPT](docs/es/guides/clients.md).

### Gemini CLI

[Gemini CLI](docs/es/guides/clients.md).

### Administradores: clientes OAuth

[Administradores: clientes OAuth](docs/es/guides/clients.md).

### Otros clientes MCP (contraseña de aplicación)

[Otros clientes MCP (contraseña de aplicación)](docs/es/guides/clients.md).

### Revocar el acceso

[Revocar el acceso](docs/es/guides/clients.md).

## Seguridad

[Seguridad](docs/es/guides/permissions.md).

### Excluidos del acceso a MCP

[Excluidos del acceso a MCP](docs/es/guides/permissions.md).

## Desarrollo

[Desarrollo (en inglés)](docs/en/development/setup.md).

## Registro de cambios

[Registro de cambios](CHANGELOG.md).

## Hoja de ruta

- Revisión nativa de la traducción al español

## Licencia

AGPL-3.0-or-later, con la condición adicional de atribución de la sección 7(b) que aparece en [NOTICE](NOTICE). La licencia completa está en [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Al redistribuir o publicar en una red versiones modificadas, conserva la atribución de Sintax en la página de administración, la documentación y los avisos de copyright.

## Créditos

El paquete incluye Smalot PdfParser, Symfony Polyfill Mbstring y el cargador automático de Composer. Consulta las versiones, licencias, derechos de autor y dependencias de desarrollo en [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Colaboradores.** Gracias a @NightDragon1 (#5) por la comprobación de visibilidad de la raíz y a @matt-ek (#4) por el informe que motivó la auditoría de etiquetas ocultas. Gracias también a @matt-ek por la traducción alemana de la interfaz (#10) y a @Zegorax por los clientes OAuth registrados por administradores (#8, #7).

## Acerca de Sintax

Desarrollado por [Sintax](https://sintax.tech) · sintax.tech.

- Sitio web: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contacto: contato@sintax.tech
- Otras aplicaciones de Nextcloud de Sintax: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)

[English](README.md) · Español · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)
