<?php
declare(strict_types=1);

final class AssetController
{
    private const COLUMNS = 'id, kind, original_name, mime_type, size_bytes, created_at';

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
    ];

    public static function index(): never
    {
        $user = Auth::user();
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM assets WHERE organization_id=? ORDER BY created_at DESC LIMIT 200');
        $stmt->execute([$user['organization_id']]);
        Http::json(['data' => $stmt->fetchAll()]);
    }

    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || is_array($file['error'] ?? null)) Http::validationError("Invia un solo file nel campo 'file'.");
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) Http::validationError('Il file supera la dimensione massima consentita.');
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) Http::validationError('Caricamento del file non riuscito.');

        $maxBytes = max(1, (int)(getenv('UPLOAD_MAX_MB') ?: 20)) * 1024 * 1024;
        if ($file['size'] > $maxBytes) Http::validationError('Il file supera la dimensione massima consentita.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!isset(self::ALLOWED[$mime])) Http::validationError('Formato non supportato. Usa PNG, JPG, WEBP, GIF, MP4, MOV, WEBM o PDF.');
        [$kind, $extension] = self::ALLOWED[$mime];

        $relative = $user['organization_id'] . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $target = self::storageRoot() . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Impossibile creare la cartella di archiviazione.');
        }
        if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('Impossibile salvare il file caricato.');

        $originalName = mb_substr(basename((string)$file['name']), 0, 255) ?: 'file.' . $extension;
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO assets (organization_id, uploaded_by, kind, original_name, mime_type, size_bytes, storage_path) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$user['organization_id'], $user['id'], $kind, $originalName, $mime, (int)$file['size'], $relative]);
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'asset.uploaded', 'asset', $id);
        Http::json(['data' => Tenant::findOrFail('assets', $id, $user['organization_id'], self::COLUMNS)], 201);
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
