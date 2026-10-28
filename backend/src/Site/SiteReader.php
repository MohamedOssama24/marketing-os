<?php
declare(strict_types=1);

/** Legge un sito e ne estrae ciò che serve al brand kit: testi, colori e un'immagine da usare come logo. */
final class SiteReader
{
    private const MAX_EXTRA_PAGES = 3;
    private const MAX_STYLESHEETS = 2;
    private const PAGE_HINTS = '/chi-siamo|about|storia|azienda|prodott|product|servizi|service|shop|negozio|menu|catalog/i';

    /**
     * @return array{url: string, host: string, title: string, description: string, site_name: string,
     *   pages: list<array{url: string, text: string}>, colors: list<string>, logo_candidates: list<string>}
     */
    public static function read(string $inputUrl): array
    {
        $home = SafeHttp::get(SafeHttp::normalizeUrl($inputUrl));
        if (!str_contains($home['content_type'], 'html')) throw new SiteException('L\'indirizzo non è una pagina web.');
        $baseUrl = $home['url'];
        $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        $dom = self::dom($home['body']);
        $xpath = new DOMXPath($dom);

        $meta = fn(string $query) => trim((string)($xpath->query($query)->item(0)?->nodeValue ?? ''));
        $title = $meta('//title');
        $description = $meta('//meta[@name="description"]/@content') ?: $meta('//meta[@property="og:description"]/@content');
        $siteName = $meta('//meta[@property="og:site_name"]/@content');

        // Colori: theme-color dichiarato, poi i più frequenti in CSS inline e nei fogli di stile del sito.
        $css = '';
        foreach ($xpath->query('//style') as $node) $css .= $node->textContent . "\n";
        foreach ($xpath->query('//*[@style]/@style') as $node) $css .= $node->nodeValue . ";\n";
        $sheets = 0;
        foreach ($xpath->query('//link[contains(@rel,"stylesheet")]/@href') as $node) {
            if ($sheets >= self::MAX_STYLESHEETS) break;
            $sheetUrl = self::absolute($baseUrl, (string)$node->nodeValue);
            if (!$sheetUrl || strtolower((string)parse_url($sheetUrl, PHP_URL_HOST)) !== $host) continue;
            try {
                $css .= SafeHttp::get($sheetUrl, 800_000, 8)['body'] . "\n";
                $sheets++;
            } catch (SiteException) {}
        }
        $colors = self::colors($css, $meta('//meta[@name="theme-color"]/@content'));

        // Logo: immagine con "logo" nel nome, nella classe o nel testo alternativo; poi icone e immagine social.
        $logoCandidates = [];
        foreach ($xpath->query('//img[contains(translate(concat(@src," ",@class," ",@id," ",@alt),"LOGO","logo"),"logo")]/@src') as $node) $logoCandidates[] = (string)$node->nodeValue;
        foreach ($xpath->query('//link[contains(@rel,"apple-touch-icon")]/@href') as $node) $logoCandidates[] = (string)$node->nodeValue;
        $logoCandidates[] = $meta('//meta[@property="og:image"]/@content');
        $logoCandidates = array_values(array_unique(array_filter(array_map(fn($u) => self::absolute($baseUrl, $u), $logoCandidates))));

        // I link vanno raccolti prima di estrarre il testo, che rimuove menu e piè di pagina.
        $links = [];
        foreach ($xpath->query('//a[@href]') as $link) $links[] = [(string)$link->getAttribute('href'), $link->textContent];
        $pages = [['url' => $baseUrl, 'text' => self::text($dom, 6000)]];
        $seen = [rtrim($baseUrl, '/') => true];
        foreach ($links as [$rawHref, $label]) {
            if (count($pages) > self::MAX_EXTRA_PAGES) break;
            $href = self::absolute($baseUrl, $rawHref);
            if (!$href || strtolower((string)parse_url($href, PHP_URL_HOST)) !== $host) continue;
            $key = rtrim(strtok($href, '#'), '/');
            if (isset($seen[$key]) || !preg_match(self::PAGE_HINTS, $href . ' ' . $label)) continue;
            $seen[$key] = true;
            try {
                $page = SafeHttp::get($key, 1_500_000, 8);
                if (str_contains($page['content_type'], 'html')) $pages[] = ['url' => $key, 'text' => self::text(self::dom($page['body']), 3000)];
            } catch (SiteException) {}
        }

        return [
            'url' => $baseUrl, 'host' => $host, 'title' => mb_substr($title, 0, 200), 'description' => mb_substr($description, 0, 500),
            'site_name' => mb_substr($siteName, 0, 160), 'pages' => $pages, 'colors' => $colors, 'logo_candidates' => array_slice($logoCandidates, 0, 5),
        ];
    }

    /** Scarica la prima immagine valida tra i candidati (PNG, JPG, WEBP, GIF; SVG escluso). */
    public static function downloadLogo(array $candidates): ?array
    {
        foreach ($candidates as $url) {
            try {
                $file = SafeHttp::get($url, 5_000_000, 8);
            } catch (SiteException) {
                continue;
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($file['body']) ?: '';
            $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
            if ($extension && strlen($file['body']) > 200) {
                return ['bytes' => $file['body'], 'mime' => $mime, 'extension' => $extension, 'name' => 'logo-' . basename((string)parse_url($url, PHP_URL_PATH) ?: 'sito') ];
            }
        }
        return null;
    }

    private static function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        return $dom;
    }

    private static function text(DOMDocument $dom, int $maxChars): string
    {
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//script|//style|//noscript|//svg|//nav|//footer|//iframe')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $text = preg_replace('/\s+/u', ' ', (string)($xpath->query('//body')->item(0)?->textContent ?? ''));
        return mb_substr(trim((string)$text), 0, $maxChars);
    }

    /** @return list<string> fino a 6 colori esadecimali, esclusi bianchi, neri e grigi */
    private static function colors(string $css, string $themeColor): array
    {
        $counts = [];
        preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $css, $matches);
        foreach ($matches[1] as $hex) {
            if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            $hex = '#' . strtoupper($hex);
            if (!self::isNeutral($hex)) $counts[$hex] = ($counts[$hex] ?? 0) + 1;
        }
        arsort($counts);
        $colors = array_keys($counts);
        if (preg_match('/^#[0-9a-f]{6}$/i', $themeColor) && !self::isNeutral(strtoupper($themeColor))) {
            array_unshift($colors, strtoupper($themeColor));
        }
        return array_slice(array_values(array_unique($colors)), 0, 6);
    }

    private static function isNeutral(string $hex): bool
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return max($r, $g, $b) - min($r, $g, $b) < 24;
    }

    private static function absolute(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^(mailto|tel|javascript|data):#i', $href)) return null;
        if (preg_match('#^https?://#i', $href)) return $href;
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($href, '//')) return $parts['scheme'] . ':' . $href;
        if (str_starts_with($href, '/')) return $origin . $href;
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        return $origin . $dir . $href;
    }
}
