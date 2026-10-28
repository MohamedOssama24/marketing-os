<?php
declare(strict_types=1);

final class AssetController
{
    private const COLUMNS = 'id, brand_profile_id, kind, original_name, mime_type, size_bytes, created_at';

    /** Tipi accettati: il MIME viene ricavato dal contenuto del file, non dal nome. */
    private const ALLOWED = [
        'image/png' => ['image', 'png'],
        'image/jpeg' => ['image', 'jpg'],
        'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'video/mp4' => ['video', 'mp4'],
        'video/quicktime' => ['video', 'mov'],
        'video/webm' => ['video', 'webm'],
        'application/pdf' => ['document', 'pdf'],
        // Musica per i video: il MIME viene riconosciuto dal contenuto, come per le immagini.
        'audio/mpeg' => ['audio', 'mp3'],
        'audio/mp4' => ['audio', 'm4a'],
        'audio/x-m4a' => ['audio', 'm4a'],
        'audio/wav' => ['audio', 'wav'],
        'audio/x-wav' => ['audio', 'wav'],
        'audio/ogg' => ['audio', 'ogg'],
    ];

    /** Elenco dei file; con ?brand_id=N solo quelli del cliente indicato. */
    public static function index(): never
    {
        $user = Auth::user();
        $brandId = Http::optionalInt($_GET, 'brand_id');
        $sql = 'SELECT ' . self::COLUMNS . ' FROM assets WHERE organization_id=?' . ($brandId !== null ? ' AND brand_profile_id=?' : '') . ' ORDER BY created_at DESC LIMIT 300';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($brandId !== null ? [$user['organization_id'], $brandId] : [$user['organization_id']]);
        Http::json(['data' => $stmt->fetchAll()]);
    }

    /** Foto del cliente (escluso il logo), usate per le grafiche dei post. */
    public static function brandPhotoIds(int $organizationId, int $brandId): array
    {
        $stmt = Database::connection()->prepare("SELECT a.id FROM assets a JOIN brand_profiles b ON b.id=a.brand_profile_id AND b.organization_id=a.organization_id
            WHERE a.organization_id=? AND a.brand_profile_id=? AND a.kind='image' AND (b.logo_asset_id IS NULL OR a.id<>b.logo_asset_id) ORDER BY a.id");
        $stmt->execute([$organizationId, $brandId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $brandId = Http::optionalInt($_POST, 'brand_profile_id');
        if ($brandId !== null && !Tenant::find('brand_profiles', $brandId, $user['organization_id'], 'id')) {
            Http::validationError('Il cliente non esiste nella tua organizzazione.');
        }
        $id = self::saveUpload($user, $brandId);
        Http::json(['data' => Tenant::findOrFail('assets', $id, $user['organization_id'], self::COLUMNS)], 201);
    }

    /**
     * Valida e salva il file inviato nel campo "file". Con $imagesOnly accetta solo immagini.
     * Il cliente ($brandId) deve essere già stato verificato dal chiamante.
     */
    public static function saveUpload(array $user, ?int $brandId, bool $imagesOnly = false): int
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || is_array($file['error'] ?? null)) Http::validationError("Invia un solo file nel campo 'file'.");
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) Http::validationError('Il file supera la dimensione massima consentita.');
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) Http::validationError('Caricamento del file non riuscito.');

        $maxBytes = max(1, (int)(getenv('UPLOAD_MAX_MB') ?: 20)) * 1024 * 1024;
        if ($file['size'] > $maxBytes) Http::validationError('Il file supera la dimensione massima consentita.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!isset(self::ALLOWED[$mime])) Http::validationError('Formato non supportato. Usa PNG, JPG, WEBP, GIF, MP4, MOV, WEBM, PDF, MP3, M4A, WAV o OGG.');
        [$kind, $extension] = self::ALLOWED[$mime];
        if ($imagesOnly && $kind !== 'image') Http::validationError('Per la grafica del post serve un\'immagine (PNG, JPG, WEBP o GIF).');

        [$relative, $target] = self::newPath($user['organization_id'], $extension);
        if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('Impossibile salvare il file caricato.');

        $originalName = mb_substr(basename((string)$file['name']), 0, 255) ?: 'file.' . $extension;
        $id = self::insert($user['organization_id'], $user['id'], $kind, $originalName, $mime, (int)$file['size'], $relative, $brandId);
        Audit::log($user, 'asset.uploaded', 'asset', $id);
        return $id;
    }

