<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Zu MCP for Cloud beitragen

[English](CONTRIBUTING.md) · [Português (Brasil)](CONTRIBUTING.pt-BR.md) · [Español](CONTRIBUTING.es.md) · [Deutsch](CONTRIBUTING.de.md)

Vielen Dank für deinen Beitrag. Fehlerbehebungen, Übersetzungen, Dokumentation und neue Funktionen sind willkommen. Dieser Leitfaden erklärt die Produktentscheidungen hinter unserem Code, damit du eine Idee beurteilen kannst, bevor du viel Arbeit in eine größere Implementierung steckst.

## Was wir entwickeln

MCP for Cloud ist eine native Nextcloud-App in PHP. Sie ermöglicht authentifizierten Personen, Nextcloud über einen MCP-Client zu nutzen. Die App läuft in ihrer Instanz mit ihrer Identität und ihren Berechtigungen. Unser Schwerpunkt ist ein nützlicher Zugang zu bestehenden Apps mit klaren Kontrollen, vorhersehbaren Auswirkungen und einer Installation, die über die Nextcloud-Oberfläche funktioniert.

## Unsere Entwicklungsphilosophie

### Einfache Installation und native Implementierung

Die ausgelieferte App muss ohne verpflichtende Terminalbefehle, manuelles SQL, Änderungen an Konfigurationsdateien oder einen separaten Dienst funktionieren. Optionale Diagnosen müssen optional bleiben. Nutze die Migrationen und Einstellungen von Nextcloud für Einrichtung und Konfiguration.

Nutze natives PHP und die Nextcloud-APIs. Halte Produktionsabhängigkeiten gering und begründe sie. Das Frontend verwendet einfaches JavaScript; Bundles erstellt das bestehende PHP-Werkzeug. Führe ohne vorherige Absprache weder Node/npm als Build- oder Testvoraussetzung noch ein weiteres Framework oder einen externen Dienst ein. Composer wird bei Entwicklung und Paketierung verwendet; Nutzer installieren ein Paket, das die Laufzeitabhängigkeiten bereits enthält.

### MCP-Regeln und native Berechtigungen respektieren

Jede Operation läuft unter der Identität der authentifizierten Person und muss die geltenden MCP-Grants, Gruppenbeschränkungen, Guests-Regeln, Ressourcenbeschränkungen, Sichtbarkeitsprüfungen und nativen ACLs erfüllen. Ein Grant ersetzt keine native Berechtigung. Ist eine Richtlinie oder ein Dienst nicht verfügbar, muss die Operation kontrolliert abgelehnt werden, statt den Zugriff auszuweiten.

Bewahre gespeicherte Zugriffsentscheidungen bei Updates. Neue Schreiboperationen und neu eingeführte sensible Leseoperationen sind standardmäßig verweigert. Manche bestehenden Lese-Grants sind standardmäßig erlaubt; daraus folgt keine automatische Freigabe jeder neuen Leseoperation. Neue Operationen und Erweiterungen bestehender Grants erfordern eine ausdrückliche Zugriffsprüfung. Beispielsweise begründet das Bearbeiten einer Karte nicht automatisch das Recht, die Labels eines Boards zu löschen.

### Schreiboperationen vor der Ausführung erklären

Eine unbestätigte Schreiboperation liefert einen Plan mit Ziel, Änderungen, betroffenen Personen und Grenzen der Wiederherstellung. Sie darf die angeforderte Änderung weder ausführen noch deren Sicherung, Ordner oder Checkout-Token vorab erstellen. Die Ausführung erfordert `confirm: true` sowie `confirm_shared` und `plan_state`, soweit der Ablauf sie verlangt. Prüfe Zugriff, Identität und relevanten Zustand unmittelbar vor der Wirkung erneut.

Die Bestätigung erfolgt im Gespräch: Der Server kann nicht beweisen, dass eine Person zugestimmt hat. `plan_state` bindet einen Aufruf an den im Plan gezeigten Zustand; es erteilt keine Berechtigung und stellt keine gespeicherte Genehmigung dar. Bewahre die Nebenläufigkeitsprüfungen jedes Ablaufs. Dokumentiere Auswirkungen der nativen Initialisierung, wo sie auftreten; versprich nicht, dass jede Vorschau völlig frei von Plattformeffekten ist.

### Natives Verhalten bewahren und Daten schützen

Nutze den für die Operation geeigneten nativen Dienst oder DAV-Ablauf und bewahre die erforderlichen Berechtigungsprüfungen, Ereignisse, Versionen, Papierkorbfunktionen und Terminplanungsmechanismen. Verändere keine Tabellen anderer Apps und umgehe deren Abläufe nicht, nur damit eine Funktion scheinbar funktioniert. Für eigene Persistenz nutzt MCP weiterhin die Datenbank- und Migrationsfunktionen von Nextcloud.

Lösche keine Benutzerdateien zur Bereinigung, Rückabwicklung oder Aufbewahrungsbegrenzung. Dateilöschung ist ein ausdrücklicher, eingeschränkter Ablauf über `files_delete`; für `notes_delete` gelten eigene Regeln. Halte die bestehende Ausnahme zum Rückgängigmachen leerer Ordner eng begrenzt. Entferne Sicherungen oder Versionen niemals stillschweigend und beschreibe einen nativen Papierkorbversuch niemals als garantierte Wiederherstellung.

