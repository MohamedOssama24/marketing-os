<?php
declare(strict_types=1);

/** Controlli automatici sul sito di un potenziale cliente: oggettivi, senza AI, alla base del punteggio dell'audit. */
final class SiteChecks
{
    /** @return list<array{key: string, ok: bool, label: string, detail: string}> */
    public static function run(array $site): array
    {
        $text = implode(' ', array_column($site['pages'], 'text'));
        $titleLength = mb_strlen($site['title']);
        $descLength = mb_strlen($site['description']);
        $socials = array_keys($site['socials']);
        $urls = implode(' ', array_column($site['pages'], 'url'));
        $found = $site['facts']['contacts'] ?? [];
        $hasContacts = !empty($found['phones']) || !empty($found['emails']) || !empty($found['whatsapp'])
            || (bool)preg_match('/(\+39|tel\.?|telefono|whatsapp)\s*[:\d(]|\b\d{2,4}[\s.]?\d{6,8}\b|@[a-z0-9-]+\.[a-z]{2,}/iu', $text);
        $checks = [
            ['https', str_starts_with($site['url'], 'https://'), 'Sito protetto (HTTPS)',
                str_starts_with($site['url'], 'https://') ? 'Il sito usa una connessione sicura.' : 'Il sito non usa HTTPS: i browser lo segnalano come "non sicuro".'],
            ['title', $titleLength >= 20 && $titleLength <= 65, 'Titolo per Google',
                $titleLength === 0 ? 'La pagina non ha un titolo.' : "Il titolo è di {$titleLength} caratteri (ideale 20-65)."],
            ['description', $descLength >= 70 && $descLength <= 170, 'Descrizione per Google',
                $descLength === 0 ? 'Manca la descrizione che Google mostra sotto il titolo.' : "La descrizione è di {$descLength} caratteri (ideale 70-170)."],
            ['socials', count($socials) >= 2, 'Profili social collegati',
                $socials ? 'Collegati dal sito: ' . implode(', ', array_map(fn($k) => ucfirst(str_replace('_url', '', $k)), $socials)) . '.' : 'Il sito non porta a nessun profilo social.'],
            ['contacts', $hasContacts, 'Contatti visibili',
                'Telefono, email o WhatsApp ' . ($hasContacts ? 'sono presenti nelle pagine.' : 'non si trovano facilmente.')],
            ['content', mb_strlen($text) >= 2500, 'Testi sufficienti',
                mb_strlen($text) >= 2500 ? 'Le pagine hanno testi utili da cui Google e i clienti capiscono l\'offerta.' : 'Le pagine hanno pochi testi: Google fatica a capire cosa offre l\'attività.'],
            ['blog', (bool)preg_match('#/(blog|news|notizie|articoli|magazine)#i', $urls) || (bool)preg_match('/\b(blog|news|notizie)\b/iu', implode(' ', $site['menu'])), 'Blog o notizie',
                'Un blog aggiornato porta visite da Google e contenuti per i social.'],
            ['brand', count($site['colors']) > 0 && $site['logo_candidates'] !== [], 'Identità visiva riconoscibile',
                count($site['colors']) > 0 ? 'Colori del marchio riconosciuti: ' . implode(', ', array_slice($site['colors'], 0, 3)) . '.' : 'Non emergono colori del marchio coerenti.'],
        ];

        // Controlli sugli strumenti del sito: solo per le letture che li hanno (quelle salvate prima non li avevano).
        if (isset($site['signals'])) {
            $s = $site['signals'];
            $measuring = array_values(array_diff($s['tools'], ['Hotjar', 'Microsoft Clarity']));
            $ways = array_keys(array_filter([
                'modulo di contatto' => $s['features']['contact_form'], 'WhatsApp' => $s['features']['whatsapp'], 'prenotazione online' => $s['features']['booking'],
                'acquisto online' => $s['features']['ecommerce'], 'chat' => $s['chat'] !== null,
            ]));
            $seconds = str_replace('.', ',', (string)$s['seconds']);
            $checks = [...$checks,
                ['mobile', $s['mobile'], 'Adatto al telefono',
                    $s['mobile'] ? 'La pagina si adatta allo schermo del telefono.' : 'Manca l\'impostazione per i telefoni: da mobile la pagina appare rimpicciolita.'],
                ['speed', $s['seconds'] > 0 && $s['seconds'] <= 2.5, 'Pagina veloce',
                    "La pagina principale arriva in {$seconds} secondi" . ($s['seconds'] > 2.5 ? ': oltre 2,5 secondi molti visitatori se ne vanno.' : '.')],
                ['tracking', $measuring !== [], 'Misura visite e risultati',
                    $measuring ? 'Strumenti attivi: ' . implode(', ', $measuring) . '.' : 'Nessuno strumento di misurazione: non si sa da dove arrivano i clienti.'],
                ['pixel', in_array('Pixel di Meta', $s['tools'], true), 'Pixel di Meta',
                    in_array('Pixel di Meta', $s['tools'], true) ? 'Le sponsorizzate su Facebook e Instagram possono misurare i risultati e ritrovare chi ha visitato il sito.' : 'Senza Pixel le sponsorizzate su Facebook e Instagram non sanno chi ha visitato il sito né chi ha comprato.'],
                ['conversion', $ways !== [], 'Modo semplice per contattare o comprare',
                    $ways ? 'Il visitatore può usare: ' . implode(', ', $ways) . '.' : 'Niente modulo, WhatsApp, prenotazione o acquisto online: chi è interessato deve cercare da solo come farsi avanti.'],
                ['trust', $s['features']['reviews'], 'Recensioni e testimonianze',
                    $s['features']['reviews'] ? 'Il sito mostra recensioni o testimonianze dei clienti.' : 'Nessuna recensione o testimonianza in vista: sono la prima cosa che convince un cliente nuovo.'],
                ['structured', $s['structured_data'], 'Scheda dati per Google',
                    $s['structured_data'] ? 'Il sito descrive l\'attività a Google con i dati strutturati.' : 'Mancano i dati strutturati: Google mostra l\'attività con meno informazioni (orari, valutazioni, indirizzo).'],
            ];
        }
        return array_map(fn($c) => ['key' => $c[0], 'ok' => $c[1], 'label' => $c[2], 'detail' => $c[3]], $checks);
    }

