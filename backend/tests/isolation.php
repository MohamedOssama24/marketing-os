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

echo "\nSponsorizzate: registro e import del CSV di Meta\n";
$metaCsv = sys_get_temp_dir() . '/m1-meta.csv';
file_put_contents($metaCsv, "\xEF\xBB\xBF" . implode("\r\n", [
    'Nome della campagna;Inizio dei report;Fine dei report;Importo speso (EUR);Impression;Copertura;Clic sul link;Risultati',
    'Promo autunno;2026-09-01;2026-09-30;1.234,56;45.000;30.000;812;40',
    '"Lead; novembre";2026-09-10;2026-09-30;100,00;5000;;50;',
    ';2026-09-01;2026-09-30;1.334,56;50.000;;862;40',
]));
$csvFile = fn() => ['file' => new CURLFile($metaCsv, 'text/csv', 'meta.csv')];
$preview = req('POST', "/brands/{$brandA['id']}/ad-spend/import", $editor, null, $csvFile() + ['preview' => '1']);
check('Anteprima del CSV di Meta in italiano', ($preview[1]['data']['counts']['new'] ?? 0) === 2 && ($preview[1]['data']['total_spent'] ?? 0) == 1334.56 && ($preview[1]['data']['skipped'] ?? 0) === 1, $preview[2]);
check("L'anteprima non salva nulla", (req('GET', "/ad-spend?brand_id={$brandA['id']}", $a['token'])[1]['data'] ?? null) === []);
$imported = req('POST', "/brands/{$brandA['id']}/ad-spend/import", $editor, null, $csvFile());
check('Import del CSV', ($imported[1]['data']['counts']['new'] ?? 0) === 2, $imported[2]);
$rows = req('GET', "/ad-spend?brand_id={$brandA['id']}", $viewer)[1]['data'] ?? [];
$promo = array_values(array_filter($rows, fn($r) => $r['campaign_name'] === 'Promo autunno'))[0] ?? [];
check('Importi e clic letti correttamente', ($promo['spent'] ?? 0) == 1234.56 && ($promo['clicks'] ?? 0) === 812 && ($promo['impressions'] ?? 0) === 45000, json_encode($promo));
$again = req('POST', "/brands/{$brandA['id']}/ad-spend/import", $editor, null, $csvFile());
check('Reimportare aggiorna senza duplicare', ($again[1]['data']['counts']['update'] ?? 0) === 2 && ($again[1]['data']['counts']['new'] ?? 1) === 0, $again[2]);
$usdCsv = sys_get_temp_dir() . '/m1-usd.csv';
file_put_contents($usdCsv, "Campaign name,Reporting starts,Reporting ends,Amount spent (USD)\nX,2026-09-01,2026-09-30,10.50\n");
expectStatus('CSV in dollari rifiutato', req('POST', "/brands/{$brandA['id']}/ad-spend/import", $editor, null, ['file' => new CURLFile($usdCsv, 'text/csv', 'usd.csv')]), 422);
file_put_contents($usdCsv, "Campagna;Clic\nX;3\n");
expectStatus('CSV senza la colonna della spesa rifiutato', req('POST', "/brands/{$brandA['id']}/ad-spend/import", $editor, null, ['file' => new CURLFile($usdCsv, 'text/csv', 'x.csv')]), 422);
expectStatus('Viewer non importa', req('POST', "/brands/{$brandA['id']}/ad-spend/import", $viewer, null, $csvFile()), 403);
expectStatus('B non importa sui clienti di A', req('POST', "/brands/{$brandA['id']}/ad-spend/import", $b['token'], null, $csvFile()), 404);

$manual = req('POST', '/ad-spend', $editor, ['brand_profile_id' => $brandA['id'], 'platform' => 'google', 'campaign_name' => 'Ricerca Google', 'period_start' => '2026-09-05', 'period_end' => '2026-09-20', 'spent' => 200.5, 'clicks' => 100, 'impressions' => 4000]);
expectStatus('Editor inserisce una spesa a mano', $manual, 201);
$manualId = $manual[1]['data']['id'] ?? 0;
expectStatus('Date invertite rifiutate', req('POST', '/ad-spend', $editor, ['brand_profile_id' => $brandA['id'], 'campaign_name' => 'X', 'period_start' => '2026-09-20', 'period_end' => '2026-09-01', 'spent' => 1]), 422);
expectStatus('B non registra spese sui clienti di A', req('POST', '/ad-spend', $b['token'], ['brand_profile_id' => $brandA['id'], 'campaign_name' => 'X', 'period_start' => '2026-09-01', 'period_end' => '2026-09-02', 'spent' => 1]), 422);
expectStatus('B non modifica le spese di A', req('PATCH', "/ad-spend/{$manualId}", $b['token'], ['spent' => 1]), 404);
expectStatus('B non elimina le spese di A', req('DELETE', "/ad-spend/{$manualId}", $b['token']), 404);
check('Lista spese di B senza righe di A', !in_array($manualId, array_column(req('GET', '/ad-spend', $b['token'])[1]['data'] ?? [], 'id')));
$summary = req('GET', "/ad-spend/summary?year=2026&brand_id={$brandA['id']}", $a['token'])[1]['data'] ?? [];
check('Riepilogo annuale: speso, clic e CPC', ($summary['totals']['spent'] ?? 0) == 1535.06 && ($summary['totals']['clicks'] ?? 0) === 962 && ($summary['totals']['cpc'] ?? 0) == 1.6 && ($summary['months'][8]['spent'] ?? 0) == 1535.06, json_encode($summary['totals'] ?? null));

