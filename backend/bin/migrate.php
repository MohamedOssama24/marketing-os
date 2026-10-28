<?php
declare(strict_types=1);

// Applica in ordine i file di database/migrations non ancora eseguiti.
require_once __DIR__ . '/../src/bootstrap.php';

$pdo = Database::connection();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) continue;
    echo "Migrazione {$name}...\n";
    // MySQL esegue DDL con commit implicito: ogni migrazione deve essere scritta per andare a buon fine per intero.
    // Un'istruzione per volta, così un errore a metà file viene segnalato invece di passare inosservato.
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
    foreach (preg_split('/;\s*(?:\R|$)/', $sql) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
}
echo "Database aggiornato.\n";
