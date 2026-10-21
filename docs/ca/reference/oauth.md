<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/oauth.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/reference/oauth.md).

# OAuth

Les metadades del recurs protegit són a `/apps/mcp/.well-known/oauth-protected-resource`. El repte `401` identifica aquest document. Les metadades del servidor d'autorització són a `/apps/mcp/.well-known/openid-configuration` i `/apps/mcp/.well-known/oauth-authorization-server`, amb emissor `https://<instance>/apps/mcp`.

`oauth/authorize` ofereix consentiment al navegador; `oauth/token` intercanvia codis d'autorització amb PKCE S256 i rota tokens de renovació. Els tokens d'accés duren una hora i els de renovació, 30 dies. La base de dades emmagatzema hashes de credencials, no els valors en clar. És autenticació de client públic (`none`); un ID de client és un identificador, no un secret ni una concessió de permisos.

## Clients publicats, natius i registrats

Els administradors gestionen clients a **Administració → MCP for Cloud → Clients i connexions**. Desar exigeix administrador, protecció CSRF de Nextcloud i la revisió actual de la política.

| Tipus de client | Identitat i regles de redirecció |
| --- | --- |
| Document d'identitat de client publicat (CIMD) | Document HTTPS en un host exacte permès. Els predeterminats són `claude.ai,chatgpt.com`. Les redireccions han de coincidir amb el document validat. |
| Client natiu inclòs | `nextcloud-mcp-native`, desactivat per defecte. Els callbacks HTTP de loopback registrats permeten ports variables per a programes locals. |
| Client personalitzat registrat per l'administrador | ID generat, nom visible, estat habilitat i llista explícita d'URI de redirecció. La coincidència és exacta, inclòs el port en HTTP de loopback. |

La llista permesa accepta hosts sense esquema, port, ruta ni comodins; n'ha de quedar almenys un. Desar substitueix la llista. Retirar un host bloqueja l'admissió encara que el document sigui a la memòria cau. Els documents es descarreguen per HTTPS sense redireccions, amb límit de 64 KiB i memòria cau d'una hora.

S'admeten com a màxim 20 clients personalitzats, noms de fins a 64 caràcters i fins a 20 URI de redirecció per client. Els noms no poden ser buits i han de ser únics sense distingir majúscules. Cada URI admet fins a 2000 bytes, utilitza HTTPS o HTTP en loopback i no conté informació d'usuari, fragment, comodí, espais en blanc, caràcters de control ASCII ni barra inversa. Una llista de redireccions buida no pot autoritzar un client. Copia l'ID generat a la configuració del client i registra el callback exacte; els clients personalitzats no hereten l'excepció de port variable del client natiu.

Després de la migració, la taula de clients gestionats és la font autoritativa. Deshabilitar/eliminar un client gestionat o retirar un dels seus URI de redirecció retira les concessions existents i avança la generació del client. Tornar-lo a habilitar no reactiva una pàgina de consentiment, codi o token anterior. Un error de lectura d'aquesta taula rebutja temporalment l'autorització en lloc de mostrar consentiment suposant que està permès.

## Connectar mitjançant ChatGPT i Gemini CLI (OAuth)

Consulta els exemples a la [guia de clients](../guides/clients.md). ChatGPT utilitza la identitat publicada a `chatgpt.com`. Gemini CLI pot utilitzar el client natiu inclòs un cop l'habilita un administrador. Els callbacks natius són `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` i `http://[::1]/oauth/callback`; el port de loopback seleccionat pot variar.

Cada redirecció validada inclou el paràmetre d'emissor `iss`, també les denegacions i els errors redirigibles. Els errors anteriors a validar la redirecció es mostren en una pàgina local. Les interfícies i la compatibilitat amb clients allotjats encara requereixen revisió amb clients reals; una prova local del protocol no certifica la interfície actual.

## Revocació i incertesa

Cada petició torna a comprovar identitat, servei, elegibilitat, connexió personal, aplicació/rol de registres, permís d'operació, restricció de recursos, etiquetes d'exclusió i ACL natives quan correspon. Un permís de grup no evita aquestes comprovacions. Una política desconeguda significa indisponibilitat temporal, no permís implícit ni revocació destructiva.

La desconnexió personal, la pèrdua d'elegibilitat, l'aturada del servei i la deshabilitació/eliminació del compte invaliden credencials OAuth mitjançant generacions per usuari o una època global. La invalidació es confirma juntament amb el canvi de política; una neteja persistent elimina després les files obsoletes i ha de conservar les credencials posteriors. Restituir accés o habilitar un client no reviu credencials anteriors. La detecció de reutilització de tokens de renovació continua aplicant-se al llinatge del propietari/client.

Canviar l'àmbit de recursos no revoca OAuth: l'operació següent avalua la nova restricció. Les contrasenyes d'aplicació HTTP Basic pertanyen a Nextcloud, no a aquest magatzem OAuth. Desconnectar MCP en bloqueja l'ús a MCP, però eliminar-les exigeix els controls de Seguretat de Nextcloud. Aturar el servei conserva la preferència de connexió personal; la recuperació també pot exigir desconnexió explícita i revisió de contrasenyes d'aplicació. Consulta [instal·lació i recuperació](../installation.md).

## Propietats de seguretat

- El consentiment està protegit per CSRF i no admet marcs. Inici de sessió, elegibilitat, connexió, permisos, consentiment i PKCE continuen sent obligatoris per a clients natius/personalitzats.
- Les comprovacions de generació i invalidació impedeixen que credencials retirades recuperin validesa en restituir accés o recrear identitat/client. Un estat incert de base de dades o política produeix un error reintentable sense suposicions.
- Els canvis administratius de clients validen tota la petició abans d'escriure. Els conflictes recarreguen l'estat en lloc de repetir silenciosament una mutació.
- Els errors inesperats mostrats al client són genèrics. El diagnòstic de l'aplicació no registra credencials en clar ni contingut de fitxers.
- Comunica vulnerabilitats en privat als responsables. No desactivis la protecció contra força bruta ni permetis un rang de xarxa no verificat per resoldre un error de connexió; investiga la denegació i els registres del servidor.

[Guies operatives](../guides/README.md).

[English](../../en/reference/oauth.md) · [Español](../../es/reference/oauth.md) · Català · [Português (Brasil)](../../pt-BR/reference/oauth.md) · [Português (Portugal)](../../pt-PT/reference/oauth.md)
