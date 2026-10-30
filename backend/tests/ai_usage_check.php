<?php
declare(strict_types=1);

// Limiti giornalieri, cache delle risposte, rinvio dei lavori automatici e passaggio al provider di riserva.
// Non consuma quota: il provider principale è finto e Groq riceve una chiave non valida.
require __DIR__ . '/../src/bootstrap.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $failures++;
}

/** Provider di prova: risponde sempre uguale, oppure è "sovraccarico". */
final class StubAi extends ClaudeMarketingAi
{
    public const PROVIDER = 'test';
    public int $calls = 0;

    public function __construct(private bool $down = false) {}

    protected function request(string $system, string $prompt, array $schema, ?string $effort, int $maxTokens): array
    {
        $this->calls++;
        if ($this->down) throw new ProviderUnavailableException('Servizio di prova giù.');
        return ['goals' => [['title' => 'Prova ' . $this->calls, 'goal' => 'Obiettivo di prova']]];
    }
}

$pdo = Database::connection();
$pdo->prepare('INSERT INTO organizations (name, slug) VALUES (?, ?)')->execute(['Test limiti AI', 'test-ai-' . bin2hex(random_bytes(4))]);
$orgId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO brand_profiles (organization_id, name) VALUES (?, ?)')->execute([$orgId, 'Cliente di prova']);
$brandId = (int)$pdo->lastInsertId();
$brand = ['name' => 'Cliente di prova ' . bin2hex(random_bytes(4))];

try {
    $today = fn() => AiUsage::today();
    putenv('AI_FALLBACK_PROVIDER=');

    // Richiesta riuscita: viene contata per il cliente e salvata.
    AiUsage::forJob($orgId, false);
    AiUsage::forBrand($brandId);
    $before = $today();
    $ai = new StubAi();
    $ai->suggestGoals($brand);
    $after = $today();
    check($after['total'] === $before['total'] + 1 && $after['client'] === 1, 'la richiesta conta nel totale e nel cliente');

    // Chi chiede di generare ottiene un testo nuovo, il lavoro automatico la risposta salvata.
    $ai->suggestGoals($brand);
    check($ai->calls === 2, 'richiesta di una persona: niente cache');
    AiUsage::forJob($orgId, true);
    AiUsage::forBrand($brandId);
    $ai->suggestGoals($brand);
    check($ai->calls === 2, 'lavoro automatico: risposta dalla cache, non contata');
    check($today()['client'] === 2, 'le risposte dalla cache non contano nel limite');

    // Limite per cliente.
    AiUsage::forJob($orgId, false);
    AiUsage::forBrand($brandId);
    putenv('AI_DAILY_LIMIT_PER_CLIENT=2');
    try {
        $ai->suggestGoals(['name' => 'Altro testo']);
        check(false, 'limite per cliente raggiunto');
    } catch (AiQuotaException $e) {
        check(str_contains($e->getMessage(), 'per questo cliente'), 'limite per cliente raggiunto');
    }
    AiUsage::forBrand(null);
    $ai->suggestGoals(['name' => 'Senza cliente ' . bin2hex(random_bytes(4))]);
    check($ai->calls === 3, 'il limite per cliente non blocca le richieste senza cliente');
    putenv('AI_DAILY_LIMIT_PER_CLIENT=0');

    // Limite totale.
    putenv('AI_DAILY_LIMIT=' . $today()['total']);
    try {
        $ai->suggestGoals(['name' => 'Ancora ' . bin2hex(random_bytes(4))]);
        check(false, 'limite totale raggiunto');
    } catch (AiQuotaException $e) {
        check(str_contains($e->getMessage(), 'AI_DAILY_LIMIT'), 'limite totale raggiunto');
    }
    putenv('AI_DAILY_LIMIT=0');

    // Provider sovraccarico senza riserva: l'errore arriva all'utente e non viene contato.
    $count = $today()['total'];
    try {
        (new StubAi(true))->suggestGoals(['name' => 'Giù ' . bin2hex(random_bytes(4))]);
        check(false, 'senza riserva arriva l\'errore del provider');
    } catch (ProviderUnavailableException $e) {
        check($e->getMessage() === 'Servizio di prova giù.', 'senza riserva arriva l\'errore del provider');
    }
    check($today()['total'] === $count, 'le richieste fallite non vengono contate');

    // Con la riserva si passa a Groq (qui con una chiave non valida: la risposta di Groq prova che è stato chiamato).
    putenv('AI_FALLBACK_PROVIDER=groq');
    putenv('GROQ_API_KEY=gsk_chiave_non_valida');
    try {
        (new StubAi(true))->suggestGoals(['name' => 'Riserva ' . bin2hex(random_bytes(4))]);
        check(false, 'si passa al provider di riserva');
    } catch (GenerationException $e) {
        check($e->getMessage() === 'Chiave API di Groq non valida.', 'si passa al provider di riserva (' . $e->getMessage() . ')');
    }
    putenv('GROQ_API_KEY=');
    check(MarketingAis::fallback('gemini') === null, 'riserva senza chiave: nessuna riserva');
    check(MarketingAis::fallback('groq') === null, 'la riserva non è lo stesso provider');

    // Lavoro automatico rimandato: resta in coda ma non viene preso fino a domani.
    $userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO jobs (organization_id, created_by, type, payload) VALUES (?, ?, 'analyze_competitor', '{\"automatic\":true}')")->execute([$orgId, $userId]);
    $jobId = (int)$pdo->lastInsertId();
    Jobs::postpone($jobId, 'Limite raggiunto');
    $job = $pdo->query("SELECT status, run_after > UTC_TIMESTAMP() AS later FROM jobs WHERE id={$jobId}")->fetch();
    check($job['status'] === 'queued' && (int)$job['later'] === 1, 'lavoro rimandato a domani');
    $pdo->exec("UPDATE jobs SET status='done' WHERE status='queued' AND id<>{$jobId} AND organization_id={$orgId}");
    $claimed = Jobs::claim();
    check($claimed === null || (int)$claimed['id'] !== $jobId, 'il lavoro rimandato non viene preso prima del tempo');
    if ($claimed !== null) $pdo->prepare("UPDATE jobs SET status='queued', attempts=attempts-1 WHERE id=?")->execute([$claimed['id']]);
} finally {
    $pdo->prepare('DELETE FROM ai_usage WHERE organization_id=?')->execute([$orgId]);
    $pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$orgId]);
}

echo $failures === 0 ? "\nTutti i controlli superati.\n" : "\n{$failures} controlli falliti.\n";
exit($failures === 0 ? 0 : 1);
