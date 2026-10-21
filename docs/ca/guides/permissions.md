<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/guides/permissions.md).

# Permisos i administració

Obre **Administració → MCP for Cloud**. Les cinc pestanyes són **Resum**, **Accés i permisos**, **Clients i connexions**, **Protecció de dades** i **Avançat**. Les adreces (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) i la navegació amb teclat obren la secció corresponent.

## Habilitar l'accés

El servei, l'elegibilitat de l'usuari (**Pot connectar-se**) i la connexió personal comencen desactivats, també per als administradors. Activa el servei, permet que l'usuari es connecti i deixa que activi la connexió a **Configuració personal → MCP for Cloud**. L'autenticació per si sola no concedeix accés: el compte, el servei, l'elegibilitat, la connexió, l'aplicació opcional, el permís d'operació i els permisos natius de Nextcloud sobre el recurs han de permetre la petició.

La lectura comença permesa, excepte els registres del servidor. Les altres operacions comencen denegades. Compartir fitxers amb persones/grups (`files.share`) i crear enllaços públics (`files.link`) són permisos independents. Llistar comparticions és una operació de lectura.

Resum mostra l'endpoint, la versió, el nombre de connexions i el límit de pujada de checkout. Aquest límit s'edita en MiB i no pot superar `post_max_size` de PHP. Avançat conté els rols i la privadesa dels registres i OCR. Protecció de dades conté les etiquetes d'exclusió; consulta [protecció de fitxers](file-protection.md).

## Opcions individuals i herència de grups

Accés i permisos ofereix nivells per mòdul i una vista **Operacions** per als permisos individuals. La cerca utilitza nom, ID o correu; el correu no es mostra. Els filtres de grup i elegibilitat/connexió s'apliquen al servidor, amb 50 usuaris per pàgina. Els comptes deshabilitats no es poden editar, i les aplicacions o els rols de registres no disponibles continuen no disponibles sense esborrar les opcions desades.

La resolució segueix aquest ordre:

1. Preval **Permet** o **Denega** explícit de l'individu.
2. Sense una opció individual, qualsevol concessió d'un grup de confiança permet l'operació.
3. Altrament s'aplica el valor predeterminat. Els grups concedeixen accés; no el deneguen ni el restringeixen.

Només poden concedir permisos els grups del backend de base de dades propi de Nextcloud. LDAP, SAML, altres backends d'aplicacions i identitats de backend ambigües no poden proporcionar herència segura. Dona permisos individuals als seus usuaris. Un grup recreat o incert no recupera silenciosament l'autoritat anterior: l'editor explica l'estat i ofereix confirmar la política actual quan correspon.

A **Permisos de grup**, selecciona un grup, edita les operacions i previsualitza abans de desar. Els recomptes de membres i les estimacions de tancament de sessió són informació de planificació, no una decisió d'autorització per membre. Retirar **Pot connectar-se** tanca les sessions dels membres que realment perden l'elegibilitat. Els grups grans poden trigar a processar-se; aquesta integració no promet una nova garantia de rendiment.

**Hereta** i les accions de restablir fila/selecció eliminen les excepcions individuals. La previsualització identifica opcions individuals, grups que concedeixen permisos, valors predeterminats i resultats desconeguts. Un grup o una política il·legible és **Desconegut**, mai un permís implícit ni un motiu per esborrar credencials. Les peticions que depenen d'aquesta incertesa fallen temporalment; les eines amb autorització desconeguda s'ometen del descobriment mentre les permeses de manera independent continuen disponibles.

## Desar, conflictes i desfer

Les escriptures de política inclouen la revisió mostrada a la interfície. Una previsualització obsoleta o un canvi concurrent produeix un conflicte i una recàrrega; el navegador no repeteix silenciosament l'escriptura. Una resposta incerta no es presenta com un desament correcte. Els esborranys de grups, recursos i clients continuen sent esborranys fins a l'acció Desa corresponent.

Les peticions de restabliment/lot es limiten a 50 usuaris i 2500 cel·les. La previsualització val 120 segons; els rebuts del servidor caduquen als 600 segons. La interfície ofereix **Desfés** durant 10 segons mentre el control estigui disponible i pausa el temporitzador amb el punter a sobre o el focus del teclat. Desfer exigeix la revisió desada i un rebut vàlid: no pot sobreescriure canvis posteriors. Restaurar un permís no restaura credencials revocades ni torna a iniciar sessions.

## Restriccions de recursos i inspecció

L'administrador pot restringir els recursos de **Files**, **Calendar** i **Tasks** d'un usuari des del panell d'accés. Cada mòdul és independent: **Tots els recursos** vol dir sense restricció; **Només els seleccionats**, amb una selecció buida, no permet cap recurs. El límit és de 200 recursos per usuari i mòdul. Les restriccions només redueixen les concessions i els permisos natius existents; canviar-les no revoca la connexió. S'apliquen a l'operació següent.

Notes, Contacts, Deck i Talk no tenen restricció de recursos per mòdul a 1.1.0. Les referències estructurades a fitxers i les operacions amb adjunts poden requerir comprovacions de l'àmbit de Files; consulta [protecció de fitxers](file-protection.md). Restringir una carpeta no restringeix de manera general tot el que altres mòduls hi emmagatzemen a sota.

L'inspector, exclusiu dels administradors, informa **Permès**, **Bloquejat** o **Desconegut**, la comprovació que bloqueja i l'origen del permís. Pot explicar una ruta exclosa a l'administrador; els errors MCP mantenen denegacions genèriques. Les consultes i els canvis poden generar missatges d'auditoria de nivell informatiu sense noms ni rutes de recursos. Depenen del nivell de registre del servidor i no garanteixen una traça d'auditoria.

## Clients i configuració personal

Gestiona els hosts de clients publicats, el client natiu i els clients personalitzats registrats a **Clients i connexions**; consulta [OAuth](../reference/oauth.md). Les API administratives exigeixen un administrador i protecció CSRF de Nextcloud. Les API personals de connexió/àmbit obtenen la identitat de la sessió i només exposen l'estat d'aquell usuari. Les respostes mai no contenen hashes de tokens.

La configuració personal mostra l'estat de connexió, els clients connectats, els controls de revocació i les restriccions de recursos de només lectura de l'usuari. Per a HTTP Basic, crea una contrasenya d'aplicació a **Configuració personal → Seguretat**. MCP no demana ni emmagatzema la contrasenya principal. Desconnectar MCP no elimina aquesta contrasenya d'aplicació de Nextcloud; consulta [revocar l'accés](clients.md).

Persisteix un risc residual d'escriptura: una denegació genèrica pot distingir una destinació no disponible d'una altra on la creació tindria èxit, tot i que no en reveli el motiu.

[Guies operatives](README.md).

[English](../../en/guides/permissions.md) · [Español](../../es/guides/permissions.md) · Català · [Português (Brasil)](../../pt-BR/guides/permissions.md) · [Português (Portugal)](../../pt-PT/guides/permissions.md)
