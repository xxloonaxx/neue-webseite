# BudgetPlaner Webseite mit PHP & MySQL

Eine deutschsprachige Budgetplanungs-Webseite mit Tabellen-Workflow, Monats-Tabs, Suche, Filtern, Auswertungen, Kategorie-Limits und CSV-Import/-Export. Die Daten werden jetzt zentral in MySQL gespeichert und über eine schlanke PHP-API geladen.

## Funktionen

- Eigene Tabs für beliebig viele Monate anlegen und löschen.
- Einnahmen und Ausgaben als Tabellenzeilen erfassen, bearbeiten, duplizieren und entfernen.
- Suche über Beschreibung, Kategorie, Status und Notizen.
- Filter nach Typ und Kategorie sowie Sortierung nach Datum oder Betrag.
- Automatische Summen für Einnahmen, Ausgaben, verfügbares Budget und Sparquote.
- Ausgaben-Auswertung nach Kategorien mit Balkenvisualisierung.
- Kategorie-Limits pro Monat inklusive Fortschrittsbalken und Warnfarbe bei Überschreitung.
- Sparziel-Rechner pro Monat.
- Wiederkehrende Buchungen aus dem vorherigen Monat übernehmen.
- CSV-Export und CSV-Import für den aktiven Monat.
- Persistente Speicherung in MySQL statt nur im Browser.

## Voraussetzungen

- PHP 8.1 oder neuer mit PDO-MySQL-Erweiterung.
- MySQL oder MariaDB.

## MySQL vorbereiten

```sql
CREATE DATABASE budget_planner CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'budget_user'@'localhost' IDENTIFIED BY 'budget_password';
GRANT ALL PRIVILEGES ON budget_planner.* TO 'budget_user'@'localhost';
FLUSH PRIVILEGES;
```

Die Zugangsdaten können in `config.php` angepasst oder per Umgebungsvariablen gesetzt werden:

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=budget_planner
export DB_USER=budget_user
export DB_PASSWORD=budget_password
```

## Datenbanktabellen erstellen

Die App legt Tabellen beim ersten API-Aufruf automatisch an. Alternativ kann die Migration manuell gestartet werden:

```bash
php migrate.php
```

Das SQL-Schema liegt zusätzlich in `schema.sql`.

## Lokal starten

```bash
php -S 127.0.0.1:8000
```

Danach die Seite unter `http://127.0.0.1:8000/index.php` öffnen.

## Zusätzliche Funktionen

- `index.php` rendert Serverdatum in der Hero-Sektion.
- Filter zurücksetzen per Klick.
- JSON-Export des aktiven Monats.
- Schnellvorlagen für Miete und Gehalt im Eingabeformular.
- Jahresübersicht über alle Monats-Tabs (Einnahmen/Ausgaben/Saldo).
