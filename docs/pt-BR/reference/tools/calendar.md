<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/calendar.md -->
<!-- translation-source-revision: c0f730d97a84cf61317486c67722af52cf30325e -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../../en/reference/tools/calendar.md).

# Calendário

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Calendário** | listar calendários e eventos (recorrência, fusos, dia inteiro) | criar, editar, mover, excluir e transferir eventos pelo pipeline CalDAV de verdade, com participantes. As escritas ficam desligadas até o administrador liberá-las na matriz de permissões (sem passo no terminal), e cada uma devolve antes um plano: **nada é gravado antes de o usuário aprovar.** |

| Ferramenta | Permissão | Observações |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendários visíveis ao usuário, próprios e compartilhados. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Eventos no intervalo (padrão: próximos 7 dias), com recorrência expandida. |
| `calendar_create_event` | calendar.create | Evento simples; `attendees` são UIDs internos resolvidos para o e-mail da conta. |
| `calendar_update_event` | calendar.edit | Atualiza texto/datas e substitui participantes; `attendees: []` remove todos. Mudança de participantes em série ou por outro organizador é recusada. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como no app Calendar. |
| `calendar_delete_event` | calendar.delete | Exclui a série na lixeira; exige retenção ativa. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro dono compartilhada com escrita; recusa participantes. |

Calendar expõe leitura e as cinco escritas, sem nenhum passo no terminal. As escritas nascem desligadas na matriz do admin (Administração → MCP) e só aparecem para quem recebeu o grant; toda escrita exige ainda `confirm: true`, a ACL do Nextcloud (e `confirm_shared` em agenda alheia) e o ETag opcional. Compatibilidade: o app declara `min-version` 32 e `max-version` 35 em `info.xml`; a parte interna (`EmbeddedCalDavServer` do core) é coberta pelos testes e por essa faixa, e uma versão nova do Nextcloud exige uma nova versão do app. O app Calendar precisa estar habilitado para o usuário. Sabre e Symfony Console vêm do core do Nextcloud e ficam fora do `vendor/` do pacote.

| Ferramenta de escrita | Permissão | Comportamento |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Evento simples; `attendees` são UIDs internos resolvidos para o e-mail da conta. |
| `calendar_update_event` | calendar.edit | Atualiza texto/datas e substitui participantes; `attendees: []` remove todos. Mudança de participantes em série ou por outro organizador é recusada. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como no app Calendar. |
| `calendar_delete_event` | calendar.delete | Exclui a série na lixeira; exige retenção ativa. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro dono compartilhada com escrita; recusa participantes. |

Create, update e delete usam `send_invitations: false` por padrão (`x-nc-scheduling: false`). `true` entrega o convite ao agendamento CalDAV do Nextcloud, inclusive para convidados existentes e CANCEL. O plano e o resultado informam `imipEnabled`, lido da configuração nativa `dav/sendInvitations`: com ela desligada o servidor não envia e-mail (o iTIP interno ainda chega à caixa do convidado) e o assistente deve dizer isso ao usuário. O app nunca confirma recebimento de e-mail. Escritas em agenda de outra pessoa ainda exigem `confirm_shared: true` após confirmação do usuário.

**Confirmação antes de toda escrita (sem estado no servidor).** As cinco escritas, chamadas sem `confirm: true`, só devolvem um plano (`requiresConfirmation: true`): valores atuais e propostos, participantes adicionados/removidos, destino, consequência para convites/CANCEL/lixeira e o `etag` atual. Nada é escrito nem agendado. O assistente deve mostrar o plano, perguntar ao usuário e só após um sim explícito repetir a chamada com `confirm: true` e os mesmos argumentos, reenviando o `etag` do plano (opcional; se vier e divergir, a escrita é recusada e um novo plano é necessário). Não há tabela, token nem registro de aprovação: o servidor não prova o sim humano, mas permissões, ACL, grants e `confirm_shared` seguem valendo. O selftest usa os handlers diretamente e não passa por esse fluxo.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/calendar.md) · [Español](../../../es/reference/tools/calendar.md) · [Català](../../../ca/reference/tools/calendar.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/calendar.md)
