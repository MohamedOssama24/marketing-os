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

    public static function validationError(string $message): never
    {
        self::json(['error' => 'validation_error', 'message' => $message], 422);
    }

    public static function requiredString(array $data, string $key, int $max): string
    {
        $value = is_scalar($data[$key] ?? null) ? trim((string)$data[$key]) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            self::validationError("Campo '{$key}' obbligatorio (massimo {$max} caratteri).");
        }
        return $value;
    }

    /** Stringa facoltativa: vuota o assente diventa null. */
    public static function optionalString(array $data, string $key, int $max): ?string
    {
        if (!isset($data[$key])) return null;
        if (!is_scalar($data[$key])) self::validationError("Campo '{$key}' non valido.");
        $value = trim((string)$data[$key]);
        if ($value === '') return null;
        if (mb_strlen($value) > $max) self::validationError("Campo '{$key}' troppo lungo (massimo {$max} caratteri).");
        return $value;
    }

    public static function optionalInt(array $data, string $key): ?int
    {
        if (!isset($data[$key]) || $data[$key] === '') return null;
        if (filter_var($data[$key], FILTER_VALIDATE_INT) === false) self::validationError("Campo '{$key}' deve essere un intero.");
        return (int)$data[$key];
    }

    public static function oneOf(array $data, string $key, array $allowed, ?string $default = null): string
    {
        $value = $data[$key] ?? $default;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::validationError("Campo '{$key}' non valido. Valori ammessi: " . implode(', ', $allowed) . '.');
        }
        return $value;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', $header, $matches)) return $matches[1];
        return null;
    }

    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
