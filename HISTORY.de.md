# Verlauf

[English](HISTORY.md) · [Português (Brasil)](HISTORY.pt-BR.md) · Deutsch · [Español](HISTORY.es.md)

Dieser Verlauf beginnt mit 1.2, der ersten Version mit deutschem README. Ältere Versionen (1.1 und 1.0) sind im [englischen](HISTORY.md) und im [portugiesischen Verlauf](HISTORY.pt-BR.md) beschrieben.

## Neu in 1.2.1

Im [Changelog](CHANGELOG.md#121) findest du alle Änderungen.

- **Sicherungseinstellungen ([#17](https://github.com/sintax-tech/mcp-for-nextcloud/issues/17)):** unter *Datenschutz* einen Ordnernamen direkt im persönlichen Stammverzeichnis der handelnden Person wählen (Standard `MCP backups`; `.mcp-backups` ist erlaubt). Der Ordner entsteht mit der ersten Kopie. Ein neuer Name verschiebt oder löscht keine Inhalte; alle früheren Namen einschließlich des bisherigen Standards bleiben gegen MCP-Schreibzugriffe geschützt und behalten die Sichtbarkeitsregeln für Sicherungskopien.
- **Dateikopien:** standardmäßig eingeschaltet. Die Administration kann MCP-Kopien vor dem Schreiben nur bei aktivem nativem `files_versions` aussetzen. Die Verfügbarkeit wird für die handelnde Person erneut geprüft; ohne Versionierung sind Kopien Pflicht und bestehende Vorgänge bleiben ohne ihre Versions-Voraussetzung gesperrt. Eine native Version für jede Datei/jedes Backend ist nicht garantiert. Kontakte löschen erfordert immer eine geprüfte Kopie im konfigurierten Ordner, auch bei ausgesetzten Dateikopien. Frühere Sicherungen bleiben erhalten.
- **Mail ([#1](https://github.com/sintax-tech/mcp-for-nextcloud/issues/1)):** `mail_list_accounts` und `mail_list_mailboxes` listen nur eigene Konten und zwischengespeicherte Ordner/Zähler. Mail ist optional und benötigt `mail.read`. Diese Aufrufe synchronisieren kein IMAP, lesen keine Nachrichten und ändern keinen E-Mail-Zustand. Bei leerem Ordnercache das Konto einmal in Mail öffnen; ein alter Cache kann alte Angaben liefern. Die optionale Integrationszelle verwendet wegwerfbare Dovecot- und Mail-Instanzen, keinen Produktionsserver.
- **Unteraufgaben ([#18](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18)):** Aufgaben liefern ihre `parent`; Anlegen und Bearbeiten legen sie im selben Kalender fest oder entfernen sie mit `null`, und `tasks_list_tasks` listet die direkten Unteraufgaben einer Aufgabe. Schleifen und Verschachtelungen tiefer als 64 Ebenen werden abgelehnt; bestätigte MCP-Schreibvorgänge mit übergeordneter Aufgabe laufen nacheinander und benötigen die Dateisperre des Servers. Beitrag von @mturgeonferland.
- **Sichtbarkeit:** ein gewöhnlicher untergeordneter Ordner namens `MCP backups` wird nicht mehr mit dem Sicherungsordner im persönlichen Stammverzeichnis verwechselt. Bereich, Ausschluss-Tags und Verweigerung bei Fehlern gelten weiter.




## Neu in 1.2

Im [Changelog](CHANGELOG.md#120) findest du alle Änderungen und den Hinweis zu älteren Deck-Fälligkeiten.

- **Deck:** Labels, native Kartenabhängigkeiten unter Nextcloud 34/35 und sichtbare Dateiverknüpfungen an Karten: Verknüpfe eine vorhandene Datei oder löse die Verknüpfung, ohne das Original zu löschen. Die Vorschau erklärt den Lesezugriff für aktuelle und künftige Board-Mitglieder und die Grenzen späterer Änderungen an Freigaberichtlinien. Fälligkeiten können jetzt eine Uhrzeit enthalten; `dueAt` liefert sie mit dem Offset deiner Zeitzone. Labels und Kartendateien funktionieren unter Nextcloud 32–35.
- **Optionale Dateitypenrichtlinie:** standardmäßig aus. Die Administration kann erlaubte Endungen unter *Datenschutz* festlegen. Die Regel gilt für MCP-Schreibvorgänge, einschließlich Uploads, Versionswiederherstellung, Kopieren/Verschieben, Stapel, Rückgängigmachen und Notes; verpflichtende interne Sicherungen bleiben möglich. Geprüft wird der Name, nicht der Inhalt; Prompt Injection wird damit nicht ausgeschlossen. Ordnerkopien verwenden bei aktiver Richtlinie eine geprüfte Liste und melden Teilkopien.
- **Kleinere Änderungen:** Mit `notes_edit` kannst du Text anhängen oder einen eindeutigen Abschnitt ersetzen. `files_replace` prüft die gemeldete Größe vor dem Lesen und lehnt überlappende Treffer als mehrdeutig ab. Die Meldung nennt keine Trefferzahl; `old` ist auf 64 KiB begrenzt.
- **Verbindungen und Aufgabenfilter:** Die Navigation nach der OAuth-Zustimmung für den gemeldeten Mistral-Ablauf wurde korrigiert. Kategorien bleiben wörtliche Filter; bei einer leeren gefilterten Liste erfährst du, wie du den Filter entfernst oder erledigte Aufgaben einschließt.
- **Prüfe ältere Deck-Fälligkeiten:** Reine Datumsangaben, die MCP vor dieser Korrektur für Konten westlich von UTC geschrieben hat, können einen Tag zu früh erscheinen. Vorhandene Karten werden nicht automatisch geändert. Prüfe das beabsichtigte Datum und korrigiere betroffene Karten gezielt.
