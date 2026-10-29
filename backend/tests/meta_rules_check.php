<?php
declare(strict_types=1);

/*
 * Controlli delle regole Meta e del monitor delle pagine, senza rete né database.
 * Da eseguire dentro il container:  docker compose exec app php tests/meta_rules_check.php
 */
require_once __DIR__ . '/../src/bootstrap.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
}

function ad(array $over = []): array
{
    return $over + [
        'objective' => 'traffic', 'special_category' => 'none',
        'audience' => ['location' => 'Pescara', 'radius_km' => 15, 'age_min' => 25, 'age_max' => 55, 'genders' => 'all', 'interests' => ['cucina'], 'advantage' => true],
        'daily_budget' => 10, 'start_date' => '2026-11-01', 'end_date' => '2026-11-14',
        'primary_text' => 'Pizza nel forno a legna, impasto a 48 ore. Prenota il tuo tavolo per il weekend.',
        'headline' => 'Prenota il tuo tavolo', 'description' => 'Aperti fino a mezzanotte', 'cta' => 'BOOK_NOW', 'url' => 'https://example.com/prenota',
    ];
}

function keys(array $result): array
{
    return array_column($result['issues'], 'key');
}

echo "Regole Meta\n";
$ok = MetaRules::check(ad());
check('un\'inserzione pulita passa', $ok['verdict'] === 'ok', json_encode($ok['issues'], JSON_UNESCAPED_UNICODE));

$r = MetaRules::check(ad(['primary_text' => 'Sei sovrappeso? Il nostro metodo ti aiuta.']));
check('attributi personali bloccati', $r['verdict'] === 'blocked' && in_array('personal_attributes', keys($r), true));
check('l\'estratto mostra il testo originale', str_contains($r['issues'][0]['excerpt'], 'Sei sovrappeso'), $r['issues'][0]['excerpt']);
check('ogni regola ha la pagina ufficiale', str_starts_with((string)$r['issues'][0]['source'], 'https://transparency.meta.com/'));

$r = MetaRules::check(ad(['primary_text' => 'Degustazione di vini del territorio venerdì sera.', 'audience' => ['age_min' => 16] + ad()['audience']]));
check('alcol sotto i 18 anni bloccato', in_array('alcohol_age', keys($r), true) && $r['verdict'] === 'blocked');
$r = MetaRules::check(ad(['primary_text' => 'Degustazione di vini del territorio venerdì sera.']));
check('alcol per maggiorenni solo avviso', $r['verdict'] === 'warning' && in_array('alcohol', keys($r), true));

$r = MetaRules::check(ad(['primary_text' => 'Mutuo a tasso fisso per la tua prima casa.']));
check('servizi finanziari: chiede la categoria speciale', $r['suggested_category'] === 'financial' && in_array('financial', keys($r), true));
$r = MetaRules::check(ad(['primary_text' => 'Mutuo a tasso fisso per la tua prima casa.', 'special_category' => 'financial', 'audience' => ['age_min' => 18, 'age_max' => 65] + ad()['audience']]));
check('categoria dichiarata: niente avviso', !in_array('financial', keys($r), true), json_encode(keys($r)));
$r = MetaRules::check(ad(['special_category' => 'housing']));
check('categoria speciale con età scelta: avviso sul pubblico', in_array('special_audience', keys($r), true));

$r = MetaRules::check(ad(['primary_text' => 'Vota il nostro candidato sindaco alle elezioni!']));
check('politica bloccata nell\'UE', $r['verdict'] === 'blocked' && in_array('politics', keys($r), true));
$r = MetaRules::check(ad(['primary_text' => 'Scommesse sportive con bonus di benvenuto.']));
check('scommesse bloccate', $r['verdict'] === 'blocked');
$r = MetaRules::check(ad(['primary_text' => 'Metti mi piace e tagga un amico per vincere!']));
check('richieste di like segnalate', in_array('engagement_bait', keys($r), true));
$r = MetaRules::check(ad(['primary_text' => 'Promo valida fino al [DATA FINE PROMO].']));
check('segnaposto dell\'AI bloccato', in_array('placeholder', keys($r), true) && $r['verdict'] === 'blocked');
$r = MetaRules::check(ad(['primary_text' => 'OFFERTA IMPERDIBILE SOLO OGGI PER TUTTI!!!']));
check('testo urlato segnalato', in_array('shouting', keys($r), true));
$r = MetaRules::check(ad(['primary_text' => 'Il negozio approvato da Instagram.']));
check('marchi di Meta come approvazione bloccati', in_array('meta_brand', keys($r), true));
$r = MetaRules::check(ad(['audience' => ['interests' => ['diabete', 'cucina']] + ad()['audience']]));
check('interessi su temi delicati segnalati', in_array('sensitive_interests', keys($r), true));

