const API_URL = window.BUDGET_API_URL || "api.php";
const currencyFormatter = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" });
const percentFormatter = new Intl.NumberFormat("de-DE", { maximumFractionDigits: 0 });

const defaultCategories = [
  "Gehalt",
  "Wohnen",
  "Lebensmittel",
  "Transport",
  "Versicherung",
  "Freizeit",
  "Sparen",
  "Gesundheit",
  "Sonstiges",
];

let state = {
  months: [],
  activeMonthId: null,
  entries: [],
  categoryBudgets: [],
};
let editingEntryId = null;

const elements = {
  monthTabs: document.querySelector("#monthTabs"),
  monthForm: document.querySelector("#monthForm"),
  monthName: document.querySelector("#monthName"),
  backendStatus: document.querySelector("#backendStatus"),
  tableTitle: document.querySelector("#table-title"),
  budgetTableBody: document.querySelector("#budgetTableBody"),
  emptyState: document.querySelector("#emptyState"),
  resultCount: document.querySelector("#resultCount"),
  totalIncome: document.querySelector("#totalIncome"),
  totalExpense: document.querySelector("#totalExpense"),
  totalBalance: document.querySelector("#totalBalance"),
  savingRate: document.querySelector("#savingRate"),
  searchInput: document.querySelector("#searchInput"),
  typeFilter: document.querySelector("#typeFilter"),
  categoryFilter: document.querySelector("#categoryFilter"),
  sortSelect: document.querySelector("#sortSelect"),
  entryForm: document.querySelector("#entryForm"),
  entryType: document.querySelector("#entryType"),
  entryDate: document.querySelector("#entryDate"),
  entryName: document.querySelector("#entryName"),
  entryCategory: document.querySelector("#entryCategory"),
  entryAmount: document.querySelector("#entryAmount"),
  entryStatus: document.querySelector("#entryStatus"),
  entryNote: document.querySelector("#entryNote"),
  categoryOptions: document.querySelector("#categoryOptions"),
  categoryBreakdown: document.querySelector("#categoryBreakdown"),
  goalInput: document.querySelector("#goalInput"),
  goalProgress: document.querySelector("#goalProgress"),
  goalText: document.querySelector("#goalText"),
  exportButton: document.querySelector("#exportButton"),
  exportJsonButton: document.querySelector("#exportJsonButton"),
  resetFiltersButton: document.querySelector("#resetFiltersButton"),
  importInput: document.querySelector("#importInput"),
  syncButton: document.querySelector("#syncButton"),
  deleteMonthButton: document.querySelector("#deleteMonthButton"),
  copyRecurringButton: document.querySelector("#copyRecurringButton"),
  budgetForm: document.querySelector("#budgetForm"),
  budgetCategory: document.querySelector("#budgetCategory"),
  budgetLimit: document.querySelector("#budgetLimit"),
  budgetList: document.querySelector("#budgetList"),
  templateRent: document.querySelector("#templateRent"),
  templateSalary: document.querySelector("#templateSalary"),
  yearIncome: document.querySelector("#yearIncome"),
  yearExpense: document.querySelector("#yearExpense"),
  yearBalance: document.querySelector("#yearBalance"),
  yearMonths: document.querySelector("#yearMonths"),
};

async function request(action, options = {}) {
  setStatus("Synchronisiere mit MySQL …", "loading");
  const url = new URL(API_URL, window.location.href);
  url.searchParams.set("action", action);
  Object.entries(options.query || {}).forEach(([key, value]) => url.searchParams.set(key, value));

  const response = await fetch(url, {
    method: options.method || "GET",
    headers: options.body instanceof FormData ? undefined : { "Content-Type": "application/json" },
    body: options.body instanceof FormData ? options.body : options.body ? JSON.stringify(options.body) : undefined,
  });

  const contentType = response.headers.get("Content-Type") || "";
  const data = contentType.includes("application/json") ? await response.json() : null;
  if (!response.ok || data?.ok === false) {
    throw new Error(data?.message || "Server-Anfrage fehlgeschlagen.");
  }

  setStatus("MySQL verbunden", "ok");
  return data;
}

async function loadApp(monthId = state.activeMonthId) {
  try {
    const query = monthId ? { monthId } : {};
    state = await request("init", { query });
    editingEntryId = null;
    resetEntryForm();
    render();
  } catch (error) {
    setStatus(error.message, "error");
    renderEmptyError(error.message);
  }
}

