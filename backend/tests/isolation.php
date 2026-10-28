<?php
declare(strict_types=1);

/*
 * Test M1: isolamento tra organizzazioni, ruoli, flusso di revisione e rate limiting.
 * Da eseguire dentro il container:  docker compose exec app php tests/isolation.php
 * Richiede AI_PROVIDER=fake oppure auto senza chiave, per non consumare crediti AI.
 */

$base = getenv('BASE_URL') ?: 'http://nginx/api/v1';
$failures = 0;
$checks = 0;

function req(string $method, string $path, ?string $token = null, ?array $json = null, ?array $multipart = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    $headers = [];
    if ($token) $headers[] = "Authorization: Bearer {$token}";
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    } elseif ($multipart !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $body = (string)curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, json_decode($body, true), $body];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
}

function expectStatus(string $label, array $response, int $expected): void
{
    check($label, $response[0] === $expected, "atteso {$expected}, ricevuto {$response[0]}: " . substr($response[2], 0, 200));
}

function register(string $org): array
{
    $email = 'm1-' . strtolower($org) . '-' . bin2hex(random_bytes(4)) . '@example.test';
    $r = req('POST', '/auth/register', null, ['name' => "Owner {$org}", 'email' => $email, 'password' => 'Local-Test-Password-123', 'organization_name' => "Org {$org}"]);
    if ($r[0] !== 201) { echo "Registrazione {$org} fallita: {$r[2]}\n"; exit(1); }
    return ['token' => $r[1]['data']['token']['access_token'], 'email' => $email];
}

function login(string $email): string
{
    $r = req('POST', '/auth/login', null, ['email' => $email, 'password' => 'Local-Test-Password-123']);
    return $r[1]['data']['token']['access_token'] ?? '';
}

// PNG 1x1 valido per il test di upload.
$pngPath = sys_get_temp_dir() . '/m1-test.png';
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

echo "Preparazione dati organizzazione A\n";
$a = register('A');
$b = register('B');
$brandA = req('POST', '/brands', $a['token'], ['name' => 'Brand A', 'primary_color' => '#5b4bdb', 'tone_of_voice' => 'Diretto e amichevole'])[1]['data'];
$assetA = req('POST', '/assets', $a['token'], null, ['file' => new CURLFile($pngPath, 'image/png', 'logo.png')])[1]['data'];
$projectA = req('POST', '/projects', $a['token'], ['name' => 'Progetto A', 'objective' => 'Test', 'brand_profile_id' => $brandA['id']])[1]['data'];
$contentA = req('POST', "/projects/{$projectA['id']}/content", $a['token'], ['channel' => 'instagram', 'title' => 'Post A', 'body' => 'Testo A'])[1]['data'];
$jobA = req('POST', "/projects/{$projectA['id']}/generate", $a['token'], ['channel' => 'facebook', 'brief' => 'Lancio autunno', 'variants' => 2]);
expectStatus('A avvia una generazione (202)', $jobA, 202);
$jobA = $jobA[1]['data'];

echo "\nIsolamento: B non vede né modifica i dati di A\n";
expectStatus('GET brand di A', req('GET', "/brands/{$brandA['id']}", $b['token']), 404);
expectStatus('PATCH brand di A', req('PATCH', "/brands/{$brandA['id']}", $b['token'], ['name' => 'X']), 404);
expectStatus('DELETE brand di A', req('DELETE', "/brands/{$brandA['id']}", $b['token']), 404);
expectStatus('GET progetto di A', req('GET', "/projects/{$projectA['id']}", $b['token']), 404);
expectStatus('PATCH progetto di A', req('PATCH', "/projects/{$projectA['id']}", $b['token'], ['name' => 'X']), 404);
expectStatus('GET contenuti del progetto di A', req('GET', "/projects/{$projectA['id']}/content", $b['token']), 404);
expectStatus('POST contenuto nel progetto di A', req('POST', "/projects/{$projectA['id']}/content", $b['token'], ['title' => 'X', 'body' => 'X']), 404);
expectStatus('Generazione nel progetto di A', req('POST', "/projects/{$projectA['id']}/generate", $b['token'], ['channel' => 'blog', 'brief' => 'X']), 404);
expectStatus('PATCH contenuto di A', req('PATCH', "/content/{$contentA['id']}", $b['token'], ['title' => 'X']), 404);
expectStatus('Invio in revisione contenuto di A', req('POST', "/content/{$contentA['id']}/submit", $b['token']), 404);
expectStatus('Approvazione contenuto di A', req('POST', "/content/{$contentA['id']}/approve", $b['token']), 404);
expectStatus('Download file di A', req('GET', "/assets/{$assetA['id']}/file", $b['token']), 404);
expectStatus('DELETE file di A', req('DELETE', "/assets/{$assetA['id']}", $b['token']), 404);
expectStatus('GET lavoro di A', req('GET', "/jobs/{$jobA['id']}", $b['token']), 404);
expectStatus('Progetto di B con brand di A', req('POST', '/projects', $b['token'], ['name' => 'X', 'objective' => 'X', 'brand_profile_id' => $brandA['id']]), 422);
expectStatus('Brand di B con logo di A', req('POST', '/brands', $b['token'], ['name' => 'X', 'logo_asset_id' => $assetA['id']]), 422);
foreach (['/brands' => $brandA['id'], '/projects' => $projectA['id'], '/assets' => $assetA['id']] as $path => $idA) {
    $ids = array_column(req('GET', $path, $b['token'])[1]['data'] ?? [], 'id');
    check("Lista {$path} di B senza elementi di A", !in_array($idA, $ids));
}
$usersB = array_column(req('GET', '/users', $b['token'])[1]['data'] ?? [], 'email');
check('Lista utenti di B senza utenti di A', !in_array($a['email'], $usersB, true));

