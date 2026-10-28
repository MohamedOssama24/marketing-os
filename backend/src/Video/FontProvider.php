<?php
declare(strict_types=1);

/**
 * File TTF statici per disegnare i testi dei video (GD non sa scegliere il peso di un font variabile).
 * Scarica da Google Fonts il font del brand o Inter e li tiene in cache nello storage; senza rete usa Inter installato.
 */
final class FontProvider
{
    private const FALLBACK = '/usr/share/fonts/inter/InterVariable.ttf';

    public static function ttf(?string $family, int $weight): string
    {
        foreach (array_unique(array_filter([trim((string)$family), 'Inter'])) as $name) {
            if (!preg_match('/^[A-Za-z0-9 ]{2,60}$/', $name)) continue;
            $path = self::cacheDir() . '/' . strtolower(str_replace(' ', '-', $name)) . "-{$weight}.ttf";
            if (is_file($path) && filesize($path) > 1000) return $path;
            try {
                $css = SafeHttp::get('https://fonts.googleapis.com/css2?family=' . rawurlencode($name) . ":wght@{$weight}", 200_000, 8)['body'];
                if (!preg_match('#url\((https://fonts\.gstatic\.com/[^)]+\.ttf)\)#', $css, $match)) continue;
                $font = SafeHttp::get($match[1], 5_000_000, 15)['body'];
                if (strlen($font) > 1000 && file_put_contents($path, $font) !== false) return $path;
            } catch (SiteException) {
                continue;
            }
        }
        return self::FALLBACK;
    }

    private static function cacheDir(): string
    {
        $dir = rtrim(getenv('STORAGE_PATH') ?: '/var/www/storage', '/') . '/fonts';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return $dir;
    }
}
