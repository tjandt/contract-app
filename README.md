# Vertragsverwaltung

Eine schlanke PHP/MySQL-Webanwendung zur Verwaltung privater Verträge
(Strom, Handy, Versicherungen, Streaming, …) mit Kostenhistorie,
Kündigungs-Remindern, Zahlungskalender und Dashboard mit Monats-/Jahresvergleich.

Reines PHP (keine Frameworks/Composer nötig), MySQL/MariaDB als Backend,
Chart.js für die Diagramme im Frontend.

## Features

- **Stammdaten**: Name, Kategorie, Anbieter + Link, Vertragsnummer, Laufzeit,
  Verlängerung, Kündigungsfrist, Kosten, Abrechnungszyklus, Notizen
- **Fristlogik** (siehe [Wie die Kündigungsfrist berechnet wird](#wie-die-kündigungsfrist-berechnet-wird)):
  Frist in Tagen *oder* Monaten, Ausrichtung auf Monats-/Quartals-/Jahresende,
  automatischer Rollover auf die nächste erreichbare Periode, korrekte Behandlung von
  Verträgen ohne Verlängerung
- **Kündigungsprozess**: „Kündigung eingereicht am“ (das Vertragsende wird daraus automatisch
  berechnet), „Bestätigung des Anbieters erhalten am“ – fehlt die Bestätigung, erinnert das Dashboard daran
- **Kündigungsdatum frei editierbar** („Gekündigt zum“): Ein gekündigter, aber noch laufender
  Vertrag zählt bis zu diesem Datum ganz normal zu den aktuellen Kosten und fällt danach automatisch
  heraus – ganz ohne Cronjob. Das Datum darf auch in der Vergangenheit liegen, um bereits beendete
  Verträge nachträglich für die Kostenhistorie zu erfassen.
- **Kostenhistorie**: Preisänderungen mit Gültigkeitsdatum. Der aktuelle Preis wird immer aus der
  Historie abgeleitet – in der Zukunft datierte Änderungen werden am Stichtag von selbst wirksam,
  rückdatierte Einträge überschreiben keinen neueren Preis.
- **Dashboard**: aktuelle Monatskosten (mit Vergleich zum Vormonatsende), geschätzte Jahreskosten,
  Kostenverlauf nach Monat/Jahr mit klickbarem Drilldown je Vertrag, Kosten nach Kategorie,
  Kündigungs-Reminder, ausstehende Kündigungsbestätigungen, aktuelle Preiserhöhungen
- **Zahlungskalender** („Zahlungen“): wann welche Beträge tatsächlich abgebucht werden
  (monatlich/vierteljährlich/jährlich, mit optionalem Abbuchungsanker), 12-Monats-Ansicht mit Balkendiagramm
- **Preisentwicklung** („Preise“): Veränderung je Vertrag (gesamt und pro Jahr),
  Entwicklung der Fixkosten der letzten 12 Monate (nur Verträge, die zu beiden Zeitpunkten liefen),
  chronologische Liste aller Preisänderungen, Hinweis auf ein mögliches Sonderkündigungsrecht
  nach Preiserhöhungen (die App kann das rechtlich nicht beurteilen – bitte Vertrag prüfen)
- **Dokumente** je Vertrag: PDF, Bilder, TXT, DOCX (z. B. Vertrag, Kündigungsbestätigung, Rechnung)
- **Kategorienverwaltung**: Name, Farbe, Icon und Reihenfolge direkt in der App
- Suche & Filter (Name, Anbieter, Vertragsnummer, Notizen, Kategorie, Status), sortierbare Spalten
- **Monatsvergleich**: zwei Monate gegenüberstellen (neu, weggefallen, teurer, günstiger, je Kategorie)
- **CSV-Export**: Vertragsliste, Kostenhistorie, Jahresübersicht – wahlweise *periodisiert* oder als
  *tatsächliche Abbuchungen* (Excel-kompatibel: UTF-8, Semikolon, deutsches Zahlenformat)
- iCal-Export der Kündigungsfrist je Vertrag
- Einfacher Passwortschutz (kein Multi-User-System), CSRF-Schutz für alle Formulare
- Responsives Design im Apple-Stil (inkl. Dark Mode); Chart.js ist lokal mitgeliefert (kein CDN)

## Voraussetzungen

- PHP 8.1 oder neuer mit `pdo_mysql`, `mbstring` und `fileinfo`
- MySQL 5.7+ / MariaDB 10.3+
- Ein Webserver (Apache mit `mod_rewrite`/`mod_headers`, oder nginx)

## Installation

1. **Dateien hochladen** – kompletten Ordnerinhalt auf den Webspace/Server kopieren.

2. **Datenbank anlegen**
   ```sql
   CREATE DATABASE vertraege CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **`config.php` anpassen**
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` eintragen
     (alternativ per Umgebungsvariablen `VERTRAEGE_DB_HOST/NAME/USER/PASS`)
   - Eigenes Passwort setzen:
     ```bash
     php -r "echo password_hash('DeinNeuesPasswort', PASSWORD_DEFAULT);"
     ```
     Das Ergebnis in `APP_PASSWORD_HASH` eintragen (Standardpasswort ab Werk: `changeme123` – bitte ändern!)
   - Optional `DOCUMENT_DIR`: Speicherort der Dokumente, am besten **außerhalb des Webroots**

4. **Datenbankschema einspielen** – einer von zwei Wegen:
   ```bash
   php migrate.php                                   # empfohlen, auch für Updates
   # oder
   mysql --default-character-set=utf8mb4 -u USER -p vertraege < db.sql
   ```

5. **Aufrufen** – im Browser `login.php` bzw. die Domain/den Unterordner öffnen.

### Update einer bestehenden Installation

1. **Backup der Datenbank anlegen** (`mysqldump`).
2. Alle Dateien überschreiben (`config.php` behalten!).
3. `php migrate.php` ausführen – oder `migrate.php` im Browser öffnen (Login nötig).
   Solange Migrationen ausstehen, zeigt die App einen Hinweis statt abzustürzen.

Die Migrationen (`includes/migrations.php`) laufen je Version genau einmal; der Stand steht in der Tabelle
`schema_migrations`. Sie bereinigen auch Altlasten: doppelte Kostenhistorie-Einträge am selben Datum,
den überflüssigen Status „cancelled“ und defekte Kategorie-Icons (`u{…}`; ersetzt die frühere `migration_v2.sql`).

## Sicherheitshinweise

- Die App nutzt **ein** gemeinsames Passwort (kein Benutzerverwaltungssystem).
- Unbedingt **HTTPS** verwenden, sonst wird das Passwort im Klartext übertragen.
- Mitgelieferte `.htaccess`-Dateien sperren `db.sql`, `README.md`, `includes/`, `tests/` und `storage/`
  (Apache). Bei **nginx** muss das in der Server-Konfiguration nachgebaut werden:
  ```nginx
  location ~ ^/(config\.php|db\.sql|README\.md)$ { deny all; }
  location ~ ^/(includes|tests|storage)/ { deny all; }
  ```
- Alle schreibenden Formulare prüfen ein CSRF-Token, Abmelden geht nur per POST.
- Datenbankzugriffe laufen ausschließlich über Prepared Statements (PDO).
- Uploads: Dateityp wird anhand der Endung **und** des Dateiinhalts geprüft, gespeichert wird unter
  zufälligem Namen, ausgeliefert nur nach Login mit `X-Content-Type-Options: nosniff`.

## Ordnerstruktur

```
├── config.php               # DB-Zugang, Passwort-Hash, Schwellenwerte (anpassen!)
├── db.sql                   # Schema (aktueller Stand) für Neuinstallationen
├── migrate.php              # Migrationen einspielen (CLI oder Browser)
├── login.php / logout.php
├── index.php                # Dashboard
├── contracts.php            # Vertragsliste mit Suche/Filter/Sortierung
├── contract_edit.php        # Formular Neu/Bearbeiten
├── contract_save.php        # Validierung, Speichern, Kostenhistorie
├── contract_view.php        # Detailansicht (Fristen, Dokumente, Kostenhistorie)
├── contract_delete.php
├── cost_update.php / cost_history_delete.php
├── document_upload.php / document_download.php / document_delete.php
├── categories.php / category_save.php / category_delete.php
├── payments.php             # Zahlungskalender
├── price_changes.php        # Preisentwicklung
├── compare.php              # Monatsvergleich
├── month_breakdown.php / year_breakdown.php   # JSON für den Drilldown im Dashboard
├── export*.php              # CSV-Exporte
├── ical.php                 # .ics-Export der Kündigungsfrist
├── includes/
│   ├── domain.php           # reine Logik: Fristen, Preise, Kosten, Zahlungen (ohne DB, testbar)
│   ├── functions.php        # DB-Abfragen und Darstellungs-Helfer
│   ├── db.php               # PDO, Schema-Prüfung, Preis-Synchronisierung
│   ├── migrations.php       # versionierte Schema-Änderungen
│   ├── documents.php        # Upload/Speicherung der Dokumente
│   ├── auth.php             # Login-Session, CSRF
│   ├── bootstrap.php        # gemeinsamer Einstieg aller Seiten
│   └── header.php / footer.php
├── tests/
│   ├── run.php              # Unit-Tests der Domain-Logik (php tests/run.php)
│   └── schema_check.sh      # prüft: db.sql == Migrationen
├── storage/documents/       # hochgeladene Dokumente (nicht im Git)
└── assets/                  # CSS, JS, lokal mitgeliefertes Chart.js
```

## Wie die Kündigungsfrist berechnet wird

Die Berechnung steckt in `includes/domain.php` (`find_next_termination()`).

**Laufzeitende einer Periode.** Eine Frist von N Monaten ab dem Vertragsbeginn endet am Tag vor dem
Jahrestag: Beginn 01.01.2025 + 12 Monate → letzter Vertragstag 31.12.2025. Fehlt der Starttag im Zielmonat
(Beginn 31.08. + 6 Monate), gilt der Monatsletzte (28.02.). Die Zeitpunkte werden immer vom Vertragsbeginn aus
berechnet, damit sich Monatsenden nicht aufsummieren. Mit **„Vertragsende zum Monats-/Quartals-/Jahresende“**
wird das Laufzeitende auf das jeweilige Ende aufgerundet.

**Kündigungsfrist.** *N Tage* werden vom Laufzeitende abgezogen (30 Tage vor 31.12. → 01.12.). Bei *N Monaten*
wird um Kalendermonate zurückgerechnet; liegt das Laufzeitende auf einem Monatsende, gilt das auch für die Frist
(3 Monate zum 31.12. → 30.09.). Das ist eine praxisnahe Näherung und keine Rechtsberatung – bei knappen Fristen
lieber früher kündigen.

**Zustände** (Spalte „Kündbar“):

| Zustand | Bedeutung |
|---|---|
| Datum (Ampel) | Vertrag verlängert sich automatisch. Angezeigt wird die nächste Periode, die noch fristgerecht kündbar ist. Ist die Frist der aktuellen Periode schon verstrichen, verlängert sich der Vertrag – das steht in der Detailansicht („Frist … verstrichen, nächstmöglich zum …“); es gibt kein dauerhaft rotes „Frist verpasst“ mehr. |
| `jederzeit kündbar` | Keine Verlängerung hinterlegt und Erstlaufzeit vorbei: Der Vertrag läuft unbefristet. Die Detailansicht nennt das Vertragsende, falls heute gekündigt wird. Einen Dauer-Reminder gibt es dafür nicht. |
| `endet <Datum>` | Keine Verlängerung, Erstlaufzeit läuft noch: Der Vertrag endet von selbst, es ist keine Kündigung nötig. |

**Kündigung eingereicht.** Trägst du „Kündigung eingereicht am“ ein und lässt „Gekündigt zum“ leer, berechnet die App
das Vertragsende (nächste Periode, deren Frist zu diesem Datum noch nicht verstrichen war). Der Wert lässt sich
jederzeit überschreiben. Ohne eingetragene Bestätigung erinnert das Dashboard nach
`CANCELLATION_CONFIRMATION_DAYS` Tagen (Standard: 14).

## Kosten, Kostenhistorie und Dashboard-Berechnung

- **Preis zu einem Datum** = letzter Historieneintrag mit `valid_from` ≤ Datum. Gibt es keinen
  (z. B. weil der Vertragsbeginn nachträglich vordatiert wurde), dient der älteste bekannte Preis als Näherung.
- **Aktueller Preis** (`contracts.current_cost`) ist nur ein Zwischenspeicher und wird bei jedem Aufruf aus der Historie
  abgeleitet. Je Vertrag und Datum gibt es höchstens einen Eintrag (neue Eingabe am selben Datum aktualisiert ihn).
- **Monatliche Kosten (Kachel)**: Summe aller heute laufenden, nicht pausierten Verträge (Beginn erreicht, noch nicht
  beendet) zum heute gültigen Preis; die Differenz bezieht sich auf dieselbe Rechnung zum **Ende des Vormonats** – Kachel und
  Differenz passen daher immer zusammen.
- **Kostenverlauf, Drilldown und Jahresübersicht** sind *periodisiert*: Jahres- und Quartalsbeiträge sind auf Monate
  verteilt, jeder angefangene Vertragsmonat zählt voll, es gilt der am Monatsende gültige Preis. Im laufenden Jahr zählen nur die
  bereits angefangenen Monate (keine Hochrechnung), das Diagramm kennzeichnet das („2026 (10 Mon.)“).
  Pausierte Verträge zählen für vergangene Monate mit (der Pausenstatus ist nicht historisiert), ab dem laufenden Monat nicht mehr.
- **Tatsächliche Abbuchungen** zeigt der Zahlungskalender; die Termine ergeben sich aus dem Abbuchungsanker des
  Vertrags (leer = Vertragsbeginn) im Rhythmus des Abrechnungszyklus. Der CSV-Export der Jahresübersicht bietet beide Sichten an.

## Tests

```bash
php tests/run.php            # Unit-Tests der Domain-Logik, keine Datenbank nötig
tests/schema_check.sh        # prüft, dass db.sql und Migrationen dasselbe Schema erzeugen
```

`.github/workflows/ci.yml` führt Syntax-Check, Unit-Tests und Schema-Check bei jedem Push aus.

## Troubleshooting

**„Nach einem Update funktioniert eine neue Funktion nicht“**

1. *Browser-Cache*: `style.css` und `dashboard.js` werden mit Änderungszeit als Cache-Buster ausgeliefert;
   zur Sicherheit trotzdem einmal Hard-Reload (Strg+F5 / Cmd+Shift+R).
2. *PHP-OPcache*: Bei `opcache.validate_timestamps=0` liefert PHP nach einem Update weiter die alte Version aus –
   PHP-FPM/Apache neu starten oder `opcache_reset()` aufrufen.
3. *Datenbank nicht migriert*: Die App meldet das ausdrücklich; dann `php migrate.php` ausführen.

**Upload schlägt fehl:** `upload_max_filesize` und `post_max_size` in der `php.ini` müssen größer als
`DOCUMENT_MAX_BYTES` (Standard 10 MB) sein, und der Webserver-Benutzer braucht Schreibrechte auf `DOCUMENT_DIR`.

## Mögliche Erweiterungen

- Aktive Erinnerungen per Cronjob (E-Mail/ntfy/Telegram) statt nur im Dashboard
- Backup-Export und CSV-Import (Ersterfassung)
- Abonnierbarer iCal-Feed über alle Fristen (mit Token)
- Sicherheits-Härtung: IP-basiertes Login-Throttling, Security-Header, `secure`-Cookie, Session-Timeout
- Mehrbenutzerfähigkeit (z. B. Haushalte mit Kostenanteilen)
