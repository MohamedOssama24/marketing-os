<?php
declare(strict_types=1);

/** Cifra i codici di accesso salvati nel database (per esempio quello di Meta) con una chiave derivata da APP_KEY. */
final class Secrets
{
    private const PREFIX = 'v1:';

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** Null se il testo è stato manomesso o cifrato con un'altra APP_KEY. */
    public static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) return null;
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $appKey = (string)getenv('APP_KEY');
        if (strlen($appKey) < 16 || $appKey === 'replace-with-a-long-random-secret') {
            throw new RuntimeException('APP_KEY mancante o di esempio: serve per cifrare i codici di accesso.');
        }
        return sodium_crypto_generichash('marketing-os secrets|' . $appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
