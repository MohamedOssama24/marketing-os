<?php
declare(strict_types=1);

/**
 * Collegamento di un cliente a Meta: accesso a Facebook, scelta di account pubblicitario, pagina e Instagram.
 * Il codice di accesso resta cifrato sul server e non arriva mai al browser.
 */
final class MetaConnectionController
{
    private const STATE_MINUTES = 15;

    public static function show(int $brandId): never
    {
        $user = Auth::user();
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        Http::json(['data' => self::present($user['organization_id'], $brandId)]);
    }

    /** Indirizzo della pagina di accesso a Facebook; con i dati di esempio collega subito un account finto. */
    public static function connect(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $mode = MetaMarketing::mode() ?? Http::validationError('Il collegamento a Meta non è configurato: aggiungi META_APP_ID e META_APP_SECRET nel file .env.');
        if ($mode === 'sample') {
            self::save($user, $brandId, ['token' => 'sample', 'expires_at' => null, 'user_id' => 'sample', 'user_name' => 'Profilo di esempio', 'scopes' => MetaMarketing::SCOPES], true);
            Http::json(['data' => ['url' => null, 'connection' => self::present($user['organization_id'], $brandId)]]);
        }
        $pdo = Database::connection();
        $pdo->exec('DELETE FROM meta_oauth_states WHERE expires_at < UTC_TIMESTAMP()');
        $state = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO meta_oauth_states (state, organization_id, brand_profile_id, user_id, expires_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ' . self::STATE_MINUTES . ' MINUTE)')
            ->execute([$state, $user['organization_id'], $brandId, $user['id']]);
        Http::json(['data' => ['url' => MetaMarketing::loginUrl($state), 'connection' => null]]);
    }

    /** Facebook rimanda qui il browser dopo l'accesso: niente codice dell'app, il cliente si riconosce dallo "state". */
    public static function callback(): never
    {
        $state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
        $pdo = Database::connection();
        $row = null;
        if (preg_match('/^[a-f0-9]{64}$/', $state)) {
            $stmt = $pdo->prepare('SELECT * FROM meta_oauth_states WHERE state=? AND expires_at >= UTC_TIMESTAMP()');
            $stmt->execute([$state]);
            $row = $stmt->fetch() ?: null;
            // Monouso: lo stesso link non si può riusare.
            $pdo->prepare('DELETE FROM meta_oauth_states WHERE state=?')->execute([$state]);
        }
        if (!$row) self::backToApp(null, 'error', 'Il collegamento è scaduto: riprova da Marketing OS.');
        $brandId = (int)$row['brand_profile_id'];
        if (isset($_GET['error'])) self::backToApp($brandId, 'error', 'Collegamento annullato su Facebook.');

        $stmt = $pdo->prepare('SELECT id, organization_id, role FROM users WHERE id=? AND organization_id=?');
        $stmt->execute([$row['user_id'], $row['organization_id']]);
        $user = $stmt->fetch();
        if (!$user || !in_array($user['role'], Auth::EDITORS, true)) self::backToApp($brandId, 'error', 'Non hai più i permessi per collegare Meta.');
        $user = ['id' => (int)$user['id'], 'organization_id' => (int)$user['organization_id']];
        try {
            $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : throw new MetaApiException('Facebook non ha restituito il codice.');
            $grant = MetaMarketing::exchangeCode($code);
            $missing = array_diff(['ads_management', 'pages_show_list'], $grant['scopes']);
            if ($missing) self::backToApp($brandId, 'error', 'Su Facebook non sono stati accettati tutti i permessi: ricollega e lascia attive tutte le voci.');
            self::save($user, $brandId, $grant, false);
        } catch (MetaApiException $e) {
            self::backToApp($brandId, 'error', $e->getMessage());
        }
        self::backToApp($brandId, 'connected', null);
    }

    /** Account pubblicitari e pagine tra cui scegliere, letti da Meta in quel momento. */
    public static function assets(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $connection = MetaMarketing::connection($user['organization_id'], $brandId) ?? Http::validationError('Collega prima Facebook.');
        try {
            Http::json(['data' => MetaMarketing::assets($connection)]);
        } catch (MetaApiException $e) {
            self::markError($connection, $e);
            Http::validationError($e->getMessage());
        }
    }

