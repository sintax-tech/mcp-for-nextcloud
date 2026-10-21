<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/clients.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/guides/clients.md).

# Connectar clients

Tots els clients fan servir el mateix URL, `https://cloud.example.com/apps/mcp/`, i s'autentiquen com la persona que els utilitza, amb els seus permisos. Tots necessiten primer el servei MCP actiu, un usuari elegible i una connexió personal activa. La configuració específica del client canvia:

| Client | Autenticació | Acció prèvia de l'administrador |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | **Inicia la sessió** amb OAuth, identitat publicada de Claude (CIMD) | Cap: `claude.ai` està permès per defecte |
| **ChatGPT** | **Inicia la sessió** amb OAuth, identitat publicada d'OpenAI (CIMD) | Cap: `chatgpt.com` està permès per defecte |
| **Gemini CLI** | OAuth amb el client natiu integrat a l'aplicació | Marca **Permet els programes locals** i copia el `client_id` |
| **Client autoallotjat registrat** | OAuth públic amb PKCE | Registrar nom i URI de callback exacte, després copiar l'ID generat |
| **Qualsevol altre client MCP** | Contrasenya d'aplicació (HTTP Basic) | Sense registre de client addicional als requisits d'accés comuns |

### Claude (claude.ai, Claude Desktop, Cowork i Claude Code — recomanat)

1. *Configuració → Connectors → Afegeix un connector personalitzat*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Autenticació: **Inicia la sessió** (OAuth), client: **Identitat publicada de Claude** (CIMD). No calen capçaleres.
4. **Connecta**: s'obrirà Nextcloud; inicia la sessió i prem **Permet**.

`claude.ai` ja és als dominis de client permesos, de manera que no cal canviar res al servidor. Si l'administrador ha restringit la llista, Claude serà rebutjat amb `invalid_client` abans que s'obri la pàgina d'inici de sessió. Demana-li que torni a afegir `claude.ai` a *Configuració d'administració → MCP for Cloud → Clients OAuth → Dominis de client permesos*.

### ChatGPT

1. A ChatGPT, afegeix un connector nou per a un servidor MCP i enganxa-hi `https://cloud.example.com/apps/mcp/` com a URL.
2. Autenticació: **Inicia la sessió** (OAuth). ChatGPT s'identifica amb el document d'identitat del client publicat a `chatgpt.com`; l'aplicació el baixa per saber quins URI de redirecció hi ha registrats. No calen capçaleres ni contrasenya d'aplicació.
3. **Connecta**: s'obrirà Nextcloud; inicia la sessió i prem **Permet**.

`chatgpt.com` està permès per defecte, així que una instal·lació sense configurar ja accepta ChatGPT. Si l'administrador ha restringit la llista a altres dominis, ChatGPT serà rebutjat amb `invalid_client` abans que s'obri la pàgina d'inici de sessió; demana-li que hi torni a afegir `chatgpt.com`.

### Gemini CLI

Calen dos passos, un de l'administrador i un de l'usuari, sense necessitat d'accedir al terminal del servidor.

**Administrador** — *Configuració d'administració → MCP for Cloud → Clients OAuth*:

1. Marca **Permet els programes locals (client natiu)**. Està desactivat per defecte.
2. La secció mostrarà el `client_id` que cal copiar — `nextcloud-mcp-native` — i els URI de redirecció que accepta.

**Usuari** — crea o edita `~/.gemini/settings.json` al teu ordinador:

```json
{
  "mcpServers": {
    "nextcloud": {
      "url": "https://cloud.example.com/apps/mcp/",
      "oauth": {
        "clientId": "nextcloud-mcp-native"
      }
    }
  }
}
```

3. A Gemini CLI, executa `/mcp auth` (o `/mcp auth nextcloud`).
4. Nextcloud s'obrirà al navegador: inicia la sessió i prem **Permet**. Gemini CLI escolta en un port lliure que tria el mateix programa i el navegador torna a `http://localhost:<port>/oauth/callback`. L'aplicació accepta `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` i `http://[::1]/oauth/callback`, i ignora el port en adreces de loopback (RFC 8252 7.3), de manera que també coincideix el port aleatori.

