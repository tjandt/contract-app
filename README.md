# Vertragsverwaltung

Eine schlanke PHP/MySQL-Webanwendung zur Verwaltung privater Verträge
(Strom, Handy, Versicherungen, Streaming, …) mit Kostenhistorie,
Kündigungs-Remindern und einem Dashboard mit Monats-/Jahresvergleich.

Reines PHP (keine Frameworks/Composer nötig), MySQL/MariaDB als Backend,
Chart.js für die Diagramme im Frontend.

## Features

- Stammdatenverwaltung: Name, Kategorie, Anbieter + Link, Vertragsnummer,
  Laufzeit, Verlängerung, Kündigungsfrist, Kosten, Notizen
- Automatische Berechnung der nächsten Kündigungsmöglichkeit
  (berücksichtigt automatische Vertragsverlängerungen)
- **Kündigungsdatum frei editierbar** ("Gekündigt zum"): Ein bereits gekündigter,
  aber noch laufender Vertrag fließt bis zu diesem Datum ganz normal in die
  aktuellen Kosten ein und fällt danach automatisch heraus – ganz ohne Cronjob,
  rein auf Basis des hinterlegten Datums beim nächsten Seitenaufruf. Das Datum
  kann auch in der Vergangenheit liegen, um bereits beendete Verträge
  nachträglich für die Kostenhistorie zu erfassen.
- Kündigungs-Reminder direkt im Dashboard und in der Vertragsliste
  (Ampel-Logik: ok / bald / dringend / Frist verpasst) – nur für Verträge,
  die noch nicht gekündigt wurden
- Kostenhistorie: Preiserhöhungen werden automatisch protokolliert –
  sowohl beim Bearbeiten eines Vertrags als auch über die eigene
  "Kostenänderung erfassen"-Box in der Vertragsdetailansicht
- Dashboard mit:
  - aktuellen monatlichen Gesamtkosten (inkl. gekündigter, aber noch
    laufender Verträge) inkl. Veränderung zum Vormonat
  - geschätzten Jahreskosten (Hochrechnung aus aktuell laufenden Verträgen)
  - Monats- und Jahresvergleich als Diagramm (umschaltbar)
  - **Klickbarer Monats- und Jahres-Drilldown**: Klick auf einen Monat bzw.
    ein Jahr im Kostenverlauf zeigt die Kostenaufstellung je Vertrag für
    genau diesen Zeitraum (inkl. damals gültigem Preis aus der Kostenhistorie)
  - Kostenverteilung nach Kategorie (Donut-Chart)
- Suche & Filter (Name, Anbieter, Vertragsnummer, Kategorie, Status:
  laufend / pausiert / beendet / alle)
- **Sortierbare Tabellenspalten** in der Vertragsliste (Vertrag, Kategorie,
  Kosten, Kündbar – per Klick auf die Spaltenüberschrift, inkl. Auf-/Absteigend)
- **Monatsvergleich** (eigene Seite "Vergleich"): zwei frei wählbare Monate
  gegenüberstellen – zeigt neu hinzugekommene und weggefallene Verträge,
  Preisänderungen (mit Alt-/Neu-Betrag), unveränderte Kosten sowie einen
  Vergleich auf Kategorie-Ebene, jeweils auf Basis der zum jeweiligen
  Zeitpunkt gültigen Preise aus der Kostenhistorie
- **CSV-Export** (eigene Seite "Export"):
  - Vertragsliste mit allen Stammdaten
  - komplette Kostenhistorie (alle Preisänderungen chronologisch)
  - Jahresübersicht je Vertrag für ein einzelnes oder alle Jahre –
    praktisch für Steuerunterlagen (Excel-kompatibel: UTF-8, Semikolon-
    getrennt, deutsches Zahlenformat)
- Optionaler iCal-Export (.ics) der Kündigungsfrist eines Vertrags
- Einfacher Passwortschutz (kein Multi-User-System)
- Responsives, reduziertes Design im Apple-Stil (inkl. Dark Mode)
- Chart.js ist **lokal mitgeliefert** (`assets/js/vendor/chart.umd.min.js`) –
  keine Abhängigkeit von einem externen CDN, läuft also auch auf Servern
  ohne (oder mit eingeschränktem) ausgehendem Internetzugriff

