<?php
declare(strict_types=1);

/**
 * Inserzioni dei concorrenti dall'archivio inserzioni di Meta (Ad Library API).
 * Per le inserzioni mostrate in Europa Meta pubblica copertura, età, sesso, paesi e zone di targeting, ma non la spesa:
 * la spesa si stima da quante persone sono state raggiunte e da un costo medio per 1.000 visualizzazioni.
 * Serve un codice di accesso di Meta (META_AD_LIBRARY_TOKEN); con META_AD_LIBRARY_SAMPLE=true usa dati di esempio.
 */
final class MetaAdLibrary
{
    /** Costo medio per 1.000 visualizzazioni in Italia, se l'agenzia non ne indica uno suo. */
    public const DEFAULT_CPM = 6.0;
    /** Quante volte in media la stessa persona vede un'inserzione: dà la forbice della stima. */
    public const FREQUENCY = [1.3, 2.2];
    /** Si leggono le inserzioni partite o ancora attive negli ultimi giorni. */
    public const WINDOW_DAYS = 90;
    /** Un'inserzione attiva da più giorni di così probabilmente rende: chi paga spegne quelle che non funzionano. */
    public const WINNER_DAYS = 30;
    private const COUNTRY = 'IT';
    private const MAX_PAGES = 3;
    private const FIELDS = 'id,page_id,page_name,ad_creative_bodies,ad_creative_link_titles,ad_creative_link_captions,ad_delivery_start_time,'
        . 'ad_delivery_stop_time,publisher_platforms,eu_total_reach,age_country_gender_reach_breakdown,target_ages,target_gender,target_locations';

    /** 'meta' con il codice di accesso, 'sample' con i dati di esempio, null se non è configurato. */
    public static function source(): ?string
    {
        if (trim((string)getenv('META_AD_LIBRARY_TOKEN')) !== '') return 'meta';
        return filter_var(getenv('META_AD_LIBRARY_SAMPLE'), FILTER_VALIDATE_BOOL) ? 'sample' : null;
    }

    /**
     * L'identificativo della pagina Facebook da un link dell'archivio inserzioni (view_all_page_id=…) o da un numero.
     * Null se il testo non ne contiene uno.
     */
    public static function pageIdFrom(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^\d{5,25}$/', $input)) return $input;
        if (preg_match('/[?&](?:view_all_page_id|page_id|id)=(\d{5,25})/', $input, $m) && str_contains($input, 'facebook.com')) return $m[1];
        return null;
    }

