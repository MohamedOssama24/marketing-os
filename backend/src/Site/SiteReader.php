<?php
declare(strict_types=1);

/**
 * Legge un sito e ne estrae ciò che serve al brand kit: testi puliti (senza menu e piè di pagina),
 * titoli, voci di menu, lingua, colori, logo e le foto più grandi dei contenuti.
 */
final class SiteReader
{
    private const MAX_STYLESHEETS = 2;
    /** Pagine interne da leggere, al massimo una per gruppo e due per i prodotti. */
    private const PAGE_GROUPS = [
        'about' => ['/chi-siamo|chisiamo|about|storia|azienda|company|who-we-are/i', 1],
        'products' => ['/prodott|product|shop|negozio|catalog|categoria|category|collezion|collection|menu|servizi|service|soluzioni|solution/i', 2],
    ];
    /** Elementi di contorno sempre da togliere prima di estrarre il testo. */
    private const NOISE_TAGS = '//script|//style|//noscript|//svg|//iframe|//form|//nav|//header|//footer|//aside|//*[@role="navigation" or @role="banner" or @role="contentinfo"]';
    /** Blocchi riconosciuti dalla classe (menu, cookie…). Mai body, main o article: alcuni temi vi mettono la parola "menu". */
    private const NOISE_CLASSES = '//*[not(self::html or self::body or self::main or self::article)]'
        . '[contains(translate(concat(@class, " ", @id), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "menu")'
        . ' or contains(translate(concat(@class, " ", @id), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "cookie")'
        . ' or contains(translate(concat(@class, " ", @id), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "breadcrumb")'
        . ' or contains(translate(concat(@class, " ", @id), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "sidebar")'
        . ' or contains(translate(concat(@class, " ", @id), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "topbar")]';

