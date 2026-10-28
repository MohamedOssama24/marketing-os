<?php
declare(strict_types=1);

/** Stile appreso per cliente: le correzioni e le richieste di modifica che l'AI usa come esempi. */
final class StyleController
{
    public static function index(int $brandId): never
    {
        $user = Auth::user();
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $stmt = Database::connection()->prepare('SELECT id, content_item_id, channel, kind, before_text, after_text, created_at FROM style_examples WHERE organization_id=? AND brand_profile_id=? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$user['organization_id'], $brandId]);
        Http::json(['data' => $stmt->fetchAll()]);
    }

    /** Un esempio sbagliato o superato si toglie, così l'AI non lo segue più. */
    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('style_examples', $id, $user['organization_id'], 'id');
        Database::connection()->prepare('DELETE FROM style_examples WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        Audit::log($user, 'style_example.deleted', 'style_example', $id);
        Http::json(['data' => ['deleted' => true]]);
    }
}
