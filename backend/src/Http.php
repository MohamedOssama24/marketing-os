<?php
declare(strict_types=1);

final class Http
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) self::json(['error' => 'invalid_json', 'message' => 'Il body deve essere un oggetto JSON valido.'], 400);
        return $data;
    }

    public static function requiredString(array $data, string $key, int $max): string
    {
        $value = trim((string)($data[$key] ?? ''));
        if ($value === '' || mb_strlen($value) > $max) {
            self::json(['error' => 'validation_error', 'message' => "Campo '{$key}' obbligatorio (massimo {$max} caratteri)."], 422);
        }
        return $value;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', $header, $matches)) return $matches[1];
        return null;
    }
}