Ein Leseaufruf darf nicht unerwartet Nachrichten als gelesen markieren, Status ablaufen lassen oder Wartung ausführen. Prüfe die tatsächlichen Auswirkungen des nativen Aufrufs. Gibt es keinen sicheren Weg, lehne die Funktion ab und erkläre die Einschränkung, statt eine Umgehung einzubauen.

### Datenschutz über App-Grenzen hinweg wahren und Unsicherheit benennen

Links, Anhänge, Suchergebnisse und Verweise müssen die Schutzregeln der offengelegten Ressource respektieren. Der Zugriff über ein anderes Modul darf weder eine verborgene Ressource noch ihren Namen oder ihre Existenz verraten. Gib nur die für die Operation nötigen Daten zurück und halte Geheimnisse sowie Ressourceninhalte aus Diagnosen heraus.

Begrenze Suchläufe und Antworten; kennzeichne Teilergebnisse und Fortsetzungsmöglichkeiten ausdrücklich. Unterscheide beobachteten Erfolg, teilweise Ausführung und ungewisse Ergebnisse. Versprich weder Atomarität noch die Zustellung von Einladungen oder Wiederherstellung, wenn die native API dies nicht nachweisen kann. Führe nach einer möglichen Schreiboperation keine automatischen Wiederholungen oder destruktiven Ausgleichsaktionen ohne gesondert geprüftes Konzept ein.

### Begrenzte Änderungen und vertraute Bedienelemente

Nutze fokussierte Klassen und die bestehenden Modulgrenzen. Bewahre Werkzeugnamen, Schemas, Reihenfolge und persistierte Identitäten, sofern die Änderung nicht ausdrücklich eine geprüfte Vertragsanpassung erfordert. Beibehaltener Altcode und Testausnahmen sind keine Vorlagen für neuen Code.

Bewahre das Layout der Berechtigungsansicht, ihre drei ohne Farbe unterscheidbaren Zustände, ausdrückliche Sammeländerungen, Zähler und Rückgängig. Eine visuelle Neugestaltung erfordert eine gesonderte Absprache.

## Vor einem größeren Beitrag

Beschreibe das Problem der Nutzer, das erwartete Verhalten, den Zielbranch, Grants, die native API und unterstützte Versionen. Erkläre gemeinsam wirksame oder destruktive Auswirkungen sowie Fehlerfälle. Stimme größere Funktionen, Architekturänderungen, neue Abhängigkeiten oder Zugriffserweiterungen vor der Implementierung mit den Maintainern ab. Kleine Fehlerbehebungen und Dokumentationsverbesserungen können direkt als klar begrenzter PR eingereicht werden.

Die Kompatibilität richtet sich nach dem Zielrelease. Die veröffentlichte Version 1.3.5 dokumentiert NC32–35. Für Arbeiten an 1.4.0 gelten neue Zusagen und Tests nur für NC34/35; bestehender NC32/33-Code und der deklarierte Installationsbereich bleiben erhalten. Ergänze keine NC32/33-spezifischen Fallbacks oder Fixtures und behandle den Installationsbereich nicht als Zusage für neue Funktionen.

## Implementierung und Nachweise

Lies [Architektur](docs/en/architecture.md), [Modul oder Werkzeug hinzufügen](docs/en/adding-a-module.md), [Tests](docs/en/testing.md) und [Paketierung](docs/en/packaging.md). Neuer und überarbeiteter PHP-Code verwendet Tabs, `strict_types`, passende SPDX-Hinweise und vollständiges PHPDoc. Bewahre Urheberangaben. Code, Kommentare, Commit-Nachrichten und Ausgangstexte sind auf Englisch; halte die unterstützten Übersetzungen bei sichtbaren Änderungen inhaltlich gleichwertig.

Führe bei Codeänderungen gezielte und betroffene Tests, Architecture, MapCoverageTest, die Contract-Suite und REUSE auf einer sauberen Kopie aus. Neue Tests müssen in das bestehende CI-Inventar und dessen Partitionen aufgenommen werden; native Integrationstests gehören in die bestehenden Integrationszellen. Mocks allein belegen kein natives Verhalten. Vollständige Testsuiten laufen in der bestehenden Release-Validierung auf GitHub, statt für jeden Patch lokal wiederholt zu werden. Reine Markdown-Änderungen benötigen eine Inhalts- und Linkprüfung, keine App-Tests oder CI.

Beschreibe im PR die Änderung, Auswirkungen auf Zugriff und Kompatibilität, Befehle und Ergebnisse sowie nicht getestete Bereiche. Prüfe Golden-Änderungen bewusst; erzeuge Erwartungen nicht neu, um Regressionen zu verbergen. Änderungen an der nativen Integration erfordern eine erneute Integrationsvalidierung im CI.

## Review, Anerkennung und Veröffentlichung

Eine funktionierende Implementierung kann weiterhin Anpassungen benötigen, um diesen Grundsätzen zu entsprechen. Kleine, geeignete Beiträge werden normalerweise durch einen regulären Merge unter Erhalt der ursprünglichen Autorenschaft integriert; Änderungen der Maintainer bleiben getrennt. Große Beiträge oder solche, die ein anderes Konzept benötigen, können vor der Integration angepasst werden. Dabei wird der ursprüngliche Beitrag gewürdigt und von unseren Änderungen unterschieden.

Die Integration eines Beitrags und die Veröffentlichung eines Releases sind getrennte Schritte. Bewahre die Lizenz- und Attributionshinweise des Projekts. Siehe [Lizenz](LICENSES/AGPL-3.0-or-later.txt), [NOTICE](NOTICE) und [technische Referenz](mcp/README.md).
