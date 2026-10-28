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

function waitJob(int $id, string $token): ?array
{
    for ($i = 0; $i < 30; $i++) {
        $job = req('GET', "/jobs/{$id}", $token)[1]['data'] ?? null;
        if (in_array($job['status'] ?? '', ['done', 'failed'], true)) return $job;
        sleep(1);
    }
    return $job;
}

echo "\nCampagne, calendario e approvazioni\n";
$campaign = req('POST', "/brands/{$brandA['id']}/campaigns", $a['token'], ['goal' => 'Più ordini online', 'weeks' => 2, 'posts_per_week' => 3, 'channels' => ['instagram', 'facebook'], 'start_date' => '2030-03-04']);
expectStatus('A crea una campagna (202)', $campaign, 202);
expectStatus('Campagna con troppi post rifiutata', req('POST', "/brands/{$brandA['id']}/campaigns", $a['token'], ['goal' => 'X', 'weeks' => 4, 'posts_per_week' => 5]), 422);
expectStatus('Campagna con canale non valido rifiutata', req('POST', "/brands/{$brandA['id']}/campaigns", $a['token'], ['goal' => 'X', 'channels' => ['tiktok']]), 422);
expectStatus('B non crea campagne sul brand di A', req('POST', "/brands/{$brandA['id']}/campaigns", $b['token'], ['goal' => 'X']), 404);
expectStatus('Viewer non crea campagne', req('POST', "/brands/{$brandA['id']}/campaigns", $viewer, ['goal' => 'X']), 403);
$campaignJob = waitJob((int)$campaign[1]['data']['job']['id'], $a['token']);
check('Campagna pianificata', ($campaignJob['status'] ?? '') === 'done', json_encode($campaignJob));
$campaignId = $campaign[1]['data']['project']['id'];
$campaignProject = req('GET', "/projects/{$campaignId}", $a['token'])[1]['data'] ?? [];
check('Strategia salvata nel progetto', is_array($campaignProject['strategy'] ?? null) && !empty($campaignProject['strategy']['key_messages']));
$posts = req('GET', "/projects/{$campaignId}/content", $a['token'])[1]['data'] ?? [];
check('Creati 6 post con data e grafica', count($posts) === 6 && count(array_filter($posts, fn($p) => $p['scheduled_at'] && is_array($p['visual']))) === 6, json_encode(array_column($posts, 'scheduled_at')));
check('Post dentro le date della campagna', $posts && min(array_column($posts, 'scheduled_at')) >= '2030-03-04' && max(array_column($posts, 'scheduled_at')) < '2030-03-18');

$calendarA = req('GET', '/calendar?from=2030-03-01&to=2030-03-31', $a['token']);
check('Calendario di A con i 6 post e il colore del brand', count($calendarA[1]['data'] ?? []) === 6 && ($calendarA[1]['data'][0]['brand_color'] ?? '') === '#5B4BDB', $calendarA[2]);
check('Calendario di B vuoto nello stesso periodo', (req('GET', '/calendar?from=2030-03-01&to=2030-03-31', $b['token'])[1]['data'] ?? null) === []);
expectStatus('Intervallo calendario troppo lungo rifiutato', req('GET', '/calendar?from=2030-01-01&to=2030-06-01', $a['token']), 422);

$post = $posts[0];
$moved = req('POST', "/content/{$post['id']}/schedule", $a['token'], ['scheduled_at' => '2030-03-10 09:15']);
check('A sposta un post nel calendario', ($moved[1]['data']['scheduled_at'] ?? '') === '2030-03-10 09:15:00', $moved[2]);
expectStatus('B non sposta i post di A', req('POST', "/content/{$post['id']}/schedule", $b['token'], ['scheduled_at' => '2030-03-11 09:00']), 404);
expectStatus('Data non valida rifiutata', req('POST', "/content/{$post['id']}/schedule", $a['token'], ['scheduled_at' => 'domani']), 422);

$visual = req('PATCH', "/content/{$post['id']}", $a['token'], ['visual' => ['headline' => 'Nuova torta', 'subheadline' => '', 'badge' => 'Novità', 'layout' => 'photo', 'asset_id' => $assetA['id']]]);
check('A imposta la grafica con una sua foto', ($visual[1]['data']['visual']['asset_id'] ?? null) === $assetA['id'], $visual[2]);
$assetB = req('POST', '/assets', $b['token'], null, ['file' => new CURLFile($pngPath, 'image/png', 'b.png')])[1]['data'];
expectStatus('A non usa una foto di B', req('PATCH', "/content/{$post['id']}", $a['token'], ['visual' => ['headline' => 'X', 'layout' => 'photo', 'asset_id' => $assetB['id']]]), 422);
expectStatus('Layout non valido rifiutato', req('PATCH', "/content/{$post['id']}", $a['token'], ['visual' => ['headline' => 'X', 'layout' => 'neon']]), 422);

