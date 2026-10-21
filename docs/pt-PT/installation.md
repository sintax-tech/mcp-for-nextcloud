<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../en/installation.md).

# Instalação

## Requisitos

- Nextcloud **32 a 35**; cada versão foi validada pelos testes de contrato da API em relação à primeira compilação dessa versão principal, com as versões correspondentes do Deck e do Talk. No Nextcloud 32, Deck 1.16+ e Talk 22+; até ao Nextcloud 35, Deck 1.19 e Talk 25.
- PHP **8.2+** com `zip`, `mbstring` e `dom`.
- Aplicações opcionais, necessárias apenas para as respetivas ferramentas (que ficam ocultas enquanto a aplicação estiver desativada): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versions (`files_versions`, necessária para editar) e Deleted files (`files_trashbin`, necessária para eliminar).
- Deck e Talk são opcionais: as respetivas ferramentas só aparecem quando a aplicação está ativa numa versão suportada. Uma versão anterior é considerada desativada; as ferramentas ficam ocultas e a página de administração indica qual versão permite voltar a usá-las.

A instalação normal é feita através da Nextcloud App Store: *Aplicações* → procure **MCP for Cloud** → **Transferir e ativar** (ou execute `occ app:install mcp`). O pacote da loja está assinado e é atualizado pelo atualizador de aplicações. A instalação por SSH indicada abaixo é uma alternativa para desenvolvimento ou para testar uma compilação local.

### Instalar através de SSH

Substitua `<server>`, `<nextcloud>` (raiz da instalação), `<apps>` (diretório de aplicações com permissões de escrita, por exemplo `custom_apps` ou `apps`, conforme `apps_paths` em `config/config.php`) e `<www>` (utilizador do servidor Web, por exemplo `www-data`).

```sh
scp build/mcp-1.1.2.tar.gz <server>:/tmp/
ssh <server>
sudo tar -xzf /tmp/mcp-1.1.2.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

No Docker, execute os comandos `occ` dentro do contentor como utilizador do servidor Web. Se o PHP utilizar OPcache com `validate_timestamps=0`, reinicie o PHP-FPM depois de copiar os ficheiros.

Para atualizar uma compilação instalada por SSH: execute `occ app:disable mcp`, remova `<apps>/mcp`, extraia o novo pacote, execute `occ app:enable mcp` e, se o Nextcloud o pedir, `occ upgrade`. A pasta tem de ser substituída por inteiro para não deixar ficheiros antigos em `vendor/`. Para remover a aplicação, execute `occ app:remove mcp`.

As migrações de 1.1.0 preservam os IDs originais e importam as configurações antigas de clientes nativos/personalizados para linhas autoritativas. Preferências e credenciais válidas de 1.0.8 sobrevivem à atualização. Os caminhos de validação incluem upstream 1.0.8 original, o 1.0.8 local migrado, a atualização de upstream 1.1.0 e 1.1.1 e a substituição de uma instalação upstream 1.1.2 já migrada. A substituição da mesma versão não força uma atualização nem repete migrações para repor a política.

## Atualização e recuperação

Antes de atualizar, prepare uma cópia de segurança consistente da aplicação, base de dados e configuração/dados necessários do Nextcloud, seguindo o procedimento do operador. O modo de manutenção impede tráfego durante a substituição sem alterar a política de serviço MCP nem revogar credenciais apenas para uma atualização de rotina. Substitua todo o diretório da aplicação, execute a atualização nativa necessária, limpe/reinicie caches PHP quando necessário e verifique histórico de migrações, recursos da interface, permissões e ligações existentes antes de reabrir o tráfego.

Prefira corrigir avançando após uma atualização que falhou. Reverter apenas os ficheiros da aplicação para 1.0.8 é inseguro: esse código não aplica as novas revogações por época/geração nem as restrições de recursos. Uma cópia de segurança da base de dados também pode conter credenciais revogadas depois de sua criação.

Para recuperação coordenada, mantenha Nextcloud em manutenção enquanto restaura estados correspondentes da aplicação e base de dados e a configuração/dados necessários. Antes de reabrir MCP, invalide códigos/tokens OAuth restaurados pelos comandos de política suportados, desligue explicitamente contas que devam voltar a ligar-se e revise permissões efetivas e restrições de recursos. Revise/revogue separadamente as palavras-passe de aplicação do Nextcloud em Segurança. Reativar o serviço não substitui essa revisão. Uma versão antiga que não aplique as restrições necessárias não pode ser reaberta para esses utilizadores.

A suite nativa descartável ensaia a restauração de aplicação/SQLite da mesma versão, a recusa de credenciais antigas e a nova ligação explícita. Não certifica as ferramentas de cópia de segurança do operador, uma reversão apenas de ficheiros nem todas as configurações de armazenamento de produção.

[Guias de operação](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · Português (Portugal)
