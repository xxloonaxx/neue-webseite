<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

try {
    $database = new BudgetDatabase(require __DIR__ . '/config.php');
    $database->initialize();
    echo "Datenbank ist bereit.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration fehlgeschlagen: {$exception->getMessage()}\n");
    exit(1);
}