req('POST', "/content/{$post['id']}/submit", $a['token']);
$approvalsA = array_column(req('GET', '/approvals', $a['token'])[1]['data'] ?? [], 'id');
check('Il post in revisione compare nelle approvazioni di A', in_array($post['id'], $approvalsA));
check('Approvazioni di B senza i post di A', !in_array($post['id'], array_column(req('GET', '/approvals', $b['token'])[1]['data'] ?? [], 'id')));

$dashA = req('GET', '/dashboard', $a['token'])[1]['data'] ?? [];
$dashB = req('GET', '/dashboard', $b['token'])[1]['data'] ?? [];
check('Dashboard di A con clienti e approvazioni', ($dashA['clients'] ?? 0) === 1 && ($dashA['pending_approval'] ?? 0) >= 1, json_encode($dashA));
check('Dashboard di B senza dati di A', ($dashB['pending_approval'] ?? -1) === 0 && ($dashB['active_campaigns'] ?? -1) === 0, json_encode($dashB));

echo "\nFoto personalizzata del post\n";
$photoPost = $posts[2];
$uploaded = req('POST', "/content/{$photoPost['id']}/photo", $a['token'], null, ['file' => new CURLFile($pngPath, 'image/png', 'prodotto.png')]);
expectStatus('A carica una foto sul post', $uploaded, 200);
$photoAsset = $uploaded[1]['data']['visual']['asset_id'] ?? null;
check('La grafica usa la foto con uno stile fotografico', $photoAsset !== null && in_array($uploaded[1]['data']['visual']['layout'] ?? '', ['photo', 'split'], true), $uploaded[2]);
check('La foto finisce nella libreria del cliente', in_array($photoAsset, array_column(req('GET', "/assets?brand_id={$brandA['id']}", $a['token'])[1]['data'] ?? [], 'id'), true));
expectStatus('B non carica foto sui post di A', req('POST', "/content/{$photoPost['id']}/photo", $b['token'], null, ['file' => new CURLFile($pngPath, 'image/png', 'x.png')]), 404);
expectStatus('Un PDF o testo non è una foto valida', req('POST', "/content/{$photoPost['id']}/photo", $a['token'], null, ['file' => new CURLFile($txtPath, 'image/png', 'finto.png')]), 422);

// Il cliente (viewer) può fornire la sua foto, anche mentre il post è in revisione.
$viewerDraft = req('POST', "/content/{$photoPost['id']}/photo", $viewer, null, ['file' => new CURLFile($pngPath, 'image/png', 'foto-cliente.png')]);
check('Il cliente carica una foto su una bozza', $viewerDraft[0] === 200 && ($viewerDraft[1]['data']['visual']['asset_id'] ?? null) !== $photoAsset, $viewerDraft[2]);
$viewerReview = req('POST', "/content/{$post['id']}/photo", $viewer, null, ['file' => new CURLFile($pngPath, 'image/png', 'foto-cliente.png')]);
check('Il cliente carica una foto su un post in revisione, che resta in revisione', $viewerReview[0] === 200 && ($viewerReview[1]['data']['status'] ?? '') === 'in_review', $viewerReview[2]);
expectStatus('Il cliente non modifica i testi', req('PATCH', "/content/{$photoPost['id']}", $viewer, ['title' => 'X']), 403);
expectStatus('Il cliente non carica file liberi in libreria', req('POST', '/assets', $viewer, null, ['file' => new CURLFile($pngPath, 'image/png', 'x.png')]), 403);
$approvedPost = $posts[3];
req('POST', "/content/{$approvedPost['id']}/submit", $a['token']);
req('POST', "/content/{$approvedPost['id']}/approve", $a['token']);
expectStatus('Non si cambia la foto di un post approvato', req('POST', "/content/{$approvedPost['id']}/photo", $viewer, null, ['file' => new CURLFile($pngPath, 'image/png', 'x.png')]), 409);

