<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: README.md -->
<!-- translation-source-revision: 31b9e8ecfa245b7606e4ed67b32d750cdc4eed67 -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](README.md).

<div align="center">

# MCP for Cloud

**App nativo do Nextcloud que transforma o seu Nextcloud em um servidor MCP.**
Deixe Claude e outros clientes MCP trabalharem com arquivos, notas, calendários, contatos, tarefas, quadros do Deck e conversas do Talk, sujeitos à política MCP, às restrições de recursos e às permissões nativas do Nextcloud de cada usuário.

[![Tests](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![App Store do Nextcloud](https://img.shields.io/badge/App%20Store-mcp-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/mcp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![Licença: AGPL-3.0](https://img.shields.io/badge/Licen%C3%A7a-AGPL--3.0-blue)](#licença)

[English](README.md) · Português (Brasil)

</div>

---

## Novidades da versão 1.1.2

As restrições do Guests passam a valer nas requisições com senha de app ou OAuth e nos links de checkout: apps permitidos, armazenamento externo oculto e compartilhamento limitado aos grupos do convidado. Contas que não são convidadas não mudam; nenhuma migração de banco de dados é adicionada. Veja os [detalhes da versão](CHANGELOG.md#112).


## Correção 1.1.1

- **1.1.2: correção de segurança para convidados do app Guests.** As restrições do Guests (apps liberados aos convidados, armazenamento externo oculto, compartilhamento só com os próprios grupos) passam a valer em todo pedido MCP, por senha de app ou OAuth, e nos links de checkout; as ferramentas de apps que o convidado não pode usar ficam de fora. Nada muda para contas que não são convidadas. Sem mudança no banco de dados.
- **1.1.1: correção nas telas de grupos, de recursos e da matriz de permissões ([#11](https://github.com/sintax-tech/mcp-for-nextcloud/issues/11)).** Quando a resposta do servidor a um salvamento não pode ser lida (por exemplo, um proxy ou uma sessão expirada devolvendo uma página em vez de dados), *Permissões por grupo*, *Restrição por recurso* e a matriz de permissões passam a avisar que o resultado não pôde ser confirmado e a mostrar o que está salvo, em vez de "Salvo" ou de uma tela desatualizada. Sem mudança no banco de dados.

## Por quê

Um endpoint dentro do Nextcloud, com as permissões de cada usuário. Node, Docker e serviços adicionais não são necessários em produção.

## Novidades da 1.1.0

Permissões de grupo, restrições de recursos por usuário, inspeção de acesso, clientes OAuth registrados e revogação reforçada. Consulte o [histórico de versões](CHANGELOG.md) e o [guia de permissões](docs/pt-BR/guides/permissions.md).
## Funcionalidades

[Funcionalidades](docs/pt-BR/reference/tools.md).

## Contatos e tarefas

[Contatos e tarefas](docs/pt-BR/reference/tools/contacts-tasks.md).

## Requisitos

[Requisitos](docs/pt-BR/installation.md).

## Instalação

Instale **MCP for Cloud** nos aplicativos do Nextcloud, habilite o serviço e os usuários na administração e conecte um cliente com a conta do próprio usuário.

[Instalação](docs/pt-BR/installation.md).

## Conectando um cliente

[Conectando um cliente](docs/pt-BR/guides/clients.md).

### Claude (claude.ai, Claude Desktop, Cowork e Claude Code — recomendado)

[Claude (claude.ai, Claude Desktop, Cowork e Claude Code — recomendado)](docs/pt-BR/guides/clients.md).

### ChatGPT

[ChatGPT](docs/pt-BR/guides/clients.md).

### Gemini CLI

[Gemini CLI](docs/pt-BR/guides/clients.md).

### Administradores: Clientes OAuth

[Administradores: Clientes OAuth](docs/pt-BR/guides/clients.md).

### Outros clientes MCP (senha de app)

[Outros clientes MCP (senha de app)](docs/pt-BR/guides/clients.md).

### Revogando acesso

[Revogando acesso](docs/pt-BR/guides/clients.md).

## Segurança

[Segurança](docs/pt-BR/guides/permissions.md).

### Excluídos do acesso MCP

[Excluídos do acesso MCP](docs/pt-BR/guides/permissions.md).

## Desenvolvimento

[Desenvolvimento (English)](docs/en/development/setup.md).

## Histórico de versões

[Histórico de versões (English)](CHANGELOG.md).

## Roadmap

- Revisão nativa da tradução para o espanhol

## Licença

AGPL-3.0-or-later, com o termo adicional de atribuição da seção 7(b) no [NOTICE](NOTICE). O texto integral da licença está em [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve o crédito à Sintax na administração, na documentação e nos avisos de autoria ao redistribuir ou oferecer versões modificadas pela rede.

## Créditos

O pacote inclui Smalot PdfParser, Symfony Polyfill Mbstring e o autoloader do Composer. Consulte versões, licenças, autoria e dependências de desenvolvimento em [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contribuidores.** Agradecimento a @NightDragon1 (#5) pela checagem de visibilidade da raiz e a @matt-ek (#4) pelo relato que motivou a auditoria da ocultação por tags. Agradecemos também a @matt-ek pela tradução alemã da interface (#10) e a @Zegorax pelos clientes OAuth registrados por administradores (#8, #7).

## Sobre a Sintax

Desenvolvido pela [Sintax](https://sintax.tech) · sintax.tech.

- Site: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contato: contato@sintax.tech
- Outros apps da Sintax para Nextcloud: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)

[English](README.md) · [Español](README.es.md) · [Català](README.ca.md) · Português (Brasil) · [Português (Portugal)](README.pt-PT.md)
