<?php
declare(strict_types=1);

final class CampaignController
{
    public const CAMPAIGN_CHANNELS = ['instagram', 'facebook', 'linkedin', 'blog', 'email'];
    private const MAX_POSTS = 16;
    private const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    /** "Dal sito alla campagna", primo passo: legge il sito e crea il cliente con il suo brand kit. */
    public static function analyzeSite(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        try {
            $url = SafeHttp::normalizeUrl(Http::requiredString($data, 'url', 2048));
        } catch (SiteException $e) {
            Http::validationError($e->getMessage());
        }
        $job = Jobs::enqueue($user, 'analyze_site', ['url' => $url]);
        Audit::log($user, 'brand.analysis_requested', 'job', (int)$job['id']);
        Http::json(['data' => $job], 202);
    }

    /** Secondo passo: crea la campagna del cliente e ne avvia la pianificazione con l'AI. */
    public static function create(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $brand = Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id, name, audience');
        $data = Http::body();

        $goal = Http::requiredString($data, 'goal', 500);
        $weeks = Http::optionalInt($data, 'weeks') ?? 4;
        $perWeek = Http::optionalInt($data, 'posts_per_week') ?? 3;
        if ($weeks < 1 || $weeks > 4) Http::validationError('La durata deve essere tra 1 e 4 settimane.');
        if ($perWeek < 1 || $perWeek > 5) Http::validationError('I post a settimana devono essere tra 1 e 5.');
        if ($weeks * $perWeek > self::MAX_POSTS) Http::validationError('Massimo ' . self::MAX_POSTS . ' post per campagna: riduci durata o frequenza.');

        $channels = $data['channels'] ?? ['instagram', 'facebook'];
        if (!is_array($channels) || $channels === [] || array_diff($channels, self::CAMPAIGN_CHANNELS)) {
            Http::validationError('Canali non validi. Valori ammessi: ' . implode(', ', self::CAMPAIGN_CHANNELS) . '.');
        }
        $channels = array_values(array_unique($channels));

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($data['start_date'] ?? ''))
            ?: new DateTimeImmutable('tomorrow');
        $name = Http::optionalString($data, 'name', 180)
            ?? 'Campagna ' . self::MONTHS[(int)$start->format('n') - 1] . ' ' . $start->format('Y');

        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT INTO projects (organization_id, brand_profile_id, created_by, name, objective, target_audience, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$user['organization_id'], $brandId, $user['id'], $name, $goal, $brand['audience']]);
        $projectId = (int)$pdo->lastInsertId();

        $job = Jobs::enqueue($user, 'generate_campaign', [
            'project_id' => $projectId, 'goal' => $goal, 'weeks' => $weeks, 'posts_per_week' => $perWeek,
            'channels' => $channels, 'start_date' => $start->format('Y-m-d'),
        ]);
        Audit::log($user, 'campaign.created', 'project', $projectId);
        Http::json(['data' => ['project' => ProjectController::find($projectId, $user['organization_id']), 'job' => $job]], 202);
    }
}