    public static function score(array $checks): int
    {
        return $checks ? (int)round(100 * count(array_filter($checks, fn($c) => $c['ok'])) / count($checks)) : 0;
    }
}

/** Formati in cui si può trasformare un contenuto. */
final class Repurpose
{
    /** formato => [canale, tipo di contenuto, etichetta, istruzioni per l'AI] */
    public const FORMATS = [
        'instagram_post' => ['instagram', 'post', 'Post Instagram', 'post per il feed Instagram'],
        'carosello' => ['instagram', 'carosello', 'Carosello', "carosello Instagram di 5-7 slide: nel body scrivi ogni slide su una riga che inizia con \"Slide N:\", poi una riga vuota e la didascalia"],
        'reel' => ['instagram', 'reel', 'Copione Reel', 'copione per un Reel di 20-40 secondi: nel body scrivi gancio iniziale, scene con testo a schermo e voce, chiusura; poi la didascalia'],
        'facebook' => ['facebook', 'post', 'Post Facebook', 'post per Facebook'],
        'linkedin' => ['linkedin', 'post', 'Post LinkedIn', 'post per LinkedIn'],
        'newsletter' => ['email', 'email', 'Newsletter', 'newsletter: il titolo è l\'oggetto, il body è il testo completo della email'],
        'blog' => ['blog', 'articolo', 'Articolo blog', 'articolo per il blog di 400-700 parole con sottotitoli'],
    ];
}

/** Esempi di stile appresi dalle correzioni del team e dalle richieste di modifica, per cliente. */
final class StyleMemory
{
    private const KEEP = 30;

