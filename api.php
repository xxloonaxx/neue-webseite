<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $database = new BudgetDatabase(require __DIR__ . '/config.php');
    $database->initialize();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? 'init';
    $payload = readJsonPayload();

    if ($action === 'export_csv') {
        exportCsv($database, positiveInt($_GET['monthId'] ?? 0));
        exit;
    }

    $result = match ($action) {
        'init' => appState($database, positiveInt($_GET['monthId'] ?? 0)),
        'create_month' => requireMethod($method, 'POST') ?? createMonth($database, $payload),
        'delete_month' => requireMethod($method, 'DELETE') ?? deleteMonth($database, positiveInt($_GET['monthId'] ?? 0)),
        'update_goal' => requireMethod($method, 'PUT') ?? updateGoal($database, $payload),
        'create_entry' => requireMethod($method, 'POST') ?? createEntry($database, $payload),
        'update_entry' => requireMethod($method, 'PUT') ?? updateEntry($database, positiveInt($_GET['entryId'] ?? 0), $payload),
        'duplicate_entry' => requireMethod($method, 'POST') ?? duplicateEntry($database, positiveInt($_GET['entryId'] ?? 0)),
        'delete_entry' => requireMethod($method, 'DELETE') ?? deleteEntry($database, positiveInt($_GET['entryId'] ?? 0), positiveInt($_GET['monthId'] ?? 0)),
        'save_budget' => requireMethod($method, 'POST') ?? saveCategoryBudget($database, $payload),
        'delete_budget' => requireMethod($method, 'DELETE') ?? deleteCategoryBudget($database, positiveInt($_GET['budgetId'] ?? 0), positiveInt($_GET['monthId'] ?? 0)),
        'copy_recurring' => requireMethod($method, 'POST') ?? copyRecurring($database, $payload),
        'import_csv' => requireMethod($method, 'POST') ?? importCsv($database),
        default => throw new RuntimeException('Unbekannte Aktion.'),
    };

    respond($result);
} catch (Throwable $exception) {
    http_response_code(500);
    respond([
        'ok' => false,
        'message' => $exception->getMessage(),
        'hint' => 'Bitte MySQL-Zugangsdaten in config.php oder per DB_HOST, DB_NAME, DB_USER und DB_PASSWORD prüfen.',
    ]);
}

function readJsonPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_contains($contentType, 'application/json')) {
        return [];
    }

    $raw = trim((string) (file_get_contents('php://input') ?: ''));
    if ($raw == '') {
        return [];
    }

    try {
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('JSON Payload ist ungültig.');
    }

    if (!is_array($payload)) {
        throw new RuntimeException('JSON Payload ist ungültig.');
    }

    return $payload;
}

function requireMethod(string $actual, string $expected): ?array
{
    if ($actual !== $expected) {
        throw new RuntimeException("Diese Aktion erwartet {$expected}.");
    }

    return null;
}

function positiveInt(mixed $value): int
{
    return max(0, (int) $value);
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function appState(BudgetDatabase $database, int $monthId = 0): array
{
    $months = $database->allMonths();
    if (!$months) {
        $months[] = $database->createMonth('Januar 2026');
    }

    $activeMonthId = $monthId > 0 ? $monthId : (int) $months[0]['id'];
    $validMonthIds = array_map(static fn (array $month): int => (int) $month['id'], $months);
    if (!in_array($activeMonthId, $validMonthIds, true)) {
        $activeMonthId = (int) $months[0]['id'];
    }

    return [
        'ok' => true,
        'months' => $months,
        'activeMonthId' => $activeMonthId,
        'entries' => $database->entries($activeMonthId),
        'categoryBudgets' => $database->categoryBudgets($activeMonthId),
    ];
}

function createMonth(BudgetDatabase $database, array $payload): array
{
    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Bitte einen Monatsnamen eingeben.');
    }

    $month = $database->createMonth($name, (float) ($payload['goal'] ?? 0));

    return appState($database, (int) $month['id']);
}