    /** Sceglie account pubblicitario, pagina e Instagram, controllando che siano davvero tra quelli accessibili. */
    public static function update(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $connection = MetaMarketing::connection($user['organization_id'], $brandId) ?? Http::validationError('Collega prima Facebook.');
        $data = Http::body();
        try {
            $assets = MetaMarketing::assets($connection);
        } catch (MetaApiException $e) {
            self::markError($connection, $e);
            Http::validationError($e->getMessage());
        }
        $fields = [];
        if (array_key_exists('ad_account_id', $data)) {
            $id = preg_replace('/^act_/i', '', (string)$data['ad_account_id']);
            $account = current(array_filter($assets['ad_accounts'], fn($a) => $a['id'] === $id)) ?: Http::validationError('Questo account pubblicitario non è tra quelli a cui hai accesso su Facebook.');
            if (!$account['active']) Http::validationError('L\'account «' . $account['name'] . '» non è attivo su Meta (disattivato o con un pagamento in sospeso).');
            $fields += ['ad_account_id' => $account['id'], 'ad_account_name' => mb_substr($account['name'], 0, 190), 'currency' => $account['currency'] ?: null];
        }
        if (array_key_exists('page_id', $data)) {
            $page = current(array_filter($assets['pages'], fn($p) => $p['id'] === (string)$data['page_id'])) ?: Http::validationError('Questa pagina non è tra quelle che gestisci su Facebook.');
            $fields += [
                'page_id' => $page['id'], 'page_name' => mb_substr($page['name'], 0, 190),
                // L'Instagram collegato alla pagina: le inserzioni escono anche lì con il suo nome.
                'instagram_id' => $page['instagram_id'], 'instagram_username' => $page['instagram_username'],
            ];
        }
        if ($fields) {
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            Database::connection()->prepare("UPDATE meta_connections SET {$set} WHERE organization_id=? AND brand_profile_id=?")
                ->execute([...array_values($fields), $user['organization_id'], $brandId]);
            // L'account scelto è anche quello della guida e dei link a Gestione inserzioni.
            if (isset($fields['ad_account_id'])) {
                Database::connection()->prepare('UPDATE brand_profiles SET meta_ad_account_id=? WHERE id=? AND organization_id=?')->execute([$fields['ad_account_id'], $brandId, $user['organization_id']]);
            }
            Audit::log($user, 'meta.connection_updated', 'brand_profile', $brandId);
        }
        Http::json(['data' => self::present($user['organization_id'], $brandId)]);
    }

    /** Scollega Facebook: revoca i permessi su Meta e cancella il codice di accesso. Le sponsorizzate già pubblicate restano su Meta. */
    public static function destroy(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $connection = MetaMarketing::connection($user['organization_id'], $brandId);
        if ($connection) {
            MetaMarketing::revoke($connection);
            Database::connection()->prepare('DELETE FROM meta_connections WHERE organization_id=? AND brand_profile_id=?')->execute([$user['organization_id'], $brandId]);
            Audit::log($user, 'meta.disconnected', 'brand_profile', $brandId);
        }
        Http::json(['data' => self::present($user['organization_id'], $brandId)]);
    }

