<?php
declare(strict_types=1);

/*
 * Sponsorizzate pubblicate su Meta, con i dati di esempio (META_ADS_SAMPLE=true e senza META_APP_ID): collegamento,
 * scelta di account e pagina, pubblicazione in pausa dal worker, attivazione con conferma, risultati, modifiche e isolamento.
 * Da eseguire dentro il container:  docker compose exec app php tests/meta_marketing_check.php
 */
require __DIR__ . '/../src/bootstrap.php';

$base = getenv('BASE_URL') ?: 'http://nginx/api/v1';
$failures = 0;

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

function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
    if (!$ok) $failures++;
}

function expectStatus(string $label, array $response, int $expected): void
{
    check($response[0] === $expected, $label, "atteso {$expected}, ricevuto {$response[0]}: " . substr($response[2], 0, 200));
}

function register(string $org): array
{
    $email = 'meta-' . strtolower($org) . '-' . bin2hex(random_bytes(4)) . '@example.test';
    $r = req('POST', '/auth/register', null, ['name' => "Owner {$org}", 'email' => $email, 'password' => 'Local-Test-Password-123', 'organization_name' => "Test Meta {$org}", 'invite_code' => getenv('REGISTRATION_INVITE_CODE') ?: '']);
    if ($r[0] !== 201) { echo "Registrazione {$org} fallita: {$r[2]}\n"; exit(1); }
    return ['token' => $r[1]['data']['token']['access_token'], 'org' => (int)$r[1]['data']['user']['organization_id']];
}

if (MetaMarketing::mode() !== 'sample') { echo "Serve META_ADS_SAMPLE=true senza META_APP_ID.\n"; exit(1); }

// Cifratura del codice di accesso.
$secret = Secrets::encrypt('codice-di-prova');
check(Secrets::decrypt($secret) === 'codice-di-prova', 'il codice di accesso si cifra e si decifra');
check(!str_contains($secret, 'codice-di-prova'), 'nel database il codice non è leggibile');
check(Secrets::decrypt(substr($secret, 0, -4) . 'AAAA') === null, 'un codice manomesso non si decifra');