    /** Ultimi esempi da mostrare all'AI per questo cliente: correzioni prima/dopo e note di revisione. */
    public static function forBrand(int $orgId, int $brandId, int $limit = 6): array
    {
        $stmt = Database::connection()->prepare("SELECT kind, channel, before_text, after_text FROM style_examples WHERE organization_id=? AND brand_profile_id=? ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute([$orgId, $brandId]);
        return $stmt->fetchAll();
    }

    /** All'approvazione: se il team ha corretto il testo dell'AI, salva la coppia prima/dopo. */
    public static function captureEdit(int $orgId, array $item): void
    {
        $before = trim(self::stripNotes((string)($item['ai_body'] ?? '')));
        $after = trim(self::stripNotes((string)$item['body']));
        if ($before === '' || $after === '' || self::similar($before, $after)) return;
        $brandId = self::brandOf($orgId, (int)$item['project_id']);
        if ($brandId) self::store($orgId, $brandId, (int)$item['id'], $item['channel'], 'edit', $before, $after);
    }

    /** Alla richiesta di modifiche: la nota del revisore o del cliente vale come regola di stile. */
    public static function captureFeedback(int $orgId, array $item, string $note): void
    {
        $brandId = self::brandOf($orgId, (int)$item['project_id']);
        if ($brandId) self::store($orgId, $brandId, (int)$item['id'], $item['channel'], 'feedback', null, $note);
    }

    private static function store(int $orgId, int $brandId, int $contentId, string $channel, string $kind, ?string $before, string $after): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO style_examples (organization_id, brand_profile_id, content_item_id, channel, kind, before_text, after_text, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$orgId, $brandId, $contentId, $channel, $kind, $before === null ? null : mb_substr($before, 0, 4000), mb_substr($after, 0, 4000), date('Y-m-d H:i:s')]);
        // Si tengono solo gli esempi più recenti: lo stile cambia nel tempo.
        $stmt = $pdo->prepare('SELECT id FROM style_examples WHERE organization_id=? AND brand_profile_id=? ORDER BY id DESC LIMIT 1 OFFSET ' . self::KEEP);
        $stmt->execute([$orgId, $brandId]);
        if ($oldest = $stmt->fetchColumn()) {
            $pdo->prepare('DELETE FROM style_examples WHERE organization_id=? AND brand_profile_id=? AND id<=?')->execute([$orgId, $brandId, $oldest]);
        }
    }

    private static function brandOf(int $orgId, int $projectId): ?int
    {
        $project = Tenant::find('projects', $projectId, $orgId, 'brand_profile_id');
        return $project && $project['brand_profile_id'] ? (int)$project['brand_profile_id'] : null;
    }

    private static function stripNotes(string $body): string
    {
        return preg_replace('/\n*---\nNote per il revisore:.*$/su', '', $body);
    }

    /** Ritocchi minimi (spazi, un paio di parole) non insegnano niente. */
    private static function similar(string $a, string $b): bool
    {
        $a = preg_replace('/\s+/u', ' ', $a);
        $b = preg_replace('/\s+/u', ' ', $b);
        if ($a === $b) return true;
        if (mb_strlen($a) > 2000 || mb_strlen($b) > 2000) return false;
        similar_text($a, $b, $percent);
        return $percent >= 97;
    }
}

/** Confronto tra il cliente e i suoi concorrenti, e scelte di stile che lo distinguono da loro. */
final class Competition
{
    /** Colori professionali tra cui scegliere quello più lontano da quelli dei concorrenti. */
    private const PALETTE = ['#1E40AF', '#0F766E', '#15803D', '#B45309', '#B91C1C', '#9D174D', '#6D28D9', '#0E7490', '#C2410C', '#334155', '#A16207', '#7C2D12'];
    /** Font di Google Fonts leggibili e professionali, con un carattere diverso l'uno dall'altro. */
    private const FONTS = ['Montserrat', 'Playfair Display', 'Poppins', 'DM Serif Display', 'Work Sans', 'Lora', 'Raleway', 'Space Grotesk', 'Nunito', 'Cormorant Garamond'];

