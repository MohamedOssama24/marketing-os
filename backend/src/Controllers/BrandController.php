<?php
declare(strict_types=1);

final class BrandController
{
    public const COLUMNS = 'id, name, summary, website_url, industry, audience, tone_of_voice, brand_guidelines, primary_color, secondary_color, font_family, logo_asset_id, products, keywords, words_to_avoid, created_at, updated_at';

    /** Campi testuali facoltativi del brand kit con la lunghezza massima. */
    private const TEXT_FIELDS = [
        'summary' => 500,
        'industry' => 160,
        'audience' => 2000,
        'tone_of_voice' => 2000,
        'brand_guidelines' => 5000,
        'font_family' => 120,
        'products' => 5000,
        'keywords' => 1000,
        'words_to_avoid' => 1000,
    ];

    public static function index(): never
    {
        $user = Auth::user();
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM brand_profiles WHERE organization_id=? ORDER BY name');
        $stmt->execute([$user['organization_id']]);
        Http::json(['data' => $stmt->fetchAll()]);
    }

    public static function show(int $id): never
    {
        $user = Auth::user();
        Http::json(['data' => Tenant::findOrFail('brand_profiles', $id, $user['organization_id'], self::COLUMNS)]);
    }

    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        $fields = ['name' => Http::requiredString($data, 'name', 160)] + self::validated($data, $user, true);

        $pdo = Database::connection();
        $columns = array_keys($fields);
        $stmt = $pdo->prepare('INSERT INTO brand_profiles (organization_id, ' . implode(', ', $columns) . ') VALUES (?' . str_repeat(', ?', count($columns)) . ')');
        $stmt->execute([$user['organization_id'], ...array_values($fields)]);
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'brand.created', 'brand_profile', $id);
        Http::json(['data' => Tenant::findOrFail('brand_profiles', $id, $user['organization_id'], self::COLUMNS)], 201);
    }

    public static function update(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $id, $user['organization_id'], 'id');
        $data = Http::body();
        $fields = self::validated($data, $user, false);
        if (array_key_exists('name', $data)) $fields['name'] = Http::requiredString($data, 'name', 160);

        if ($fields) {
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            $stmt = Database::connection()->prepare("UPDATE brand_profiles SET {$set} WHERE id=? AND organization_id=?");
            $stmt->execute([...array_values($fields), $id, $user['organization_id']]);
            Audit::log($user, 'brand.updated', 'brand_profile', $id);
        }
        Http::json(['data' => Tenant::findOrFail('brand_profiles', $id, $user['organization_id'], self::COLUMNS)]);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        Tenant::findOrFail('brand_profiles', $id, $user['organization_id'], 'id');
        $stmt = Database::connection()->prepare('DELETE FROM brand_profiles WHERE id=? AND organization_id=?');
        $stmt->execute([$id, $user['organization_id']]);
        Audit::log($user, 'brand.deleted', 'brand_profile', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /**
     * Valida i campi presenti nel body. In creazione ogni campo assente vale null;
     * in modifica vengono restituiti solo i campi inviati.
     */
    private static function validated(array $data, array $user, bool $creating): array
    {
        $fields = [];
        foreach (self::TEXT_FIELDS as $key => $max) {
            if ($creating || array_key_exists($key, $data)) $fields[$key] = Http::optionalString($data, $key, $max);
        }
        if ($creating || array_key_exists('website_url', $data)) {
            $url = Http::optionalString($data, 'website_url', 2048);
            if ($url !== null && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
                Http::validationError("Campo 'website_url' deve essere un indirizzo http(s) valido.");
            }
            $fields['website_url'] = $url;
        }
        foreach (['primary_color', 'secondary_color'] as $key) {
            if (!$creating && !array_key_exists($key, $data)) continue;
            $color = Http::optionalString($data, $key, 7);
            if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) Http::validationError("Campo '{$key}' deve essere un colore esadecimale, es. #5B4BDB.");
            $fields[$key] = $color === null ? null : strtoupper($color);
        }
        if ($creating || array_key_exists('logo_asset_id', $data)) {
            $logoId = Http::optionalInt($data, 'logo_asset_id');
            if ($logoId !== null) {
                $asset = Tenant::find('assets', $logoId, $user['organization_id'], 'id, kind');
                if (!$asset || $asset['kind'] !== 'image') Http::validationError('Il logo deve essere un\'immagine della tua libreria file.');
            }
            $fields['logo_asset_id'] = $logoId;
        }
        return $fields;
    }
}