$a = register('A');
$b = register('B');
try {
    $brand = req('POST', '/brands', $a['token'], ['name' => 'Cliente Meta', 'primary_color' => '#5b4bdb'])[1]['data'];
    $png = sys_get_temp_dir() . '/meta-test.png';
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    $asset = req('POST', '/assets', $a['token'], null, ['file' => new CURLFile($png, 'image/png', 'foto.png')])[1]['data'];

    // Collegamento.
    $view = req('GET', "/brands/{$brand['id']}/meta", $a['token']);
    check($view[0] === 200 && $view[1]['data']['mode'] === 'sample' && !$view[1]['data']['connected'], 'cliente non ancora collegato');
    expectStatus('un\'altra organizzazione non vede il collegamento', req('GET', "/brands/{$brand['id']}/meta", $b['token']), 404);
    expectStatus('un\'altra organizzazione non può collegare il cliente', req('POST', "/brands/{$brand['id']}/meta/connect", $b['token']), 404);
    $connect = req('POST', "/brands/{$brand['id']}/meta/connect", $a['token']);
    check($connect[0] === 200 && $connect[1]['data']['connection']['ready'] === true, 'collegamento di esempio pronto con account e pagina');
    check(!str_contains($connect[2], 'access_token') && !str_contains($connect[2], 'v1:'), 'il codice di accesso non arriva al browser');
    $assets = req('GET', "/brands/{$brand['id']}/meta/assets", $a['token']);
    check(count($assets[1]['data']['ad_accounts'] ?? []) === 2, 'elenco degli account pubblicitari');
    expectStatus('account disattivato rifiutato', req('PATCH', "/brands/{$brand['id']}/meta", $a['token'], ['ad_account_id' => '100000000000002']), 422);
    expectStatus('pagina non gestita rifiutata', req('PATCH', "/brands/{$brand['id']}/meta", $a['token'], ['page_id' => '999']), 422);
    $chosen = req('PATCH', "/brands/{$brand['id']}/meta", $a['token'], ['ad_account_id' => 'act_100000000000001']);
    check($chosen[0] === 200 && $chosen[1]['data']['ad_account']['id'] === '100000000000001', 'account scelto (anche scritto come act_…)');

    // Sponsorizzata.
    $input = [
        'brand_id' => $brand['id'], 'name' => 'Autunno', 'objective' => 'traffic', 'audience' => ['location' => 'Italia', 'age_min' => 25, 'age_max' => 55],
        'daily_budget' => 5, 'start_date' => date('Y-m-d'), 'primary_text' => 'Scopri la nuova collezione autunno.', 'headline' => 'Nuova collezione',
        'cta' => 'LEARN_MORE', 'url' => 'https://example.com/autunno', 'image_asset_id' => $asset['id'],
    ];
    $leads = req('POST', '/ad-plans', $a['token'], ['objective' => 'leads', 'cta' => 'SIGN_UP'] + $input)[1]['data'];
    $r = req('POST', "/ad-plans/{$leads['id']}/publish", $a['token']);
    check($r[0] === 422 && str_contains($r[1]['message'] ?? '', 'Gestione inserzioni'), 'obiettivo Contatti: spiega che va pubblicato da Gestione inserzioni');
    $plan = req('POST', '/ad-plans', $a['token'], $input)[1]['data'];
    check($plan['image_asset_id'] === $asset['id'] && $plan['meta']['published'] === false, 'sponsorizzata salvata con l\'immagine, non ancora su Meta');
    expectStatus('un\'altra organizzazione non può pubblicarla', req('POST', "/ad-plans/{$plan['id']}/publish", $b['token']), 404);
    $r = req('POST', "/ad-plans/{$plan['id']}/publish", $a['token']);
    check($r[0] === 202 && $r[1]['data']['meta']['status'] === 'publishing', 'pubblicazione affidata al worker');
    for ($i = 0; $i < 30; $i++) {
        $plan = req('GET', "/ad-plans/{$plan['id']}", $a['token'])[1]['data'];
        if (!in_array($plan['meta']['job_status'], ['queued', 'running'], true)) break;
        usleep(500000);
    }
    check($plan['meta']['ad_id'] !== null && $plan['meta']['status'] === 'paused' && $plan['meta']['sample'], 'creata su Meta in pausa', json_encode($plan['meta']));
    expectStatus('non si pubblica due volte', req('POST', "/ad-plans/{$plan['id']}/publish", $a['token']), 422);

    // Attivazione.
    expectStatus('attivare senza conferma del budget', req('POST', "/ad-plans/{$plan['id']}/activate", $a['token'], []), 422);
    expectStatus('attivare con un budget diverso da quello mostrato', req('POST', "/ad-plans/{$plan['id']}/activate", $a['token'], ['confirm_daily_budget' => 50]), 422);
    expectStatus('un\'altra organizzazione non può attivarla', req('POST', "/ad-plans/{$plan['id']}/activate", $b['token'], ['confirm_daily_budget' => 5]), 404);
    $r = req('POST', "/ad-plans/{$plan['id']}/activate", $a['token'], ['confirm_daily_budget' => 5]);
    check($r[0] === 200 && $r[1]['data']['status'] === 'live' && $r[1]['data']['meta']['activated_at'] !== null, 'attivata con la conferma del budget');

    // Risultati.
    $r = req('POST', "/ad-plans/{$plan['id']}/meta-sync", $a['token']);
    check($r[0] === 200 && ($r[1]['data']['meta']['results']['spent'] ?? 0) > 0 && $r[1]['data']['meta']['status'] === 'active', 'risultati letti da Meta', substr($r[2], 0, 300));
    $spend = req('GET', '/ad-spend?brand_id=' . $brand['id'], $a['token'])[1]['data'] ?? [];
    $rows = array_values(array_filter($spend, fn($s) => $s['source'] === 'meta'));
    check(count($rows) === 1 && (float)$rows[0]['spent'] > 0, 'la spesa arriva in Sponsorizzate senza inserirla a mano');
    req('POST', "/ad-plans/{$plan['id']}/meta-sync", $a['token']);
    $spend = req('GET', '/ad-spend?brand_id=' . $brand['id'], $a['token'])[1]['data'] ?? [];
    check(count(array_filter($spend, fn($s) => $s['source'] === 'meta')) === 1, 'rileggere i risultati non duplica la spesa');

    // Modifiche dopo la pubblicazione.
    expectStatus('i testi pubblicati non si cambiano da qui', req('PATCH', "/ad-plans/{$plan['id']}", $a['token'], ['headline' => 'Altro titolo']), 422);
    $r = req('PATCH', "/ad-plans/{$plan['id']}", $a['token'], ['daily_budget' => 7]);
    check($r[0] === 200 && (float)$r[1]["data"]["daily_budget"] === 7.0, "il budget si cambia anche su Meta", substr($r[2], 0, 300));
    expectStatus('budget oltre il tetto dell\'app', req('PATCH', "/ad-plans/{$plan['id']}", $a['token'], ['daily_budget' => 500]), 422);
    expectStatus('con i dati di esempio si elimina anche se attiva', req('DELETE', "/ad-plans/{$plan['id']}", $a['token']), 200);
    $plan2 = req('POST', '/ad-plans', $a['token'], $input)[1]['data'];
    $r = req('POST', "/ad-plans/{$plan2['id']}/pause", $a['token']);
    expectStatus('mettere in pausa una sponsorizzata non pubblicata', $r, 422);

    // Scollegamento.
    $r = req('DELETE', "/brands/{$brand['id']}/meta", $a['token']);
    check($r[0] === 200 && $r[1]['data']['connected'] === false, 'scollegato');
    $r = req('POST', "/ad-plans/{$plan2['id']}/publish", $a['token']);
    check($r[0] === 422 && str_contains($r[1]['message'] ?? '', 'Collega Facebook'), 'senza collegamento chiede di collegare Facebook');
} finally {
    $pdo = Database::connection();
    $pdo->prepare('DELETE FROM organizations WHERE id IN (?, ?)')->execute([$a['org'], $b['org']]);
}

echo $failures === 0 ? "\nTutti i controlli superati.\n" : "\n{$failures} controlli falliti.\n";
exit($failures === 0 ? 0 : 1);