    /** Salva un'immagine ottenuta dal server (ad esempio il logo letto dal sito del cliente). */
    public static function saveBytes(int $organizationId, ?int $userId, string $bytes, string $originalName, ?int $brandId = null): ?int
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!isset(self::ALLOWED[$mime]) || self::ALLOWED[$mime][0] !== 'image') return null;
        [$relative, $target] = self::newPath($organizationId, self::ALLOWED[$mime][1]);
        if (file_put_contents($target, $bytes) === false) throw new RuntimeException('Impossibile salvare il file.');
        return self::insert($organizationId, $userId, 'image', mb_substr($originalName, 0, 255), $mime, strlen($bytes), $relative, $brandId);
    }

    /** Sposta nella libreria un file creato sul server (ad esempio un video montato dal worker). */
    public static function saveLocalFile(int $organizationId, ?int $userId, string $path, string $originalName, ?int $brandId = null): int
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (!isset(self::ALLOWED[$mime])) throw new RuntimeException("Tipo di file non ammesso: {$mime}");
        [$kind, $extension] = self::ALLOWED[$mime];
        [$relative, $target] = self::newPath($organizationId, $extension);
        // rename non funziona tra dischi diversi (ad esempio da /tmp al volume dei file): in quel caso si copia.
        if (!@rename($path, $target) && !(copy($path, $target) && unlink($path))) throw new RuntimeException('Impossibile salvare il file.');
        return self::insert($organizationId, $userId, $kind, mb_substr($originalName, 0, 255), $mime, (int)filesize($target), $relative, $brandId);
    }

    /** Percorso sul disco di un file dell'organizzazione, oppure null se non esiste o non appartiene all'organizzazione. */
    public static function localPath(int $assetId, int $organizationId, ?string $kind = null): ?string
    {
        $asset = Tenant::find('assets', $assetId, $organizationId, 'kind, storage_path');
        if (!$asset || ($kind !== null && $asset['kind'] !== $kind)) return null;
        $path = self::storageRoot() . '/' . $asset['storage_path'];
        return is_file($path) ? $path : null;
    }

    /** @return array{0: string, 1: string} percorso relativo e assoluto di un nuovo file */
    private static function newPath(int $organizationId, string $extension): array
    {
        $relative = $organizationId . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $target = self::storageRoot() . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Impossibile creare la cartella di archiviazione.');
        }
        return [$relative, $target];
    }

    private static function insert(int $organizationId, ?int $userId, string $kind, string $name, string $mime, int $size, string $relative, ?int $brandId = null): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO assets (organization_id, uploaded_by, brand_profile_id, kind, original_name, mime_type, size_bytes, storage_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$organizationId, $userId, $brandId, $kind, $name, $mime, $size, $relative]);
        return (int)$pdo->lastInsertId();
    }

    /** Restituisce il file solo a chi appartiene alla stessa organizzazione. */
    public static function download(int $id): never
    {
        $user = Auth::user();
        $asset = Tenant::findOrFail('assets', $id, $user['organization_id'], 'original_name, mime_type, storage_path');
        $path = self::storageRoot() . '/' . $asset['storage_path'];
        if (!is_file($path)) Http::json(['error' => 'not_found', 'message' => 'File non trovato.'], 404);

        header('Content-Type: ' . $asset['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . addcslashes($asset['original_name'], "\"\\\r\n") . '"');
        header('Cache-Control: private, max-age=300');
        header("Content-Security-Policy: default-src 'none'");
        readfile($path);
        exit;
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $asset = Tenant::findOrFail('assets', $id, $user['organization_id'], 'id, storage_path');
        $stmt = Database::connection()->prepare('DELETE FROM assets WHERE id=? AND organization_id=?');
        $stmt->execute([$id, $user['organization_id']]);
        @unlink(self::storageRoot() . '/' . $asset['storage_path']);
        Audit::log($user, 'asset.deleted', 'asset', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    private static function storageRoot(): string
    {
        return rtrim(getenv('STORAGE_PATH') ?: '/var/www/storage', '/');
    }
}