Per què hi ha un client integrat: Gemini CLI no publica cap document d'identitat del client, així que l'aplicació incorpora un únic client públic per als programes locals. El seu identificador és el mateix per a tothom a la instància i és segur perquè per si sol no concedeix cap accés: continuen sent necessaris l'inici de sessió, l'elegibilitat, la connexió personal, els permisos de l'administrador, la pantalla de consentiment i PKCE. Desactivar aquesta opció també impedeix que el client bescanviï codis d'autorització o actualitzi tokens.

### Administradors: clients OAuth

*Configuració d'administració → MCP for Cloud → Clients OAuth* decideix quins clients es poden connectar, sense cap ordre de terminal:

- **Dominis de client permesos** — dominis dels documents d'identitat publicats que l'aplicació pot baixar; separats per comes. Per defecte: `claude.ai` i `chatgpt.com`. Només s'accepten noms de domini exactes: sense `https://`, port, camí, `*` i cal mantenir almenys un domini. El domini és el del URL que el client fa servir com a `client_id`. En desar se substitueix la llista sencera, i treure'n un domini rebutja el client de seguida, encara que el document estigui a la memòria cau.
- **Permet els programes locals (client natiu)** — activa el client `nextcloud-mcp-native` descrit més amunt i mostra el `client_id` i els URI de redirecció acceptats per copiar.

- **Clients autoallotjats (URI de redirecció personalitzats)**: registra nom, estat habilitat i URI exactes de callback, i copia l'ID generat al client. Utilitzen HTTPS o HTTP de loopback; el port ha de coincidir exactament, a diferència del client natiu inclòs. Consulta límits i validació a [OAuth](../reference/oauth.md#clients-publicats-natius-i-registrats).

Els controls de clients exigeixen administrador, protecció CSRF i revisió de política vigent, i informen de desaments rebutjats. Una configuració pròpia no se substitueix pels valors predeterminats: un valor d'`oauth_client_hosts` desat abans de 0.8.0 continua en ús.

### Altres clients MCP (contrasenya d'aplicació)

Crea una contrasenya d'aplicació a *Configuració personal → Seguretat*, activa la connexió a *Configuració personal → MCP for Cloud* i configura el client amb l'URL anterior i autenticació HTTP Basic (`usuari:contrasenya-aplicació`). Comprovació ràpida:

```bash
curl -u 'alice:APP-PASSWORD' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revocar l'accés

*Desconnecta* a la pàgina personal bloqueja la següent petició MCP, fins i tot amb la mateixa contrasenya d'aplicació. La contrasenya continua sent vàlida a Nextcloud i cal revocar-la a **Seguretat**. A la mateixa pàgina, *Revoca* talla una connexió OAuth concreta del mateix usuari. L'administrador també pot retirar l'elegibilitat de l'usuari o desactivar el servei; totes dues accions s'apliquen a la petició següent. Al bloc **Connexions actives**, l'administrador pot revocar immediatament una connexió OAuth concreta o totes les d'un usuari; l'usuari continua activat i pot tornar a iniciar la sessió.

La revocació invalida generacions de credencials OAuth o l'època global abans de la neteja diferida. Restituir accés, desfer un canvi de política o habilitar de nou un client gestionat no restaura codis/tokens antics. Retirar un URI de redirecció personalitzat també retira les concessions existents d'aquell client. Aturar el servei conserva la preferència de connexió personal; el cicle de vida de les contrasenyes d'aplicació i la revisió de recuperació són independents. Consulta [OAuth](../reference/oauth.md#revocació-i-incertesa).

[Guies operatives](README.md).

[English](../../en/guides/clients.md) · [Español](../../es/guides/clients.md) · Català · [Português (Brasil)](../../pt-BR/guides/clients.md) · [Português (Portugal)](../../pt-PT/guides/clients.md)
