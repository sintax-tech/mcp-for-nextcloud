<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/guides/troubleshooting.md -->
<!-- translation-source-revision: c0f730d97a84cf61317486c67722af52cf30325e -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../en/guides/troubleshooting.md).

# Diagnóstico

## Teste manual após a instalação

```sh
URL=https://<instance>/apps/mcp/
AUTH='<user>:<app-password>'
H='-H Content-Type:application/json -H Accept:application/json,text/event-stream'

curl -s -u "$AUTH" $H -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' "$URL"
curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$URL"   # 202
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' "$URL"
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp_status","arguments":{}}}' "$URL"
```

Respostas esperadas:

| Situação | HTTP |
| --- | --- |
| Sem credencial, credencial inválida ou usuário desativado | 401 com `WWW-Authenticate: Basic` |
| Serviço desligado, usuário inelegível ou desconectado | 403 |
| `Origin` de outro host | 403 |
| Faltando `Accept: application/json, text/event-stream` ou `Content-Type: application/json` | 406 |
| `MCP-Protocol-Version` desconhecida ou malformada: responde `UnsupportedProtocolVersionError` (`-32022`, `data.supported`). As versões legadas aceitas depois do `initialize` são 2025-03-26, 2025-06-18 e 2025-11-25; header ausente vale 2025-03-26, e no `initialize` o header é ignorado. Também dá 400 quando `MCP-Protocol-Version`, `Mcp-Method` ou `Mcp-Name` divergem do corpo (`-32020`). O motivo de cada 400 vai para o log em nível debug. | 400 |
| JSON inválido (`-32700`), lote ou envelope inválido (`-32600`) | 400 |
| Método desconhecido (`-32601`) ou parâmetros inválidos (`-32602`) | 200 com erro JSON-RPC |
| GET ou DELETE | 405 |

## Diagnóstico opcional do Calendar (`occ mcp:calendar-selftest`)


Não é pré-requisito: nada depende dele para as tools aparecerem ou funcionarem, e ele não grava nem esconde nada. É uma ferramenta de diagnóstico opcional e avançada para quem administra o servidor e quer ver, passo a passo, o CalDAV real respondendo. Rode na raiz do Nextcloud como o usuário do servidor web. O UID organizador precisa ter conta habilitada, e-mail válido e Calendar habilitado; a retenção `dav/calendarRetentionObligation` não pode ser `0` (a limpeza seria permanente, então ele falha antes de criar qualquer objeto).

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID
```

Cada execução cria dois calendários com URIs `mcp-selftest-...-a`/`-b`, toca somente eventos criados nela e move esses calendários para a lixeira ao terminar. Sem opções, as três provas opcionais mostram `NOT TESTED`. Para exercitar convites internos, transferência e ACL:

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID \
  --attendee-uid ATTENDEE_UID \
  --shared-calendar '/remote.php/dav/calendars/ORGANIZER_UID/SHARED_URI/' \
  --acl-probe-user NO_ACCESS_UID
```

Essa execução gera efeitos nos dois usuários (convite interno, CANCEL de limpeza, Activity na agenda compartilhada) e remove somente itens com o UID gerado pelo teste. Não é prova de SMTP.

| Etapa do relatório | Resultado esperado |
| --- | --- |
| `preflight`, `calendars` | Conta/app/retenção válidos; dois MKCALENDAR HTTP 201 visíveis. |
| `create` | HTTP 201, UID e ETag relidos, sync-token aumentou. |
| `suppression-create`, `suppression-update` | PUT 201/204, ATTENDEE `@example.invalid` sem SCHEDULE-STATUS; SEQUENCE subiu. |
| `stale-tool`, `stale-dav` | Conflito no handler e HTTP 412 no DAV; dados e ETag intactos. |
| `move`, `overwrite` | MOVE 201 com UID só no destino; Overwrite F retorna 412 e preserva os dois objetos. |
| `delete` | HTTP 204, UID fora da leitura e na lixeira. |
| `invitations`, `transfer`, `acl` | `OK` se solicitadas e comprovadas; `NOT TESTED` sem as opções. |
| `cleanup`, `result` | Limpeza HTTP 204 confirmada, sessão restaurada e resultado final do diagnóstico. |

Qualquer `FAIL` retorna código não zero. Se a limpeza falhar, `cleanup-resource` indica o path/UID a conferir no servidor. O resultado não altera quais tools existem.


[Guias de operação](README.md).

[English](../../en/guides/troubleshooting.md) · [Español](../../es/guides/troubleshooting.md) · [Català](../../ca/guides/troubleshooting.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/guides/troubleshooting.md)