$variantJob = req('POST', "/projects/{$campaignId}/generate", $a['token'], ['channel' => 'instagram', 'brief' => 'Nuovo prodotto', 'variants' => 2, 'asset_id' => $photoAsset]);
expectStatus('Varianti con foto avviate (202)', $variantJob, 202);
$variantResult = waitJob((int)($variantJob[1]['data']['id'] ?? 0), $a['token']);
$variantIds = $variantResult['result']['content_ids'] ?? [];
$variantsWithPhoto = array_filter($variantIds, function ($id) use ($a, $photoAsset) {
    $v = req('GET', "/content/{$id}", $a['token'])[1]['data']['visual'] ?? [];
    return ($v['asset_id'] ?? null) === $photoAsset && in_array($v['layout'] ?? '', ['photo', 'split'], true);
});
check('Le varianti usano la foto scelta', count($variantIds) === 2 && count($variantsWithPhoto) === 2, json_encode($variantResult));
expectStatus('Varianti con una foto di B rifiutate', req('POST', "/projects/{$campaignId}/generate", $a['token'], ['channel' => 'instagram', 'brief' => 'X', 'asset_id' => $assetB['id']]), 422);

echo "\nVideo Studio\n";
// WAV di un secondo di silenzio, per provare le tracce audio senza file esterni.
$wavPath = sys_get_temp_dir() . '/m1-musica.wav';
$pcm = str_repeat("\0\0", 8000);
file_put_contents($wavPath, 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm);
$music = req('POST', '/assets', $a['token'], null, ['file' => new CURLFile($wavPath, 'audio/wav', 'musica.wav'), 'brand_profile_id' => (string)$brandA['id']]);
check('Traccia audio caricata nella libreria', $music[0] === 201 && ($music[1]['data']['kind'] ?? '') === 'audio', $music[2]);
$musicId = $music[1]['data']['id'] ?? 0;
expectStatus('B non crea video dai post di A', req('POST', "/content/{$photoPost['id']}/video", $b['token'], ['format' => 'square']), 404);
expectStatus('Il cliente non crea video', req('POST', "/content/{$photoPost['id']}/video", $viewer, ['format' => 'square']), 403);
expectStatus('Formato video non valido rifiutato', req('POST', "/content/{$photoPost['id']}/video", $a['token'], ['format' => 'cinema']), 422);
expectStatus('Una foto non vale come musica', req('POST', "/content/{$photoPost['id']}/video", $a['token'], ['format' => 'square', 'music_asset_id' => $photoAsset]), 422);
expectStatus('B non usa la musica di A', req('POST', "/content/{$photoPost['id']}/video", $b['token'], ['format' => 'square', 'music_asset_id' => $musicId]), 404);
$videoJob = req('POST', "/content/{$photoPost['id']}/video", $a['token'], ['format' => 'square', 'music_asset_id' => $musicId]);
expectStatus('A avvia il video (202)', $videoJob, 202);
$videoResult = null;
for ($i = 0; $i < 90; $i++) {
    $videoResult = req('GET', "/jobs/" . ($videoJob[1]['data']['id'] ?? 0), $a['token'])[1]['data'] ?? null;
    if (in_array($videoResult['status'] ?? '', ['done', 'failed'], true)) break;
    sleep(1);
}
check('Video montato', ($videoResult['status'] ?? '') === 'done', json_encode($videoResult));
$withVideo = req('GET', "/content/{$photoPost['id']}", $a['token'])[1]['data'] ?? [];
$videoId = $withVideo['video_asset_id'] ?? null;
check('Il post ha il video collegato', $videoId !== null && $videoId === ($videoResult['result']['asset_id'] ?? -1));
$videoFile = req('GET', "/assets/{$videoId}/file", $a['token']);
check('Il file è un MP4 scaricabile da A', $videoFile[0] === 200 && substr($videoFile[2], 4, 4) === 'ftyp');
expectStatus('B non scarica il video di A', req('GET', "/assets/{$videoId}/file", $b['token']), 404);
echo "  (montaggio: {$videoResult['result']['seconds']} s, {$videoResult['result']['scenes']} scene)\n";

