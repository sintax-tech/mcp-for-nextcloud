<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../en/installation.md).

# Instalação

## Requisitos

- Nextcloud **32 a 35**, cada versão conferida pelos testes de contrato de API contra o primeiro build da major (com o Deck e o Talk correspondentes); Deck 1.16+ e Talk 22+ no 32, até Deck 1.19 e Talk 25 no 35
- PHP **8.2+** com `zip`, `mbstring` e `dom`
- Apps opcionais, só para as próprias ferramentas (ocultas enquanto o app estiver desativado): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versões (`files_versions`, obrigatório para editar), Arquivos excluídos (`files_trashbin`, obrigatório para excluir)
- Deck e Talk são opcionais: as ferramentas deles só aparecem com o app ativo numa versão suportada; uma versão anterior conta como desativada, as ferramentas dela ficam ocultas e a página de administração diz qual versão as traz de volta

A instalação normal é pela App Store do Nextcloud: *Apps* → buscar **MCP for Cloud** → **Baixar e ativar** (ou `occ app:install mcp`). O pacote da loja é assinado e atualiza pelo atualizador de apps. A instalação por SSH abaixo é a alternativa, para desenvolvimento ou testes de um build local.

### Instalar via SSH

Substitua `<server>`, `<nextcloud>` (raiz da instalação), `<apps>` (diretório de apps gravável, por exemplo `custom_apps` ou `apps`, conforme `apps_paths` em `config/config.php`) e `<www>` (usuário do servidor web, por exemplo `www-data`).

```sh
scp build/mcp-1.1.2.tar.gz <server>:/tmp/
ssh <server>
sudo tar -xzf /tmp/mcp-1.1.2.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

Em Docker, rode os comandos `occ` dentro do container como o usuário do servidor web. Se o PHP usa OPcache com `validate_timestamps=0`, reinicie o PHP-FPM após copiar os arquivos.

Para atualizar um build instalado por SSH: `occ app:disable mcp`, remover `<apps>/mcp`, extrair o novo pacote, `occ app:enable mcp` e `occ upgrade` se o Nextcloud pedir. A pasta precisa ser trocada inteira, para não sobrarem arquivos antigos em `vendor/`. Para remover: `occ app:remove mcp`.

As migrações de 1.1.0 preservam os IDs originais e importam as configurações antigas de clientes nativos/personalizados para linhas autoritativas. Preferências e credenciais válidas de 1.0.8 sobrevivem à atualização. Os caminhos de validação incluem upstream 1.0.8 original, o 1.0.8 local migrado, a atualização de upstream 1.1.0 e 1.1.1 e a substituição de uma instalação upstream 1.1.2 já migrada. A substituição da mesma versão não força uma atualização nem repete migrações para redefinir a política.

## Atualização e recuperação

Antes de atualizar, prepare um backup consistente do aplicativo, banco de dados e configuração/dados necessários do Nextcloud, seguindo o procedimento do operador. O modo de manutenção impede tráfego durante a substituição sem alterar a política de serviço MCP nem revogar credenciais apenas para uma atualização rotineira. Substitua todo o diretório do aplicativo, execute a atualização nativa necessária, limpe/reinicie caches PHP quando necessário e verifique histórico de migrações, recursos da interface, permissões e conexões existentes antes de reabrir o tráfego.

Prefira corrigir avançando após uma atualização que falhou. Reverter apenas os arquivos do aplicativo para 1.0.8 é inseguro: esse código não aplica as novas revogações por época/geração nem as restrições de recursos. Um backup do banco de dados também pode conter credenciais revogadas depois de sua criação.

Para recuperação coordenada, mantenha Nextcloud em manutenção enquanto restaura estados correspondentes do aplicativo e banco de dados e a configuração/dados necessários. Antes de reabrir MCP, invalide códigos/tokens OAuth restaurados pelos comandos de política suportados, desconecte explicitamente contas que devam se reconectar e revise permissões efetivas e restrições de recursos. Revise/revogue separadamente as senhas de aplicativo do Nextcloud em Segurança. Reabilitar o serviço não substitui essa revisão. Uma versão antiga que não aplique as restrições necessárias não pode ser reaberta para esses usuários.

A suíte nativa descartável ensaia a restauração de aplicativo/SQLite da mesma versão, a recusa de credenciais antigas e a reconexão explícita. Não certifica as ferramentas de backup do operador, uma reversão apenas de arquivos nem todas as configurações de armazenamento de produção.

[Guias de operação](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · [Català](../ca/installation.md) · Português (Brasil) · [Português (Portugal)](../pt-PT/installation.md)
