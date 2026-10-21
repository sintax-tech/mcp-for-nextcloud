<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/contacts-tasks.md -->
<!-- translation-source-revision: 0105e8e8a2706d139deb119f87d7d01c0788586c -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/contacts-tasks.md).

# Contactes i tasques (0.8.0)

| Mòdul | Eines | Dependència |
|---|---|---|
| Contactes | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | aplicació Contacts activada; CardDAV del nucli |
| Tasques | `tasks_list_calendars`, `tasks_list_tasks`, `tasks_read_task`, `tasks_create_task`, `tasks_edit_task`, `tasks_complete_task`, `tasks_delete_task` | DAV del nucli; no requereix l'aplicació Tasks ni Calendar |

Els catàlegs de Contactes són propis o compartits i filtren explícitament qualsevol principal de sistema. Les lectures fan servir adaptadors mandrosos del dorsal natiu, després del filtre d'accés; les escriptures passen pel Sabre del nucli (un dispatcher CardDAV separat i l'`EmbeddedDavDispatcher` existent per a VTODO). La sessió ha de coincidir amb qui fa l'acció, i el principal queda fixat en executar-la. Les consultes de col·leccions de Calendar fan servir VEVENT per defecte; Tasks selecciona VTODO.

`GrantPolicy`/`GrantMatrix` inclouen `contacts` i `tasks` amb permisos de lectura/creació/edició/supressió, i les escriptures estan desactivades inicialment. Completar una tasca requereix el permís d'edició. `PreviewsWrites` genera el pla sense efectes secundaris; `WriteGate` requereix `confirm:true`; els elements compartits requereixen `confirm_shared`; si s'ha proporcionat un ETag, es comprova i `If-Match` fa servir el valor tornat a llegir. En editar una vCard es conserven els camps desconeguts, els paràmetres, els grups i la versió; el pla inclou totes les propietats abans/després i la vCard completa.

**A Nextcloud 33, suprimir un contacte és PERMANENT i no hi ha paperera.** Només després de la confirmació, `ContactBackup` desa la vCard original completa a `/MCP backups/Contacts/<catalog>/<name-or-uid>.<YYYYmmdd-HHMMSS>-<unique-suffix>.vcf`, amb la zona horària del compte i a la carpeta de qui fa l'acció. Fa servir un mutex de l'aplicació, un sufix aleatori i una comprovació de col·lisió per no triar mai un fitxer existent, i verifica la còpia tornant a llegir-ne els bytes. Si falla, s'impedeix el DELETE de CardDAV; el resultat informa `backupPath`. Per recuperar el contacte, torna'l a importar mitjançant la interfície de Contacts. No es crea cap còpia de seguretat durant la previsualització.

Les tasques fan servir VTODO, inclouen tasques sense data, amaguen les tasques PRIVATE/CONFIDENTIAL d'un altre propietari i exclouen els objectes de la paperera. Es poden llegir les recurrències; es rebutgen les escriptures en sèries o tasques amb participants. Els camps que no han canviat es conserven a iCalendar; completar una tasca escriu STATUS/PERCENT-COMPLETE/COMPLETED, i reduir-ne el progrés la torna a obrir. En suprimir-la, es fa servir la paperera de CalDAV, es rebutja la retenció zero i es comprova deleted-at després del DELETE. Els llistats fan servir `limit`/`offset`/nextOffset; per incloure tasques completades cal `include_completed`. Les `categories` (`CATEGORIES`) són text lliure: lectura i llistats retornen `categories`, combinades de totes les línies `CATEGORIES` (es respecten les comes escapades), amb espais eliminats i duplicats ignorats sense distingir majúscules Unicode, conservant la primera grafia. create i edit accepten `categories` (fins a 50 elements d'1–255 caràcters després d'eliminar espais, sense caràcters de control; es valida tot abans d'escriure): si s'omet, es conserven totes les línies; [] les elimina; una llista les substitueix per una sola línia amb diversos valors. `tasks_list_tasks` filtra amb `category` (coincidència exacta sense distingir majúscules) abans d'aplicar `offset`/`limit`, juntament amb `include_completed`; el pla mostra cada nom entre cometes, abans i després. Les `categories` de tasques ocultes per CLASS mai no es llegeixen, filtren ni mostren.

No hi ha migració, approval_id, registre d'aprovació, dependència d'occ, servei addicional ni base de dades auxiliar. Les descripcions/ToolGuideNotes continuen en anglès; els títols i missatges fan servir Translator amb paritat de/pt_BR/es. Les proves cobreixen plans, còpia de seguretat, ACL, permisos, ETag, preservació i dispatcher Sabre real; no demostren el funcionament dels connectors interns en una instal·lació real de Nextcloud.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/contacts-tasks.md) · [Español](../../../es/reference/tools/contacts-tasks.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/contacts-tasks.md) · [Português (Portugal)](../../../pt-PT/reference/tools/contacts-tasks.md)