## Voraussetzungen

- PHP 8.1 oder neuer mit der `pdo_mysql`-Extension
- MySQL 5.7+ / MariaDB 10.3+
- Ein Webserver (Apache mit `mod_rewrite`/`mod_headers`, oder nginx)

## Installation

> **Bereits installiert?** Falls du eine ältere Version dieser App bereits
> laufen hast und die Kategorie-Icons als kryptischer Text wie `u{26A1}`
> statt als Emoji angezeigt werden, reicht es, `migration_v2.sql` einzuspielen:
> ```bash
> mysql -u DEIN_USER -p DEINE_DB < migration_v2.sql
> ```
> Anschließend alle PHP-Dateien sowie den neuen Ordner `assets/js/vendor/`
> auf den Server kopieren (überschreibt die alten Dateien) – kein weiterer
> Schritt nötig, die Datenbankstruktur selbst hat sich nicht geändert.

1. **Dateien hochladen**
   Kompletten Ordnerinhalt auf den Webspace/Server kopieren.

2. **Datenbank anlegen**
   ```sql
   CREATE DATABASE vertraege CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Danach das Schema importieren:
   ```bash
   mysql -u DEIN_USER -p vertraege < db.sql
   ```

3. **`config.php` anpassen**
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` eintragen
   - Eigenes Passwort für den Login setzen:
     ```bash
     php -r "echo password_hash('DeinNeuesPasswort', PASSWORD_DEFAULT);"
     ```
     Das Ergebnis in `APP_PASSWORD_HASH` eintragen.
     (Das Standardpasswort ab Werk ist `changeme123` – bitte unbedingt ändern!)

4. **Aufrufen**
   Im Browser die `login.php` bzw. direkt die Domain/den Unterordner öffnen.

## Sicherheitshinweise

- Die App nutzt **ein** gemeinsames Passwort (kein Benutzerverwaltungssystem),
  wie gewünscht als einfache Absicherung.
- Unbedingt **HTTPS** verwenden, da das Passwort sonst im Klartext übertragen wird.
- `config.php` und `db.sql` sind per `.htaccess` vor direktem Web-Zugriff
  geschützt (bei Apache). Bei nginx muss das äquivalent in der Server-Konfiguration
  ergänzt werden (siehe unten).
- Es werden ausschließlich vorbereitete Statements (PDO) verwendet – Schutz
  vor SQL-Injection ist damit durchgängig gegeben.

### nginx-Äquivalent zum .htaccess-Schutz

```nginx
location ~ ^/(config\.php|db\.sql)$ {
    deny all;
}
location /includes/ {
    deny all;
}
```

## Ordnerstruktur

```
├── config.php              # DB-Zugang & Passwort-Hash (anpassen!)
├── db.sql                  # Datenbankschema + Standardkategorien
├── migration_v2.sql        # Für bestehende Installationen: behebt Icon-Bug
├── login.php / logout.php  # Zugangsschutz
├── index.php                # Dashboard
├── contracts.php            # Vertragsliste mit Suche/Filter
├── contract_edit.php        # Formular Neu/Bearbeiten (inkl. Kündigungsdatum)
├── contract_save.php        # Speichert Verträge, protokolliert Preisänderungen
├── contract_view.php        # Detailansicht inkl. Kostenhistorie
├── cost_update.php          # Schnellerfassung von Kostenänderungen
├── cost_history_delete.php  # Löscht einen einzelnen Kostenhistorie-Eintrag
├── contract_delete.php      # Löschen
├── compare.php              # Monatsvergleich (zwei frei wählbare Monate)
├── month_breakdown.php      # JSON-Endpoint für den Monats-Drilldown im Dashboard
├── year_breakdown.php       # JSON-Endpoint für den Jahres-Drilldown im Dashboard
├── export.php               # Export-Übersichtsseite
├── export_contracts.php     # CSV-Export: Vertragsliste
├── export_history.php       # CSV-Export: Kostenhistorie
├── export_yearly.php        # CSV-Export: Jahresübersicht je Vertrag (Steuerunterlagen)
├── ical.php                  # Optionaler .ics-Export der Kündigungsfrist
├── includes/
│   ├── auth.php              # Session-Login
│   ├── functions.php         # Berechnungen (Laufzeit, Kündigung, Statistik)
│   ├── header.php / footer.php
│   └── .htaccess             # Blockiert direkten Zugriff
└── assets/
    ├── css/style.css
    └── js/
        ├── dashboard.js        # Chart.js-Rendering
        └── vendor/chart.umd.min.js   # lokal mitgeliefertes Chart.js (kein CDN nötig)
```