echo "\nFile e brand kit\n";
expectStatus('A scarica il proprio file', req('GET', "/assets/{$assetA['id']}/file", $a['token']), 200);
expectStatus('A imposta il proprio logo', req('PATCH', "/brands/{$brandA['id']}", $a['token'], ['logo_asset_id' => $assetA['id']]), 200);
expectStatus('Colore non valido rifiutato', req('PATCH', "/brands/{$brandA['id']}", $a['token'], ['primary_color' => 'rosso']), 422);
$txtPath = sys_get_temp_dir() . '/m1-test.txt';
file_put_contents($txtPath, '<?php echo "x";');
expectStatus('Upload di un tipo non ammesso', req('POST', '/assets', $a['token'], null, ['file' => new CURLFile($txtPath, 'image/png', 'finto.png')]), 422);

echo "\nRuoli\n";
$viewerEmail = 'm1-viewer-' . bin2hex(random_bytes(4)) . '@example.test';
$editorEmail = 'm1-editor-' . bin2hex(random_bytes(4)) . '@example.test';
expectStatus('Owner aggiunge un viewer', req('POST', '/users', $a['token'], ['name' => 'Viewer', 'email' => $viewerEmail, 'password' => 'Local-Test-Password-123', 'role' => 'viewer']), 201);
expectStatus('Owner aggiunge un editor', req('POST', '/users', $a['token'], ['name' => 'Editor', 'email' => $editorEmail, 'password' => 'Local-Test-Password-123', 'role' => 'editor']), 201);
$viewer = login($viewerEmail);
$editor = login($editorEmail);
expectStatus('Viewer vede i progetti', req('GET', '/projects', $viewer), 200);
expectStatus('Viewer non crea progetti', req('POST', '/projects', $viewer, ['name' => 'X', 'objective' => 'X']), 403);
expectStatus('Viewer non carica file', req('POST', '/assets', $viewer, null, ['file' => new CURLFile($pngPath, 'image/png', 'x.png')]), 403);
expectStatus('Viewer non genera contenuti', req('POST', "/projects/{$projectA['id']}/generate", $viewer, ['channel' => 'blog', 'brief' => 'X']), 403);
expectStatus('Editor non aggiunge utenti', req('POST', '/users', $editor, ['name' => 'X', 'email' => 'x' . bin2hex(random_bytes(3)) . '@example.test', 'password' => 'Local-Test-Password-123']), 403);
expectStatus('Editor non elimina brand', req('DELETE', "/brands/{$brandA['id']}", $editor), 403);

echo "\nFlusso di revisione\n";
expectStatus('Editor invia in revisione', req('POST', "/content/{$contentA['id']}/submit", $editor), 200);
expectStatus('Editor non può approvare', req('POST', "/content/{$contentA['id']}/approve", $editor), 403);
expectStatus('In revisione non è modificabile', req('PATCH', "/content/{$contentA['id']}", $editor, ['title' => 'X']), 409);
expectStatus('Rifiuto senza nota non valido', req('POST', "/content/{$contentA['id']}/reject", $a['token'], []), 422);
expectStatus('Owner rifiuta con nota', req('POST', "/content/{$contentA['id']}/reject", $a['token'], ['note' => 'Tono troppo formale']), 200);
$edited = req('PATCH', "/content/{$contentA['id']}", $editor, ['body' => 'Testo corretto']);
check('Modifica dopo rifiuto torna in bozza', ($edited[1]['data']['status'] ?? '') === 'draft', $edited[2]);
req('POST', "/content/{$contentA['id']}/submit", $editor);
$approved = req('POST', "/content/{$contentA['id']}/approve", $a['token']);
check('Owner approva', ($approved[1]['data']['status'] ?? '') === 'approved', $approved[2]);

echo "\nGenerazione in background\n";
$job = null;
for ($i = 0; $i < 20; $i++) {
    $job = req('GET', "/jobs/{$jobA['id']}", $a['token'])[1]['data'] ?? null;
    if (in_array($job['status'] ?? '', ['done', 'failed'], true)) break;
    sleep(1);
}
check('Lavoro completato', ($job['status'] ?? '') === 'done', json_encode($job));
$generated = array_filter(req('GET', "/projects/{$projectA['id']}/content", $a['token'])[1]['data'] ?? [], fn($c) => (int)$c['ai_generated'] === 1);
check('Create 2 bozze AI', count($generated) === 2 && array_unique(array_column($generated, 'status')) === ['draft']);

echo "\nRate limiting\n";
$target = 'm1-ratelimit-' . bin2hex(random_bytes(4)) . '@example.test';
for ($i = 0; $i < 5; $i++) req('POST', '/auth/login', null, ['email' => $target, 'password' => 'sbagliata-123456']);
expectStatus('Sesto tentativo di login bloccato', req('POST', '/auth/login', null, ['email' => $target, 'password' => 'sbagliata-123456']), 429);

echo "\n{$checks} controlli, {$failures} falliti.\n";
exit($failures === 0 ? 0 : 1);
