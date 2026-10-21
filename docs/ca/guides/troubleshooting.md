<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/troubleshooting.md -->
<!-- translation-source-revision: c1836790061cd223b3d5c7bd51639ceb1c91c38f -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/guides/troubleshooting.md).

# Resolució de problemes

## Prova manual després de la instal·lació

```sh
URL=https://<instance>/apps/mcp/
AUTH='<user>:<app-password>'
H='-H Content-Type:application/json -H Accept:application/json,text/event-stream'

curl -s -u "$AUTH" $H -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' "$URL"
curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$URL"   # 202
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' "$URL"
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp_status","arguments":{}}}' "$URL"
```

Respostes esperades:

| Cas | HTTP |
| --- | --- |
| Sense credencial, credencial no vàlida o usuari desactivat | 401 amb `WWW-Authenticate: Basic` |
| Servei desactivat, usuari no elegible o desconnectat | 403 |
| `Origin` d'un altre domini | 403 |
| Falta `Accept: application/json, text/event-stream` o `Content-Type: application/json` | 406 |
| `MCP-Protocol-Version` desconeguda o malformada: retorna `UnsupportedProtocolVersionError` (`-32022`, `data.supported`). Les versions antigues acceptades després d'`initialize` són 2025-03-26, 2025-06-18 i 2025-11-25; si falta la capçalera s'utilitza 2025-03-26, i a `initialize` s'ignora. També retorna 400 si `MCP-Protocol-Version`, `Mcp-Method` o `Mcp-Name` no coincideixen amb el cos (`-32020`). El motiu de cada 400 s'anota al registre amb nivell debug. | 400 |
| JSON no vàlid (`-32700`), lot o embolcall no vàlid (`-32600`) | 400 |
| Mètode desconegut (`-32601`) o paràmetres no vàlids (`-32602`) | 200 amb un error JSON-RPC |
| GET o DELETE | 405 |

## Diagnòstic opcional de Calendar (`occ mcp:calendar-selftest`)

No és un requisit previ: res no en depèn perquè les eines apareguin o funcionin, i executar-lo no activa ni amaga cap eina. El diagnòstic escriu calendaris i esdeveniments temporals i els neteja tal com es descriu més avall. És una eina de diagnòstic avançada i opcional per a qui administra el servidor i vol veure, pas a pas, com respon el CalDAV real. Executa-la a l'arrel de Nextcloud com a usuari del servidor web. L'identificador UID de l'organitzador ha de correspondre a un compte activat, amb un correu electrònic vàlid i Calendar activat; la retenció `dav/calendarRetentionObligation` no pot ser `0` (la neteja seria permanent i l'ordre falla abans de crear cap element).

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID
```

Cada execució crea dos calendaris amb URI `mcp-selftest-...-a`/`-b`, només modifica esdeveniments que ella mateixa ha creat i, al final, mou els calendaris a la paperera. Sense opcions, les tres comprovacions opcionals mostren `NOT TESTED`. Per provar invitacions internes, transferències i ACL:

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID \
  --attendee-uid ATTENDEE_UID \
  --shared-calendar '/remote.php/dav/calendars/ORGANIZER_UID/SHARED_URI/' \
  --acl-probe-user NO_ACCESS_UID
```

Aquesta execució té efectes en tots dos usuaris (invitació interna, CANCEL durant la neteja i Activitat al calendari compartit) i només elimina elements amb l'UID generat per la prova. No demostra que SMTP funcioni.

| Pas de l'informe | Resultat esperat |
| --- | --- |
| `preflight`, `calendars` | Compte, aplicació i retenció vàlids; dos MKCALENDAR HTTP 201 visibles. |
| `create` | HTTP 201; UID i ETag tornats a llegir; sync-token incrementat. |
| `suppression-create`, `suppression-update` | PUT 201/204; ATTENDEE `@example.invalid` sense SCHEDULE-STATUS; SEQUENCE augmentat. |
| `stale-tool`, `stale-dav` | Conflicte al gestor i HTTP 412 a DAV; dades i ETag intactes. |
| `move`, `overwrite` | MOVE 201 amb l'UID només a la destinació; Overwrite F retorna 412 i conserva tots dos elements. |
| `delete` | HTTP 204; UID absent de la lectura i a la paperera. |
| `invitations`, `transfer`, `acl` | `OK` si s'han demanat i comprovat; `NOT TESTED` si no s'han indicat les opcions. |
| `cleanup`, `result` | Neteja HTTP 204 confirmada, sessió restaurada i resultat final del diagnòstic. |

Qualsevol `FAIL` retorna un codi diferent de zero. Si falla la neteja, `cleanup-resource` indica el camí/UID que cal comprovar al servidor. El resultat no canvia quines eines hi ha disponibles.

[Guies operatives](README.md).

[English](../../en/guides/troubleshooting.md) · [Español](../../es/guides/troubleshooting.md) · Català · [Português (Brasil)](../../pt-BR/guides/troubleshooting.md) · [Português (Portugal)](../../pt-PT/guides/troubleshooting.md)