    /** Il collegamento visto dal browser: mai il codice di accesso. */
    public static function present(int $organizationId, int $brandId): array
    {
        $stmt = Database::connection()->prepare('SELECT sample, meta_user_name, token_expires_at, ad_account_id, ad_account_name, currency, page_id, page_name,
            instagram_id, instagram_username, status, last_error, created_at FROM meta_connections WHERE organization_id=? AND brand_profile_id=?');
        $stmt->execute([$organizationId, $brandId]);
        $row = $stmt->fetch() ?: null;
        $expired = $row && ($row['status'] === 'expired' || ($row['token_expires_at'] !== null && $row['token_expires_at'] < gmdate('Y-m-d H:i:s')));
        return [
            'mode' => MetaMarketing::mode(),
            'connected' => $row !== null,
            'sample' => $row ? (bool)$row['sample'] : false,
            'user_name' => $row['meta_user_name'] ?? null,
            'expires_at' => $row['token_expires_at'] ?? null,
            'expired' => $expired,
            'error' => $row && $row['status'] === 'error' ? $row['last_error'] : null,
            'ad_account' => $row && $row['ad_account_id'] ? ['id' => $row['ad_account_id'], 'name' => $row['ad_account_name'], 'currency' => $row['currency']] : null,
            'page' => $row && $row['page_id'] ? ['id' => $row['page_id'], 'name' => $row['page_name']] : null,
            'instagram' => $row && $row['instagram_id'] ? ['id' => $row['instagram_id'], 'username' => $row['instagram_username']] : null,
            'ready' => $row !== null && !$expired && $row['ad_account_id'] && $row['page_id'],
            'max_daily_budget' => MetaMarketing::maxDailyBudget(),
            'connected_at' => $row['created_at'] ?? null,
        ];
    }

    /** Segna il collegamento come scaduto o in errore, così la scheda del cliente chiede di ricollegarlo. */
    public static function markError(array $connection, MetaApiException $e): void
    {
        Database::connection()->prepare('UPDATE meta_connections SET status=?, last_error=? WHERE id=?')
            ->execute([$e->expired() ? 'expired' : 'error', mb_substr($e->getMessage(), 0, 500), $connection['id']]);
    }

    /** Salva il collegamento; ricollegando lo stesso cliente si tengono le scelte fatte prima. */
    private static function save(array $user, int $brandId, array $grant, bool $sample): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO meta_connections (organization_id, brand_profile_id, connected_by, sample, meta_user_id, meta_user_name, access_token, token_expires_at, scopes, status, last_error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', NULL)
            ON DUPLICATE KEY UPDATE connected_by=VALUES(connected_by), sample=VALUES(sample), meta_user_id=VALUES(meta_user_id), meta_user_name=VALUES(meta_user_name),
              access_token=VALUES(access_token), token_expires_at=VALUES(token_expires_at), scopes=VALUES(scopes), status=\'active\', last_error=NULL')
            ->execute([
                $user['organization_id'], $brandId, $user['id'], $sample ? 1 : 0, $grant['user_id'], $grant['user_name'],
                $sample ? 'sample' : Secrets::encrypt($grant['token']), $grant['expires_at'], json_encode($grant['scopes']),
            ]);
        if ($sample) {
            // Con i dati di esempio l'account e la pagina sono già scelti.
            $assets = MetaMarketing::assets(['sample' => 1]);
            $account = $assets['ad_accounts'][0];
            $page = $assets['pages'][0];
            $pdo->prepare('UPDATE meta_connections SET ad_account_id=COALESCE(ad_account_id, ?), ad_account_name=COALESCE(ad_account_name, ?), currency=COALESCE(currency, ?),
                page_id=COALESCE(page_id, ?), page_name=COALESCE(page_name, ?), instagram_id=COALESCE(instagram_id, ?), instagram_username=COALESCE(instagram_username, ?)
                WHERE organization_id=? AND brand_profile_id=?')
                ->execute([$account['id'], $account['name'], $account['currency'], $page['id'], $page['name'], $page['instagram_id'], $page['instagram_username'], $user['organization_id'], $brandId]);
        }
        Audit::log($user, 'meta.connected', 'brand_profile', $brandId);
    }

    /** Torna alla scheda del cliente nell'app, con l'esito nell'indirizzo (mai dati di Meta). */
    private static function backToApp(?int $brandId, string $result, ?string $message): never
    {
        $base = rtrim(getenv('FRONTEND_URL') ?: (getenv('CORS_ORIGIN') ?: 'http://localhost:4200'), '/');
        $query = http_build_query(array_filter(['meta' => $result, 'meta_message' => $message]));
        header('Location: ' . $base . ($brandId ? "/clients/{$brandId}" : '/clients') . '?' . $query, true, 302);
        exit;
    }
}