    /**
     * Legge le inserzioni del concorrente e le salva. Restituisce le inserzioni nuove (mai viste prima).
     * @param array{id: int|string, organization_id: int|string, name: string, meta_page_id: ?string} $competitor
     * @return list<array>
     */
    public static function sync(array $competitor): array
    {
        $source = self::source() ?? throw new GenerationException('L\'archivio inserzioni di Meta non è collegato.');
        $pdo = Database::connection();
        $ads = $source === 'sample' ? self::sampleAds((string)$competitor['name']) : self::fetchFromMeta($competitor);
        $pageId = $competitor['meta_page_id'] ?: ($ads[0]['page_id'] ?? null);
        if ($pageId && !$competitor['meta_page_id']) {
            $pdo->prepare('UPDATE competitors SET meta_page_id=? WHERE id=?')->execute([$pageId, $competitor['id']]);
        }

        $known = $pdo->prepare('SELECT archive_id FROM competitor_ads WHERE competitor_id=?');
        $known->execute([$competitor['id']]);
        $known = array_flip($known->fetchAll(PDO::FETCH_COLUMN));
        $now = date('Y-m-d H:i:s');
        $upsert = $pdo->prepare('INSERT INTO competitor_ads (organization_id, competitor_id, archive_id, page_id, page_name, bodies, titles, captions, platforms,
            started_on, stopped_on, eu_reach, demographics, target_locations, target_ages, target_gender, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE page_name=VALUES(page_name), bodies=VALUES(bodies), titles=VALUES(titles), captions=VALUES(captions), platforms=VALUES(platforms),
            stopped_on=VALUES(stopped_on), eu_reach=VALUES(eu_reach), demographics=VALUES(demographics), target_locations=VALUES(target_locations),
            target_ages=VALUES(target_ages), target_gender=VALUES(target_gender), last_seen_at=VALUES(last_seen_at)');
        $json = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
        $new = [];
        foreach ($ads as $ad) {
            $upsert->execute([
                $competitor['organization_id'], $competitor['id'], $ad['archive_id'], $ad['page_id'], mb_substr($ad['page_name'], 0, 190),
                $json($ad['bodies']), $json($ad['titles']), $json($ad['captions']), $json($ad['platforms']),
                $ad['started_on'], $ad['stopped_on'], $ad['eu_reach'], $json($ad['demographics']), $json($ad['target_locations']),
                $json($ad['target_ages']), $ad['target_gender'], $now, $now,
            ]);
            if (!isset($known[$ad['archive_id']])) $new[] = $ad;
        }
        return $new;
    }

    /** @return list<array> inserzioni della pagina del concorrente, nella forma salvata in competitor_ads */
    private static function fetchFromMeta(array $competitor): array
    {
        $params = [
            'ad_reached_countries' => json_encode([self::COUNTRY]),
            'ad_active_status' => 'ALL',
            'ad_delivery_date_min' => date('Y-m-d', strtotime('-' . self::WINDOW_DAYS . ' days')),
            'fields' => self::FIELDS,
            'limit' => '100',
        ];
        if ($competitor['meta_page_id']) {
            $params['search_page_ids'] = json_encode([$competitor['meta_page_id']]);
        } else {
            $params['search_terms'] = mb_substr((string)$competitor['name'], 0, 100);
        }
        $version = preg_match('/^v\d+\.\d+$/', (string)getenv('META_GRAPH_VERSION')) ? getenv('META_GRAPH_VERSION') : 'v23.0';
        $url = "https://graph.facebook.com/{$version}/ads_archive?" . http_build_query($params);
        $rows = [];
        for ($page = 0; $page < self::MAX_PAGES && $url; $page++) {
            $response = self::request($url);
            foreach ($response['data'] ?? [] as $row) if (is_array($row)) $rows[] = $row;
            $next = (string)($response['paging']['next'] ?? '');
            // Si segue solo la pagina successiva dell'API di Meta, mai altri indirizzi.
            $url = str_starts_with($next, 'https://graph.facebook.com/') ? $next : null;
        }
        $ads = array_values(array_filter(array_map([self::class, 'parse'], $rows)));
        if ($competitor['meta_page_id']) return $ads;

        // Ricerca per nome: si tiene la pagina il cui nome somiglia di più a quello del concorrente.
        $best = null;
        $bestScore = 0.0;
        foreach ($ads as $ad) {
            similar_text(self::normalize($ad['page_name']), self::normalize((string)$competitor['name']), $percent);
            if (str_contains(self::normalize($ad['page_name']), self::normalize((string)$competitor['name']))) $percent = max($percent, 90);
            if ($percent > $bestScore) [$best, $bestScore] = [$ad['page_id'], $percent];
        }
        if ($best === null || $bestScore < 60) {
            throw new GenerationException('Non trovo la pagina Facebook di ' . $competitor['name'] . ' nell\'archivio inserzioni: incolla il link della sua pagina nell\'archivio.');
        }
        return array_values(array_filter($ads, fn($ad) => $ad['page_id'] === $best));
    }

    /** Chiamata all'API di Meta con gli errori tradotti in messaggi comprensibili (senza mai mostrare il codice di accesso). */
    private static function request(string $url): array
    {
        $token = trim((string)getenv('META_AD_LIBRARY_TOKEN'));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) throw new GenerationException('Meta non risponde. Riprovo più tardi.');
        $data = json_decode((string)$body, true);
        if ($status === 200 && is_array($data)) return $data;

        $code = (int)($data['error']['code'] ?? 0);
        error_log("Meta Ad Library: HTTP {$status}, codice {$code}");
        throw new GenerationException(match (true) {
            $code === 190 => 'Il codice di accesso di Meta non è valido o è scaduto: generane uno nuovo.',
            in_array($code, [10, 200, 2332], true) => 'Il codice di accesso non può leggere l\'archivio inserzioni: completa la verifica dell\'identità su facebook.com/ID e accetta i termini dell\'archivio.',
            in_array($code, [4, 17, 32, 613], true) => 'Troppe richieste a Meta in poco tempo: riprovo più tardi.',
            default => "Meta ha risposto con un errore ({$status}). Riprova più tardi.",
        });
    }