function setStatus(message, mode = "ok") {
  elements.backendStatus.textContent = message;
  elements.backendStatus.dataset.mode = mode;
}

function getActiveMonth() {
  return state.months.find((month) => Number(month.id) === Number(state.activeMonthId)) || state.months[0] || { name: "Kein Monat", goal: 0 };
}

function getFilteredEntries() {
  const query = elements.searchInput.value.trim().toLowerCase();
  const type = elements.typeFilter.value;
  const category = elements.categoryFilter.value;

  return [...state.entries]
    .filter((entry) => type === "all" || entry.type === type)
    .filter((entry) => category === "all" || entry.category === category)
    .filter((entry) => {
      if (!query) return true;
      return [entry.name, entry.category, entry.note, entry.status]
        .join(" ")
        .toLowerCase()
        .includes(query);
    })
    .sort(sortEntries);
}

function sortEntries(a, b) {
  const sortMode = elements.sortSelect.value;
  if (sortMode === "dateDesc") return b.date.localeCompare(a.date);
  if (sortMode === "amountDesc") return Number(b.amount) - Number(a.amount);
  if (sortMode === "amountAsc") return Number(a.amount) - Number(b.amount);
  return a.date.localeCompare(b.date);
}

function calculateTotals(entries) {
  const income = entries.filter((entry) => entry.type === "income").reduce((sum, entry) => sum + Number(entry.amount), 0);
  const expense = entries.filter((entry) => entry.type === "expense").reduce((sum, entry) => sum + Number(entry.amount), 0);
  const balance = income - expense;
  const savingRate = income > 0 ? Math.max(0, (balance / income) * 100) : 0;
  return { income, expense, balance, savingRate };
}

function render() {
  const month = getActiveMonth();
  const filteredEntries = getFilteredEntries();
  const totals = calculateTotals(state.entries);

  elements.tableTitle.textContent = month.name;
  elements.goalInput.value = Number(month.goal) || "";
  renderMonthTabs();
  renderCategoryInputs();
  renderTable(filteredEntries);
  renderSummary(totals);
  renderBreakdown(state.entries);
  renderBudgets(state.entries);
  renderGoal(totals.balance, month.goal);
  renderYearlySummary().catch(showError);
}

function renderEmptyError(message) {
  state = { months: [], activeMonthId: null, entries: [], categoryBudgets: [] };
  elements.tableTitle.textContent = "MySQL nicht erreichbar";
  elements.budgetTableBody.innerHTML = "";
  elements.emptyState.hidden = false;
  elements.emptyState.textContent = message;
  renderSummary({ income: 0, expense: 0, balance: 0, savingRate: 0 });
}

function renderMonthTabs() {
  elements.monthTabs.innerHTML = "";
  state.months.forEach((month) => {
    const button = document.createElement("button");
    button.className = "month-tab";
    button.type = "button";
    button.role = "tab";
    button.textContent = month.name;
    button.setAttribute("aria-selected", Number(month.id) === Number(state.activeMonthId) ? "true" : "false");
    button.addEventListener("click", () => loadApp(month.id));
    elements.monthTabs.append(button);
  });
}

function renderCategoryInputs() {
  const selectedCategory = elements.categoryFilter.value;
  const categories = [...new Set([...defaultCategories, ...state.entries.map((entry) => entry.category), ...state.categoryBudgets.map((budget) => budget.category)])].sort();

  elements.categoryFilter.innerHTML = '<option value="all">Alle Kategorien</option>';
  elements.categoryOptions.innerHTML = "";

  categories.forEach((category) => {
    const filterOption = document.createElement("option");
    filterOption.value = category;
    filterOption.textContent = category;
    elements.categoryFilter.append(filterOption);

    const dataOption = document.createElement("option");
    dataOption.value = category;
    elements.categoryOptions.append(dataOption);
  });

  elements.categoryFilter.value = categories.includes(selectedCategory) ? selectedCategory : "all";
}