## Wie die Kündigungsfrist berechnet wird

Für jeden noch nicht gekündigten, aktiven Vertrag wird aus `start_date` und
`initial_term_months` das Ende der Erstlaufzeit berechnet. Ist eine
automatische Verlängerung (`renewal_term_months > 0`) hinterlegt, wird in
Schritten dieser Länge weitergerechnet, bis die aktuell laufende Periode
gefunden ist. Von deren Ende wird die `notice_period_days` (Kündigungsfrist)
abgezogen – das Ergebnis ist das Datum, bis zu dem spätestens gekündigt
werden muss. Verträge ohne Verlängerung (`renewal_term_months = 0`) gelten
nach Ablauf der Erstlaufzeit als jederzeit kündbar.

Sobald im Vertrag ein Kündigungsdatum (`cancelled_at`) hinterlegt ist,
entfällt diese Berechnung – der Reminder ist dann nicht mehr nötig, da
bereits gekündigt wurde.

## Das Feld "Gekündigt zum"

Dieses Datum ist komplett frei editierbar und unabhängig vom Tagesdatum:

- **Leer** → Vertrag läuft normal, Kündigungsfrist wird berechnet und im
  Dashboard als Reminder angezeigt.
- **In der Zukunft** → Vertrag gilt als gekündigt, zählt aber bis zu diesem
  Datum ganz normal zu den aktuellen monatlichen Kosten. Danach verschwindet
  er automatisch aus den aktuellen Kosten und der Vertragsliste (Filter
  "Laufend") – ganz ohne Cronjob, die Berechnung erfolgt bei jedem Aufruf
  neu auf Basis des aktuellen Datums.
- **In der Vergangenheit** → ideal, um bereits beendete/historische Verträge
  nachträglich zu erfassen (z. B. für eine vollständige Kostenhistorie über
  mehrere Jahre). Der Vertrag taucht nicht mehr in den aktuellen Kosten auf,
  fließt aber weiterhin korrekt in den historischen Kostenverlauf
  (Monats-/Jahresvergleich) für die Monate ein, in denen er tatsächlich lief.

Der Status "Pausiert" ist davon unabhängig und eignet sich für Verträge,
die vorübergehend ruhen (z. B. pausierte Fitnessstudio-Mitgliedschaft).

## Kostenhistorie & Dashboard-Berechnung

Jeder Vertrag erhält beim Anlegen automatisch einen ersten Eintrag in
`cost_history`. Jede spätere Preisänderung (ob über das Bearbeiten-Formular
oder die "Kostenänderung erfassen"-Box) wird zusätzlich mit Datum und
optionalem Grund gespeichert. Das Dashboard rekonstruiert daraus für
jeden Monat der letzten 24 Monate den zu diesem Zeitpunkt gültigen Preis
je Vertrag (nur für damals bereits existierende, noch nicht gekündigte
Verträge) und summiert sie – daraus ergeben sich Monats- und
Jahresvergleich. Die "geschätzten Jahreskosten" sind dagegen eine reine
Hochrechnung der aktuellen monatlichen Kosten × 12.

## Troubleshooting: "Ein Vertrag fehlt im Kostenverlauf/Monats-Drilldown, obwohl er damals aktiv war"