echo "\nFatturazione\n";
expectStatus('Editor non vede le fatture', req('GET', '/invoices?period=2026-09', $editor), 403);
expectStatus('Viewer non vede i canoni', req('GET', '/billing/clients', $viewer), 403);
expectStatus('Codice SDI non valido', req('PATCH', "/billing/clients/{$brandA['id']}", $a['token'], ['sdi_code' => 'ABC']), 422);
expectStatus('B non cambia il canone dei clienti di A', req('PATCH', "/billing/clients/{$brandA['id']}", $b['token'], ['monthly_fee' => 1]), 404);
$billing = req('PATCH', "/billing/clients/{$brandA['id']}", $a['token'], ['monthly_fee' => 500, 'ad_fee_percent' => 10, 'billing_name' => 'Brand A S.r.l.', 'vat_number' => 'IT01234567890', 'sdi_code' => 'abc1234']);
check('Canone, commissione e dati di fatturazione', ($billing[1]['data']['monthly_fee'] ?? 0) == 500 && ($billing[1]['data']['sdi_code'] ?? '') === 'ABC1234', $billing[2]);
$prepared = req('POST', '/invoices/prepare', $a['token'], ['period' => '2026-09']);
$invoiceA = array_values(array_filter($prepared[1]['data']['invoices'] ?? [], fn($i) => $i['brand_profile_id'] === $brandA['id']))[0] ?? [];
// 500 canone + 1535,06 sponsorizzate + 153,51 commissione = 2188,57 + IVA 22% 481,49
check('Bozza del mese con canone, spese e commissione', ($invoiceA['subtotal'] ?? 0) == 2188.57 && ($invoiceA['vat_amount'] ?? 0) == 481.49 && ($invoiceA['total'] ?? 0) == 2670.06 && ($invoiceA['lines_count'] ?? 0) === 5, $prepared[2]);
$invoiceId = $invoiceA['id'] ?? 0;
expectStatus('B non vede le fatture di A', req('GET', "/invoices/{$invoiceId}", $b['token']), 404);
expectStatus('B non emette le fatture di A', req('POST', "/invoices/{$invoiceId}/issue", $b['token'], ['number' => '1']), 404);
req('POST', "/invoices/{$invoiceId}/lines", $a['token'], ['description' => 'Servizio fotografico', 'amount' => 150]);
req('PATCH', "/ad-spend/{$manualId}", $editor, ['spent' => 300]);
$draft = req('GET', "/invoices/{$invoiceId}", $a['token'])[1]['data'] ?? [];
// 500 + 1634,56 + 163,46 + 150
check('La bozza segue le spese modificate e tiene le voci a mano', ($draft['subtotal'] ?? 0) == 2448.02 && in_array('Servizio fotografico', array_column($draft['lines'] ?? [], 'description'), true), json_encode($draft['lines'] ?? null));
expectStatus('Emissione senza numero rifiutata', req('POST', "/invoices/{$invoiceId}/issue", $a['token'], []), 422);
$issued = req('POST', "/invoices/{$invoiceId}/issue", $a['token'], ['number' => '42/2026', 'issued_on' => '2026-10-01']);
check('Fattura emessa', ($issued[1]['data']['status'] ?? '') === 'invoiced' && ($issued[1]['data']['number'] ?? '') === '42/2026', $issued[2]);
expectStatus('Una spesa fatturata non si modifica', req('PATCH', "/ad-spend/{$manualId}", $editor, ['spent' => 1]), 409);
expectStatus('Una spesa fatturata non si elimina', req('DELETE', "/ad-spend/{$manualId}", $editor), 409);
expectStatus('Una fattura emessa non si modifica', req('POST', "/invoices/{$invoiceId}/lines", $a['token'], ['description' => 'X', 'amount' => 1]), 409);
check('Reimportare non tocca le righe fatturate', (req('POST', "/brands/{$brandA['id']}/ad-spend/import", $editor, null, $csvFile())[1]['data']['counts']['locked'] ?? 0) === 2);
expectStatus('Segnata come pagata', req('POST', "/invoices/{$invoiceId}/paid", $a['token'], ['paid_on' => '2026-10-15']), 200);
check('Preparare di nuovo salta le fatture emesse', (req('POST', '/invoices/prepare', $a['token'], ['period' => '2026-09'])[1]['data']['skipped'] ?? 0) >= 1);
$csv = req('GET', '/invoices/export?period=2026-09', $a['token']);
check('Export CSV per il commercialista', $csv[0] === 200 && str_contains($csv[2], 'Canone gestione marketing') && str_contains($csv[2], '2448,02') && str_contains($csv[2], 'IT01234567890'), substr($csv[2], 0, 200));
check("L'export di B non contiene le fatture di A", !str_contains(req('GET', '/invoices/export?period=2026-09', $b['token'])[2], 'Brand A'));
req('POST', "/invoices/{$invoiceId}/reopen", $a['token']);
$reopened = req('POST', "/invoices/{$invoiceId}/reopen", $a['token']);
check('Riaperta due volte torna da fatturare', ($reopened[1]['data']['status'] ?? '') === 'to_invoice', $reopened[2]);
expectStatus('Eliminazione della bozza', req('DELETE', "/invoices/{$invoiceId}", $a['token']), 200);
check('Le spese tornano da fatturare', array_filter(req('GET', "/ad-spend?brand_id={$brandA['id']}", $a['token'])[1]['data'] ?? [], fn($r) => $r['invoice_id'] !== null) === []);

