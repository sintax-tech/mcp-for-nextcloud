<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/calendar.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/calendar.md).

# Calendário

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Calendário** | listar calendários e eventos (recorrência, fusos, dia inteiro) | criar, editar, mover, excluir e transferir eventos pelo pipeline CalDAV de verdade, com participantes. As escritas ficam desligadas até o administrador liberá-las na matriz de permissões (sem passo no terminal), e cada uma devolve antes um plano: **nada é gravado antes de o utilizador aprovar.** |

| Tool | Permissão | Notas |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendários visíveis ao utilizador, próprios e partilhados. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Eventos no intervalo (predefinição: próximos 7 dias), com recorrência expandida. |
| `calendar_create_event` | calendar.create | Evento simples; `attendees` são UIDs internos resolvidos para o e-mail da conta. |
| `calendar_update_event` | calendar.edit | Atualiza texto/datas e substitui participantes; `attendees: []` remove todos. Mudança de participantes em série ou por outro organizador é recusada. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como na aplicação Calendário. |
| `calendar_delete_event` | calendar.delete | Exclui a série no lixo; exige retenção ativa. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro proprietário partilhada com permissão de escrita; recusa participantes. |

Calendário expõe leitura e as cinco escritas, sem nenhum passo no terminal. As escritas nascem desligadas na matriz da administração (Administração → MCP) e só aparecem para quem recebeu o grant; toda escrita exige ainda `confirm: true`, a ACL do Nextcloud (e `confirm_shared` em agenda alheia) e o ETag opcional. Compatibilidade: a aplicação declara `min-version` 32 e `max-version` 35 em `info.xml`; a parte interna (`EmbeddedCalDavServer` do core) é coberta pelos testes e por essa faixa, e uma versão nova do Nextcloud exige uma nova versão do app. O app Calendário precisa estar ativado para o utilizador. Sabre e Symfony Console vêm do core do Nextcloud e ficam fora do `vendor/` do pacote.

| Tool de escrita | Grant | Comportamento |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Evento simples; `attendees` são UIDs internos resolvidos para o e-mail da conta. |
| `calendar_update_event` | calendar.edit | Atualiza texto/datas e substitui participantes; `attendees: []` remove todos. Mudança de participantes em série ou por outro organizador é recusada. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como na aplicação Calendário. |
| `calendar_delete_event` | calendar.delete | Exclui a série no lixo; exige retenção ativa. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro proprietário partilhada com permissão de escrita; recusa participantes. |

Create, update e delete usam `send_invitations: false` por predefinição (`x-nc-scheduling: false`). `true` entrega o convite ao agendamento CalDAV do Nextcloud, inclusive para convidados existentes e CANCEL. O plano e o resultado informam `imipEnabled`, lido da configuração nativa `dav/sendInvitations`: com ela desligada o servidor não envia e-mail (o iTIP interno ainda chega à caixa do convidado) e o assistente deve dizer isso ao utilizador. O app nunca confirma recebimento de e-mail. Escritas em agenda de outra pessoa ainda exigem `confirm_shared: true` após confirmação do utilizador.

**Confirmação antes de toda escrita (sem estado no servidor).** As cinco escritas, chamadas sem `confirm: true`, só devolvem um plano (`requiresConfirmation: true`): valores atuais e propostos, participantes adicionados/removidos, destino, consequência para convites/CANCEL/lixo e o `etag` atual. Nada é escrito nem agendado. O assistente deve mostrar o plano, perguntar ao utilizador e só após um sim explícito repetir a chamada com `confirm: true` e os mesmos argumentos, reenviando o `etag` do plano (opcional; se vier e divergir, a escrita é recusada e um novo plano é necessário). Não há tabela, token nem registo de aprovação: o servidor não prova o sim humano, mas permissões, ACL, grants e `confirm_shared` seguem valendo. O selftest usa os handlers diretamente e não passa por esse fluxo.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/calendar.md) · [Español](../../../es/reference/tools/calendar.md) · [Català](../../../ca/reference/tools/calendar.md) · [Português (Brasil)](../../../pt-BR/reference/tools/calendar.md) · Português (Portugal)