Die Monats-/Jahresauswertung sucht für jeden Monat den zu diesem Zeitpunkt
gültigen Preis aus der Kostenhistorie (den jeweils letzten Eintrag mit
`valid_from` ≤ Monatsende). Das kann zu Lücken führen, wenn das
Vertragsbeginn-Datum eines Vertrags nachträglich geändert wurde, der
automatisch angelegte "Vertragsbeginn"-Eintrag in der Kostenhistorie aber
noch auf dem alten (späteren) Datum stand – dann gab es für frühere Monate
schlicht keinen Preis-Eintrag, und der Vertrag wurde für diese Monate
komplett ausgelassen.

Das ist jetzt auf zwei Ebenen behoben:

1. **Vorsorge beim Bearbeiten**: Änderst du jetzt das Vertragsbeginn-Datum
   eines bestehenden Vertrags, wird der zugehörige "Vertragsbeginn"-Eintrag
   in der Kostenhistorie automatisch mit angepasst.
2. **Robuster Fallback bei der Auswertung**: Findet die Monats-/Jahresaus-
   wertung für einen Monat keinen passenden Preis-Eintrag (z. B. bei älteren,
   schon vorher inkonsistenten Daten), wird jetzt der älteste bekannte Preis
   als Näherung verwendet, statt den Vertrag für diesen Monat stillschweigend
   wegzulassen.
3. **Kostenhistorie-Einträge sind jetzt löschbar** (✕-Button neben jedem
   Eintrag in der Vertragsdetailansicht) – damit lassen sich fehlerhafte
   Alteinträge direkt in der App bereinigen, ohne die Datenbank anzufassen.

## Troubleshooting: "Nach einem Update funktioniert eine neue Funktion nicht"

Die Logik selbst (Sortierung, Diagramm-Klicks etc.) wurde isoliert getestet
und funktioniert korrekt. Wenn eine neue Funktion nach dem Hochladen trotzdem
nicht reagiert, liegt es fast immer an einer der folgenden zwei Ursachen:

1. **Browser-Cache von `style.css` / `dashboard.js`**: Diese Dateien werden
   jetzt automatisch mit einem Cache-Busting-Parameter ausgeliefert
   (`assets/js/dashboard.js?v=<Änderungszeit-der-Datei>`), sodass der Browser
   nach jedem Deployment zuverlässig die neue Version lädt. Zur Sicherheit
   trotzdem einmal mit **Hard-Reload** testen (Strg+F5 bzw. Cmd+Shift+R), um
   alte Browser-Caches endgültig auszuschließen.

2. **PHP-OPcache auf dem Server**: Falls auf dem Server OPcache aktiv ist
   und `opcache.validate_timestamps=0` gesetzt ist, liefert PHP nach einem
   Datei-Update weiterhin die alte, zwischengespeicherte Version aus – auch
   wenn die neue `.php`-Datei längst hochgeladen wurde. Abhilfe: PHP-FPM/
   Apache neu starten, oder `opcache_reset()` aufrufen, oder in der
   `php.ini` `opcache.validate_timestamps=1` setzen.

**Gezielt testen:**
- Sortierung: direkt `contracts.php?sort=cost&dir=desc` in der Adresszeile
  aufrufen. Ändert sich die Reihenfolge? Falls ja, liegt es nur an den
  Klick-Links/Cache, nicht an der Sortierlogik selbst.
- Monats-/Jahres-Klick: Browser-Entwicklertools öffnen (F12) → Tab „Konsole“
  beim Klick auf einen Monat/ein Jahr prüfen (JS-Fehler?) sowie Tab
  „Netzwerk“ prüfen, ob ein Request an `month_breakdown.php` bzw.
  `year_breakdown.php` auftaucht und was er zurückgibt.

## Mögliche Erweiterungen für später

Falls gewünscht, ließe sich die App später leicht ergänzen um:
- Mehrbenutzerfähigkeit (z. B. für Familien mit gemeinsamer Haushaltskasse)
- CSV-Export der Vertragsliste
- Eigene Wunsch-Reminder-Schwellen pro Vertrag statt globaler Konstanten
- "Kündigung eingereicht"-Status mit separatem Bestätigungsdatum