    /** Una riga dell'API nella forma salvata; null se mancano i dati minimi. */
    public static function parse(array $row): ?array
    {
        $id = (string)($row['id'] ?? '');
        $start = substr((string)($row['ad_delivery_start_time'] ?? ''), 0, 10);
        if (!preg_match('/^\d+$/', $id) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return null;
        $stop = substr((string)($row['ad_delivery_stop_time'] ?? ''), 0, 10);
        $strings = fn($list, int $max, int $len) => array_slice(array_values(array_unique(array_filter(array_map(
            fn($s) => is_scalar($s) ? mb_substr(trim((string)$s), 0, $len) : '', is_array($list) ? $list : [])))), 0, $max);

        $demographics = [];
        foreach (is_array($row['age_country_gender_reach_breakdown'] ?? null) ? $row['age_country_gender_reach_breakdown'] : [] as $country) {
            foreach (is_array($country['age_gender_breakdowns'] ?? null) ? $country['age_gender_breakdowns'] : [] as $b) {
                $age = (string)($b['age_range'] ?? '');
                if (!preg_match('/^\d{2}(-\d{2}|\+)$/', $age)) continue;
                $demographics[] = [
                    'country' => mb_substr((string)($country['country'] ?? ''), 0, 4), 'age' => $age,
                    'female' => (int)($b['female'] ?? 0), 'male' => (int)($b['male'] ?? 0), 'unknown' => (int)($b['unknown'] ?? 0),
                ];
            }
        }
        $locations = [];
        foreach (is_array($row['target_locations'] ?? null) ? $row['target_locations'] : [] as $l) {
            $name = mb_substr(trim((string)($l['name'] ?? '')), 0, 120);
            if ($name === '') continue;
            $locations[] = ['name' => $name, 'type' => mb_substr((string)($l['type'] ?? ''), 0, 30), 'excluded' => (bool)($l['excluded'] ?? false)];
        }
        $ages = array_values(array_filter(array_map(fn($a) => is_scalar($a) ? (string)$a : '', is_array($row['target_ages'] ?? null) ? $row['target_ages'] : [])));
        return [
            'archive_id' => $id,
            'page_id' => (string)($row['page_id'] ?? ''),
            'page_name' => trim((string)($row['page_name'] ?? '')),
            'bodies' => $strings($row['ad_creative_bodies'] ?? [], 3, 2000),
            'titles' => $strings($row['ad_creative_link_titles'] ?? [], 3, 255),
            'captions' => $strings($row['ad_creative_link_captions'] ?? [], 3, 255),
            'platforms' => $strings($row['publisher_platforms'] ?? [], 6, 30),
            'started_on' => $start,
            'stopped_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $stop) ? $stop : null,
            'eu_reach' => isset($row['eu_total_reach']) && is_numeric($row['eu_total_reach']) ? max(0, (int)$row['eu_total_reach']) : null,
            'demographics' => $demographics ?: null,
            'target_locations' => $locations ?: null,
            'target_ages' => array_slice($ages, 0, 2) ?: null,
            'target_gender' => is_scalar($row['target_gender'] ?? null) ? mb_substr((string)$row['target_gender'], 0, 20) : null,
        ];
    }

    /**
     * Cosa si capisce dalle inserzioni di un concorrente: quante, chi raggiungono, dove, quanto spende (stima), quali rendono e cosa offrono.
     * @param list<array> $ads righe di competitor_ads già decodificate
     */
    public static function insights(array $ads, float $cpm, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $day = fn(string $d) => (int)(strtotime($d . ' 00:00:00 UTC') / 86400);
        $t = $day($today);
        $monthStart = $t - 29;
        $active = $new = $reach = 0;
        $spend = [0.0, 0.0];
        $month = [0.0, 0.0];
        $ages = $genders = $countries = $places = $excluded = $platforms = $domains = $cadence = $winners = $offers = [];
        foreach ($ads as $ad) {
            $start = $day($ad['started_on']);
            $end = $ad['stopped_on'] ? min($day($ad['stopped_on']), $t) : $t;
            $isActive = $ad['stopped_on'] === null || $day($ad['stopped_on']) >= $t;
            $days = max(1, $end - $start + 1);
            if ($isActive) $active++;
            if ($start >= $monthStart) $new++;
            $cadence[substr($ad['started_on'], 0, 7)] = ($cadence[substr($ad['started_on'], 0, 7)] ?? 0) + 1;
            foreach ($ad['platforms'] as $p) $platforms[$p] = ($platforms[$p] ?? 0) + 1;
            foreach ($ad['captions'] as $c) {
                $domain = explode('/', strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', $c) ?? ''))[0];
                if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) $domains[$domain] = ($domains[$domain] ?? 0) + 1;
            }
            foreach ($ad['target_locations'] ?? [] as $l) {
                if ($l['excluded']) $excluded[$l['name']] = true;
                else $places[$l['name']] = ($places[$l['name']] ?? 0) + 1;
            }
            foreach ($ad['demographics'] ?? [] as $d) {
                $ages[$d['age']] = ($ages[$d['age']] ?? 0) + $d['female'] + $d['male'] + $d['unknown'];
                $genders['female'] = ($genders['female'] ?? 0) + $d['female'];
                $genders['male'] = ($genders['male'] ?? 0) + $d['male'];
                $countries[$d['country']] = ($countries[$d['country']] ?? 0) + $d['female'] + $d['male'] + $d['unknown'];
            }
            foreach (array_merge($ad['bodies'], $ad['titles']) as $text) {
                foreach (self::offerPhrases($text) as $o) $offers[mb_strtolower($o)] ??= $o;
            }
            $estimate = self::estimate($ad['eu_reach'], $cpm);
            if ($estimate) {
                $reach += $ad['eu_reach'];
                $spend[0] += $estimate[0];
                $spend[1] += $estimate[1];
                // La parte della spesa caduta negli ultimi 30 giorni, in proporzione ai giorni.
                $overlap = max(0, $end - max($start, $monthStart) + 1);
                $month[0] += $estimate[0] * $overlap / $days;
                $month[1] += $estimate[1] * $overlap / $days;
            }
            if ($isActive && $days >= self::WINNER_DAYS) $winners[] = ['archive_id' => $ad['archive_id'], 'days' => $days];
        }
        usort($winners, fn($a, $b) => $b['days'] <=> $a['days']);
        arsort($places);
        arsort($platforms);
        arsort($domains);
        arsort($countries);
        ksort($cadence);
        $ageTotal = array_sum($ages);
        uksort($ages, fn($a, $b) => (int)$a <=> (int)$b);
        $genderTotal = array_sum($genders);
        $countryTotal = array_sum($countries);
        $percent = fn(int $n, int $total) => $total > 0 ? (int)round($n / $total * 100) : 0;
        $topAge = $ages ? array_search(max($ages), $ages, true) : null;
        return [
            'ads' => count($ads),
            'active' => $active,
            'new_30d' => $new,
            'reach' => $reach,
            'spend' => $reach > 0 ? ['low' => (int)round($spend[0]), 'high' => (int)round($spend[1])] : null,
            'spend_30d' => $reach > 0 ? ['low' => (int)round($month[0]), 'high' => (int)round($month[1])] : null,
            'ages' => array_map(fn($age, $n) => ['age' => $age, 'percent' => $percent($n, $ageTotal)], array_keys($ages), $ages),
            'top_age' => $topAge,
            'women_percent' => $genderTotal > 0 ? $percent($genders['female'], $genderTotal) : null,
            'countries' => array_slice(array_map(fn($c, $n) => ['country' => $c, 'percent' => $percent($n, $countryTotal)], array_keys($countries), $countries), 0, 5),
            'places' => array_slice(array_map(fn($name, $n) => ['name' => $name, 'ads' => $n], array_keys($places), $places), 0, 12),
            'excluded_places' => array_slice(array_keys($excluded), 0, 8),
            'platforms' => array_map(fn($p, $n) => ['platform' => $p, 'ads' => $n], array_keys($platforms), $platforms),
            'domains' => array_slice(array_map(fn($d, $n) => ['domain' => $d, 'ads' => $n], array_keys($domains), $domains), 0, 5),
            'cadence' => array_map(fn($m, $n) => ['month' => $m, 'ads' => $n], array_keys($cadence), $cadence),
            'winners' => array_slice($winners, 0, 3),
            'offers' => array_slice(array_values($offers), 0, 6),
        ];
    }

    /** Forbice di spesa di un'inserzione: persone raggiunte × visualizzazioni a testa × costo per 1.000. */
    public static function estimate(?int $reach, float $cpm): ?array
    {
        if (!$reach) return null;
        return [round($reach * self::FREQUENCY[0] * $cpm / 1000, 2), round($reach * self::FREQUENCY[1] * $cpm / 1000, 2)];
    }

    /** Le frasi di un testo che contengono un'offerta: sconti, prezzi, omaggi, scadenze. */
    public static function offerPhrases(string $text): array
    {
        $found = [];
        foreach (preg_split('/(?<=[.!?\n])\s+/u', $text) ?: [] as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '' || mb_strlen($sentence) > 160) continue;
            if (preg_match('/\d+\s?%|€\s?\d|\d+(?:[.,]\d+)?\s?(?:€|euro)|\b(sconto|sconti|gratis|gratuit[oaie]|omaggio|offerta|promo|saldi|black friday|prova gratuita|spedizione gratuita|solo (oggi|fino)|fino al \d)/iu', $sentence)) {
                $found[] = $sentence;
            }
        }
        return $found;
    }