    /**
     * @return array{url: string, host: string, language: string, title: string, description: string, site_name: string, tagline: string,
     *   keywords: string, headings: list<string>, menu: list<string>, pages: list<array{url: string, text: string}>,
     *   colors: list<string>, logo_candidates: list<string>, image_candidates: list<string>}
     */
    public static function read(string $inputUrl): array
    {
        $url = SafeHttp::normalizeUrl($inputUrl);
        try {
            $home = SafeHttp::get($url);
        } catch (BlockedAddressException $e) {
            throw $e;
        } catch (SiteException $e) {
            // Molti siti rispondono solo su www.: se l'utente ha scritto il dominio nudo, riprova così.
            $host = (string)parse_url($url, PHP_URL_HOST);
            if (str_starts_with($host, 'www.') || filter_var($host, FILTER_VALIDATE_IP)) throw $e;
            $home = SafeHttp::get(preg_replace('#//' . preg_quote($host, '#') . '#', '//www.' . $host, $url, 1));
        }
        if (!str_contains($home['content_type'], 'html')) throw new SiteException('L\'indirizzo non è una pagina web.');
        $baseUrl = $home['url'];
        $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        $dom = self::dom($home['body']);
        $xpath = new DOMXPath($dom);
        $meta = fn(string $query) => self::clean((string)($xpath->query($query)->item(0)?->nodeValue ?? ''));

        $title = $meta('//title');
        $description = $meta('//meta[@name="description"]/@content') ?: $meta('//meta[@property="og:description"]/@content');
        $siteName = $meta('//meta[@property="og:site_name"]/@content');
        $language = strtolower(substr($meta('//html/@lang') ?: $meta('//meta[@property="og:locale"]/@content'), 0, 2));
        // Motto del sito: in molti temi (WordPress compreso) è la descrizione accanto al logo.
        $tagline = $meta('//*[contains(@class,"site-description") or contains(@class,"tagline") or contains(@class,"slogan")]');

        $colors = self::colors(self::css($xpath, $baseUrl, $host), $meta('//meta[@name="theme-color"]/@content'));

        $logoCandidates = [];
        foreach ($xpath->query('//img[contains(translate(concat(@src," ",@class," ",@id," ",@alt),"LOGO","logo"),"logo")]/@src') as $node) $logoCandidates[] = (string)$node->nodeValue;
        foreach ($xpath->query('//link[contains(@rel,"apple-touch-icon")]/@href') as $node) $logoCandidates[] = (string)$node->nodeValue;
        $logoCandidates[] = $meta('//meta[@property="og:image"]/@content');
        $logoCandidates = array_values(array_unique(array_filter(array_map(fn($u) => self::absolute($baseUrl, $u), $logoCandidates))));

        // Voci di menu: spesso sono proprio le categorie di prodotto. Vanno lette prima di ripulire la pagina.
        // Solo link interni al sito: esclude social e link estranei (a volte spam nascosto nel tema).
        $menu = [];
        foreach ($xpath->query('//nav//a | //header//a | //*[contains(@class,"menu")]//a') as $a) {
            $label = self::clean($a->textContent);
            $href = self::absolute($baseUrl, (string)$a->getAttribute('href'));
            if (!$href || str_starts_with(trim((string)$a->getAttribute('href')), '#') || strtolower((string)parse_url($href, PHP_URL_HOST)) !== $host) continue;
            if (mb_strlen($label) >= 3 && mb_strlen($label) <= 60 && !preg_match('/new window|nuova finestra|^(home|homepage|contatti|contattaci|contact|login|accedi|carrello|cart|cerca|search|blog|news|notizie|privacy.*|cookie.*|legal.*|scopri.*|scarica.*|vedi.*)$/iu', $label)) {
                $menu[mb_strtolower($label)] = $label;
            }
        }
        $links = [];
        foreach ($xpath->query('//a[@href]') as $link) $links[] = [(string)$link->getAttribute('href'), self::clean($link->textContent)];

        $pages = [self::page($dom, $baseUrl, 6000)];
        $images = $pages[0]['images'];
        $seen = [rtrim($baseUrl, '/') => true];
        foreach (self::PAGE_GROUPS as [$pattern, $limit]) {
            $taken = 0;
            foreach ($links as [$rawHref, $label]) {
                if ($taken >= $limit) break;
                $href = self::absolute($baseUrl, $rawHref);
                if (!$href || strtolower((string)parse_url($href, PHP_URL_HOST)) !== $host) continue;
                $key = rtrim((string)strtok($href, '#?'), '/');
                if (isset($seen[$key]) || !preg_match($pattern, $key . ' ' . $label)) continue;
                $seen[$key] = true;
                try {
                    $response = SafeHttp::get($key, 1_500_000, 8);
                    if (!str_contains($response['content_type'], 'html')) continue;
                    $page = self::page(self::dom($response['body']), $key, 3000);
                    if (mb_strlen($page['text']) < 120) continue;
                    $pages[] = $page;
                    $images = [...$images, ...$page['images']];
                    $taken++;
                } catch (SiteException) {}
            }
        }

        $pages = self::stripSharedPrefix($pages);

        return [
            'url' => $baseUrl, 'host' => $host, 'language' => $language ?: 'it',
            'title' => mb_substr($title, 0, 200), 'description' => mb_substr($description, 0, 500),
            'site_name' => mb_substr($siteName, 0, 160), 'tagline' => mb_substr($tagline, 0, 200),
            'keywords' => mb_substr($meta('//meta[@name="keywords"]/@content'), 0, 300),
            'headings' => array_slice(array_values(array_unique(array_merge(...array_column($pages, 'headings')))), 0, 30),
            'menu' => array_slice(array_values($menu), 0, 40),
            'pages' => array_map(fn($p) => ['url' => $p['url'], 'text' => $p['text']], $pages),
            'colors' => $colors,
            'logo_candidates' => array_slice($logoCandidates, 0, 5),
            'image_candidates' => array_slice(array_values(array_unique(array_diff($images, $logoCandidates))), 0, 12),
        ];
    }

    /** Scarica la prima immagine valida tra i candidati (PNG, JPG, WEBP, GIF; SVG escluso). */
    public static function downloadLogo(array $candidates): ?array
    {
        foreach ($candidates as $url) {
            if ($image = self::download($url, 200)) return $image + ['name' => 'logo-' . (basename((string)parse_url($url, PHP_URL_PATH)) ?: 'sito')];
        }
        return null;
    }

    /**
     * Scarica fino a $max foto dei contenuti, scartando icone e immagini piccole.
     * @return list<array{bytes: string, name: string}>
     */
    public static function downloadImages(array $candidates, int $max = 6): array
    {
        $images = [];
        $hashes = [];
        foreach ($candidates as $url) {
            if (count($images) >= $max) break;
            $image = self::download($url, 15_000);
            if (!$image || isset($hashes[md5($image['bytes'])])) continue;
            $size = @getimagesizefromstring($image['bytes']);
            if (!$size || $size[0] < 400 || $size[1] < 300) continue;
            $hashes[md5($image['bytes'])] = true;
            $images[] = ['bytes' => $image['bytes'], 'name' => mb_substr(basename((string)parse_url($url, PHP_URL_PATH)) ?: 'foto-sito', 0, 200)];
        }
        return $images;
    }

