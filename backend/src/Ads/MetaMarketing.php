<?php
declare(strict_types=1);

/** Errore dell'API di Meta, con il codice di Meta per capire se il collegamento va rifatto. */
final class MetaApiException extends GenerationException
{
    public function __construct(string $message, public readonly int $metaCode = 0)
    {
        parent::__construct($message);
    }

    /** Il codice di accesso non vale più: bisogna ricollegare Facebook. */
    public function expired(): bool
    {
        return $this->metaCode === 190;
    }
}

/**
 * Sponsorizzate pubblicate direttamente su Meta (Marketing API) dall'account pubblicitario del cliente.
 * Si crea tutto in pausa: campagna, gruppo di inserzioni, immagine, creatività e inserzione; si attiva solo con una conferma.
 * Serve un'app su developers.facebook.com (META_APP_ID e META_APP_SECRET); con META_ADS_SAMPLE=true
 * tutto funziona con dati di esempio, senza chiamare Meta.
 */
final class MetaMarketing
{
    /** Permessi chiesti a chi collega Facebook: solo quelli che servono per creare e leggere le sponsorizzate. */
    public const SCOPES = ['ads_management', 'ads_read', 'business_management', 'pages_show_list', 'pages_read_engagement', 'pages_manage_ads', 'instagram_basic'];

    /** Obiettivi pubblicabili da qui: obiettivo della campagna, ottimizzazione e destinazione su Meta. */
    public const OBJECTIVES = [
        'awareness' => ['objective' => 'OUTCOME_AWARENESS', 'goal' => 'REACH', 'destination' => null],
        'traffic' => ['objective' => 'OUTCOME_TRAFFIC', 'goal' => 'LINK_CLICKS', 'destination' => 'WEBSITE'],
        'engagement' => ['objective' => 'OUTCOME_ENGAGEMENT', 'goal' => 'POST_ENGAGEMENT', 'destination' => 'ON_POST'],
    ];

    /** Pulsanti che portano a un link: gli altri (chiamata, WhatsApp, messaggi) vanno configurati in Gestione inserzioni. */
    public const LINK_CTAS = ['LEARN_MORE', 'SHOP_NOW', 'BOOK_NOW', 'ORDER_NOW', 'SIGN_UP', 'CONTACT_US', 'GET_QUOTE', 'GET_OFFER', 'DOWNLOAD', 'SUBSCRIBE', 'APPLY_NOW', 'WATCH_MORE'];

    private const SPECIAL_CATEGORIES = ['none' => [], 'financial' => ['FINANCIAL_PRODUCTS_SERVICES'], 'employment' => ['EMPLOYMENT'], 'housing' => ['HOUSING']];
    private const TIMEZONE = 'Europe/Rome';

    /** 'meta' con l'app configurata, 'sample' con i dati di esempio, null se non è configurato. */
    public static function mode(): ?string
    {
        if (trim((string)getenv('META_APP_ID')) !== '' && trim((string)getenv('META_APP_SECRET')) !== '') return 'meta';
        return filter_var(getenv('META_ADS_SAMPLE'), FILTER_VALIDATE_BOOL) ? 'sample' : null;
    }

    /** Tetto al budget giornaliero di una sponsorizzata pubblicata da qui: protegge da errori di battitura. */
    public static function maxDailyBudget(): float
    {
        $max = (float)(getenv('META_ADS_MAX_DAILY_BUDGET') ?: 100);
        return $max > 0 ? $max : 100.0;
    }

    public static function redirectUri(): string
    {
        return rtrim(getenv('APP_URL') ?: 'http://localhost:8088', '/') . '/api/v1/meta/callback';
    }

    /** Pagina di accesso a Facebook dove chi gestisce l'account pubblicitario autorizza l'app. */
    public static function loginUrl(string $state): string
    {
        // Con Facebook Login for Business i permessi vengono dalla configurazione creata nel pannello Meta.
        $configId = trim((string)getenv('META_LOGIN_CONFIG_ID'));
        return 'https://www.facebook.com/' . self::version() . '/dialog/oauth?' . http_build_query([
            'client_id' => trim((string)getenv('META_APP_ID')),
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'response_type' => 'code',
        ] + ($configId !== '' ? ['config_id' => $configId] : ['scope' => implode(',', self::SCOPES)]));
    }