    private static function normalize(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s)) ?? '');
    }

    /**
     * Inserzioni di esempio, sempre uguali per lo stesso concorrente, per provare la sezione senza collegare Meta.
     * @return list<array>
     */
    public static function sampleAds(string $name): array
    {
        mt_srand(crc32($name));
        $cities = [['Pescara', 'Chieti', 'Montesilvano'], ['Milano', 'Monza', 'Bergamo'], ['Roma', 'Fiumicino', 'Tivoli'], ['Bologna', 'Modena', 'Imola']][mt_rand(0, 3)];
        $texts = [
            ['Solo questo mese: 20% di sconto sulla prima visita. Prenota online in 2 minuti.', 'Sconto del 20% sulla prima visita'],
            ['Da 15 anni accanto a te. Scopri perché oltre 2.000 clienti ci scelgono ogni anno.', 'Scopri chi siamo'],
            ['Consegna gratuita per ordini sopra i 49 €. Approfitta fino a domenica!', 'Consegna gratuita da 49 €'],
            ['Il nostro team ti segue passo passo, dalla prima chiamata al risultato.', 'Parla con un esperto'],
            ['Nuova collezione autunno: vieni a trovarci in negozio o scopri tutto online.', 'Novità di stagione'],
            ['Prova gratuita di 7 giorni, senza impegno. Disdici quando vuoi.', 'Inizia la prova gratuita'],
            ['Black Friday anticipato: fino al 40% su tutto il catalogo.', 'Fino al 40% di sconto'],
            ['Recensioni a 5 stelle su Google: leggi cosa dicono i nostri clienti.', 'Le opinioni dei clienti'],
        ];
        $domain = preg_replace('/[^a-z0-9]+/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name)) . '.it';
        $pageId = (string)(100000000000 + mt_rand(0, 899999999));
        $ads = [];
        $count = mt_rand(5, 8);
        for ($i = 0; $i < $count; $i++) {
            [$body, $title] = $texts[($i + mt_rand(0, 7)) % count($texts)];
            $startDays = mt_rand(3, 85);
            $stopped = mt_rand(0, 2) === 0 ? max(0, $startDays - mt_rand(7, 30)) : null;
            $reach = mt_rand(3, 60) * 1000 + mt_rand(0, 999);
            $demographics = [];
            foreach (['18-24' => 0.12, '25-34' => 0.27, '35-44' => 0.26, '45-54' => 0.18, '55-64' => 0.11, '65+' => 0.06] as $age => $share) {
                $n = $reach * $share * (0.7 + mt_rand(0, 60) / 100);
                $women = 0.45 + mt_rand(0, 25) / 100;
                $demographics[] = ['country' => 'IT', 'age' => $age, 'female' => (int)($n * $women), 'male' => (int)($n * (1 - $women)), 'unknown' => 0];
            }
            $ads[] = [
                'archive_id' => (string)(900000000000000 + crc32($name . $i)),
                'page_id' => $pageId,
                'page_name' => $name,
                'bodies' => [$body],
                'titles' => [$title],
                'captions' => [$domain],
                'platforms' => mt_rand(0, 2) ? ['facebook', 'instagram'] : ['instagram'],
                'started_on' => date('Y-m-d', strtotime("-{$startDays} days")),
                'stopped_on' => $stopped === null ? null : date('Y-m-d', strtotime("-{$stopped} days")),
                'eu_reach' => $reach,
                'demographics' => $demographics,
                'target_locations' => array_merge(
                    array_map(fn($c) => ['name' => $c, 'type' => 'city', 'excluded' => false], array_slice($cities, 0, mt_rand(1, 3))),
                    mt_rand(0, 3) === 0 ? [['name' => 'Italia', 'type' => 'country', 'excluded' => false]] : [],
                ),
                'target_ages' => [(string)(18 + 5 * mt_rand(0, 3)), (string)(45 + 5 * mt_rand(0, 4))],
                'target_gender' => ['All', 'All', 'Women'][mt_rand(0, 2)],
            ];
        }
        mt_srand();
        return $ads;
    }
}