function renderTable(entries) {
  elements.budgetTableBody.innerHTML = "";
  elements.emptyState.hidden = entries.length > 0;
  elements.emptyState.textContent = "Noch keine Einträge. Füge oben deine erste Zeile hinzu.";
  elements.resultCount.textContent = `${entries.length} ${entries.length === 1 ? "Eintrag" : "Einträge"}`;

  entries.forEach((entry) => {
    const row = document.querySelector("#rowTemplate").content.firstElementChild.cloneNode(true);
    row.children[0].innerHTML = `<span class="badge badge--${entry.type}">${entry.type === "income" ? "Einnahme" : "Ausgabe"}</span>`;
    row.children[1].textContent = formatDate(entry.date);
    row.children[2].textContent = entry.name;
    row.children[3].textContent = entry.category;
    row.children[4].innerHTML = `<span class="badge badge--${entry.status}">${translateStatus(entry.status)}</span>`;
    row.children[5].textContent = entry.note || "—";
    row.children[6].className = entry.type === "income" ? "amount--income" : "amount--expense";
    row.children[6].textContent = `${entry.type === "income" ? "+" : "-"}${currencyFormatter.format(Number(entry.amount))}`;
    row.children[7].innerHTML = `
      <div class="action-group">
        <button class="icon-button" type="button" title="Bearbeiten" data-action="edit" data-id="${entry.id}">✎</button>
        <button class="icon-button" type="button" title="Duplizieren" data-action="duplicate" data-id="${entry.id}">⧉</button>
        <button class="icon-button" type="button" title="Löschen" data-action="delete" data-id="${entry.id}">×</button>
      </div>
    `;
    elements.budgetTableBody.append(row);
  });
}