echo "\nLink tracciati e clic\n";
/** Apre un link breve come farebbe un browser, senza seguire il reindirizzamento. */
function hit(string $code, string $agent, string $referrer = ''): array
{
    global $base;
    $ch = curl_init(preg_replace('#/api/v1$#', '', $base) . "/r/{$code}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => $agent, CURLOPT_REFERER => $referrer]);
    $response = (string)curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, preg_match('/^Location:\s*(\S+)/mi', $response, $m) ? $m[1] : null];
}
$iphoneInstagram = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.0.40.92';
$desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

expectStatus('Viewer non crea link', req('POST', '/links', $viewer, ['content_item_id' => $contentA['id'], 'target_url' => 'https://example.com']), 403);
expectStatus('B non crea link sui post di A', req('POST', '/links', $b['token'], ['content_item_id' => $contentA['id'], 'target_url' => 'https://example.com']), 422);
expectStatus('Destinazione non valida', req('POST', '/links', $editor, ['content_item_id' => $contentA['id'], 'target_url' => 'non è un sito']), 422);
$link = req('POST', '/links', $editor, ['content_item_id' => $contentA['id'], 'target_url' => 'example.com/promo?x=1#form']);
$linkData = $link[1]['data'] ?? [];
check('Link del post con UTM ricavati dal post', $link[0] === 201 && ($linkData['utm_source'] ?? '') === 'instagram' && ($linkData['utm_medium'] ?? '') === 'social' && ($linkData['utm_content'] ?? '') === "post-{$contentA['id']}" && ($linkData['brand_profile_id'] ?? 0) === $brandA['id'], $link[2]);
check('Destinazione con parametri UTM', ($linkData['destination_url'] ?? '') === "https://example.com/promo?x=1&utm_source=instagram&utm_medium=social&utm_campaign=progetto-a&utm_content=post-{$contentA['id']}#form", $linkData['destination_url'] ?? '');
check('Lo stesso post riusa lo stesso link', (req('POST', '/links', $editor, ['content_item_id' => $contentA['id'], 'target_url' => 'https://example.com/altro'])[1]['data']['id'] ?? 0) === $linkData['id']);
$code = $linkData['code'] ?? 'x';
$first = hit($code, $iphoneInstagram);
check('Il link breve reindirizza alla destinazione', $first[0] === 302 && $first[1] === $linkData['destination_url'], json_encode($first));
hit($code, $iphoneInstagram);
check("L'anteprima dei social non conta come clic", hit($code, 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)')[0] === 302);
hit($code, $desktop, 'https://www.facebook.com/');
check('Link inesistente', hit('nonEsiste99', $desktop)[0] === 404);
$listed = array_values(array_filter(req('GET', '/links', $a['token'])[1]['data'] ?? [], fn($l) => $l['id'] === $linkData['id']))[0] ?? [];
check('Clic e visitatori unici', ($listed['clicks'] ?? 0) === 3 && ($listed['unique_clicks'] ?? 0) === 2, json_encode($listed));
$linkSummary = req('GET', "/links/summary?link_id={$linkData['id']}", $a['token'])[1]['data'] ?? [];
$sources = array_column($linkSummary['sources'] ?? [], 'clicks', 'name');
$devices = array_column($linkSummary['devices'] ?? [], 'clicks', 'name');
check('Provenienza e dispositivo dei clic', ($linkSummary['clicks'] ?? 0) === 3 && ($sources['instagram'] ?? 0) === 2 && ($sources['facebook'] ?? 0) === 1 && ($devices['mobile'] ?? 0) === 2 && ($devices['desktop'] ?? 0) === 1 && end($linkSummary['daily'])['clicks'] === 3, json_encode($linkSummary));
$posts = req('GET', "/projects/{$projectA['id']}/content", $viewer)[1]['data'] ?? [];
$postA = array_values(array_filter($posts, fn($p) => $p['id'] === $contentA['id']))[0] ?? [];
check('I clic compaiono sul post', ($postA['tracked_link']['clicks'] ?? 0) === 3, json_encode($postA['tracked_link'] ?? null));
check('Lista link di B senza link di A', !in_array($linkData['id'], array_column(req('GET', '/links', $b['token'])[1]['data'] ?? [], 'id')));
check('Riepilogo di B senza clic di A', (req('GET', '/links/summary', $b['token'])[1]['data']['clicks'] ?? -1) === 0);
expectStatus('B non elimina i link di A', req('DELETE', "/links/{$linkData['id']}", $b['token']), 404);
expectStatus('A elimina il link', req('DELETE', "/links/{$linkData['id']}", $editor), 200);
check('Il link eliminato non reindirizza più', hit($code, $desktop)[0] === 404);
$free = req('POST', '/links', $a['token'], ['brand_profile_id' => $brandA['id'], 'target_url' => 'https://example.com/?utm_source=bio', 'label' => 'Link in bio']);
check('Link libero che rispetta gli UTM già presenti', $free[0] === 201 && ($free[1]['data']['destination_url'] ?? '') === 'https://example.com/?utm_source=bio', $free[2]);

echo "\nPagamento delle sponsorizzate Meta\n";
expectStatus('Viewer vede la configurazione', req('GET', "/brands/{$brandA['id']}/ads-setup", $viewer), 200);
expectStatus('Viewer non la modifica', req('PATCH', "/brands/{$brandA['id']}/ads-setup", $viewer, ['ads_payer' => 'client']), 403);
expectStatus('B non vede la configurazione di A', req('GET', "/brands/{$brandA['id']}/ads-setup", $b['token']), 404);
expectStatus('B non modifica la configurazione di A', req('PATCH', "/brands/{$brandA['id']}/ads-setup", $b['token'], ['ads_payer' => 'client']), 404);
expectStatus('ID account pubblicitario non valido', req('PATCH', "/brands/{$brandA['id']}/ads-setup", $editor, ['meta_ad_account_id' => 'abc']), 422);
$setup = req('PATCH', "/brands/{$brandA['id']}/ads-setup", $editor, ['ads_payer' => 'client', 'meta_ad_account_id' => 'act_123456789', 'steps' => ['client_payment', 'agency_payment', 'x']]);
check('Chi paga, account e passaggi della guida', ($setup[1]['data']['meta_ad_account_id'] ?? '') === '123456789' && ($setup[1]['data']['steps'] ?? null) === ['client_payment'] && ($setup[1]['data']['total_steps'] ?? 0) === 5, $setup[2]);
$switched = req('PATCH', "/brands/{$brandA['id']}/ads-setup", $editor, ['ads_payer' => 'agency']);
check('Cambiando chi paga i passaggi ripartono', ($switched[1]['data']['steps'] ?? null) === [], $switched[2]);
req('PATCH', "/brands/{$brandA['id']}/ads-setup", $editor, ['ads_payer' => 'client']);
$clientPays = req('POST', '/invoices/prepare', $a['token'], ['period' => '2026-09']);
$clientInvoice = array_values(array_filter($clientPays[1]['data']['invoices'] ?? [], fn($i) => $i['brand_profile_id'] === $brandA['id']))[0] ?? [];
$clientLines = req('GET', '/invoices/' . ($clientInvoice['id'] ?? 0), $a['token'])[1]['data']['lines'] ?? [];
// Se paga il cliente: canone 500 + commissione 10% di 1634,56, senza rifatturare la spesa.
check('Se paga il cliente si fattura solo la commissione', ($clientInvoice['subtotal'] ?? 0) == 663.46 && !in_array('ad_spend', array_column($clientLines, 'kind'), true), json_encode($clientLines));
req('DELETE', '/invoices/' . ($clientInvoice['id'] ?? 0), $a['token']);

echo "\nReport mensile per il cliente\n";
$reportPath = "/brands/{$brandA['id']}/report";
expectStatus('Mese non valido', req('GET', "{$reportPath}?period=2026-13", $a['token']), 422);
expectStatus('Viewer legge il report', req('GET', "{$reportPath}?period=2026-09", $viewer), 200);
expectStatus('Viewer non scrive il commento', req('PATCH', $reportPath, $viewer, ['period' => '2026-09', 'summary' => 'x']), 403);
expectStatus('B non legge il report di A', req('GET', "{$reportPath}?period=2026-09", $b['token']), 404);
expectStatus('B non condivide il report di A', req('POST', "{$reportPath}/share", $b['token'], ['period' => '2026-09']), 404);
$report = req('GET', "{$reportPath}?period=2026-09", $a['token'])[1]['data'] ?? [];
check('Spesa del mese uguale alla somma delle campagne', abs(($report['ads']['totals']['spent'] ?? -1) - array_sum(array_column($report['ads']['campaigns'] ?? [], 'spent'))) < 0.01 && count($report['clicks']['daily'] ?? []) === 30, json_encode($report['ads'] ?? null));
$noted = req('PATCH', $reportPath, $editor, ['period' => '2026-09', 'summary' => 'Ottimo mese', 'next_steps' => 'Campagna di Natale']);
check("L'editor salva il commento", ($noted[1]['data']['summary'] ?? '') === 'Ottimo mese' && array_key_exists('token', $noted[1]['data']['share'] ?? []) && $noted[1]['data']['share']['token'] === null, $noted[2]);
$shared = req('POST', "{$reportPath}/share", $editor, ['period' => '2026-09']);
$reportToken = $shared[1]['data']['share']['token'] ?? '';
check('Link pubblico creato senza perdere il commento', preg_match('/^[a-f0-9]{32}$/', $reportToken) === 1 && ($shared[1]['data']['summary'] ?? '') === 'Ottimo mese', $shared[2]);
check('Condividere di nuovo mantiene lo stesso link', (req('POST', "{$reportPath}/share", $a['token'], ['period' => '2026-09'])[1]['data']['share']['token'] ?? '') === $reportToken);
$public = req('GET', "/public/reports/{$reportToken}");
check('Il cliente apre il report senza accesso', $public[0] === 200 && ($public[1]['data']['summary'] ?? '') === 'Ottimo mese' && !array_key_exists('share', $public[1]['data'] ?? []) && array_key_exists('logo_asset_id', $public[1]['data']['client'] ?? []) && $public[1]['data']['client']['logo_asset_id'] === null, $public[2]);
check("Le aperture del cliente si contano", (req('GET', "{$reportPath}?period=2026-09", $a['token'])[1]['data']['share']['views'] ?? 0) === 1);
$statusA = array_values(array_filter(req('GET', '/reports?period=2026-09', $a['token'])[1]['data']['clients'] ?? [], fn($c) => $c['id'] === $brandA['id']))[0] ?? [];
check('Stato del report nella lista', ($statusA['shared'] ?? false) === true && ($statusA['has_summary'] ?? false) === true && ($statusA['views'] ?? 0) === 1, json_encode($statusA));
check('Lista report di B senza clienti di A', !in_array($brandA['id'], array_column(req('GET', '/reports?period=2026-09', $b['token'])[1]['data']['clients'] ?? [], 'id')));
expectStatus('Viewer non disattiva il link', req('POST', "{$reportPath}/unshare", $viewer, ['period' => '2026-09']), 403);
expectStatus('A disattiva il link', req('POST', "{$reportPath}/unshare", $a['token'], ['period' => '2026-09']), 200);
expectStatus('Il link disattivato non mostra più il report', req('GET', "/public/reports/{$reportToken}"), 404);
expectStatus('Né il logo', req('GET', "/public/reports/{$reportToken}/logo"), 404);


echo "\nPotenziali clienti e audit gratuito\n";
expectStatus('Viewer non crea audit', req('POST', '/prospects', $viewer, ['url' => 'https://example.com']), 403);
$prospect = req('POST', '/prospects', $editor, ['url' => 'http://127.0.0.1/']);
expectStatus("L'editor avvia un audit (202)", $prospect, 202);
$prospectId = $prospect[1]['data']['id'] ?? 0;
$auditJob = waitJob((int)($prospect[1]['data']['job_id'] ?? 0), $a['token']);
check('Un indirizzo interno non viene letto (protezione SSRF)', ($auditJob['status'] ?? '') === 'failed', json_encode($auditJob));
expectStatus('B non vede il potenziale cliente di A', req('GET', "/prospects/{$prospectId}", $b['token']), 404);
check('Lista di B senza potenziali clienti di A', !in_array($prospectId, array_column(req('GET', '/prospects', $b['token'])[1]['data'] ?? [], 'id')));
expectStatus('Senza audit pronto non si condivide', req('POST', "/prospects/{$prospectId}/share", $a['token']), 409);
// L'audit di un sito esterno richiede internet: qui si simula il risultato del worker.
require_once __DIR__ . '/../src/bootstrap.php';
$fakeAudit = ['name' => 'Pizzeria Test', 'summary' => 'Pizzeria', 'score' => 50, 'checks' => [], 'strengths' => [], 'issues' => [], 'post_ideas' => [], 'services' => [], 'pitch' => 'Sentiamoci', 'colors' => [], 'site_url' => 'https://example.com', 'analyzed_at' => '2026-10-01 10:00:00'];
Database::connection()->prepare('UPDATE prospects SET audit=? WHERE id=?')->execute([json_encode($fakeAudit), $prospectId]);
expectStatus('Canone proposto non valido', req('PATCH', "/prospects/{$prospectId}", $a['token'], ['monthly_fee' => -5]), 422);
$updatedProspect = req('PATCH', "/prospects/{$prospectId}", $editor, ['monthly_fee' => 450, 'agency_note' => 'A presto!']);
check('Proposta salvata', ($updatedProspect[1]['data']['monthly_fee'] ?? 0) == 450, $updatedProspect[2]);
$sharedAudit = req('POST', "/prospects/{$prospectId}/share", $editor);
$auditToken = $sharedAudit[1]['data']['share_token'] ?? '';
check('Condiviso: link creato e stato "inviato"', preg_match('/^[a-f0-9]{32}$/', $auditToken) === 1 && ($sharedAudit[1]['data']['status'] ?? '') === 'sent', $sharedAudit[2]);
$publicAudit = req('GET', "/public/audits/{$auditToken}");
check("Il potenziale cliente apre l'audit senza accesso", $publicAudit[0] === 200 && ($publicAudit[1]['data']['monthly_fee'] ?? 0) == 450 && !array_key_exists('share_token', $publicAudit[1]['data'] ?? []), $publicAudit[2]);
check("Le aperture dell'audit si contano", (req('GET', "/prospects/{$prospectId}", $a['token'])[1]['data']['views'] ?? 0) === 1);
expectStatus('Token inesistente', req('GET', '/public/audits/' . str_repeat('a', 32)), 404);
req('POST', "/prospects/{$prospectId}/unshare", $a['token']);
expectStatus("Link dell'audit disattivato", req('GET', "/public/audits/{$auditToken}"), 404);
expectStatus('B non elimina il potenziale cliente di A', req('DELETE', "/prospects/{$prospectId}", $b['token']), 404);

echo "\nAltri formati e stile appreso\n";
$source = req('POST', "/projects/{$projectA['id']}/content", $a['token'], ['channel' => 'instagram', 'title' => 'Nuovo menù', 'body' => 'Da oggi il menù autunnale.'])[1]['data'];
expectStatus('Formato non valido', req('POST', "/content/{$source['id']}/repurpose", $a['token'], ['formats' => ['tiktok']]), 422);
expectStatus('Viewer non trasforma', req('POST', "/content/{$source['id']}/repurpose", $viewer, ['formats' => ['reel']]), 403);
expectStatus('B non trasforma il contenuto di A', req('POST', "/content/{$source['id']}/repurpose", $b['token'], ['formats' => ['reel']]), 404);
$repurpose = req('POST', "/content/{$source['id']}/repurpose", $editor, ['formats' => ['carosello', 'newsletter']]);
$repurposeJob = waitJob((int)($repurpose[1]['data']['id'] ?? 0), $editor);
$newIds = $repurposeJob['result']['content_ids'] ?? [];
check('Due nuovi formati nella stessa campagna', count($newIds) === 2 && ($repurposeJob['result']['project_id'] ?? 0) === $projectA['id'], json_encode($repurposeJob));
$newsletter = array_values(array_filter(array_map(fn($id) => req('GET', "/content/{$id}", $a['token'])[1]['data'] ?? [], $newIds), fn($c) => ($c['channel'] ?? '') === 'email'))[0] ?? [];
check('La newsletter è una email in bozza', ($newsletter['content_type'] ?? '') === 'email' && ($newsletter['status'] ?? '') === 'draft', json_encode($newsletter));
$stylePath = "/brands/{$brandA['id']}/style-examples";
$before = count(req('GET', $stylePath, $a['token'])[1]['data'] ?? []);
req('PATCH', "/content/{$newsletter['id']}", $editor, ['body' => 'Ciao! Da oggi trovate il nostro menù autunnale, con zucca e funghi del territorio. Vi aspettiamo.']);
req('POST', "/content/{$newsletter['id']}/submit", $editor);
req('POST', "/content/{$newsletter['id']}/approve", $a['token']);
$examples = req('GET', $stylePath, $a['token'])[1]['data'] ?? [];
check('La correzione approvata diventa un esempio di stile', count($examples) === $before + 1 && ($examples[0]['kind'] ?? '') === 'edit' && str_contains($examples[0]['after_text'] ?? '', 'zucca'), json_encode($examples[0] ?? null));
$carousel = array_values(array_diff($newIds, [$newsletter['id']]))[0];
req('POST', "/content/{$carousel}/submit", $editor);
req('POST', "/content/{$carousel}/reject", $a['token'], ['note' => 'Niente emoji, tono più sobrio']);
$examples = req('GET', $stylePath, $a['token'])[1]['data'] ?? [];
check('La richiesta di modifiche diventa una regola di stile', ($examples[0]['kind'] ?? '') === 'feedback' && ($examples[0]['after_text'] ?? '') === 'Niente emoji, tono più sobrio', json_encode($examples[0] ?? null));
expectStatus('B non legge lo stile di A', req('GET', $stylePath, $b['token']), 404);
expectStatus('B non cancella un esempio di A', req('DELETE', "/style-examples/{$examples[0]['id']}", $b['token']), 404);
expectStatus('Viewer non cancella esempi', req('DELETE', "/style-examples/{$examples[0]['id']}", $viewer), 403);

echo "\nOre e redditività\n";
expectStatus('Viewer non segna ore', req('POST', '/time-entries', $viewer, ['brand_id' => $brandA['id'], 'minutes' => 30]), 403);
expectStatus('B non segna ore sul cliente di A', req('POST', '/time-entries', $b['token'], ['brand_id' => $brandA['id'], 'minutes' => 30]), 404);
expectStatus('Durata non valida', req('POST', '/time-entries', $editor, ['brand_id' => $brandA['id'], 'minutes' => 0]), 422);
$entry = req('POST', '/time-entries', $editor, ['brand_id' => $brandA['id'], 'minutes' => 120, 'worked_on' => '2026-09-10', 'note' => 'Calendario']);
expectStatus("L'editor segna 2 ore", $entry, 201);
expectStatus("L'editor non vede la redditività", req('GET', '/profitability?period=2026-09', $editor), 403);
req('PATCH', '/profitability/settings', $a['token'], ['hourly_cost' => 40]);
$profit = req('GET', '/profitability?period=2026-09', $a['token'])[1]['data'] ?? [];
$profitA = array_values(array_filter($profit['clients'] ?? [], fn($c) => $c['id'] === $brandA['id']))[0] ?? [];
check('Costo del tempo = ore × costo orario', ($profitA['minutes'] ?? 0) === 120 && abs(($profitA['cost'] ?? 0) - 80) < 0.01 && $profit['hourly_cost'] == 40, json_encode($profitA));
check('Margine = ricavo − costo', abs(($profitA['margin'] ?? 0) - (($profitA['revenue'] ?? 0) - 80)) < 0.01, json_encode($profitA));
check('Redditività di B senza clienti di A', !in_array($brandA['id'], array_column(req('GET', '/profitability?period=2026-09', $b['token'])[1]['data']['clients'] ?? [], 'id')));
$otherEntry = req('POST', '/time-entries', $a['token'], ['brand_id' => $brandA['id'], 'minutes' => 15, 'worked_on' => '2026-09-11'])[1]['data']['id'];
expectStatus("L'editor non cancella le ore di un altro", req('DELETE', "/time-entries/{$otherEntry}", $editor), 403);
expectStatus("L'editor cancella le sue ore", req('DELETE', "/time-entries/{$entry[1]['data']['id']}", $editor), 200);

echo "\nRecensioni, ricorrenze e avvisi\n";
$suggest = req('POST', "/brands/{$brandA['id']}/review-replies/suggest", $editor, ['platform' => 'google', 'rating' => 2, 'author' => 'Marco', 'review_text' => 'Servizio lento.']);
$repliesJob = waitJob((int)($suggest[1]['data']['id'] ?? 0), $editor);
check('Tre risposte proposte', count($repliesJob['result']['replies'] ?? []) === 3, json_encode($repliesJob));
expectStatus('Stelle non valide', req('POST', "/brands/{$brandA['id']}/review-replies/suggest", $editor, ['rating' => 7, 'review_text' => 'x']), 422);
expectStatus('B non chiede risposte per il cliente di A', req('POST', "/brands/{$brandA['id']}/review-replies/suggest", $b['token'], ['review_text' => 'x']), 404);
$savedReply = req('POST', '/review-replies', $editor, ['brand_id' => $brandA['id'], 'platform' => 'google', 'rating' => 2, 'author' => 'Marco', 'review_text' => 'Servizio lento.', 'reply' => 'Grazie Marco, ci dispiace.']);
expectStatus('Risposta salvata', $savedReply, 201);
check('B non vede le risposte di A', (req('GET', '/review-replies', $b['token'])[1]['data'] ?? null) === []);
expectStatus('B non cancella la risposta di A', req('DELETE', "/review-replies/{$savedReply[1]['data']['id']}", $b['token']), 404);
$occasions = array_column(req('GET', '/occasions?from=2027-03-01&to=2027-05-31', $a['token'])[1]['data'] ?? [], 'date', 'name');
check('Pasqua 2027 il 28 marzo, festa della mamma il 9 maggio', ($occasions['Pasqua'] ?? '') === '2027-03-28' && ($occasions['Festa della mamma'] ?? '') === '2027-05-09', json_encode($occasions));
expectStatus('Intervallo di ricorrenze troppo lungo', req('GET', '/occasions?from=2027-01-01&to=2029-01-01', $a['token']), 422);

echo "\nPortale del cliente\n";
expectStatus('Viewer non attiva il portale', req('POST', "/brands/{$brandA['id']}/portal/enable", $viewer), 403);
expectStatus('B non attiva il portale di A', req('POST', "/brands/{$brandA['id']}/portal/enable", $b['token']), 404);
$portalToken = req('POST', "/brands/{$brandA['id']}/portal/enable", $editor)[1]['data']['token'] ?? '';
check('Portale attivato', preg_match('/^[a-f0-9]{32}$/', $portalToken) === 1);
$toReview = req('POST', "/projects/{$projectA['id']}/content", $a['token'], ['channel' => 'facebook', 'title' => 'Da approvare', 'body' => "Testo per il cliente\n\n---\nNote per il revisore: manca il prezzo"])[1]['data'];
req('POST', "/content/{$toReview['id']}/submit", $a['token']);
$portal = req('GET', "/public/portal/{$portalToken}");
$pendingPost = array_values(array_filter($portal[1]['data']['pending'] ?? [], fn($p) => $p['id'] === $toReview['id']))[0] ?? [];
check('Il cliente vede il post da approvare senza accesso', $portal[0] === 200 && $pendingPost !== [], $portal[2]);
check('Le note interne non arrivano al cliente', ($pendingPost['body'] ?? '') === 'Testo per il cliente', json_encode($pendingPost));
expectStatus('Richiesta di modifiche senza nota', req('POST', "/public/portal/{$portalToken}/content/{$toReview['id']}/reject", null, ['note' => '']), 422);
$projectB = req('POST', '/projects', $b['token'], ['name' => 'Progetto B', 'objective' => 'Test'])[1]['data'];
$contentB = req('POST', "/projects/{$projectB['id']}/content", $b['token'], ['channel' => 'instagram', 'title' => 'Post B', 'body' => 'B'])[1]['data'];
req('POST', "/content/{$contentB['id']}/submit", $b['token']);
expectStatus('Dal portale di A non si approva un post di B', req('POST', "/public/portal/{$portalToken}/content/{$contentB['id']}/approve", null, []), 404);
$approved = req('POST', "/public/portal/{$portalToken}/content/{$toReview['id']}/approve", null, ['name' => 'Anna']);
check('Il cliente approva dal portale', ($approved[1]['data']['status'] ?? '') === 'approved' && str_contains($approved[1]['data']['review_note'] ?? '', 'Anna'), $approved[2]);
check("Nell'app risulta approvato dal portale", (req('GET', "/content/{$toReview['id']}", $a['token'])[1]['data']['reviewed_via'] ?? '') === 'portal');
expectStatus('Non si approva due volte', req('POST', "/public/portal/{$portalToken}/content/{$toReview['id']}/approve", null, []), 409);
expectStatus('Il cliente scrive una richiesta', req('POST', "/public/portal/{$portalToken}/requests", null, ['name' => 'Anna', 'message' => 'Post per sabato?']), 201);
$requests = req('GET', "/brands/{$brandA['id']}/portal", $a['token'])[1]['data']['requests'] ?? [];
check("L'agenzia vede la richiesta", ($requests[0]['message'] ?? '') === 'Post per sabato?');
check('La richiesta compare negli avvisi di A', in_array("request-{$brandA['id']}", array_column(req('GET', '/alerts', $a['token'])[1]['data'] ?? [], 'key'), true));
check('…e non in quelli di B', !in_array("request-{$brandA['id']}", array_column(req('GET', '/alerts', $b['token'])[1]['data'] ?? [], 'key'), true));
expectStatus('B non chiude la richiesta di A', req('PATCH', "/client-requests/{$requests[0]['id']}", $b['token'], ['status' => 'done']), 404);
$assetB = req('POST', '/assets', $b['token'], null, ['file' => new CURLFile($pngPath, 'image/png', 'b.png')])[1]['data'];
expectStatus("Dal portale non si scaricano immagini di un'altra organizzazione", req('GET', "/public/portal/{$portalToken}/assets/{$assetB['id']}"), 404);
req('POST', "/brands/{$brandA['id']}/portal/disable", $a['token']);
expectStatus('Portale disattivato', req('GET', "/public/portal/{$portalToken}"), 404);

echo "\nConcorrenti\n";
expectStatus('Viewer non aggiunge concorrenti', req('POST', "/brands/{$brandA['id']}/competitors", $viewer, ['url' => 'https://example.com']), 403);
expectStatus('B non aggiunge concorrenti ad A', req('POST', "/brands/{$brandA['id']}/competitors", $b['token'], ['url' => 'https://example.com']), 404);
$competitor = req('POST', "/brands/{$brandA['id']}/competitors", $editor, ['url' => 'http://127.0.0.1/', 'name' => 'Rivale']);
expectStatus('Concorrente aggiunto (202)', $competitor, 202);
$competitorJob = waitJob((int)($competitor[1]['data']['job_id'] ?? 0), $editor);
check('Il sito di un concorrente interno non viene letto (SSRF)', ($competitorJob['status'] ?? '') === 'failed', json_encode($competitorJob));
check('Link alla libreria inserzioni di Meta', str_contains($competitor[1]['data']['ad_library_url'] ?? '', 'q=Rivale'));
expectStatus('B non vede i concorrenti di A', req('GET', "/brands/{$brandA['id']}/competitors", $b['token']), 404);
expectStatus('B non elimina il concorrente di A', req('DELETE', "/competitors/{$competitor[1]['data']['id']}", $b['token']), 404);

echo "\nRate limiting\n";
$target = 'm1-ratelimit-' . bin2hex(random_bytes(4)) . '@example.test';
for ($i = 0; $i < 5; $i++) req('POST', '/auth/login', null, ['email' => $target, 'password' => 'sbagliata-123456']);
expectStatus('Sesto tentativo di login bloccato', req('POST', '/auth/login', null, ['email' => $target, 'password' => 'sbagliata-123456']), 429);

echo "\n{$checks} controlli, {$failures} falliti.\n";
exit($failures === 0 ? 0 : 1);
