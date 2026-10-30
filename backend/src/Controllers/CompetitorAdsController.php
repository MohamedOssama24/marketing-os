<?php
declare(strict_types=1);

/** Le inserzioni dei concorrenti di un cliente: pubblico, zone, spesa stimata e cosa offrono, accanto alla spesa reale del cliente. */
final class CompetitorAdsController
{
    public static function index(int $brandId): never
    {
        $user = Auth::user();
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $pdo = Database::connection();
        $cpm = self::cpm($user['organization_id']);
        $today = date('Y-m-d');

        $stmt = $pdo->prepare('SELECT c.id, c.name, c.meta_page_id, c.ads_checked_at, c.ads_error, c.ads_summary, c.ads_new_count, c.ads_new_at, c.ads_job_id, j.status AS job_status
            FROM competitors c LEFT JOIN jobs j ON j.id=c.ads_job_id WHERE c.organization_id=? AND c.brand_profile_id=? ORDER BY c.id');
        $stmt->execute([$user['organization_id'], $brandId]);
        $competitors = [];
        foreach ($stmt->fetchAll() as $c) {
            $ads = self::ads((int)$c['id'], $user['organization_id']);
            $insights = MetaAdLibrary::insights($ads, $cpm, $today);
            $winners = array_column($insights['winners'], 'archive_id');
            usort($ads, fn($a, $b) => [$b['stopped_on'] === null, $b['started_on']] <=> [$a['stopped_on'] === null, $a['started_on']]);
            $competitors[] = [
                'id' => (int)$c['id'],
                'name' => $c['name'],
                'meta_page_id' => $c['meta_page_id'],
                'checked_at' => $c['ads_checked_at'],
                'error' => $c['ads_error'],
                'syncing' => in_array($c['job_status'], ['queued', 'running'], true),
                'job_id' => $c['ads_job_id'] === null ? null : (int)$c['ads_job_id'],
                'summary' => $c['ads_summary'] === null ? null : json_decode($c['ads_summary'], true),
                'new_ads' => (int)$c['ads_new_count'],
                'new_at' => $c['ads_new_at'],
                'insights' => $insights,
                'ads' => array_map(fn($ad) => self::present($ad, $cpm, $today, in_array($ad['archive_id'], $winners, true)), array_slice($ads, 0, 12)),
                'library_url' => $c['meta_page_id']
                    ? 'https://www.facebook.com/ads/library/?active_status=all&ad_type=all&country=IT&view_all_page_id=' . rawurlencode($c['meta_page_id'])
                    : 'https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country=IT&q=' . rawurlencode($c['name']),
            ];
        }

        // Spesa reale del cliente negli ultimi 30 giorni, dalle sponsorizzate registrate, per il confronto.
        $spent = $pdo->prepare('SELECT COALESCE(SUM(spent), 0) FROM ad_spend WHERE organization_id=? AND brand_profile_id=? AND period_end >= ?');
        $spent->execute([$user['organization_id'], $brandId, date('Y-m-d', strtotime('-30 days'))]);
        Http::json(['data' => [
            'source' => MetaAdLibrary::source(),
            'cpm' => $cpm,
            'default_cpm' => MetaAdLibrary::DEFAULT_CPM,
            'frequency' => MetaAdLibrary::FREQUENCY,
            'window_days' => MetaAdLibrary::WINDOW_DAYS,
            'client_spend_30d' => round((float)$spent->fetchColumn(), 2),
            'competitors' => $competitors,
        ]]);
    }

    /** Rilegge le inserzioni di un concorrente; con meta_page imposta la sua pagina Facebook (link dell'archivio o numero). */
    public static function sync(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $competitor = Tenant::findOrFail('competitors', $id, $user['organization_id'], 'id, meta_page_id');
        if (MetaAdLibrary::source() === null) Http::validationError('L\'archivio inserzioni di Meta non è collegato: aggiungi META_AD_LIBRARY_TOKEN nel file .env.');
        $data = Http::body();
        $pdo = Database::connection();
        $page = Http::optionalString($data, 'meta_page', 500);
        if ($page !== null) {
            $pageId = MetaAdLibrary::pageIdFrom($page) ?? Http::validationError('Incolla il link della pagina nell\'archivio inserzioni di Meta (contiene view_all_page_id=…) oppure il suo numero.');
            if ($pageId !== $competitor['meta_page_id']) {
                // Pagina diversa: le inserzioni lette finora erano di qualcun altro.
                $pdo->prepare('DELETE FROM competitor_ads WHERE competitor_id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
                $pdo->prepare('UPDATE competitors SET meta_page_id=?, ads_summary=NULL, ads_new_count=0, ads_new_at=NULL, ads_checked_at=NULL WHERE id=? AND organization_id=?')
                    ->execute([$pageId, $id, $user['organization_id']]);
                Audit::log($user, 'competitor.meta_page_updated', 'competitor', $id);
            }
        }
        $job = self::enqueue($id, $user);
        Http::json(['data' => ['job_id' => (int)$job['id']]], 202);
    }

    /** Costo per 1.000 visualizzazioni usato nelle stime (null torna al valore predefinito). */
    public static function updateSettings(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        $cpm = $data['cpm'] ?? null;
        if ($cpm !== null && (!is_numeric($cpm) || (float)$cpm < 0.5 || (float)$cpm > 100)) Http::validationError('Il costo per 1.000 visualizzazioni deve essere tra 0,50 € e 100 €.');
        Database::connection()->prepare('UPDATE organizations SET ads_cpm=? WHERE id=?')->execute([$cpm === null ? null : round((float)$cpm, 2), $user['organization_id']]);
        Audit::log($user, 'organization.ads_cpm_updated', 'organization', $user['organization_id']);
        Http::json(['data' => ['cpm' => self::cpm($user['organization_id'])]]);
    }

    /** Mette in coda la lettura delle inserzioni (anche dal worker e quando si aggiunge un concorrente). */
    public static function enqueue(int $competitorId, array $user, bool $automatic = false): array
    {
        $job = Jobs::enqueue($user, 'competitor_ads', ['competitor_id' => $competitorId] + ($automatic ? ['automatic' => true] : []));
        Database::connection()->prepare('UPDATE competitors SET ads_job_id=? WHERE id=? AND organization_id=?')->execute([$job['id'], $competitorId, $user['organization_id']]);
        return $job;
    }

    public static function cpm(int $organizationId): float
    {
        $stmt = Database::connection()->prepare('SELECT ads_cpm FROM organizations WHERE id=?');
        $stmt->execute([$organizationId]);
        $value = $stmt->fetchColumn();
        return $value === null || $value === false ? MetaAdLibrary::DEFAULT_CPM : (float)$value;
    }

    /** @return list<array> inserzioni salvate del concorrente, ultime 90 giorni */
    public static function ads(int $competitorId, int $organizationId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM competitor_ads WHERE competitor_id=? AND organization_id=? AND (stopped_on IS NULL OR stopped_on >= ?) ORDER BY started_on DESC LIMIT 300');
        $stmt->execute([$competitorId, $organizationId, date('Y-m-d', strtotime('-' . MetaAdLibrary::WINDOW_DAYS . ' days'))]);
        return array_map(function (array $ad): array {
            foreach (['bodies', 'titles', 'captions', 'platforms'] as $key) $ad[$key] = json_decode($ad[$key], true) ?: [];
            foreach (['demographics', 'target_locations', 'target_ages'] as $key) $ad[$key] = $ad[$key] === null ? null : json_decode($ad[$key], true);
            $ad['eu_reach'] = $ad['eu_reach'] === null ? null : (int)$ad['eu_reach'];
            return $ad;
        }, $stmt->fetchAll());
    }

    private static function present(array $ad, float $cpm, string $today, bool $winner): array
    {
        $end = $ad['stopped_on'] !== null && $ad['stopped_on'] < $today ? $ad['stopped_on'] : $today;
        $estimate = MetaAdLibrary::estimate($ad['eu_reach'], $cpm);
        return [
            'archive_id' => $ad['archive_id'],
            'title' => $ad['titles'][0] ?? '',
            'text' => $ad['bodies'][0] ?? '',
            'caption' => $ad['captions'][0] ?? '',
            'platforms' => $ad['platforms'],
            'started_on' => $ad['started_on'],
            'stopped_on' => $ad['stopped_on'],
            'active' => $ad['stopped_on'] === null || $ad['stopped_on'] >= $today,
            'days' => (int)((strtotime($end) - strtotime($ad['started_on'])) / 86400) + 1,
            'reach' => $ad['eu_reach'],
            'spend' => $estimate ? ['low' => (int)round($estimate[0]), 'high' => (int)round($estimate[1])] : null,
            'places' => array_values(array_map(fn($l) => $l['name'], array_filter($ad['target_locations'] ?? [], fn($l) => !$l['excluded']))),
            'ages' => $ad['target_ages'],
            'gender' => $ad['target_gender'],
            'winner' => $winner,
            // Link pubblico all'inserzione: l'anteprima dell'API contiene il codice di accesso e non va mai mostrata.
            'url' => 'https://www.facebook.com/ads/library/?id=' . rawurlencode($ad['archive_id']),
        ];
    }
}