function renderSummary(totals) {
  elements.totalIncome.textContent = currencyFormatter.format(totals.income);
  elements.totalExpense.textContent = currencyFormatter.format(totals.expense);
  elements.totalBalance.textContent = currencyFormatter.format(totals.balance);
  elements.savingRate.textContent = `${percentFormatter.format(totals.savingRate)} %`;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function renderBreakdown(entries) {
  const expenses = entries.filter((entry) => entry.type === "expense");
  const total = expenses.reduce((sum, entry) => sum + Number(entry.amount), 0);
  const grouped = expenses.reduce((groups, entry) => {
    groups[entry.category] = (groups[entry.category] || 0) + Number(entry.amount);
    return groups;
  }, {});

  elements.categoryBreakdown.innerHTML = "";
  if (!expenses.length) {
    elements.categoryBreakdown.innerHTML = '<p class="muted">Keine Ausgaben vorhanden.</p>';
    return;
  }

  Object.entries(grouped)
    .sort(([, a], [, b]) => b - a)
    .forEach(([category, amount]) => {
      const percent = total > 0 ? (amount / total) * 100 : 0;
      const row = document.createElement("div");
      row.className = "breakdown__row";
      row.innerHTML = `
        <div class="breakdown__meta"><span>${escapeHtml(category)}</span><span>${currencyFormatter.format(amount)}</span></div>
        <div class="breakdown__bar"><span style="width: ${percent}%"></span></div>
      `;
      elements.categoryBreakdown.append(row);
    });
}

function renderBudgets(entries) {
  elements.budgetList.innerHTML = "";
  if (!state.categoryBudgets.length) {
    elements.budgetList.innerHTML = '<p class="muted">Noch keine Kategorie-Limits gespeichert.</p>';
    return;
  }

  state.categoryBudgets.forEach((budget) => {
    const spent = entries
      .filter((entry) => entry.type === "expense" && entry.category === budget.category)
      .reduce((sum, entry) => sum + Number(entry.amount), 0);
    const limit = Number(budget.limitAmount);
    const percent = limit > 0 ? Math.min(100, (spent / limit) * 100) : 0;
    const item = document.createElement("div");
    item.className = "budget-item";
    item.innerHTML = `
      <div class="breakdown__meta"><span>${escapeHtml(budget.category)}</span><span>${currencyFormatter.format(spent)} / ${currencyFormatter.format(limit)}</span></div>
      <div class="breakdown__bar"><span class="${spent > limit ? "over-limit" : ""}" style="width: ${percent}%"></span></div>
      <button class="link-button" type="button" data-budget-delete="${budget.id}">Limit löschen</button>
    `;
    elements.budgetList.append(item);
  });
}

function renderGoal(balance, goal) {
  const safeGoal = Number(goal) || 0;
  const progress = safeGoal > 0 ? Math.min(100, Math.max(0, (balance / safeGoal) * 100)) : 0;
  elements.goalProgress.style.width = `${progress}%`;

  if (!safeGoal) {
    elements.goalText.textContent = "Setze ein Ziel, um den Fortschritt zu sehen.";
    return;
  }

  const missing = Math.max(0, safeGoal - balance);
  elements.goalText.textContent = missing === 0
    ? `Ziel erreicht: ${currencyFormatter.format(balance)} verfügbar.`
    : `Noch ${currencyFormatter.format(missing)} bis zum Monatsziel.`;
}

function formatDate(dateValue) {
  if (!dateValue) return "—";
  return new Intl.DateTimeFormat("de-DE").format(new Date(`${dateValue}T00:00:00`));
}

function translateStatus(status) {
  return {
    paid: "Bezahlt",
    planned: "Geplant",
    recurring: "Wiederkehrend",
  }[status] || status;
}

function resetEntryForm() {
  elements.entryForm.reset();
  elements.entryDate.valueAsDate = new Date();
  elements.entryForm.querySelector('button[type="submit"]').textContent = "Zeile speichern";
  editingEntryId = null;
}

function entryPayload() {
  return {
    monthId: state.activeMonthId,
    type: elements.entryType.value,
    date: elements.entryDate.value,
    name: elements.entryName.value.trim(),
    category: elements.entryCategory.value.trim(),
    amount: Number(elements.entryAmount.value),
    status: elements.entryStatus.value,
    note: elements.entryNote.value.trim(),
  };
}

async function handleEntrySubmit(event) {
  event.preventDefault();
  const action = editingEntryId ? "update_entry" : "create_entry";
  const query = editingEntryId ? { entryId: editingEntryId } : {};
  state = await request(action, { method: editingEntryId ? "PUT" : "POST", query, body: entryPayload() });
  resetEntryForm();
  render();
}

async function handleTableAction(event) {
  const button = event.target.closest("button[data-action]");
  if (!button) return;

  const entry = state.entries.find((item) => Number(item.id) === Number(button.dataset.id));
  if (!entry) return;

  if (button.dataset.action === "edit") {
    editingEntryId = entry.id;
    elements.entryType.value = entry.type;
    elements.entryDate.value = entry.date;
    elements.entryName.value = entry.name;
    elements.entryCategory.value = entry.category;
    elements.entryAmount.value = entry.amount;
    elements.entryStatus.value = entry.status;
    elements.entryNote.value = entry.note || "";
    elements.entryForm.querySelector('button[type="submit"]').textContent = "Änderung speichern";
    elements.entryName.focus();
    return;
  }

  if (button.dataset.action === "duplicate") {
    state = await request("duplicate_entry", { method: "POST", query: { entryId: entry.id } });
  }

  if (button.dataset.action === "delete") {
    state = await request("delete_entry", { method: "DELETE", query: { entryId: entry.id, monthId: state.activeMonthId } });
  }

  render();
}

async function addMonth(event) {
  event.preventDefault();
  const name = elements.monthName.value.trim();
  if (!name) return;

  state = await request("create_month", { method: "POST", body: { name } });
  elements.monthForm.reset();
  resetEntryForm();
  render();
}

async function deleteActiveMonth() {
  if (state.months.length === 1) {
    alert("Mindestens ein Monat muss vorhanden bleiben.");
    return;
  }

  const month = getActiveMonth();
  const confirmed = confirm(`Monat "${month.name}" wirklich löschen?`);
  if (!confirmed) return;

  state = await request("delete_month", { method: "DELETE", query: { monthId: state.activeMonthId } });
  render();
}

function exportCsv() {
  const url = new URL(API_URL, window.location.href);
  url.searchParams.set("action", "export_csv");
  url.searchParams.set("monthId", state.activeMonthId);
  window.location.href = url.toString();
}

async function importCsv(event) {
  const file = event.target.files[0];
  if (!file) return;

  const formData = new FormData();
  formData.append("monthId", state.activeMonthId);
  formData.append("csv", file);
  state = await request("import_csv", { method: "POST", body: formData });
  elements.importInput.value = "";
  render();
}

async function saveGoal() {
  state = await request("update_goal", {
    method: "PUT",
    body: { monthId: state.activeMonthId, goal: Number(elements.goalInput.value) || 0 },
  });
  render();
}

async function saveBudget(event) {
  event.preventDefault();
  state = await request("save_budget", {
    method: "POST",
    body: {
      monthId: state.activeMonthId,
      category: elements.budgetCategory.value.trim(),
      limitAmount: Number(elements.budgetLimit.value) || 0,
    },
  });
  elements.budgetForm.reset();
  render();
}

async function deleteBudget(event) {
  const button = event.target.closest("button[data-budget-delete]");
  if (!button) return;

  state = await request("delete_budget", {
    method: "DELETE",
    query: { budgetId: button.dataset.budgetDelete, monthId: state.activeMonthId },
  });
  render();
}

async function copyRecurringEntries() {
  state = await request("copy_recurring", { method: "POST", body: { monthId: state.activeMonthId } });
  if (state.message) {
    setStatus(state.message, "ok");
  }
  render();
}

elements.monthForm.addEventListener("submit", (event) => addMonth(event).catch(showError));
elements.entryForm.addEventListener("submit", (event) => handleEntrySubmit(event).catch(showError));
elements.budgetTableBody.addEventListener("click", (event) => handleTableAction(event).catch(showError));
elements.deleteMonthButton.addEventListener("click", () => deleteActiveMonth().catch(showError));
elements.copyRecurringButton.addEventListener("click", () => copyRecurringEntries().catch(showError));
elements.exportButton.addEventListener("click", exportCsv);
elements.exportJsonButton?.addEventListener("click", exportJson);
elements.resetFiltersButton?.addEventListener("click", resetFilters);
elements.importInput.addEventListener("change", (event) => importCsv(event).catch(showError));
elements.syncButton.addEventListener("click", () => loadApp());
elements.budgetForm.addEventListener("submit", (event) => saveBudget(event).catch(showError));
elements.budgetList.addEventListener("click", (event) => deleteBudget(event).catch(showError));
[elements.searchInput, elements.typeFilter, elements.categoryFilter, elements.sortSelect].forEach((element) => {
  element.addEventListener("input", render);
});
elements.goalInput.addEventListener("change", () => saveGoal().catch(showError));
elements.templateRent?.addEventListener("click", () => applyTemplate("rent"));
elements.templateSalary?.addEventListener("click", () => applyTemplate("salary"));

function showError(error) {
  setStatus(error.message, "error");
}

resetEntryForm();
loadApp();


async function renderYearlySummary() {
  if (!state.months.length) {
    elements.yearIncome.textContent = currencyFormatter.format(0);
    elements.yearExpense.textContent = currencyFormatter.format(0);
    elements.yearBalance.textContent = currencyFormatter.format(0);
    elements.yearMonths.textContent = "0";
    return;
  }

  let income = 0;
  let expense = 0;
  for (const month of state.months) {
    const snapshot = await request("init", { query: { monthId: month.id } });
    const totals = calculateTotals(snapshot.entries || []);
    income += totals.income;
    expense += totals.expense;
  }

  elements.yearIncome.textContent = currencyFormatter.format(income);
  elements.yearExpense.textContent = currencyFormatter.format(expense);
  elements.yearBalance.textContent = currencyFormatter.format(income - expense);
  elements.yearMonths.textContent = String(state.months.length);
}

function resetFilters() {
  elements.searchInput.value = "";
  elements.typeFilter.value = "all";
  elements.categoryFilter.value = "all";
  elements.sortSelect.value = "dateAsc";
  render();
}

function exportJson() {
  const payload = {
    exportedAt: new Date().toISOString(),
    month: getActiveMonth(),
    entries: state.entries,
    budgets: state.categoryBudgets,
  };
  const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `${(getActiveMonth().name || "monat").toLowerCase().replaceAll(" ", "-")}-budget.json`;
  link.click();
  URL.revokeObjectURL(url);
}

function applyTemplate(kind) {
  const today = new Date().toISOString().slice(0, 10);
  if (kind === "rent") {
    elements.entryType.value = "expense";
    elements.entryDate.value = today;
    elements.entryName.value = "Miete";
    elements.entryCategory.value = "Wohnen";
    elements.entryAmount.value = "950";
    elements.entryStatus.value = "recurring";
    elements.entryNote.value = "Vorlage";
  }
  if (kind === "salary") {
    elements.entryType.value = "income";
    elements.entryDate.value = today;
    elements.entryName.value = "Gehalt";
    elements.entryCategory.value = "Gehalt";
    elements.entryAmount.value = "3000";
    elements.entryStatus.value = "paid";
    elements.entryNote.value = "Vorlage";
  }
}