    /**
     * Tabella dei controlli: per ogni voce chi la supera, poi dove il cliente vince (lui sì, la maggior parte dei concorrenti no) e dove perde.
     * @param list<array{key: string, ok: bool, label: string, detail: string}>|null $client
     * @param list<array{id: int, name: string, checks: list<array{key: string, ok: bool}>}> $competitors
     */
    public static function compare(?array $client, array $competitors): array
    {
        $rows = [];
        $wins = [];
        $losses = [];
        $labels = $client ?? ($competitors[0]['checks'] ?? []);
        foreach ($labels as $check) {
            $key = $check['key'];
            $others = [];
            foreach ($competitors as $c) {
                $match = array_values(array_filter($c['checks'], fn($x) => $x['key'] === $key))[0] ?? null;
                $others[] = $match === null ? null : (bool)$match['ok'];
            }
            $clientOk = $client === null ? null : (bool)$check['ok'];
            $passing = count(array_filter($others, fn($ok) => $ok === true));
            $rows[] = ['key' => $key, 'label' => $check['label'], 'client' => $clientOk, 'competitors' => $others];
            if ($clientOk === true && $passing * 2 < count($others)) $wins[] = $check['label'];
            if ($clientOk === false && $passing > 0) {
                $names = [];
                foreach ($competitors as $i => $c) if ($others[$i] === true) $names[] = $c['name'];
                $losses[] = $check['label'] . ': ce l\'ha ' . implode(', ', $names);
            }
        }
        return ['rows' => $rows, 'wins' => $wins, 'losses' => $losses];
    }

    /** Il colore della tavolozza con la tinta più lontana da tutte quelle già usate dai concorrenti. */
    public static function distinctColor(array $taken): string
    {
        $hues = array_filter(array_map([self::class, 'hue'], $taken), fn($h) => $h !== null);
        if (!$hues) return self::PALETTE[0];
        $best = self::PALETTE[0];
        $bestDistance = -1;
        foreach (self::PALETTE as $color) {
            $distance = min(array_map(fn($h) => self::hueDistance((float)self::hue($color), $h), $hues));
            if ($distance > $bestDistance) {
                $best = $color;
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /** Un font che nessun concorrente usa. */
    public static function distinctFont(array $taken): string
    {
        $taken = array_map('mb_strtolower', $taken);
        foreach (self::FONTS as $font) {
            if (!in_array(mb_strtolower($font), $taken, true)) return $font;
        }
        return self::FONTS[0];
    }

    /** Colore esadecimale #RRGGBB valido, altrimenti null. */
    public static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', trim($value)) ? strtoupper(trim($value)) : null;
    }

    /** Nome di un font di Google Fonts (lettere, numeri e spazi), altrimenti null. */
    public static function font(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9 ]{2,60}$/', trim($value)) ? trim($value) : null;
    }

    /** Tinta in gradi (0-360), null per i colori quasi grigi. */
    public static function hue(string $hex): ?float
    {
        if (!self::hex($hex)) return null;
        [$r, $g, $b] = array_map(fn($v) => $v / 255, sscanf($hex, '#%02x%02x%02x'));
        $max = max($r, $g, $b);
        $delta = $max - min($r, $g, $b);
        if ($delta < 0.1) return null;
        $hue = match ($max) {
            $r => fmod(($g - $b) / $delta, 6),
            $g => ($b - $r) / $delta + 2,
            default => ($r - $g) / $delta + 4,
        };
        return fmod($hue * 60 + 360, 360);
    }

    private static function hueDistance(float $a, float $b): float
    {
        $d = abs($a - $b);
        return min($d, 360 - $d);
    }
}

/** Diagnosi strategica del sito di un cliente: i dati rilevati, i controlli e l'analisi dell'AI "da amministratore delegato". */
final class SiteAnalysis
{
    /** Aree della pagella, nell'ordine in cui si mostrano, con i controlli automatici che le riguardano. */
    public const AREAS = [
        'Chiarezza dell\'offerta' => ['content', 'title', 'description'],
        'Fiducia e prove' => ['trust', 'https', 'contacts'],
        'Conversione dei visitatori' => ['conversion', 'contacts', 'mobile', 'speed'],
        'Visibilità su Google' => ['title', 'description', 'structured', 'blog', 'content'],
        'Identità di marca' => ['brand'],
        'Presenza social e contenuti' => ['socials', 'blog'],
        'Misurazione e dati' => ['tracking', 'pixel'],
    ];
    public const IMPACT = ['alta', 'media', 'bassa'];
    public const EFFORT = ['basso', 'medio', 'alto'];
    public const WHEN = ['Questa settimana', 'Entro 30 giorni', 'Entro 90 giorni'];