echo "\nPubblicazione\n";
// Il post approvato con data di ieri deve comparire tra quelli da pubblicare.
req('POST', "/content/{$approvedPost['id']}/schedule", $a['token'], ['scheduled_at' => date('Y-m-d', strtotime('-1 day')) . ' 18:30']);
$due = array_column(req('GET', '/dashboard', $a['token'])[1]['data']['to_publish'] ?? [], 'id');
check('Il post approvato e scaduto è tra quelli da pubblicare', in_array($approvedPost['id'], $due));
check('B non vede i post di A da pubblicare', !in_array($approvedPost['id'], array_column(req('GET', '/dashboard', $b['token'])[1]['data']['to_publish'] ?? [], 'id')));
expectStatus('Una bozza non si pubblica', req('POST', "/content/{$photoPost['id']}/publish", $a['token'], []), 409);
expectStatus('B non pubblica i post di A', req('POST', "/content/{$approvedPost['id']}/publish", $b['token'], []), 404);
expectStatus('Il cliente non segna i post come pubblicati', req('POST', "/content/{$approvedPost['id']}/publish", $viewer, []), 403);
expectStatus('Link del post non valido rifiutato', req('POST', "/content/{$approvedPost['id']}/publish", $a['token'], ['url' => 'javascript:alert(1)']), 422);
$published = req('POST', "/content/{$approvedPost['id']}/publish", $a['token'], ['url' => 'https://www.instagram.com/p/esempio/']);
check('A segna il post come pubblicato con il link', ($published[1]['data']['status'] ?? '') === 'published' && !empty($published[1]['data']['published_at']) && ($published[1]['data']['published_url'] ?? '') === 'https://www.instagram.com/p/esempio/', $published[2]);
check('Il post pubblicato esce da quelli da pubblicare', !in_array($approvedPost['id'], array_column(req('GET', '/dashboard', $a['token'])[1]['data']['to_publish'] ?? [], 'id')));
expectStatus('Un post pubblicato non si sposta', req('POST', "/content/{$approvedPost['id']}/schedule", $a['token'], ['scheduled_at' => '2030-03-20 10:00']), 409);
expectStatus('Il cliente non può cambiare la foto di un post pubblicato', req('POST', "/content/{$approvedPost['id']}/photo", $viewer, null, ['file' => new CURLFile($pngPath, 'image/png', 'x.png')]), 409);
$unpublished = req('POST', "/content/{$approvedPost['id']}/unpublish", $a['token']);
check('Annullare la pubblicazione torna ad approvato', ($unpublished[1]['data']['status'] ?? '') === 'approved'
    && array_key_exists('published_url', $unpublished[1]['data'] ?? []) && $unpublished[1]['data']['published_url'] === null, $unpublished[2]);
expectStatus('B non annulla le pubblicazioni di A', req('POST', "/content/{$approvedPost['id']}/unpublish", $b['token']), 404);

expectStatus('Profilo social non valido rifiutato', req('PATCH', "/brands/{$brandA['id']}", $a['token'], ['instagram_url' => 'instagram']), 422);
$socials = req('PATCH', "/brands/{$brandA['id']}", $a['token'], ['instagram_url' => 'https://www.instagram.com/brand_a', 'facebook_url' => 'https://www.facebook.com/brandA']);
check('Profili social salvati nel brand kit', ($socials[1]['data']['instagram_url'] ?? '') === 'https://www.instagram.com/brand_a', $socials[2]);

echo "\nObiettivi suggeriti, riscrittura ed eliminazioni\n";
$goalsJob = req('POST', "/brands/{$brandA['id']}/goal-suggestions", $a['token'], []);
expectStatus('A chiede obiettivi suggeriti (202)', $goalsJob, 202);
expectStatus('B non chiede obiettivi per il cliente di A', req('POST', "/brands/{$brandA['id']}/goal-suggestions", $b['token'], []), 404);
$goals = waitJob((int)$goalsJob[1]['data']['id'], $a['token']);
check('Ricevuti 4 obiettivi con titolo e testo', count($goals['result']['goals'] ?? []) === 4 && !empty($goals['result']['goals'][0]['goal']), json_encode($goals));

$draft = $posts[1];
expectStatus('B non riscrive i post di A', req('POST', "/content/{$draft['id']}/rewrite", $b['token'], ['instruction' => 'Più breve']), 404);
expectStatus('Riscrittura senza istruzione rifiutata', req('POST', "/content/{$draft['id']}/rewrite", $a['token'], []), 422);
$rewriteJob = req('POST', "/content/{$draft['id']}/rewrite", $a['token'], ['instruction' => 'Più breve e diretto']);
$rewritten = waitJob((int)($rewriteJob[1]['data']['id'] ?? 0), $a['token']);
$after = req('GET', "/content/{$draft['id']}", $a['token'])[1]['data'] ?? [];
check('Post riscritto con la nuova istruzione', ($rewritten['status'] ?? '') === 'done' && str_contains($after['body'] ?? '', 'Più breve e diretto'), json_encode($rewritten));
expectStatus('Non si riscrive un post in revisione', req('POST', "/content/{$post['id']}/rewrite", $a['token'], ['instruction' => 'X']), 409);