    /**
     * Scambia il codice restituito da Facebook con un codice di accesso di lunga durata (circa 60 giorni).
     * @return array{token: string, expires_at: ?string, user_id: string, user_name: string, scopes: list<string>}
     */
    public static function exchangeCode(string $code): array
    {
        $app = ['client_id' => trim((string)getenv('META_APP_ID')), 'client_secret' => trim((string)getenv('META_APP_SECRET'))];
        $short = self::call('GET', 'oauth/access_token', null, $app + ['redirect_uri' => self::redirectUri(), 'code' => $code]);
        $long = self::call('GET', 'oauth/access_token', null, $app + ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => (string)($short['access_token'] ?? '')]);
        $token = (string)($long['access_token'] ?? '');
        if ($token === '') throw new MetaApiException('Meta non ha restituito il codice di accesso. Riprova il collegamento.');
        $me = self::call('GET', 'me', $token, ['fields' => 'id,name']);
        $permissions = self::call('GET', 'me/permissions', $token);
        $granted = array_values(array_map(fn($p) => (string)$p['permission'], array_filter($permissions['data'] ?? [], fn($p) => ($p['status'] ?? '') === 'granted')));
        return [
            'token' => $token,
            'expires_at' => isset($long['expires_in']) ? gmdate('Y-m-d H:i:s', time() + (int)$long['expires_in']) : null,
            'user_id' => (string)($me['id'] ?? ''),
            'user_name' => mb_substr((string)($me['name'] ?? ''), 0, 190),
            'scopes' => $granted,
        ];
    }

    /** Collegamento del cliente con il codice di accesso in chiaro (solo lato server), oppure null. */
    public static function connection(int $organizationId, int $brandId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM meta_connections WHERE organization_id=? AND brand_profile_id=?');
        $stmt->execute([$organizationId, $brandId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['token'] = $row['sample'] ? 'sample' : Secrets::decrypt($row['access_token']);
        return $row;
    }

    /**
     * Account pubblicitari e pagine (con il loro Instagram) a cui ha accesso chi ha collegato Facebook.
     * @return array{ad_accounts: list<array>, pages: list<array>}
     */
    public static function assets(array $connection): array
    {
        if ($connection['sample']) {
            return [
                'ad_accounts' => [
                    ['id' => '100000000000001', 'name' => 'Account di esempio', 'currency' => 'EUR', 'active' => true, 'business' => 'Portfolio di esempio'],
                    ['id' => '100000000000002', 'name' => 'Account disattivato (esempio)', 'currency' => 'EUR', 'active' => false, 'business' => null],
                ],
                'pages' => [['id' => '200000000000001', 'name' => 'Pagina di esempio', 'instagram_id' => '300000000000001', 'instagram_username' => 'esempio']],
            ];
        }
        $accounts = self::call('GET', 'me/adaccounts', self::token($connection), ['fields' => 'account_id,name,currency,account_status,business{name}', 'limit' => '100']);
        $pages = self::call('GET', 'me/accounts', self::token($connection), ['fields' => 'id,name,instagram_business_account{id,username}', 'limit' => '100']);
        return [
            'ad_accounts' => array_map(fn($a) => [
                'id' => (string)$a['account_id'],
                'name' => (string)($a['name'] ?? $a['account_id']),
                'currency' => (string)($a['currency'] ?? ''),
                // 1 = attivo; gli altri stati (disattivato, da pagare, in revisione) non possono pubblicare.
                'active' => (int)($a['account_status'] ?? 0) === 1,
                'business' => $a['business']['name'] ?? null,
            ], $accounts['data'] ?? []),
            'pages' => array_map(fn($p) => [
                'id' => (string)$p['id'],
                'name' => (string)($p['name'] ?? $p['id']),
                'instagram_id' => isset($p['instagram_business_account']['id']) ? (string)$p['instagram_business_account']['id'] : null,
                'instagram_username' => $p['instagram_business_account']['username'] ?? null,
            ], $pages['data'] ?? []),
        ];
    }

    /** Toglie a Marketing OS i permessi su Facebook (lo stesso che rimuovere l'app dalle impostazioni di Facebook). */
    public static function revoke(array $connection): void
    {
        if ($connection['sample'] || !$connection['token']) return;
        try {
            self::call('DELETE', 'me/permissions', $connection['token']);
        } catch (MetaApiException) {
            // Se il codice è già scaduto i permessi sono comunque inutilizzabili: il collegamento si cancella lo stesso.
        }
    }

    /**
     * Motivi per cui la sponsorizzata non si può ancora pubblicare da qui (lista vuota = si può).
     * @return list<string>
     */
    public static function problems(array $plan, ?array $connection, ?string $imagePath): array
    {
        $problems = [];
        if (self::mode() === null) return ['Il collegamento a Meta non è configurato: aggiungi META_APP_ID e META_APP_SECRET nel file .env.'];
        if (!$connection) return ['Collega Facebook e Instagram nella scheda del cliente (Sponsorizzate su Meta).'];
        if ($connection['status'] === 'expired') $problems[] = 'Il collegamento a Facebook è scaduto: ricollegalo nella scheda del cliente.';
        if (!$connection['ad_account_id']) $problems[] = 'Scegli l\'account pubblicitario nella scheda del cliente.';
        if (!$connection['page_id']) $problems[] = 'Scegli la pagina Facebook nella scheda del cliente.';
        // Budget e tetti dell'app sono in euro e vengono inviati così come sono: in un'altra valuta sarebbero importi sbagliati.
        if ($connection['currency'] && strtoupper($connection['currency']) !== 'EUR') {
            $problems[] = 'L\'account pubblicitario è in ' . strtoupper($connection['currency']) . ', ma i budget dell\'app sono in euro: scegli un account in EUR.';
        }
        if (!isset(self::OBJECTIVES[$plan['objective']])) {
            $problems[] = 'L\'obiettivo «' . MetaRules::OBJECTIVES[$plan['objective']]['label'] . '» richiede modulo contatti, Pixel o app su Meta: per ora pubblicala da Gestione inserzioni, oppure scegli Notorietà, Traffico o Interazione.';
        }
        if ($plan['special_category'] === 'social_issues') $problems[] = 'Meta non pubblica più inserzioni su temi sociali, elezioni o politica nell\'Unione Europea.';
        if (($plan['checks']['verdict'] ?? null) === 'blocked') $problems[] = 'Correggi prima i punti in rosso del controllo con le regole di Meta.';
        if ($imagePath === null) $problems[] = 'Scegli l\'immagine dell\'inserzione.';
        if ($plan['daily_budget'] < MetaRules::MIN_DAILY_BUDGET) $problems[] = 'Il budget giornaliero minimo su Meta è ' . number_format(MetaRules::MIN_DAILY_BUDGET, 2, ',', '.') . ' €.';
        if ($plan['daily_budget'] > self::maxDailyBudget()) {
            $problems[] = 'Il budget giornaliero supera il tetto di ' . number_format(self::maxDailyBudget(), 0, ',', '.') . ' € impostato per l\'app (META_ADS_MAX_DAILY_BUDGET).';
        }
        if ($plan['objective'] === 'traffic' && !$plan['url']) $problems[] = 'Per l\'obiettivo Traffico serve il link della pagina di destinazione.';
        if ($plan['url'] && !in_array($plan['cta'], self::LINK_CTAS, true)) {
            $problems[] = 'Il pulsante «' . (MetaRules::CTAS[$plan['cta']] ?? $plan['cta']) . '» va configurato in Gestione inserzioni: scegli un pulsante che apre il link.';
        }
        if (trim($plan['primary_text']) === '') $problems[] = 'Scrivi il testo principale dell\'inserzione.';
        if ($plan['end_date'] !== null && $plan['end_date'] < date('Y-m-d')) $problems[] = 'La data di fine è già passata.';
        return $problems;
    }

    /**
     * Crea su Meta campagna, gruppo di inserzioni, immagine, creatività e inserzione, tutto in pausa.
     * Ogni ID viene salvato appena creato con $save: se un passaggio fallisce, riprovando si riparte da lì senza doppioni.
     * @param callable(array<string, string>): void $save
     */
    public static function publish(array $plan, array $connection, array $brand, string $imagePath, string $payer, callable $save): void
    {
        $ids = array_filter([
            'meta_campaign_id' => $plan['meta']['campaign_id'], 'meta_adset_id' => $plan['meta']['adset_id'],
            'meta_image_hash' => $plan['meta']['image_hash'], 'meta_creative_id' => $plan['meta']['creative_id'], 'meta_ad_id' => $plan['meta']['ad_id'],
        ]);
        $step = function (string $key, callable $create) use (&$ids, $save): string {
            if (!isset($ids[$key])) {
                $ids[$key] = $create();
                $save([$key => $ids[$key]]);
            }
            return $ids[$key];
        };
        if ($connection['sample']) {
            foreach (['meta_campaign_id', 'meta_adset_id', 'meta_image_hash', 'meta_creative_id', 'meta_ad_id'] as $key) {
                $step($key, fn() => 'sample_' . bin2hex(random_bytes(6)));
            }
            return;
        }

        $token = self::token($connection);
        $account = 'act_' . $connection['ad_account_id'];
        $spec = self::OBJECTIVES[$plan['objective']];
        $name = mb_substr($plan['name'], 0, 190);
        $special = self::SPECIAL_CATEGORIES[$plan['special_category']] ?? [];

        $campaignId = $step('meta_campaign_id', fn() => (string)self::call('POST', "{$account}/campaigns", $token, [
            'name' => $name,
            'objective' => $spec['objective'],
            'status' => 'PAUSED',
            'special_ad_categories' => $special,
            ...($special ? ['special_ad_category_country' => ['IT']] : []),
            // Il budget è sul gruppo di inserzioni, non condiviso tra gruppi.
            'is_adset_budget_sharing_enabled' => false,
        ])['id']);

        $adsetId = $step('meta_adset_id', function () use ($account, $token, $plan, $spec, $name, $campaignId, $brand, $payer, $connection) {
            $start = new DateTimeImmutable($plan['start_date'] . ' 00:00:00', new DateTimeZone(self::TIMEZONE));
            $soon = new DateTimeImmutable('+10 minutes', new DateTimeZone(self::TIMEZONE));
            $fields = [
                'name' => $name,
                'campaign_id' => $campaignId,
                'status' => 'PAUSED',
                'daily_budget' => self::minorUnits($plan['daily_budget'], (string)$connection['currency']),
                'billing_event' => 'IMPRESSIONS',
                'optimization_goal' => $spec['goal'],
                'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                'targeting' => self::targeting($plan, $token),
                'start_time' => max($start, $soon)->format(DATE_ATOM),
                // Regolamento europeo sui servizi digitali: chi beneficia dell'inserzione e chi la paga.
                'dsa_beneficiary' => mb_substr((string)$brand['name'], 0, 100),
                'dsa_payor' => mb_substr($payer, 0, 100),
            ];
            if ($plan['end_date']) $fields['end_time'] = (new DateTimeImmutable($plan['end_date'] . ' 23:59:00', new DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM);
            if ($spec['destination']) $fields['destination_type'] = $spec['destination'];
            return (string)self::call('POST', "{$account}/adsets", $token, $fields)['id'];
        });

        $imageHash = $step('meta_image_hash', function () use ($account, $token, $imagePath) {
            $images = self::call('POST', "{$account}/adimages", $token, [], ['filename' => new CURLFile($imagePath)]);
            $first = is_array($images['images'] ?? null) ? reset($images['images']) : null;
            return (string)($first['hash'] ?? throw new MetaApiException('Meta non ha accettato l\'immagine: usa un JPG o PNG di almeno 600 px.'));
        });

        $creativeId = $step('meta_creative_id', function () use ($account, $token, $plan, $connection, $imageHash, $name, $brand) {
            $story = ['page_id' => $connection['page_id']];
            if ($connection['instagram_id']) $story['instagram_user_id'] = $connection['instagram_id'];
            if ($plan['url']) {
                $story['link_data'] = array_filter([
                    'image_hash' => $imageHash,
                    'link' => $plan['url'],
                    'message' => $plan['primary_text'],
                    'name' => $plan['headline'],
                    'description' => $plan['description'],
                    'call_to_action' => ['type' => $plan['cta'], 'value' => ['link' => $plan['url']]],
                ], fn($v) => $v !== '' && $v !== null);
            } else {
                $story['photo_data'] = ['image_hash' => $imageHash, 'caption' => $plan['primary_text']];
            }
            return (string)self::call('POST', "{$account}/adcreatives", $token, [
                'name' => $name,
                'object_story_spec' => $story,
                // Le visite arrivate da questa sponsorizzata si riconoscono in Analytics.
                'url_tags' => http_build_query(['utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => self::slug($brand['name'] . '-' . $plan['name'])]),
            ])['id'];
        });

        $step('meta_ad_id', fn() => (string)self::call('POST', "{$account}/ads", $token, [
            'name' => $name,
            'adset_id' => $adsetId,
            'creative' => ['creative_id' => $creativeId],
            'status' => 'PAUSED',
        ])['id']);
    }

    /** Attiva o mette in pausa campagna, gruppo e inserzione insieme. */
    public static function setStatus(array $plan, array $connection, bool $active): void
    {
        if ($connection['sample']) return;
        $status = $active ? 'ACTIVE' : 'PAUSED';
        // In attivazione si parte dall'inserzione, in pausa dalla campagna: così non resta mai attivo un pezzo senza gli altri.
        $ids = [$plan['meta']['ad_id'], $plan['meta']['adset_id'], $plan['meta']['campaign_id']];
        foreach ($active ? $ids : array_reverse($ids) as $id) self::call('POST', (string)$id, self::token($connection), ['status' => $status]);
    }

    /** Cambia budget giornaliero e data di fine di una sponsorizzata già pubblicata. */
    public static function updateSchedule(array $plan, array $connection, float $dailyBudget, ?string $endDate): void
    {
        if ($connection['sample']) return;
        $fields = ['daily_budget' => self::minorUnits($dailyBudget, (string)$connection['currency'])];
        if ($endDate) $fields['end_time'] = (new DateTimeImmutable($endDate . ' 23:59:00', new DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM);
        self::call('POST', (string)$plan['meta']['adset_id'], self::token($connection), $fields);
    }

    /**
     * Stato dell'inserzione su Meta (revisione, rifiuto, consegna) e risultati mese per mese.
     * @return array{status: string, feedback: list<string>, months: list<array{start: string, end: string, spent: float, impressions: int, reach: int, clicks: int, results: int}>}
     */
    public static function sync(array $plan, array $connection): array
    {
        if ($connection['sample']) return self::sampleSync($plan);
        $token = self::token($connection);
        $ad = self::call('GET', (string)$plan['meta']['ad_id'], $token, ['fields' => 'effective_status,ad_review_feedback,issues_info']);
        $feedback = [];
        foreach ((array)($ad['ad_review_feedback']['global'] ?? []) as $reason => $text) $feedback[] = mb_substr(is_string($text) ? $text : (string)$reason, 0, 300);
        foreach ((array)($ad['issues_info'] ?? []) as $issue) if (!empty($issue['error_message'])) $feedback[] = mb_substr((string)$issue['error_message'], 0, 300);

        $months = [];
        if ($plan['meta']['activated_at']) {
            $insights = self::call('GET', $plan['meta']['campaign_id'] . '/insights', $token, [
                'fields' => 'spend,impressions,reach,inline_link_clicks,actions',
                'time_range' => ['since' => $plan['start_date'], 'until' => date('Y-m-d')],
                'time_increment' => 'monthly',
                'limit' => '50',
            ]);
            foreach ($insights['data'] ?? [] as $row) {
                $actions = array_column((array)($row['actions'] ?? []), 'value', 'action_type');
                $months[] = [
                    'start' => (string)$row['date_start'],
                    'end' => (string)$row['date_stop'],
                    'spent' => round((float)($row['spend'] ?? 0), 2),
                    'impressions' => (int)($row['impressions'] ?? 0),
                    'reach' => (int)($row['reach'] ?? 0),
                    'clicks' => (int)($row['inline_link_clicks'] ?? 0),
                    'results' => match ($plan['objective']) {
                        'traffic' => (int)($actions['link_click'] ?? $row['inline_link_clicks'] ?? 0),
                        'engagement' => (int)($actions['post_engagement'] ?? 0),
                        default => (int)($row['reach'] ?? 0),
                    },
                ];
            }
        }
        return ['status' => strtolower((string)($ad['effective_status'] ?? 'unknown')), 'feedback' => array_values(array_unique($feedback)), 'months' => $months];
    }

    /** Pubblico su Meta: zona (cercata tra le località di Meta), età, genere, interessi e pubblico Advantage+. */
    private static function targeting(array $plan, string $token): array
    {
        $audience = $plan['audience'];
        $special = $plan['special_category'] !== 'none';
        $targeting = ['geo_locations' => self::geo((string)$audience['location'], (int)$audience['radius_km'], $special, $token)];
        if (!$special) {
            // Con Advantage+ età e genere sono solo suggerimenti: Meta accetta come limite solo l'età minima, fino a 25 anni.
            $targeting['age_min'] = $audience['advantage'] ? min((int)$audience['age_min'], 25) : (int)$audience['age_min'];
            if (!$audience['advantage'] && (int)$audience['age_max'] < 65) $targeting['age_max'] = (int)$audience['age_max'];
            if (!$audience['advantage'] && $audience['genders'] !== 'all') $targeting['genders'] = [$audience['genders'] === 'male' ? 1 : 2];
            $interests = [];
            foreach (array_slice($audience['interests'], 0, 10) as $interest) {
                $found = self::call('GET', 'search', $token, ['type' => 'adinterest', 'q' => $interest, 'limit' => '1'])['data'][0] ?? null;
                if ($found) $interests[] = ['id' => (string)$found['id'], 'name' => (string)$found['name']];
            }
            if ($interests) $targeting['flexible_spec'] = [['interests' => $interests]];
        }
        $targeting['targeting_automation'] = ['advantage_audience' => $audience['advantage'] && !$special ? 1 : 0];
        return $targeting;
    }

    private static function geo(string $location, int $radiusKm, bool $special, string $token): array
    {
        $normalized = mb_strtolower(trim($location));
        if (in_array($normalized, ['', 'italia', 'italy'], true)) return ['countries' => ['IT']];
        $found = self::call('GET', 'search', $token, [
            'type' => 'adgeolocation', 'q' => $location, 'location_types' => ['city', 'region', 'country'], 'country_code' => 'IT', 'limit' => '1',
        ])['data'][0] ?? throw new MetaApiException("Meta non trova la zona «{$location}»: scrivi il nome di una città o di una regione.");
        return match ($found['type'] ?? '') {
            'country' => ['countries' => [(string)$found['country_code']]],
            'region' => ['regions' => [['key' => (string)$found['key']]]],
            // Il raggio intorno a una città va da 17 a 80 km; con le categorie speciali almeno 25 km.
            default => ['cities' => [array_filter([
                'key' => (string)$found['key'],
                'radius' => $radiusKm > 0 || $special ? max(min($radiusKm, 80), $special ? 25 : 17) : null,
                'distance_unit' => $radiusKm > 0 || $special ? 'kilometer' : null,
            ])]],
        };
    }

    /** Importi per Meta in unità minori della valuta dell'account (centesimi per l'euro). */
    private static function minorUnits(float $amount, string $currency): int
    {
        $zeroDecimal = ['JPY', 'KRW', 'CLP', 'COP', 'HUF', 'ISK', 'TWD', 'VND', 'IDR', 'PYG'];
        return (int)round($amount * (in_array(strtoupper($currency), $zeroDecimal, true) ? 1 : 100));
    }

    /** Risultati inventati ma coerenti con budget e giorni di attività, per provare la sezione senza Meta. */
    private static function sampleSync(array $plan): array
    {
        if (!$plan['meta']['activated_at']) return ['status' => 'paused', 'feedback' => [], 'months' => []];
        $from = new DateTimeImmutable(max($plan['start_date'], substr($plan['meta']['activated_at'], 0, 10)));
        $until = new DateTimeImmutable(min(date('Y-m-d'), $plan['end_date'] ?? '9999-12-31'));
        $months = [];
        for ($cursor = $from; $cursor <= $until; $cursor = $cursor->modify('first day of next month')) {
            $end = min($until, $cursor->modify('last day of this month'));
            $days = (int)$cursor->diff($end)->days + 1;
            $spent = round($days * $plan['daily_budget'] * 0.93, 2);
            $impressions = (int)round($spent / 6.0 * 1000);
            $clicks = (int)round($impressions * 0.012);
            $months[] = [
                'start' => $cursor->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'spent' => $spent,
                'impressions' => $impressions, 'reach' => (int)round($impressions / 1.6), 'clicks' => $clicks,
                'results' => match ($plan['objective']) { 'engagement' => $clicks * 3, 'awareness' => (int)round($impressions / 1.6), default => $clicks },
            ];
        }
        $status = $plan['meta']['status'] === 'paused' ? 'paused' : ($plan['end_date'] && $plan['end_date'] < date('Y-m-d') ? 'completed' : 'active');
        return ['status' => $status, 'feedback' => [], 'months' => $months];
    }

    private static function token(array $connection): string
    {
        return $connection['token'] ?: throw new MetaApiException('Il collegamento a Facebook non è più leggibile: ricollegalo nella scheda del cliente.', 190);
    }

    private static function slug(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($text)) ?: '';
        return mb_substr(trim((string)preg_replace('/[^a-z0-9]+/', '-', $ascii), '-'), 0, 60);
    }

    private static function version(): string
    {
        return preg_match('/^v\d+\.\d+$/', (string)getenv('META_GRAPH_VERSION')) ? (string)getenv('META_GRAPH_VERSION') : 'v23.0';
    }

    /**
     * Chiamata all'API Graph con la prova del segreto dell'app (appsecret_proof): un codice rubato non basta senza il segreto.
     * Gli errori diventano messaggi comprensibili, senza mai mostrare il codice di accesso.
     */
    private static function call(string $method, string $path, ?string $token, array $params = [], array $files = []): array
    {
        $params = array_map(fn($v) => is_array($v) || is_bool($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v, $params);
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = "Authorization: Bearer {$token}";
            $params['appsecret_proof'] = hash_hmac('sha256', $token, trim((string)getenv('META_APP_SECRET')));
        }
        $url = 'https://graph.facebook.com/' . self::version() . '/' . $path;
        $ch = curl_init($method === 'GET' || $method === 'DELETE' ? $url . '?' . http_build_query($params) : $url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $files ? 120 : 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $files ? $params + $files : http_build_query($params));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) throw new MetaApiException('Meta non risponde. Riprova tra qualche minuto.');
        $data = json_decode((string)$body, true);
        if ($status === 200 && is_array($data)) return $data;

        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = (int)($error['code'] ?? 0);
        error_log("Meta Marketing API {$method} {$path}: HTTP {$status}, codice {$code}/" . ($error['error_subcode'] ?? '-'));
        // Meta spiega l'errore in un testo per l'utente (spesso tradotto): quando c'è è il più utile da mostrare.
        $userMessage = trim((string)($error['error_user_msg'] ?? ''));
        throw new MetaApiException(match (true) {
            $code === 190 => 'Il collegamento a Facebook è scaduto o è stato revocato: ricollegalo nella scheda del cliente.',
            in_array($code, [10, 200, 294], true) || ($code >= 200 && $code < 300) => 'Facebook non ha dato il permesso per questa operazione: ricollega Facebook accettando tutti i permessi richiesti.',
            in_array($code, [4, 17, 32, 613, 80004], true) => 'Troppe richieste a Meta in poco tempo: riprova tra qualche minuto.',
            $userMessage !== '' => 'Meta: ' . mb_substr($userMessage, 0, 400),
            default => "Meta ha risposto con un errore ({$status}). Riprova più tardi.",
        }, $code);
    }
}