$r = MetaRules::check(ad(['url' => '', 'daily_budget' => 0.5]));
check('traffico senza link bloccato', in_array('url_missing', keys($r), true));
check('budget sotto il minimo bloccato', in_array('budget_min', keys($r), true));
$r = MetaRules::check(ad(['daily_budget' => 3, 'end_date' => '2026-11-02', 'headline' => str_repeat('Titolo lungo ', 5), 'cta' => 'NON_ESISTE']));
check('budget basso, durata breve, titolo lungo: consigli', !array_diff(['budget_low', 'short_run', 'headline_long'], keys($r)));
check('pulsante fuori elenco bloccato', in_array('cta_unknown', keys($r), true));
check('blocchi prima degli avvisi', $r['issues'][0]['severity'] === 'block');

$own = fn(array $music) => ad(['music' => $music + ['source' => 'own', 'asset_id' => 7, 'track_name' => 'jingle-pizzeria.mp3']]);
$r = MetaRules::check($own(['rights_confirmed' => true]));
check('traccia propria con licenza: passa', $r['verdict'] === 'ok', json_encode(keys($r)));
$r = MetaRules::check($own([]));
check('traccia propria senza licenza confermata: bloccata', $r['verdict'] === 'blocked' && in_array('music_rights', keys($r), true));
$r = MetaRules::check($own(['asset_id' => null, 'rights_confirmed' => true]));
check('traccia propria non scelta: bloccata', in_array('music_missing', keys($r), true));
$r = MetaRules::check($own(['track_name' => 'Artista - Canzone (Official Video).mp3', 'rights_confirmed' => true]));
check('brano commerciale riconosciuto dal nome', in_array('music_commercial', keys($r), true) && $r['verdict'] === 'warning');
$r = MetaRules::check(ad(['objective' => 'awareness', 'music' => ['source' => 'none']]));
check('notorietà senza musica: consiglio', in_array('music_none', keys($r), true) && $r['verdict'] === 'ok');

$book = MetaRules::rulebook();
check('atmosfera consigliata per ogni obiettivo', !array_diff(array_keys(MetaRules::OBJECTIVES), array_keys($book['music']['by_objective'])) && !array_diff($book['music']['by_objective'], array_keys(MetaRules::MUSIC_MOODS)));
check('sei obiettivi di Meta', count($book['objectives']) === 6);
check('ogni obiettivo propone pulsanti validi', !array_diff(array_merge(...array_column($book['objectives'], 'ctas')), array_keys(MetaRules::CTAS)));
check('ogni regola punta a una pagina controllata', !in_array(null, array_column($book['rules'], 'source'), true));

echo "\nMonitor delle pagine\n";
// Pagina del Centro sulla trasparenza: i testi sono nei dati JavaScript, con JSON dentro JSON.
$transparency = '<html><body><div id="root"></div><script>require("x",{"data":"{\"type\":\"Text\",\"children\":[\"Prodotti alcolici\"]},{\"children\":[\"Le inserzioni non possono targetizzare persone di et\\\\u00e0 inferiore ai 18 anni.\"]},{\"children\":[\"Norvegia\"]},{\"children\":[\"fds-black-alpha-05\"]}"});</script></body></html>';
$lines = MetaPolicyMonitor::extract($transparency);
check('testi letti dai dati della pagina', in_array('Prodotti alcolici', $lines, true), json_encode($lines, JSON_UNESCAPED_UNICODE));
check('lettere accentate decodificate', in_array('Le inserzioni non possono targetizzare persone di età inferiore ai 18 anni.', $lines, true), json_encode($lines, JSON_UNESCAPED_UNICODE));
check('nomi dei Paesi tenuti', in_array('Norvegia', $lines, true));
check('codici di stile scartati', !in_array('fds-black-alpha-05', $lines, true));

// Articolo del Centro assistenza: HTML dentro una stringa JSON.
$article = '<div><h1>Categorie speciali</h1><p>Dal 21 gennaio 2025 c\'è la categoria Prodotti e servizi finanziari per chi promuove prestiti e carte.</p></div>' . str_repeat(' ', 300);
$help = '<html><head><title>x</title></head><body><script>handle({"__html":' . json_encode($article, JSON_HEX_TAG) . '});</script></body></html>';
$lines = MetaPolicyMonitor::extract($help);
check('articolo letto dall\'HTML nel JSON', in_array('Categorie speciali', $lines, true) && count(array_filter($lines, fn($l) => str_contains($l, 'Prodotti e servizi finanziari'))) === 1, json_encode($lines, JSON_UNESCAPED_UNICODE));

$old = ['Prodotti alcolici', 'Le inserzioni non possono targetizzare persone di età inferiore ai 18 anni.', 'Norvegia'];
check('stesso testo: nessun cambiamento', MetaPolicyMonitor::diff($old, $old) === null);
check('differenza minima: rumore ignorato', MetaPolicyMonitor::diff($old, [...$old, 'Svezia']) === null);
$new = [...array_slice($old, 0, 2), 'Le inserzioni di prodotti alcolici devono mostrare un avviso sul consumo responsabile in tutti i Paesi dell\'UE.'];
$diff = MetaPolicyMonitor::diff($old, $new);
check('regola nuova trovata', $diff !== null && count($diff['added']) === 1 && $diff['removed'] === ['Norvegia'], json_encode($diff, JSON_UNESCAPED_UNICODE));

echo $failures ? "\n{$failures} controlli falliti.\n" : "\nTutto ok.\n";
exit($failures ? 1 : 0);
