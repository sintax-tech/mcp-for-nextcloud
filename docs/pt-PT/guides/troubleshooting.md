<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/troubleshooting.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/guides/troubleshooting.md).

# Resolução de problemas

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
| Sem credenciais, credencial inválida ou utilizador desativado | 401 com `WWW-Authenticate: Basic` |
| Serviço desativado, utilizador não elegível ou desligado | 403 |
| `Origin` de outro anfitrião | 403 |
| Falta `Accept: application/json, text/event-stream` ou `Content-Type: application/json` | 406 |
| `MCP-Protocol-Version` desconhecido ou malformado: devolve `UnsupportedProtocolVersionError` (`-32022`, `data.supported`). As versões antigas aceites após `initialize` são 2025-03-26, 2025-06-18 e 2025-11-25; se o cabeçalho estiver ausente, assume-se 2025-03-26 e, em `initialize`, o cabeçalho é ignorado. Também devolve 400 quando `MCP-Protocol-Version`, `Mcp-Method` ou `Mcp-Name` diferem do corpo (`-32020`). O motivo de cada 400 é escrito no registo ao nível de depuração. | 400 |
| JSON inválido (`-32700`), lote ou envelope inválido (`-32600`) | 400 |
| Método desconhecido (`-32601`) ou parâmetros inválidos (`-32602`) | 200 com um erro JSON-RPC |
| GET ou DELETE | 405 |

## Diagnóstico opcional do Calendário (`occ mcp:calendar-selftest`)

Este diagnóstico não é um pré-requisito: nenhuma ferramenta depende dele para aparecer ou funcionar, e executá-lo não ativa nem oculta ferramentas. O diagnóstico cria calendários e eventos temporários e elimina-os no final, conforme descrito abaixo. É uma ferramenta de diagnóstico avançada e opcional para os administradores que queiram observar, passo a passo, a resposta real do CalDAV. Execute-a na raiz do Nextcloud como utilizador do servidor Web. O UID do organizador tem de corresponder a uma conta ativa, com um endereço de e-mail válido e o Calendário ativo. A retenção `dav/calendarRetentionObligation` não pode ser `0` (a limpeza seria permanente, pelo que o diagnóstico falha antes de criar qualquer objeto).

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID
```

Cada execução cria dois calendários com URIs `mcp-selftest-...-a`/`-b`, só altera eventos que ela própria criou e move esses calendários para o lixo no final. Sem opções, as três verificações opcionais apresentam `NOT TESTED`. Para testar convites internos, transferência e ACL:

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID \
  --attendee-uid ATTENDEE_UID \
  --shared-calendar '/remote.php/dav/calendars/ORGANIZER_UID/SHARED_URI/' \
  --acl-probe-user NO_ACCESS_UID
```

Esta execução afeta ambos os utilizadores (convite interno, CANCEL de limpeza e Activity no calendário partilhado) e remove apenas itens com o UID gerado pelo teste. Não comprova o funcionamento de SMTP.

| Etapa do relatório | Resultado esperado |
| --- | --- |
| `preflight`, `calendars` | Conta, aplicação e retenção válidas; dois MKCALENDAR HTTP 201 visíveis. |
| `create` | HTTP 201, UID e ETag lidos novamente, `sync-token` incrementado. |
| `suppression-create`, `suppression-update` | PUT 201/204, ATTENDEE `@example.invalid` sem SCHEDULE-STATUS; SEQUENCE incrementado. |
| `stale-tool`, `stale-dav` | Conflito no handler e HTTP 412 no DAV; dados e ETag intactos. |
| `move`, `overwrite` | MOVE 201, com o UID apenas no destino; Overwrite F devolve 412 e preserva ambos os objetos. |
| `delete` | HTTP 204, UID removido da leitura e colocado no lixo. |
| `invitations`, `transfer`, `acl` | `OK` se solicitados e comprovados; `NOT TESTED` se as opções não forem indicadas. |
| `cleanup`, `result` | Limpeza confirmada com HTTP 204, sessão reposta e resultado final do diagnóstico. |

Qualquer `FAIL` devolve um código diferente de zero. Se a limpeza falhar, `cleanup-resource` indica o caminho/UID a verificar no servidor. O resultado não altera as ferramentas disponíveis.

[Guias de operação](README.md).

[English](../../en/guides/troubleshooting.md) · [Español](../../es/guides/troubleshooting.md) · [Català](../../ca/guides/troubleshooting.md) · [Português (Brasil)](../../pt-BR/guides/troubleshooting.md) · Português (Portugal)