    /** @return array{url: string, text: string, headings: list<string>, images: list<string>} */
    private static function page(DOMDocument $dom, string $url, int $maxChars): array
    {
        $xpath = new DOMXPath($dom);
        $headings = [];
        foreach ($xpath->query('//h1|//h2|//h3') as $h) {
            $text = self::clean($h->textContent);
            if (mb_strlen($text) >= 3 && mb_strlen($text) <= 120) $headings[] = $text;
        }

        foreach (iterator_to_array($xpath->query(self::NOISE_TAGS)) as $node) $node->parentNode?->removeChild($node);
        $beforeClasses = self::clean((string)($xpath->query('//body')->item(0)?->textContent ?? ''));
        foreach (iterator_to_array($xpath->query(self::NOISE_CLASSES)) as $node) $node->parentNode?->removeChild($node);

        $images = [];
        foreach ($xpath->query('//body//img') as $img) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: self::largestFromSrcset($img->getAttribute('srcset')) ?: $img->getAttribute('src');
            $hint = strtolower($src . ' ' . $img->getAttribute('class') . ' ' . $img->getAttribute('alt'));
            if ($src === '' || preg_match('/\.svg|logo|icon|avatar|sprite|placeholder|loader|pixel|flag|payment/', $hint)) continue;
            $width = (int)$img->getAttribute('width');
            if ($width > 0 && $width < 300) continue;
            if ($absolute = self::absolute($url, $src)) $images[] = $absolute;
        }

        // Il contenuto principale, se il tema lo marca; altrimenti tutto il corpo già ripulito.
        $main = $xpath->query('//main | //article | //*[@role="main"] | //*[contains(@class,"entry-content") or contains(@class,"page-content")]')->item(0);
        $text = self::clean((string)($main?->textContent ?? ''));
        if (mb_strlen($text) < 300) $text = self::clean((string)($xpath->query('//body')->item(0)?->textContent ?? ''));
        // Se il filtro per classi ha tolto quasi tutto, il tema usa quelle parole sui contenitori: meglio il testo meno filtrato.
        if (mb_strlen($text) < 300) $text = $beforeClasses;

        return ['url' => $url, 'text' => mb_substr($text, 0, $maxChars), 'headings' => $headings, 'images' => $images];
    }

    /** Toglie il testo iniziale comune a tutte le pagine interne: è il menu del tema, non contenuto. */
    private static function stripSharedPrefix(array $pages): array
    {
        $inner = array_slice($pages, 1);
        if (count($inner) < 2) return $pages;
        $prefix = $inner[0]['text'];
        foreach ($inner as $page) {
            $length = 0;
            $max = min(mb_strlen($prefix), mb_strlen($page['text']));
            while ($length < $max && mb_substr($prefix, $length, 1) === mb_substr($page['text'], $length, 1)) $length++;
            $prefix = mb_substr($prefix, 0, $length);
        }
        if (mb_strlen($prefix) < 40) return $pages;
        foreach ($pages as &$page) {
            if (str_starts_with($page['text'], $prefix)) $page['text'] = trim(mb_substr($page['text'], mb_strlen($prefix)));
        }
        return $pages;
    }

    private static function css(DOMXPath $xpath, string $baseUrl, string $host): string
    {
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
        return $css;
    }

    /** @return array{bytes: string}|null */
    private static function download(string $url, int $minBytes): ?array
    {
        try {
            $file = SafeHttp::get($url, 5_000_000, 8);
        } catch (SiteException) {
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($file['body']) ?: '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) || strlen($file['body']) < $minBytes) return null;
        return ['bytes' => $file['body']];
    }

    private static function largestFromSrcset(string $srcset): string
    {
        $best = '';
        $bestWidth = 0;
        foreach (explode(',', $srcset) as $candidate) {
            [$candidateUrl, $descriptor] = array_pad(preg_split('/\s+/', trim($candidate)), 2, '');
            $width = (int)$descriptor;
            if ($candidateUrl !== '' && $width >= $bestWidth) {
                $best = $candidateUrl;
                $bestWidth = $width;
            }
        }
        return $best;
    }

    private static function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        return $dom;
    }

    private static function clean(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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
