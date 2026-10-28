<?php
declare(strict_types=1);

// Verifica che la richiesta a Claude sia accettata dall'SDK: con una chiave non valida deve rispondere "chiave non valida".
require __DIR__ . '/../src/bootstrap.php';
try {
    (new ClaudeContentGenerator())->generate([], ['name' => 'Test', 'objective' => 'Test', 'target_audience' => null], ['channel' => 'instagram', 'content_type' => 'post', 'brief' => 'prova', 'variants' => 1]);
    echo "Richiesta completata.\n";
} catch (GenerationException $e) {
    echo 'GenerationException: ', $e->getMessage(), "\n";
}