    /** Ciò che si conserva della lettura del sito: dati dell'attività, strumenti, social, controlli e punteggio. */
    public static function profile(array $site): array
    {
        $checks = SiteChecks::run($site);
        return [
            'url' => $site['url'], 'facts' => $site['facts'] ?? null, 'signals' => $site['signals'] ?? null, 'socials' => $site['socials'],
            'colors' => array_slice($site['colors'], 0, 4), 'fonts' => $site['fonts'] ?? [], 'checks' => $checks, 'score' => SiteChecks::score($checks),
        ];
    }

    /** I dati rilevati dal sito in righe leggibili, per il prompt. */
    public static function factsText(array $site): string
    {
        $f = $site['facts'] ?? [];
        $c = $f['contacts'] ?? [];
        $s = $site['signals'] ?? null;
        $yes = fn(bool $v) => $v ? 'sì' : 'no';
        $lines = array_filter([
            ($f['name'] ?? '') !== '' ? "Nome nei dati strutturati: {$f['name']}" . (($f['legal_name'] ?? '') !== '' ? " ({$f['legal_name']})" : '') : '',
            'Telefoni: ' . (implode(', ', $c['phones'] ?? []) ?: 'nessuno trovato'),
            'Email: ' . (implode(', ', $c['emails'] ?? []) ?: 'nessuna trovata'),
            'WhatsApp: ' . $yes(!empty($c['whatsapp'])),
            ($c['address'] ?? '') !== '' ? "Indirizzo: {$c['address']}" : 'Indirizzo: non trovato',
            ($c['vat'] ?? '') !== '' ? 'Partita IVA indicata' : 'Partita IVA non trovata',
            ($f['opening_hours'] ?? '') !== '' ? "Orari: {$f['opening_hours']}" : '',
            ($f['price_range'] ?? '') !== '' ? "Fascia di prezzo: {$f['price_range']}" : '',
            !empty($f['rating']) ? "Valutazione dichiarata: {$f['rating']['value']} su {$f['rating']['count']} recensioni" : '',
            ($f['founded'] ?? '') !== '' ? "Fondata: {$f['founded']}" : '',
            'Social collegati: ' . (implode(', ', [...array_map(fn($k) => str_replace('_url', '', $k), array_keys($site['socials'])), ...array_keys($f['other_socials'] ?? [])]) ?: 'nessuno'),
            'Colori: ' . (implode(', ', array_slice($site['colors'], 0, 4)) ?: 'non rilevati') . '; caratteri: ' . (implode(', ', $site['fonts'] ?? []) ?: 'non rilevati'),
        ]);
        if ($s) {
            $features = array_keys(array_filter($s['features']));
            $lines = [...$lines,
                'Piattaforma: ' . ($s['platform'] ?? 'non riconosciuta'),
                'Strumenti di misura e pubblicità: ' . (implode(', ', $s['tools']) ?: 'nessuno'),
                'Gestione cookie: ' . ($s['consent'] ?? 'non riconosciuta') . '; chat: ' . ($s['chat'] ?? 'nessuna'),
                'Funzioni presenti: ' . (implode(', ', $features) ?: 'nessuna'),
                "Tecnico: adatto al telefono {$yes($s['mobile'])}, risposta in {$s['seconds']} s, pagina di {$s['page_kb']} KB, {$s['languages']} lingue, "
                    . "{$s['h1']} titoli H1, {$s['images_without_alt']} immagini su {$s['images']} senza descrizione",
            ];
        }
        return implode("\n", $lines);
    }

