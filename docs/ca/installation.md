<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: b399933ce2b824c7f278b9615b72d97555cd8e84 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../en/installation.md).

# Instal·lació

## Requisits

- Nextcloud **32 a 35**; cada versió s'ha comprovat amb proves de contracte de l'API contra la compilació més antiga de la seva versió principal (amb les versions corresponents de Deck i Talk); Deck 1.16+ i Talk 22+ a la 32, fins a Deck 1.19 i Talk 25 a la 35
- PHP **8.2+** amb `zip`, `mbstring` i `dom`
- Aplicacions opcionals, només per a les seves pròpies eines (s'amaguen si l'aplicació està desactivada): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versions (`files_versions`, necessari per editar) i fitxers suprimits (`files_trashbin`, necessari per suprimir)
- Deck i Talk són opcionals: les seves eines només apareixen si l'aplicació està activada en una versió compatible; una versió antiga compta com a desactivada, les eines continuen ocultes i la pàgina d'administració indica quina versió les torna a habilitar

La instal·lació habitual es fa des de Nextcloud App Store: *Aplicacions* → cerca **MCP for Cloud** → **Baixa i activa** (o `occ app:install mcp`). El paquet de la botiga està signat i s'actualitza amb l'actualitzador d'aplicacions. La instal·lació per SSH següent és una alternativa per al desenvolupament o per provar una compilació local.

### Instal·lar per SSH

Substitueix `<server>`, `<nextcloud>` (directori d'instal·lació), `<apps>` (directori d'aplicacions amb permisos d'escriptura, per exemple `custom_apps` o `apps`, segons `apps_paths` a `config/config.php`) i `<www>` (usuari del servidor web, per exemple `www-data`).

```sh
scp build/mcp-1.1.2.tar.gz <server>:/tmp/
ssh <server>
sudo tar -xzf /tmp/mcp-1.1.2.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

A Docker, executa les ordres `occ` dins del contenidor com a usuari del servidor web. Si PHP fa servir OPcache amb `validate_timestamps=0`, reinicia PHP-FPM després de copiar els fitxers.

Per actualitzar una compilació instal·lada per SSH: `occ app:disable mcp`, elimina `<apps>/mcp`, extreu el paquet nou, executa `occ app:enable mcp` i `occ upgrade` si Nextcloud ho demana. Cal substituir la carpeta sencera perquè no quedin fitxers antics a `vendor/`. Per desinstal·lar-la: `occ app:remove mcp`.

Les migracions de 1.1.0 conserven els ID originals i importen la configuració antiga de clients natius/personalitzats a files autoritatives. Les preferències i credencials vàlides de 1.0.8 sobreviuen a l'actualització. Els camins de validació inclouen upstream 1.0.8 original, el 1.0.8 local migrat, l'actualització des d'upstream 1.1.0 i 1.1.1 i la substitució d'una instal·lació upstream 1.1.2 ja migrada. La substitució de la mateixa versió no força una actualització ni repeteix migracions per restablir la política.

## Actualització i recuperació

Abans d'actualitzar, prepara una còpia coherent de l'aplicació, base de dades i configuració/dades necessàries de Nextcloud, seguint el procediment de l'operador. El mode de manteniment impedeix trànsit durant la substitució sense canviar la política de servei MCP ni revocar credencials per una actualització rutinària. Substitueix tot el directori de l'aplicació, executa l'actualització nativa necessària, neteja/reinicia les memòries cau PHP quan calgui i comprova historial de migracions, recursos d'interfície, permisos i connexions existents abans de reobrir el trànsit.

Prioritza reparar cap endavant després d'una actualització fallida. Revertir només els fitxers d'aplicació a 1.0.8 és insegur: aquell codi no aplica les noves revocacions per època/generació ni restriccions de recursos. Una còpia de base de dades també pot contenir credencials revocades després de crear-la.

Per a una recuperació coordinada, mantén Nextcloud en manteniment mentre restaures estats corresponents d'aplicació i base de dades i la configuració/dades necessàries. Abans de reobrir MCP, invalida codis/tokens OAuth restaurats mitjançant les ordres de política admeses, desconnecta explícitament els comptes que s'hagin de reconnectar i revisa permisos efectius i restriccions de recursos. Revisa/revoca per separat les contrasenyes d'aplicació de Nextcloud a Seguretat. Tornar a habilitar el servei no substitueix aquesta revisió. Una versió antiga que no apliqui les restriccions necessàries no es pot reobrir per a aquests usuaris.

La suite nativa d'un sol ús assaja restaurar aplicació/SQLite de la mateixa versió, rebutjar credencials antigues i reconnectar explícitament. No certifica les eines de còpia de l'operador, una reversió només de fitxers ni totes les configuracions d'emmagatzematge de producció.

[Guies operatives](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · Català · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
