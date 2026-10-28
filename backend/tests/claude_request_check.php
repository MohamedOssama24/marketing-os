<?php
declare(strict_types=1);

// Verifica che la richiesta a Claude sia accettata dall'SDK: con una chiave non valida deve rispondere "chiave non valida".
// Con una chiave vera genera davvero una variante (costa pochi centesimi).
require __DIR__ . '/../src/bootstrap.php';
try {
    $variants = (new ClaudeMarketingAi())->contentVariants([], ['name' => 'Test', 'objective' => 'Test', 'target_audience' => null], ['channel' => 'instagram', 'content_type' => 'post', 'brief' => 'prova', 'variants' => 1]);
    echo "Richiesta completata:\n", json_encode($variants, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
} catch (GenerationException $e) {
    echo 'GenerationException: ', $e->getMessage(), "\n";
}