    /** L'analisi dell'AI ripulita: solo i campi attesi, testi accorciati, voti tra 1 e 10, valori ammessi. */
    public static function clean(array $raw): array
    {
        $text = fn(mixed $v, int $max = 600): string => is_scalar($v) ? mb_substr(trim((string)$v), 0, $max) : '';
        $list = fn(mixed $v, int $count, int $max = 300): array => is_array($v) ? array_slice(array_values(array_filter(array_map(fn($s) => $text($s, $max), $v))), 0, $count) : [];
        $pick = fn(mixed $v, array $allowed): string => in_array($v, $allowed, true) ? $v : $allowed[1];

        $scores = [];
        foreach (is_array($raw['scorecard'] ?? null) ? $raw['scorecard'] : [] as $row) {
            $area = is_array($row) ? ($row['area'] ?? null) : null;
            if (!is_string($area) || !isset(self::AREAS[$area]) || isset($scores[$area])) continue;
            $scores[$area] = ['area' => $area, 'score' => max(1, min(10, (int)($row['score'] ?? 1))), 'note' => $text($row['note'] ?? '', 300)];
        }
        $priorities = [];
        foreach (is_array($raw['priorities'] ?? null) ? $raw['priorities'] : [] as $p) {
            if (!is_array($p) || $text($p['title'] ?? '') === '') continue;
            $priorities[] = [
                'title' => $text($p['title'], 160), 'why' => $text($p['why'] ?? ''), 'how' => $text($p['how'] ?? '', 900),
                'impact' => $pick($p['impact'] ?? null, self::IMPACT), 'effort' => $pick($p['effort'] ?? null, self::EFFORT),
                'when' => $pick($p['when'] ?? null, self::WHEN), 'kpi' => $text($p['kpi'] ?? '', 200),
            ];
        }
        $kpis = [];
        foreach (is_array($raw['kpis'] ?? null) ? $raw['kpis'] : [] as $k) {
            if (is_array($k) && $text($k['name'] ?? '') !== '') $kpis[] = ['name' => $text($k['name'], 120), 'target' => $text($k['target'] ?? '', 200)];
        }
        $swot = is_array($raw['swot'] ?? null) ? $raw['swot'] : [];
        return [
            'verdict' => $text($raw['verdict'] ?? '', 1200),
            'business_model' => $text($raw['business_model'] ?? '', 800),
            'value_proposition' => $text($raw['value_proposition'] ?? '', 600),
            'sharper_proposition' => $text($raw['sharper_proposition'] ?? '', 300),
            'target' => $text($raw['target'] ?? '', 600),
            'scorecard' => array_values(array_filter(array_map(fn($area) => $scores[$area] ?? null, array_keys(self::AREAS)))),
            'swot' => [
                'strengths' => $list($swot['strengths'] ?? [], 5), 'weaknesses' => $list($swot['weaknesses'] ?? [], 5),
                'opportunities' => $list($swot['opportunities'] ?? [], 5), 'threats' => $list($swot['threats'] ?? [], 5),
            ],
            'priorities' => array_slice($priorities, 0, 8),
            'quick_wins' => $list($raw['quick_wins'] ?? [], 6),
            'revenue_ideas' => $list($raw['revenue_ideas'] ?? [], 5, 400),
            'kpis' => array_slice($kpis, 0, 6),
            'questions' => $list($raw['questions'] ?? [], 6),
        ];
    }

    /** Voto da 1 a 10 di un'area secondo i controlli automatici (null se nessun controllo la riguarda). */
    public static function areaScore(string $area, array $checks): ?int
    {
        $related = array_filter($checks, fn($c) => in_array($c['key'], self::AREAS[$area] ?? [], true));
        if (!$related) return null;
        return (int)round(2 + 7 * count(array_filter($related, fn($c) => $c['ok'])) / count($related));
    }
}
