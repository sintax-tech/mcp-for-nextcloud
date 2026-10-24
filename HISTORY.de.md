# Verlauf

[English](HISTORY.md) · [Português (Brasil)](HISTORY.pt-BR.md) · Deutsch · [Español](HISTORY.es.md)

Dieser Verlauf beginnt mit 1.2, der ersten Version mit deutschem README. Ältere Versionen (1.1 und 1.0) sind im [englischen](HISTORY.md) und im [portugiesischen Verlauf](HISTORY.pt-BR.md) beschrieben.

## Neu in 1.2

Im [Changelog](CHANGELOG.md#120) findest du alle Änderungen und den Hinweis zu älteren Deck-Fälligkeiten.

- **Deck:** Labels, native Kartenabhängigkeiten unter Nextcloud 34/35 und sichtbare Dateiverknüpfungen an Karten: Verknüpfe eine vorhandene Datei oder löse die Verknüpfung, ohne das Original zu löschen. Die Vorschau erklärt den Lesezugriff für aktuelle und künftige Board-Mitglieder und die Grenzen späterer Änderungen an Freigaberichtlinien. Fälligkeiten können jetzt eine Uhrzeit enthalten; `dueAt` liefert sie mit dem Offset deiner Zeitzone. Labels und Kartendateien funktionieren unter Nextcloud 32–35.
- **Optionale Dateitypenrichtlinie:** standardmäßig aus. Die Administration kann erlaubte Endungen unter *Datenschutz* festlegen. Die Regel gilt für MCP-Schreibvorgänge, einschließlich Uploads, Versionswiederherstellung, Kopieren/Verschieben, Stapel, Rückgängigmachen und Notes; verpflichtende interne Sicherungen bleiben möglich. Geprüft wird der Name, nicht der Inhalt; Prompt Injection wird damit nicht ausgeschlossen. Ordnerkopien verwenden bei aktiver Richtlinie eine geprüfte Liste und melden Teilkopien.
- **Kleinere Änderungen:** Mit `notes_edit` kannst du Text anhängen oder einen eindeutigen Abschnitt ersetzen. `files_replace` prüft die gemeldete Größe vor dem Lesen und lehnt überlappende Treffer als mehrdeutig ab. Die Meldung nennt keine Trefferzahl; `old` ist auf 64 KiB begrenzt.
- **Verbindungen und Aufgabenfilter:** Die Navigation nach der OAuth-Zustimmung für den gemeldeten Mistral-Ablauf wurde korrigiert. Kategorien bleiben wörtliche Filter; bei einer leeren gefilterten Liste erfährst du, wie du den Filter entfernst oder erledigte Aufgaben einschließt.
- **Prüfe ältere Deck-Fälligkeiten:** Reine Datumsangaben, die MCP vor dieser Korrektur für Konten westlich von UTC geschrieben hat, können einen Tag zu früh erscheinen. Vorhandene Karten werden nicht automatisch geändert. Prüfe das beabsichtigte Datum und korrigiere betroffene Karten gezielt.
