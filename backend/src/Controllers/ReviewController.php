<?php
declare(strict_types=1);

/** Risposte alle recensioni (Google, Facebook, TripAdvisor…): l'AI propone, l'agenzia sceglie e salva. */
final class ReviewController
{
    public const PLATFORMS = ['google', 'facebook', 'tripadvisor', 'trustpilot', 'other'];

    public static function index(): never
    {
        $user = Auth::user();
        $params = [$user['organization_id']];
        $brandFilter = '';
        if (($brandId = filter_var($_GET['brand_id'] ?? null, FILTER_VALIDATE_INT)) !== false && $brandId !== null) {
            $brandFilter = ' AND r.brand_profile_id=?';
            $params[] = $brandId;
        }
        $stmt = Database::connection()->prepare("SELECT r.id, r.brand_profile_id, b.name AS brand_name, r.platform, r.author, r.rating, r.review_text, r.reply, r.created_at
            FROM review_replies r JOIN brand_profiles b ON b.id=r.brand_profile_id AND b.organization_id=r.organization_id
            WHERE r.organization_id=?{$brandFilter} ORDER BY r.id DESC LIMIT 200");
        $stmt->execute($params);
        Http::json(['data' => array_map(fn($r) => ['rating' => (int)$r['rating']] + $r, $stmt->fetchAll())]);
    }

    /** Avvia la scrittura delle risposte proposte, in background. */
    public static function suggest(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $review = self::review(Http::body());
        Http::json(['data' => Jobs::enqueue($user, 'review_replies', ['brand_id' => $brandId] + $review)], 202);
    }

    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        $brandId = Http::optionalInt($data, 'brand_id') ?? Http::validationError('Scegli il cliente.');
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $review = self::review($data);
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO review_replies (organization_id, brand_profile_id, platform, author, rating, review_text, reply, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$user['organization_id'], $brandId, $review['platform'], $review['author'], $review['rating'], $review['text'], Http::requiredString($data, 'reply', 4000), $user['id']]);
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'review_reply.saved', 'review_reply', $id);
        Http::json(['data' => ['id' => $id]], 201);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('review_replies', $id, $user['organization_id'], 'id');
        Database::connection()->prepare('DELETE FROM review_replies WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        Http::json(['data' => ['deleted' => true]]);
    }

    /** @return array{platform: string, rating: int, author: ?string, text: string} */
    private static function review(array $data): array
    {
        $rating = Http::optionalInt($data, 'rating') ?? 5;
        if ($rating < 1 || $rating > 5) Http::validationError('Le stelle devono essere tra 1 e 5.');
        return [
            'platform' => Http::oneOf($data, 'platform', self::PLATFORMS, 'google'),
            'rating' => $rating,
            'author' => Http::optionalString($data, 'author', 120),
            'text' => Http::requiredString($data, 'review_text', 4000),
        ];
    }
}
