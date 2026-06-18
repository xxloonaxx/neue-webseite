<?php
declare(strict_types=1);
$today = (new DateTimeImmutable("now", new DateTimeZone("UTC")))->format("d.m.Y");
?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Budgetplanung mit PHP</title>
    <link rel="stylesheet" href="styles.css" />
  </head>
  <body>
    <header class="hero">
      <nav class="topbar" aria-label="Hauptnavigation">
        <div class="brand">
          <span class="brand__icon" aria-hidden="true">€</span>
          <span>BudgetPlaner PHP</span>
        </div>
        <a class="topbar__link" href="#budget-app">Zur Tabelle</a>
      </nav>
      <section class="hero__content">
        <p class="eyebrow">Monatsbudget · Einnahmen · Ausgaben · Ziele · PHP Backend</p>
        <h1>Budgetplanung wie eine smarte Tabelle.</h1>
        <p>
          Plane jeden Monat in einem eigenen Tab, durchsuche Buchungen, filtere Kategorien,
          erstelle wiederkehrende Zeilen und behalte Sparziele, Fixkosten und freien Spielraum im Blick.
        </p>
        <p class="muted hero-meta">Serverdatum (UTC): <?= htmlspecialchars($today, ENT_QUOTES, "UTF-8") ?></p>
        <div class="hero__actions">
          <a class="button button--primary" href="#budget-app">Budget starten</a>
          <button class="button button--ghost" id="syncButton" type="button">Mit MySQL synchronisieren</button>
        </div>
      </section>
    </header>

    <main id="budget-app" class="app-shell">
      <section class="panel month-manager" aria-labelledby="month-title">
        <div>
          <p class="eyebrow">Monats-Tabs</p>
          <h2 id="month-title">Monate verwalten</h2>
        </div>
        <div class="month-tabs" id="monthTabs" role="tablist" aria-label="Budgetmonate"></div>
        <form class="inline-form" id="monthForm">
          <input id="monthName" name="monthName" type="text" placeholder="z. B. Januar 2026" required />
          <button class="button button--primary" type="submit">+ Neuer Tab</button>
          <span id="backendStatus" class="sync-status">MySQL wird geladen …</span>
        </form>
      </section>

      <section class="summary-grid" aria-label="Budgetübersicht">
        <article class="summary-card income">
          <span>Einnahmen</span>
          <strong id="totalIncome">0,00 €</strong>
        </article>
        <article class="summary-card expense">
          <span>Ausgaben</span>
          <strong id="totalExpense">0,00 €</strong>
        </article>
        <article class="summary-card balance">
          <span>Verfügbar</span>
          <strong id="totalBalance">0,00 €</strong>
        </article>
        <article class="summary-card saving">
          <span>Sparquote</span>
          <strong id="savingRate">0 %</strong>
        </article>
      </section>

      <section class="panel toolbar" aria-label="Tabellenwerkzeuge">
        <div class="search-box">
          <label for="searchInput">Suche</label>
          <input id="searchInput" type="search" placeholder="Name, Notiz oder Kategorie suchen …" />
        </div>
        <div>
          <label for="typeFilter">Typ</label>
          <select id="typeFilter">
            <option value="all">Alle</option>
            <option value="income">Einnahmen</option>
            <option value="expense">Ausgaben</option>
          </select>
        </div>
        <div>
          <label for="categoryFilter">Kategorie</label>
          <select id="categoryFilter">
            <option value="all">Alle Kategorien</option>
          </select>
        </div>
        <div>
          <label for="sortSelect">Sortierung</label>
          <select id="sortSelect">
            <option value="dateAsc">Datum aufsteigend</option>
            <option value="dateDesc">Datum absteigend</option>
            <option value="amountDesc">Betrag hoch</option>
            <option value="amountAsc">Betrag niedrig</option>
          </select>
        </div>
        <div class="toolbar__actions">
          <button class="button button--ghost" id="resetFiltersButton" type="button">Filter zurücksetzen</button>
          <button class="button button--ghost" id="exportJsonButton" type="button">JSON Export</button>
          <button class="button button--ghost" id="exportButton" type="button">CSV Export</button>
          <label class="button button--ghost file-button" for="importInput">CSV Import</label>
          <input id="importInput" type="file" accept=".csv,text/csv" hidden />
        </div>
      </section>

      <section class="panel entry-panel" aria-labelledby="entry-title">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Neue Tabellenzeile</p>
            <h2 id="entry-title">Buchung hinzufügen</h2>
          </div>
          <div class="section-actions">
            <button class="button button--ghost" id="copyRecurringButton" type="button">Fixkosten übernehmen</button>
            <button class="button button--danger" id="deleteMonthButton" type="button">Aktuellen Monat löschen</button>
          </div>
        </div>
        <form class="entry-form" id="entryForm">
          <label>
            Typ
            <select id="entryType" required>
              <option value="expense">Ausgabe</option>
              <option value="income">Einnahme</option>
            </select>
          </label>
          <label>
            Datum
            <input id="entryDate" type="date" required />
          </label>
          <label>
            Beschreibung
            <input id="entryName" type="text" placeholder="z. B. Miete, Gehalt" required />
          </label>
          <label>
            Kategorie
            <input id="entryCategory" list="categoryOptions" type="text" placeholder="Wohnen" required />
            <datalist id="categoryOptions"></datalist>
          </label>
          <label>
            Betrag
            <input id="entryAmount" type="number" step="0.01" min="0" placeholder="0.00" required />
          </label>
          <label>
            Status
            <select id="entryStatus">
              <option value="planned">Geplant</option>
              <option value="paid">Bezahlt</option>
              <option value="recurring">Wiederkehrend</option>
            </select>
          </label>
          <label class="entry-form__wide">
            Notiz
            <input id="entryNote" type="text" placeholder="Optional: Vertrag, Ziel, Erinnerung …" />
          </label>
          <button class="button button--primary" type="submit">Zeile speichern</button>
          <button class="button button--ghost" id="templateRent" type="button">Vorlage: Miete</button>
          <button class="button button--ghost" id="templateSalary" type="button">Vorlage: Gehalt</button>
        </form>
      </section>

      <section class="panel table-panel" aria-labelledby="table-title">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Budget-Tabelle</p>
            <h2 id="table-title">Aktueller Monat</h2>
          </div>
          <p id="resultCount" class="muted">0 Einträge</p>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Typ</th>
                <th>Datum</th>
                <th>Beschreibung</th>
                <th>Kategorie</th>
                <th>Status</th>
                <th>Notiz</th>
                <th>Betrag</th>
                <th>Aktionen</th>
              </tr>
            </thead>
            <tbody id="budgetTableBody"></tbody>
          </table>
        </div>
        <p id="emptyState" class="empty-state">Noch keine Einträge. Füge oben deine erste Zeile hinzu.</p>
      </section>

      <section class="panel" aria-labelledby="yearly-title">
        <div class="section-heading">
          <div>
            <p class="eyebrow">Extra Funktion</p>
            <h2 id="yearly-title">Jahresübersicht (alle Monate)</h2>
          </div>
          <p class="muted" id="yearlyHint">Summiert alle vorhandenen Monatstabellen.</p>
        </div>
        <div class="summary-grid" id="yearlySummary">
          <article class="summary-card income"><span>Jahres-Einnahmen</span><strong id="yearIncome">0,00 €</strong></article>
          <article class="summary-card expense"><span>Jahres-Ausgaben</span><strong id="yearExpense">0,00 €</strong></article>
          <article class="summary-card balance"><span>Jahres-Saldo</span><strong id="yearBalance">0,00 €</strong></article>
          <article class="summary-card saving"><span>Monate</span><strong id="yearMonths">0</strong></article>
        </div>
      </section>

      <section class="insights-grid">
        <article class="panel">
          <p class="eyebrow">Kategorien</p>
          <h2>Ausgaben nach Kategorie</h2>
          <div id="categoryBreakdown" class="breakdown"></div>
        </article>
        <article class="panel">
          <p class="eyebrow">Budgets</p>
          <h2>Kategorie-Limits</h2>
          <form class="budget-form" id="budgetForm">
            <input id="budgetCategory" list="categoryOptions" type="text" placeholder="Kategorie" required />
            <input id="budgetLimit" type="number" min="0" step="10" placeholder="Limit €" required />
            <button class="button button--primary" type="submit">Limit speichern</button>
          </form>
          <div id="budgetList" class="budget-list"></div>
        </article>
        <article class="panel">
          <p class="eyebrow">Ziele</p>
          <h2>Sparziel-Rechner</h2>
          <label class="goal-input">
            Sparziel für den Monat
            <input id="goalInput" type="number" min="0" step="50" placeholder="z. B. 500" />
          </label>
          <div class="progress" aria-label="Fortschritt zum Sparziel">
            <span id="goalProgress"></span>
          </div>
          <p id="goalText" class="muted">Setze ein Ziel, um den Fortschritt zu sehen.</p>
        </article>
      </section>
    </main>

    <template id="rowTemplate">
      <tr>
        <td data-label="Typ"></td>
        <td data-label="Datum"></td>
        <td data-label="Beschreibung"></td>
        <td data-label="Kategorie"></td>
        <td data-label="Status"></td>
        <td data-label="Notiz"></td>
        <td data-label="Betrag"></td>
        <td data-label="Aktionen"></td>
      </tr>
    </template>

    <script>window.BUDGET_API_URL = "api.php";</script>
    <script src="script.js"></script>
  </body>
</html>
