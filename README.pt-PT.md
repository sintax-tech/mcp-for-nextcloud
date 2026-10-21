<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: README.md -->
<!-- translation-source-revision: 31b9e8ecfa245b7606e4ed67b32d750cdc4eed67 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](README.md).

<div align="center">

# MCP for Cloud

**Uma aplicação nativa do Nextcloud que transforma o seu Nextcloud num servidor MCP.**
Deixe Claude e outros clientes MCP trabalharem com ficheiros, notas, calendários, contactos, tarefas, quadros do Deck e conversas do Talk, sujeitos à política MCP, às restrições de recursos e às permissões nativas do Nextcloud de cada utilizador.

[![Tests](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![Nextcloud App Store](https://img.shields.io/badge/App%20Store-mcp-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/mcp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue)](#licença)

</div>

---

## Porquê

Um endpoint dentro do Nextcloud, com as permissões de cada utilizador. Não é necessário Node, Docker nem qualquer serviço adicional durante a execução.

## Novidades da versão 1.1.2

As restrições do Guests passam a aplicar-se aos pedidos com palavra-passe de aplicação ou OAuth e às ligações de checkout: aplicações permitidas, armazenamento externo oculto e partilha limitada aos grupos do convidado. As contas que não são convidadas não mudam; não é acrescentada nenhuma migração da base de dados. Consulte os [detalhes da versão](CHANGELOG.md#112).


## Novidades da versão 1.1.1

Os ecrãs de permissões tratam as respostas de gravação ilegíveis como resultados não confirmados e voltam a carregar as permissões guardadas sem repetir a escrita. Não é adicionada nenhuma migração da base de dados. Consulte os [detalhes da versão](CHANGELOG.md#111).

## Novidades da versão 1.1.0

Permissões de grupo, restrições de recursos por utilizador, inspeção de acesso, clientes OAuth registados e revogação reforçada. Consulte o [registo de alterações](CHANGELOG.md) e o [guia de permissões](docs/pt-PT/guides/permissions.md).
## Funcionalidades

[Funcionalidades](docs/pt-PT/reference/tools.md).

## Contactos e tarefas

[Contactos e tarefas](docs/pt-PT/reference/tools/contacts-tasks.md).

## Requisitos

[Requisitos](docs/pt-PT/installation.md).

## Instalação

Instale o **MCP for Cloud** a partir das Aplicações do Nextcloud, ative o serviço e a elegibilidade dos utilizadores na administração e, em seguida, ligue um cliente com a conta do próprio utilizador.

[Instalação](docs/pt-PT/installation.md).

## Ligar um cliente

[Ligar um cliente](docs/pt-PT/guides/clients.md).

### Claude (claude.ai, Claude Desktop, Cowork e Claude Code — recomendado)

[Claude (claude.ai, Claude Desktop, Cowork e Claude Code — recomendado)](docs/pt-PT/guides/clients.md).

### ChatGPT

[ChatGPT](docs/pt-PT/guides/clients.md).

### Gemini CLI

[Gemini CLI](docs/pt-PT/guides/clients.md).

### Administradores: clientes OAuth

[Administradores: clientes OAuth](docs/pt-PT/guides/clients.md).

### Outros clientes MCP (palavra-passe de aplicação)

[Outros clientes MCP (palavra-passe de aplicação)](docs/pt-PT/guides/clients.md).

### Revogar o acesso

[Revogar o acesso](docs/pt-PT/guides/clients.md).

## Segurança

[Segurança](docs/pt-PT/guides/permissions.md).

### Excluído do acesso MCP

[Excluído do acesso MCP](docs/pt-PT/guides/permissions.md).

## Desenvolvimento

A documentação de desenvolvimento está disponível em [English](docs/en/development/setup.md).

## Registo de alterações

[Registo de alterações](CHANGELOG.md).

## Roteiro

- Revisão nativa da tradução espanhola

## Licença

AGPL-3.0-or-later, com a cláusula adicional de atribuição da secção 7(b) em [NOTICE](NOTICE). A licença completa encontra-se em [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve a atribuição à Sintax na página de administração, na documentação e nos avisos de direitos de autor ao redistribuir ou disponibilizar versões modificadas numa rede.

## Créditos

O pacote inclui Smalot PdfParser, Symfony Polyfill Mbstring e o autoloader do Composer. Consulte as versões, licenças, direitos de autor e dependências de desenvolvimento em [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contribuidores.** Agradecemos a @NightDragon1 (#5) pela verificação da visibilidade da raiz e a @matt-ek (#4) pelo relatório que esteve na origem da auditoria de etiquetas ocultas. Agradecemos também a @matt-ek pela tradução alemã da interface (#10) e a @Zegorax pelos clientes OAuth registados por administradores (#8, #7).

## Sobre a Sintax

Desenvolvido pela [Sintax](https://sintax.tech) · sintax.tech.

- Site: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contacto: contato@sintax.tech
- Outras aplicações Nextcloud da Sintax: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)

[English](README.md) · [Español](README.es.md) · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · Português (Portugal)