expectStatus('B non elimina i post di A', req('DELETE', "/content/{$draft['id']}", $b['token']), 404);
expectStatus('Viewer non elimina post', req('DELETE', "/content/{$draft['id']}", $viewer), 403);
expectStatus('A elimina un post', req('DELETE', "/content/{$draft['id']}", $a['token']), 200);
expectStatus('Il post eliminato non esiste più', req('GET', "/content/{$draft['id']}", $a['token']), 404);

expectStatus('B non elimina la campagna di A', req('DELETE', "/projects/{$campaignId}", $b['token']), 404);
expectStatus('Editor non elimina campagne', req('DELETE', "/projects/{$campaignId}", $editor), 403);
expectStatus('A elimina la campagna', req('DELETE', "/projects/{$campaignId}", $a['token']), 200);
expectStatus('La campagna eliminata non esiste più', req('GET', "/projects/{$campaignId}", $a['token']), 404);
check('Anche i suoi post sono eliminati', (req('GET', '/calendar?from=2030-03-01&to=2030-03-31', $a['token'])[1]['data'] ?? null) === []);

$brandDel = req('POST', '/brands', $a['token'], ['name' => 'Cliente da eliminare'])[1]['data'];
$campDel = req('POST', "/brands/{$brandDel['id']}/campaigns", $a['token'], ['goal' => 'X', 'weeks' => 1, 'posts_per_week' => 1])[1]['data']['project'];
$photoDel = req('POST', '/assets', $a['token'], null, ['file' => new CURLFile($pngPath, 'image/png', 'foto.png'), 'brand_profile_id' => (string)$brandDel['id']])[1]['data'];
check('File collegato al cliente', ($photoDel['brand_profile_id'] ?? null) === $brandDel['id']);
check('Filtro della libreria per cliente', array_column(req('GET', "/assets?brand_id={$brandDel['id']}", $a['token'])[1]['data'] ?? [], 'id') === [$photoDel['id']]);
expectStatus('B non collega file ai clienti di A', req('POST', '/assets', $b['token'], null, ['file' => new CURLFile($pngPath, 'image/png', 'x.png'), 'brand_profile_id' => (string)$brandDel['id']]), 422);
$deleted = req('DELETE', "/brands/{$brandDel['id']}?with_campaigns=1", $a['token']);
check('Cliente eliminato con le sue campagne', ($deleted[1]['data']['deleted_campaigns'] ?? 0) === 1, $deleted[2]);
expectStatus('La campagna del cliente eliminato non esiste più', req('GET', "/projects/{$campDel['id']}", $a['token']), 404);

expectStatus('B non rianalizza il cliente di A', req('POST', "/brands/{$brandA['id']}/analyze", $b['token'], ['url' => 'example.com']), 404);
$reJob = req('POST', "/brands/{$brandA['id']}/analyze", $a['token'], ['url' => 'http://127.0.0.1/']);
$reResult = waitJob((int)($reJob[1]['data']['id'] ?? 0), $a['token']);
check('Nuova analisi verso un indirizzo interno bloccata', ($reResult['status'] ?? '') === 'failed', json_encode($reResult));

echo "\nAnalisi del sito: indirizzi interni bloccati\n";
expectStatus('Indirizzo non valido rifiutato', req('POST', '/onboarding/analyze', $a['token'], ['url' => 'non è un sito']), 422);
foreach (['http://nginx/', 'http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1/', 'http://db:3306/'] as $internal) {
    $job = req('POST', '/onboarding/analyze', $a['token'], ['url' => $internal]);
    $result = waitJob((int)($job[1]['data']['id'] ?? 0), $a['token']);
    check("Bloccato {$internal}", ($result['status'] ?? '') === 'failed' && preg_match('/non (è )?consentit|porte standard/i', $result['error'] ?? ''), json_encode($result));
}