function deleteMonth(BudgetDatabase $database, int $monthId): array
{
    if ($monthId <= 0) {
        throw new RuntimeException('Monat fehlt.');
    }

    if (count($database->allMonths()) <= 1) {
        throw new RuntimeException('Mindestens ein Monat muss vorhanden bleiben.');
    }

    $database->deleteMonth($monthId);

    return appState($database);
}

function updateGoal(BudgetDatabase $database, array $payload): array
{
    $monthId = positiveInt($payload['monthId'] ?? 0);
    $database->updateGoal($monthId, (float) ($payload['goal'] ?? 0));

    return appState($database, $monthId);
}

function createEntry(BudgetDatabase $database, array $payload): array
{
    $monthId = positiveInt($payload['monthId'] ?? 0);
    if ($monthId <= 0) {
        throw new RuntimeException('Monat fehlt.');
    }

    $database->createEntry($monthId, $payload);

    return appState($database, $monthId);
}

function updateEntry(BudgetDatabase $database, int $entryId, array $payload): array
{
    if ($entryId <= 0) {
        throw new RuntimeException('Eintrag fehlt.');
    }

    $database->updateEntry($entryId, $payload);

    return appState($database, positiveInt($payload['monthId'] ?? 0));
}

function duplicateEntry(BudgetDatabase $database, int $entryId): array
{
    $entry = $database->duplicateEntry($entryId);

    return appState($database, (int) $entry['monthId']);
}

function deleteEntry(BudgetDatabase $database, int $entryId, int $monthId): array
{
    $database->deleteEntry($entryId);

    return appState($database, $monthId);
}

function saveCategoryBudget(BudgetDatabase $database, array $payload): array
{
    $monthId = positiveInt($payload['monthId'] ?? 0);
    $category = trim((string) ($payload['category'] ?? ''));
    if ($monthId <= 0 || $category === '') {
        throw new RuntimeException('Monat und Kategorie sind erforderlich.');
    }

    $database->upsertCategoryBudget($monthId, $category, (float) ($payload['limitAmount'] ?? 0));

    return appState($database, $monthId);
}

function deleteCategoryBudget(BudgetDatabase $database, int $budgetId, int $monthId): array
{
    $database->deleteCategoryBudget($budgetId);

    return appState($database, $monthId);
}

function copyRecurring(BudgetDatabase $database, array $payload): array
{
    $monthId = positiveInt($payload['monthId'] ?? 0);
    $copied = $database->copyRecurringFromPreviousMonth($monthId);
    $state = appState($database, $monthId);
    $state['message'] = "{$copied} wiederkehrende Buchungen kopiert.";

    return $state;
}

function importCsv(BudgetDatabase $database): array
{
    $monthId = positiveInt($_POST['monthId'] ?? 0);
    if ($monthId <= 0 || !isset($_FILES['csv'])) {
        throw new RuntimeException('CSV-Datei oder Monat fehlt.');
    }

    $handle = fopen($_FILES['csv']['tmp_name'], 'rb');
    if ($handle === false) {
        throw new RuntimeException('CSV-Datei konnte nicht geöffnet werden.');
    }

    fgetcsv($handle, 0, ';');
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (count($row) < 7) {
            continue;
        }
        $database->createEntry($monthId, [
            'type' => $row[0],
            'date' => $row[1],
            'name' => $row[2],
            'category' => $row[3],
            'status' => $row[4],
            'note' => $row[5],
            'amount' => (float) str_replace(',', '.', $row[6]),
        ]);
    }
    fclose($handle);

    return appState($database, $monthId);
}

function exportCsv(BudgetDatabase $database, int $monthId): void
{
    if ($monthId <= 0) {
        throw new RuntimeException('Monat fehlt.');
    }

    $month = $database->findMonth($monthId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]+/i', '-', $month['name']) . '-budget.csv"');

    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Typ', 'Datum', 'Beschreibung', 'Kategorie', 'Status', 'Notiz', 'Betrag'], ';');
    foreach ($database->entries($monthId) as $entry) {
        fputcsv($output, [
            $entry['type'],
            $entry['date'],
            $entry['name'],
            $entry['category'],
            $entry['status'],
            $entry['note'],
            $entry['amount'],
        ], ';');
    }
    fclose($output);
}
