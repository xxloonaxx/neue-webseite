<?php

declare(strict_types=1);

final class BudgetDatabase
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function initialize(): void
    {
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('schema.sql konnte nicht gelesen werden.');
        }

        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $this->pdo->exec($statement);
        }

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM months')->fetchColumn();
        if ($count === 0) {
            $this->seedDemoData();
        }
    }

    private function seedDemoData(): void
    {
        $this->pdo->beginTransaction();
        try {
            $month = $this->createMonth('Januar 2026', 500);
            $entries = [
                ['income', '2026-01-01', 'Gehalt', 'Gehalt', 3200, 'paid', 'Nettoeinkommen'],
                ['expense', '2026-01-03', 'Miete', 'Wohnen', 980, 'recurring', 'Warmmiete'],
                ['expense', '2026-01-05', 'Supermarkt', 'Lebensmittel', 185.40, 'paid', 'Wocheneinkauf'],
                ['expense', '2026-01-10', 'Deutschlandticket', 'Transport', 49, 'recurring', 'Abo'],
                ['expense', '2026-01-15', 'ETF Sparplan', 'Sparen', 450, 'planned', 'Automatisch'],
            ];

            foreach ($entries as $entry) {
                $this->createEntry((int) $month['id'], $entry);
            }

            $this->upsertCategoryBudget((int) $month['id'], 'Wohnen', 1000);
            $this->upsertCategoryBudget((int) $month['id'], 'Lebensmittel', 450);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function allMonths(): array
    {
        return $this->pdo->query('SELECT id, name, goal, created_at FROM months ORDER BY id ASC')->fetchAll();
    }

    public function createMonth(string $name, float $goal = 0): array
    {
        $statement = $this->pdo->prepare('INSERT INTO months (name, goal) VALUES (:name, :goal)');
        $statement->execute(['name' => $name, 'goal' => $goal]);

        return $this->findMonth((int) $this->pdo->lastInsertId());
    }

    public function findMonth(int $monthId): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, goal, created_at FROM months WHERE id = :id');
        $statement->execute(['id' => $monthId]);
        $month = $statement->fetch();
        if (!$month) {
            throw new RuntimeException('Monat wurde nicht gefunden.');
        }

        return $month;
    }

    public function updateGoal(int $monthId, float $goal): array
    {
        $statement = $this->pdo->prepare('UPDATE months SET goal = :goal WHERE id = :month_id');
        $statement->execute(['goal' => $goal, 'month_id' => $monthId]);

        return $this->findMonth($monthId);
    }

    public function deleteMonth(int $monthId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM months WHERE id = :id');
        $statement->execute(['id' => $monthId]);
    }

    public function entries(int $monthId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, month_id AS monthId, type, entry_date AS date, name, category, amount, status, note
             FROM entries WHERE month_id = :month_id ORDER BY entry_date ASC, id ASC'
        );
        $statement->execute(['month_id' => $monthId]);

        return $statement->fetchAll();
    }

    public function createEntry(int $monthId, array $entry): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO entries (month_id, type, entry_date, name, category, amount, status, note)
             VALUES (:month_id, :type, :entry_date, :name, :category, :amount, :status, :note)'
        );
        $statement->execute($this->entryParameters($monthId, $entry));

        return $this->findEntry((int) $this->pdo->lastInsertId());
    }

    public function updateEntry(int $entryId, array $entry): array
    {
        $statement = $this->pdo->prepare(
            'UPDATE entries
             SET type = :type, entry_date = :entry_date, name = :name, category = :category,
                 amount = :amount, status = :status, note = :note
             WHERE id = :id'
        );
        $params = $this->entryParameters((int) ($entry['monthId'] ?? 0), $entry);
        unset($params['month_id']);
        $params['id'] = $entryId;
        $statement->execute($params);

        return $this->findEntry($entryId);
    }

    public function duplicateEntry(int $entryId): array
    {
        $entry = $this->findEntry($entryId);
        $entry['name'] = $entry['name'] . ' Kopie';

        return $this->createEntry((int) $entry['monthId'], $entry);
    }

    public function deleteEntry(int $entryId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM entries WHERE id = :id');
        $statement->execute(['id' => $entryId]);
    }

    public function findEntry(int $entryId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, month_id AS monthId, type, entry_date AS date, name, category, amount, status, note
             FROM entries WHERE id = :id'
        );
        $statement->execute(['id' => $entryId]);
        $entry = $statement->fetch();
        if (!$entry) {
            throw new RuntimeException('Eintrag wurde nicht gefunden.');
        }

        return $entry;
    }

    public function categoryBudgets(int $monthId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, month_id AS monthId, category, limit_amount AS limitAmount
             FROM category_budgets WHERE month_id = :month_id ORDER BY category ASC'
        );
        $statement->execute(['month_id' => $monthId]);

        return $statement->fetchAll();
    }

    public function upsertCategoryBudget(int $monthId, string $category, float $limitAmount): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO category_budgets (month_id, category, limit_amount)
             VALUES (:month_id, :category, :limit_amount)
             ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)'
        );
        $statement->execute([
            'month_id' => $monthId,
            'category' => $category,
            'limit_amount' => $limitAmount,
        ]);

        $find = $this->pdo->prepare(
            'SELECT id, month_id AS monthId, category, limit_amount AS limitAmount
             FROM category_budgets WHERE month_id = :month_id AND category = :category'
        );
        $find->execute(['month_id' => $monthId, 'category' => $category]);

        return $find->fetch();
    }

    public function deleteCategoryBudget(int $budgetId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM category_budgets WHERE id = :id');
        $statement->execute(['id' => $budgetId]);
    }

    public function copyRecurringFromPreviousMonth(int $targetMonthId): int
    {
        $previousMonthId = $this->findPreviousMonthId($targetMonthId);
        if ($previousMonthId === null) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO entries (month_id, type, entry_date, name, category, amount, status, note)
             SELECT :target_month_id, type,
                    DATE_ADD(entry_date, INTERVAL 1 MONTH),
                    name, category, amount, status, note
             FROM entries
             WHERE month_id = :previous_month_id AND status = "recurring"'
        );
        $statement->execute([
            'target_month_id' => $targetMonthId,
            'previous_month_id' => $previousMonthId,
        ]);

        return $statement->rowCount();
    }

    private function findPreviousMonthId(int $targetMonthId): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM months WHERE id < :id ORDER BY id DESC LIMIT 1');
        $statement->execute(['id' => $targetMonthId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function entryParameters(int $monthId, array $entry): array
    {
        return [
            'month_id' => $monthId,
            'type' => $entry['type'] === 'income' ? 'income' : 'expense',
            'entry_date' => $entry['date'] ?? date('Y-m-d'),
            'name' => trim((string) ($entry['name'] ?? 'Neue Buchung')),
            'category' => trim((string) ($entry['category'] ?? 'Sonstiges')),
            'amount' => max(0, (float) ($entry['amount'] ?? 0)),
            'status' => in_array(($entry['status'] ?? 'planned'), ['planned', 'paid', 'recurring'], true) ? $entry['status'] : 'planned',
            'note' => trim((string) ($entry['note'] ?? '')),
        ];
    }
}