echo "\nPannello Admin della piattaforma\n";
foreach (['GET /admin/overview', 'GET /admin/organizations', 'GET /admin/organizations/1'] as $route) {
    [$m, $p] = explode(' ', $route);
    expectStatus("Un titolare normale non accede a {$p}", req($m, $p, $a['token']), 403);
}
$orgB = req('GET', '/me', $b['token'])[1]['data']['user']['organization_id'];
expectStatus('Un titolare normale non sospende organizzazioni', req('POST', "/admin/organizations/{$orgB}/suspend", $a['token'], ['reason' => 'X']), 403);

$admin = register('Admin');
exec('php ' . escapeshellarg(__DIR__ . '/../bin/make-admin.php') . ' ' . escapeshellarg($admin['email']), $out, $code);
check('Nomina amministratore da riga di comando', $code === 0, implode("\n", $out));
check('/me indica l\'amministratore di piattaforma', (req('GET', '/me', $admin['token'])[1]['data']['user']['is_platform_admin'] ?? false) === true);
$overview = req('GET', '/admin/overview', $admin['token']);
check('Panoramica della piattaforma', $overview[0] === 200 && ($overview[1]['data']['organizations'] ?? 0) >= 3, $overview[2]);
$found = req('GET', '/admin/organizations?q=' . urlencode($a['email']), $admin['token'])[1]['data'] ?? [];
check('Ricerca per email del titolare', count($found) === 1 && $found[0]['owner_email'] === $a['email'] && $found[0]['clients_count'] >= 1, json_encode($found));
$orgA = $found[0]['id'] ?? 0;
$detail = req('GET', "/admin/organizations/{$orgA}", $admin['token'])[1]['data'] ?? [];
check('Scheda organizzazione con utenti, clienti e attività', in_array($a['email'], array_column($detail['users'] ?? [], 'email'), true) && !empty($detail['clients']) && !empty($detail['activity']));
expectStatus('Organizzazione inesistente', req('GET', '/admin/organizations/999999', $admin['token']), 404);

expectStatus('Sospensione senza motivo rifiutata', req('POST', "/admin/organizations/{$orgB}/suspend", $admin['token'], []), 422);
$adminOrg = req('GET', '/me', $admin['token'])[1]['data']['user']['organization_id'];
expectStatus("L'amministratore non sospende la propria organizzazione", req('POST', "/admin/organizations/{$adminOrg}/suspend", $admin['token'], ['reason' => 'X']), 409);
$suspended = req('POST', "/admin/organizations/{$orgB}/suspend", $admin['token'], ['reason' => 'Pagamento non ricevuto']);
check('Organizzazione sospesa', ($suspended[1]['data']['organization']['status'] ?? '') === 'suspended', $suspended[2]);
$blocked = req('GET', '/projects', $b['token']);
check('Le sessioni aperte della sospesa sono chiuse', in_array($blocked[0], [401, 403], true), $blocked[2]);
$bLogin = req('POST', '/auth/login', null, ['email' => $b['email'], 'password' => 'Local-Test-Password-123']);
check('La sospesa non può accedere', $bLogin[0] === 403 && ($bLogin[1]['error'] ?? '') === 'account_suspended', $bLogin[2]);
check('Le sospese si filtrano', in_array($orgB, array_column(req('GET', '/admin/organizations?status=suspended', $admin['token'])[1]['data'] ?? [], 'id')));
expectStatus('Riattivazione', req('POST', "/admin/organizations/{$orgB}/reactivate", $admin['token']), 200);
$b['token'] = login($b['email']);
check('Dopo la riattivazione si accede di nuovo', $b['token'] !== '' && req('GET', '/projects', $b['token'])[0] === 200);
$notes = req('PATCH', "/admin/organizations/{$orgA}", $admin['token'], ['admin_notes' => 'Cliente pilota, chiamare a novembre']);
check('Note interne salvate', ($notes[1]['data']['organization']['admin_notes'] ?? '') === 'Cliente pilota, chiamare a novembre', $notes[2]);
check('Le note non sono visibili all\'organizzazione', !str_contains(req('GET', '/me', $a['token'])[2], 'Cliente pilota'));

echo "\nRate limiting\n";
$target = 'm1-ratelimit-' . bin2hex(random_bytes(4)) . '@example.test';
for ($i = 0; $i < 5; $i++) req('POST', '/auth/login', null, ['email' => $target, 'password' => 'sbagliata-123456']);
expectStatus('Sesto tentativo di login bloccato', req('POST', '/auth/login', null, ['email' => $target, 'password' => 'sbagliata-123456']), 429);

echo "\n{$checks} controlli, {$failures} falliti.\n";
exit($failures === 0 ? 0 : 1);
