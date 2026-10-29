<?php
declare(strict_types=1);

/**
 * Legge un sito e ne estrae ciò che serve al brand kit: testi puliti (senza menu e piè di pagina),
 * titoli, voci di menu, lingua, colori, caratteri, logo e le foto più grandi dei contenuti;
 * in più i dati dell'attività (contatti, indirizzo, orari, valutazioni) e gli strumenti di marketing del sito.
 */
final class SiteReader
{
    private const MAX_STYLESHEETS = 2;
    /** Font generici, di sistema e di icone: non dicono nulla dello stile del marchio. */
    private const NOT_BRAND_FONTS = '/^(inherit|initial|unset|serif|sans-serif|monospace|cursive|fantasy|system-ui|-apple-system|blinkmacsystemfont|segoe ui|roboto|helvetica|helvetica neue|arial|verdana|tahoma|times|times new roman|georgia|courier|courier new|ui-sans-serif|ui-serif|ui-monospace|dashicons|eicons|glyphicons|slick|revicons|themify|ionicons|icomoon|.*awesome.*|.*icon.*|.*star.*|swiper.*)$/i';
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

    /** Immagini che hanno "logo" nel nome ma non sono il logo dell'attività: partner, pagamenti, certificazioni, social. */
    private const NOT_LOGO = '/partner|sponsor|certif|award|premio|badge|payment|pagament|paypal|visa|mastercard|trustpilot|google-?play|app-?store|flag|bandiera|organic|iso-?\d|cookie|avatar|banner|slide|hero|menu-|stories|logos\b|placeholder|loader|spinner|social|facebook|instagram|linkedin|whatsapp|youtube|tiktok|twitter|-icon\b|icon-|arrow|chevron/';
    /** Contenitori di loghi altrui (caroselli di clienti, partner, marchi trattati). */
    private const OTHERS_BOX = '/partner|sponsor|client|brands|logos|carousel|slider|certif|award|payment|marchi/';
    /** Tipi schema.org che descrivono l'attività stessa. */
    private const BUSINESS_TYPES = '/Organization|Business|Corporation|Restaurant|Store|Hotel|Clinic|Dentist|Physician|Attorney|Bakery|CafeOrCoffeeShop|BarOrPub|Winery|Brewery|BeautySalon|DaySpa|HealthClub|Dealer|Agency|School/';
    private const MAIN_SOCIALS = [
        'instagram_url' => '#^https?://(www\.)?instagram\.com/(?!p/|reel/|explore/|share)([A-Za-z0-9._]+)/?#i',
        'facebook_url' => '#^https?://(www\.|m\.|[a-z]{2}-[a-z]{2}\.)?facebook\.com/(?!sharer|share|dialog|plugins|events/)([A-Za-z0-9.\-]+)/?#i',
        'linkedin_url' => '#^https?://([a-z]{2,3}\.)?linkedin\.com/(company|in|school)/([A-Za-z0-9\-_%]+)/?#i',
    ];
    private const OTHER_SOCIALS = [
        'tiktok' => '#^https?://(www\.)?tiktok\.com/@[A-Za-z0-9._]+#i',
        'youtube' => '#^https?://(www\.)?youtube\.com/(channel/|c/|user/|@)[A-Za-z0-9._\-]+#i',
        'x' => '#^https?://(www\.)?(twitter|x)\.com/(?!share|intent|home)[A-Za-z0-9_]+#i',
        'pinterest' => '#^https?://([a-z]{2}\.|www\.)?pinterest\.[a-z.]+/(?!pin/)[A-Za-z0-9_]+#i',
    ];
    private const PLATFORMS = [
        'Shopify' => '#cdn\.shopify\.com|Shopify\.theme#',
        'WordPress' => '#/wp-content/|/wp-includes/#',
        'Wix' => '#static\.wixstatic\.com|wix-bolt#i',
        'Squarespace' => '#static1\.squarespace\.com#',
        'Webflow' => '#data-wf-page|webflow\.(js|css)#',
        'PrestaShop' => '#prestashop#i',
        'Magento' => '#Magento_|mage/cookies#',
        'Salesforce Commerce' => '#demandware#',
        'Joomla' => '#/media/jui/|content="Joomla#',
        'Drupal' => '#Drupal\.settings|drupal-settings-json#',
    ];
    private const TRACKERS = [
        'Google Analytics' => '#gtag\(|google-analytics\.com|googletagmanager\.com/gtag#',
        'Google Tag Manager' => '#googletagmanager\.com/gtm\.js|GTM-[A-Z0-9]{5,}#',
        'Google Ads' => '#googleads\.g\.doubleclick\.net|[\'"]AW-\d{6,}#',
        'Pixel di Meta' => '#connect\.facebook\.net/[^"\']*/fbevents\.js|fbq\(#',
        'Pixel di TikTok' => '#analytics\.tiktok\.com|ttq\.load#',
        'LinkedIn Insight' => '#snap\.licdn\.com|_linkedin_partner_id#',
        'Hotjar' => '#static\.hotjar\.com#',
        'Microsoft Clarity' => '#clarity\.ms/tag#',
        'Matomo' => '#matomo\.js|piwik\.js#',
    ];
    private const CONSENT = ['iubenda' => '#iubenda#i', 'Cookiebot' => '#cookiebot#i', 'OneTrust' => '#onetrust|cookielaw\.org#i', 'Complianz' => '#complianz#i', 'CookieYes' => '#cookieyes|cookie-law-info#i', 'Usercentrics' => '#usercentrics#i'];
    private const CHATS = ['Tawk.to' => '#tawk\.to#', 'Crisp' => '#client\.crisp\.chat#', 'Intercom' => '#widget\.intercom\.io#', 'Zendesk' => '#zdassets\.com|zopim#', 'HubSpot' => '#js\.hs-scripts\.com#', 'Tidio' => '#code\.tidio\.co#', 'Smartsupp' => '#smartsuppchat#', 'LiveChat' => '#cdn\.livechatinc\.com#'];

    /**
     * @return array{url: string, host: string, language: string, title: string, description: string, site_name: string, tagline: string,
     *   keywords: string, headings: list<string>, menu: list<string>, pages: list<array{url: string, text: string}>,
     *   colors: list<string>, fonts: list<string>, logos: list<array{url?: string, svg?: string, score: int}>, logo_candidates: list<string>,
     *   image_candidates: list<string>, socials: array{instagram_url?: string, facebook_url?: string, linkedin_url?: string},
     *   facts: array<string, mixed>, signals: array<string, mixed>}
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

        $css = self::css($xpath, $baseUrl, $host);
        $colors = self::colors($css, $meta('//meta[@name="theme-color"]/@content'));
        $fonts = self::fonts($css, $xpath);

        $structured = self::structuredData($xpath);
        $org = $structured['organization'];
        $logos = self::logos($xpath, $baseUrl, $host, $org['logo'], $org['name'] ?: $siteName ?: $title);
        $logoCandidates = array_values(array_filter(array_column($logos, 'url')));

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
        $hrefs = [...array_column($links, 0), ...$org['same_as']];
        $socials = self::socialProfiles($hrefs, self::MAIN_SOCIALS);

        // Testo di tutta la home, piè di pagina compreso: è lì che stanno indirizzo, telefono e partita IVA.
        // Va letto, come i segnali, prima che page() tolga menu e piè di pagina dal documento.
        $fullText = self::clean(implode(' ', array_map(fn($n) => (string)$n->nodeValue, iterator_to_array($xpath->query('//body//text()[not(ancestor::script or ancestor::style or ancestor::noscript)]')))));
        $contacts = self::contacts($xpath, $fullText, $org);
        $linkBlob = mb_strtolower(implode(' ', array_map(fn($l) => $l[0] . ' ' . $l[1], $links)));
        $signals = self::signals($home['body'], $xpath, $structured, $home['seconds'] ?? 0.0, $contacts, $linkBlob, mb_strtolower($fullText));

        $pages = [self::page($dom, $baseUrl, 6000)];
        $images = $pages[0]['images'];
        if ($ogImage = self::absolute($baseUrl, $meta('//meta[@property="og:image"]/@content') ?? '')) array_unshift($images, $ogImage);
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
            'fonts' => $fonts,
            'logos' => $logos,
            'logo_candidates' => array_slice($logoCandidates, 0, 5),
            'image_candidates' => array_slice(array_values(array_unique(array_diff($images, $logoCandidates))), 0, 12),
            'socials' => $socials,
            // Telefono, email e indirizzo dichiarati sono già tra i contatti.
            'facts' => array_diff_key($org, array_flip(['logo', 'same_as', 'telephone', 'email', 'address'])) + [
                'contacts' => $contacts,
                'other_socials' => self::socialProfiles($hrefs, self::OTHER_SOCIALS),
            ],
            'signals' => $signals,
        ];
    }

    /**
     * Scarica il primo logo valido tra i candidati, nell'ordine (PNG, JPG, WEBP, GIF; gli SVG diventano PNG).
     * Un logo bianco su trasparente (fatto per sfondi scuri) si usa solo se non c'è altro.
     * @param list<array{url?: string, svg?: string}|string> $logos
     */
    public static function downloadLogo(array $logos): ?array
    {
        $white = null;
        foreach ($logos as $logo) {
            if (is_string($logo)) $logo = ['url' => $logo];
            $image = isset($logo['svg']) ? self::rasterize($logo['svg']) : self::download($logo['url'], 200, true);
            if (!$image) continue;
            $base = isset($logo['url']) ? (basename((string)parse_url($logo['url'], PHP_URL_PATH)) ?: 'sito') : 'sito.svg';
            if ($image['converted']) $base = preg_replace('/\.svg$/i', '', $base) . '.png';
            $result = ['bytes' => $image['bytes'], 'name' => mb_substr('logo-' . $base, 0, 200)];
            if (!self::isWhiteOnTransparent($image['bytes'])) return $result;
            $white ??= $result;
        }
        return $white;
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

    /**
     * Possibili loghi, dal più probabile: immagini nell'intestazione o nel link alla home, logo dichiarato nei dati schema.org,
     * SVG disegnati nella pagina e, in fondo, le icone del sito. Partner, pagamenti e piè di pagina pesano in negativo.
     * @return list<array{url?: string, svg?: string, score: int}>
     */
    private static function logos(DOMXPath $xpath, string $baseUrl, string $host, string $declared, string $brandName): array
    {
        $brandWords = self::brandWords($brandName, $host);
        $found = [];
        $add = function (array $candidate, int $score) use (&$found): void {
            $key = $candidate['url'] ?? md5($candidate['svg']);
            if (!isset($found[$key]) || $found[$key]['score'] < $score) $found[$key] = $candidate + ['score' => $score];
        };
        $named = function (string $own) use ($brandWords): int {
            foreach ($brandWords as $word) if (str_contains($own, $word)) return 20;
            return 0;
        };

        if ($declared !== '' && ($url = self::absolute($baseUrl, $declared))) $add(['url' => $url], 70);

        $order = 0;
        foreach ($xpath->query('//body//img') as $img) {
            $src = $img->getAttribute('src');
            if ($src === '' || str_starts_with($src, 'data:')) $src = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: self::largestFromSrcset($img->getAttribute('srcset') ?: $img->getAttribute('data-srcset'));
            if (!($url = self::absolute($baseUrl, $src))) continue;
            $own = strtolower(implode(' ', [basename((string)parse_url($url, PHP_URL_PATH)), $img->getAttribute('class'), $img->getAttribute('id'), $img->getAttribute('alt'), $img->getAttribute('title')]));
            $score = self::placeScore($img, $baseUrl, $host) + (str_contains($own, 'logo') ? 35 : 0) + $named($own) + max(0, 10 - $order++);
            if (preg_match(self::NOT_LOGO, $own)) $score -= 60;
            if ($score >= 40) $add(['url' => $url], $score);
        }

        // Loghi disegnati direttamente nella pagina come SVG (frequente nei siti moderni).
        foreach ($xpath->query('//body//svg') as $svg) {
            $own = strtolower(implode(' ', [$svg->getAttribute('class'), $svg->getAttribute('id'), $svg->getAttribute('aria-label'), (string)($xpath->query('./title', $svg)->item(0)?->textContent ?? '')]));
            if (preg_match('/icon|hamburger|search|cart|arrow|close|chevron|caret|toggle|social/', $own) || $xpath->query('.//use', $svg)->length > 0) continue;
            $markup = self::standaloneSvg((string)$svg->ownerDocument->saveXML($svg));
            if (strlen($markup) < 400 || strlen($markup) > 300_000) continue;
            $score = self::placeScore($svg, $baseUrl, $host) + (str_contains($own, 'logo') ? 35 : 0) + $named($own) - 5;
            if ($score >= 55) $add(['svg' => $markup], $score);
        }

        foreach ($xpath->query('//link[contains(@rel,"apple-touch-icon")]/@href') as $node) {
            if ($url = self::absolute($baseUrl, (string)$node->nodeValue)) $add(['url' => $url], 20);
        }
        foreach ($xpath->query('//link[contains(@rel,"icon")][@sizes]') as $link) {
            if ((int)$link->getAttribute('sizes') >= 96 && ($url = self::absolute($baseUrl, $link->getAttribute('href')))) $add(['url' => $url], 12);
        }

        $found = array_values($found);
        usort($found, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($found, 0, 6);
    }

    /** Quanto la posizione nella pagina fa pensare al logo: intestazione e link alla home sì, piè di pagina e caroselli di partner no. */
    private static function placeScore(DOMNode $node, string $baseUrl, string $host): int
    {
        $header = $home = $logoBox = $footer = $others = false;
        for ($n = $node->parentNode, $depth = 0; $n instanceof DOMElement && $depth < 12; $n = $n->parentNode, $depth++) {
            $tag = strtolower($n->tagName);
            $attrs = strtolower($n->getAttribute('class') . ' ' . $n->getAttribute('id'));
            if ($tag === 'header' || preg_match('/header|navbar|masthead/', $attrs)) $header = true;
            if ($tag === 'footer' || str_contains($attrs, 'footer')) $footer = true;
            if (preg_match('/\blogo\b|logo-|-logo|site-branding|site-title|navbar-brand/', $attrs)) $logoBox = true;
            if (preg_match(self::OTHERS_BOX, $attrs)) $others = true;
            if ($tag === 'a' && self::isHomeLink($n->getAttribute('href'), $baseUrl, $host)) $home = true;
        }
        return ($header ? 25 : 0) + ($home ? 30 : 0) + ($logoBox ? 30 : 0) - ($footer ? 40 : 0) - ($others ? 50 : 0);
    }

    private static function isHomeLink(string $href, string $baseUrl, string $host): bool
    {
        $url = self::absolute($baseUrl, $href);
        if (!$url) return false;
        $bare = fn(string $h) => preg_replace('/^www\./', '', strtolower($h));
        if ($bare((string)parse_url($url, PHP_URL_HOST)) !== $bare($host)) return false;
        return (bool)preg_match('#^/?([a-z]{2}([-_][a-z]{2})?/?)?((index|home)(\.(html?|php))?)?/?$#i', (string)parse_url($url, PHP_URL_PATH));
    }

    /** Parole del nome dell'attività e del dominio con cui spesso è chiamato il file del logo. */
    private static function brandWords(string $name, string $host): array
    {
        $label = explode('.', preg_replace('/^www\./', '', $host))[0];
        $words = preg_split('/[^\p{L}0-9]+/u', mb_strtolower(explode('|', $name)[0])) ?: [];
        return array_values(array_unique(array_filter([$label, ...$words], fn($w) => mb_strlen($w) >= 4 && !preg_match('/^(home|sito|ufficiale|official|benvenuti|welcome)$/', $w))));
    }

    /** Un SVG preso da una pagina HTML, reso autonomo: namespace e nomi con le maiuscole che il parser HTML ha abbassato. */
    private static function standaloneSvg(string $markup): string
    {
        $markup = str_ireplace(
            ['viewbox=', 'preserveaspectratio=', 'gradientunits=', 'gradienttransform=', 'clippathunits=', 'patternunits=', '<lineargradient', '</lineargradient', '<radialgradient', '</radialgradient', '<clippath', '</clippath', '<textpath', '</textpath'],
            ['viewBox=', 'preserveAspectRatio=', 'gradientUnits=', 'gradientTransform=', 'clipPathUnits=', 'patternUnits=', '<linearGradient', '</linearGradient', '<radialGradient', '</radialGradient', '<clipPath', '</clipPath', '<textPath', '</textPath'],
            $markup
        );
        return (string)preg_replace('/^<svg\b(?![^>]*\sxmlns=)/i', '<svg xmlns="http://www.w3.org/2000/svg"', $markup);
    }

    /** Converte un SVG in PNG largo 600 px con rsvg-convert, che non scarica nulla dalla rete. */
    private static function rasterize(string $svg): ?array
    {
        if (strlen($svg) > 1_000_000 || !is_file('/usr/bin/rsvg-convert')) return null;
        $process = proc_open(['timeout', '10', '/usr/bin/rsvg-convert', '--width', '600', '--keep-aspect-ratio', '--format', 'png'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return null;
        fwrite($pipes[0], $svg);
        fclose($pipes[0]);
        $png = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 && @getimagesizefromstring($png) ? ['bytes' => $png, 'converted' => true] : null;
    }

    /** Vero se l'immagine ha parti trasparenti e quasi tutto il resto è bianco: un logo pensato per sfondi scuri. */
    private static function isWhiteOnTransparent(string $bytes): bool
    {
        $image = @imagecreatefromstring($bytes);
        if (!$image) return false;
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, intdiv(max($width, $height), 60));
        $visible = $white = $transparent = 0;
        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($c['alpha'] > 100) {
                    $transparent++;
                    continue;
                }
                $visible++;
                if ($c['red'] > 230 && $c['green'] > 230 && $c['blue'] > 230) $white++;
            }
        }
        imagedestroy($image);
        return $transparent > 0 && $visible > 0 && $white / $visible > 0.9;
    }

    /**
     * Dati schema.org (JSON-LD) dell'attività: nome, logo, contatti, indirizzo, orari, valutazione.
     * @return array{organization: array{name: string, legal_name: string, logo: string, telephone: string, email: string, address: string,
     *   opening_hours: string, price_range: string, rating: ?array{value: float, count: int}, founded: string, same_as: list<string>}, types: list<string>}
     */
    private static function structuredData(DOMXPath $xpath): array
    {
        $nodes = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode(trim((string)$script->textContent), true);
            if (is_array($data)) self::collectLd($data, $nodes, 0);
        }
        $types = [];
        $org = [];
        foreach ($nodes as $node) {
            $nodeTypes = array_map('strval', array_filter((array)$node['@type'], 'is_scalar'));
            $types = [...$types, ...$nodeTypes];
            // L'attività con più dati vince (alcuni siti dichiarano anche l'editore o il marchio con il solo nome).
            if (preg_grep(self::BUSINESS_TYPES, $nodeTypes) && count($node) > count($org)) $org = $node;
        }
        $text = function (mixed $value) use (&$text): string {
            if (is_array($value)) return array_is_list($value) ? ($value ? $text($value[0]) : '') : $text($value['url'] ?? $value['contentUrl'] ?? $value['name'] ?? '');
            return is_scalar($value) ? self::clean((string)$value) : '';
        };
        $rating = is_array($org['aggregateRating'] ?? null) ? $org['aggregateRating'] : null;
        return [
            'organization' => [
                'name' => mb_substr($text($org['name'] ?? ''), 0, 160),
                'legal_name' => mb_substr($text($org['legalName'] ?? ''), 0, 160),
                'logo' => mb_substr($text($org['logo'] ?? ''), 0, 2048),
                'telephone' => mb_substr($text($org['telephone'] ?? ''), 0, 40),
                'email' => mb_substr(preg_replace('/^mailto:/i', '', $text($org['email'] ?? '')), 0, 160),
                'address' => mb_substr(self::address($org['address'] ?? null), 0, 200),
                'opening_hours' => mb_substr(self::openingHours($org), 0, 300),
                'price_range' => mb_substr($text($org['priceRange'] ?? ''), 0, 40),
                'rating' => $rating && is_numeric($rating['ratingValue'] ?? null)
                    ? ['value' => round((float)$rating['ratingValue'], 1), 'count' => (int)($rating['reviewCount'] ?? $rating['ratingCount'] ?? 0)] : null,
                'founded' => mb_substr($text($org['foundingDate'] ?? ''), 0, 20),
                'same_as' => array_values(array_filter(array_map(fn($u) => is_string($u) ? $u : '', (array)($org['sameAs'] ?? [])))),
            ],
            'types' => array_values(array_unique($types)),
        ];
    }

    private static function collectLd(array $data, array &$nodes, int $depth): void
    {
        if ($depth > 4) return;
        if (array_is_list($data)) {
            foreach ($data as $item) if (is_array($item)) self::collectLd($item, $nodes, $depth + 1);
            return;
        }
        if (isset($data['@type'])) $nodes[] = $data;
        foreach (['@graph', 'publisher', 'brand', 'provider', 'mainEntity', 'about', 'author'] as $key) {
            if (is_array($data[$key] ?? null)) self::collectLd($data[$key], $nodes, $depth + 1);
        }
    }

    private static function address(mixed $address): string
    {
        if (is_array($address) && array_is_list($address)) $address = $address[0] ?? null;
        if (is_string($address)) return self::clean($address);
        if (!is_array($address)) return '';
        $part = fn(string $key) => is_scalar($address[$key] ?? null) ? self::clean((string)$address[$key]) : '';
        $city = trim($part('postalCode') . ' ' . $part('addressLocality'));
        return implode(', ', array_filter([$part('streetAddress'), $city, $part('addressRegion')]));
    }

    private static function openingHours(array $org): string
    {
        $hours = $org['openingHours'] ?? null;
        if (is_string($hours) || (is_array($hours) && array_is_list($hours))) return implode('; ', array_filter(array_map(fn($h) => is_string($h) ? self::clean($h) : '', (array)$hours)));
        $days = ['Monday' => 'lun', 'Tuesday' => 'mar', 'Wednesday' => 'mer', 'Thursday' => 'gio', 'Friday' => 'ven', 'Saturday' => 'sab', 'Sunday' => 'dom'];
        $lines = [];
        foreach ((array)($org['openingHoursSpecification'] ?? []) as $spec) {
            if (!is_array($spec) || !is_string($spec['opens'] ?? null) || !is_string($spec['closes'] ?? null)) continue;
            $names = array_map(fn($d) => $days[basename((string)$d)] ?? '', array_filter((array)($spec['dayOfWeek'] ?? []), 'is_string'));
            $lines[] = trim(implode(', ', array_filter($names)) . ' ' . substr($spec['opens'], 0, 5) . '-' . substr($spec['closes'], 0, 5));
        }
        return implode('; ', $lines);
    }

    /**
     * Telefoni, email, WhatsApp, indirizzo e partita IVA: prima dai link e dai dati schema.org, poi dal testo della pagina.
     * @return array{phones: list<string>, emails: list<string>, whatsapp: ?string, address: string, vat: string}
     */
    private static function contacts(DOMXPath $xpath, string $text, array $org): array
    {
        $phones = $org['telephone'] !== '' ? [$org['telephone']] : [];
        $emails = $org['email'] !== '' ? [strtolower($org['email'])] : [];
        $whatsapp = null;
        foreach ($xpath->query('//a[@href]/@href') as $node) {
            $href = trim((string)$node->nodeValue);
            if (preg_match('/^tel:(.+)/i', $href, $m)) $phones[] = self::clean(rawurldecode($m[1]));
            elseif (preg_match('/^mailto:([^?]+)/i', $href, $m)) $emails[] = strtolower(self::clean(rawurldecode($m[1])));
            elseif ($whatsapp === null && preg_match('#^(https?://)?(wa\.me/|api\.whatsapp\.com/send|web\.whatsapp\.com/send|whatsapp://send)#i', $href)) $whatsapp = mb_substr($href, 0, 300);
        }

        $vat = preg_match('/(?:P(?:artita)?\.?\s?I(?:VA|\.)?|VAT(?:\s+(?:number|n\.?))?)\s*[:.n°]*\s*(?:IT\s?)?(\d{11})\b/iu', $text, $m) ? $m[1] : '';
        $scan = $vat === '' ? $text : str_replace($vat, ' ', $text);
        preg_match_all('/(?<![\d+\/.,])(?:\+39[\s.]?)?(?:0\d{1,3}[\s.\/-]?\d{4,8}|3\d{2}[\s.]?\d{3}[\s.]?\d{3,4})(?![\d.,\/])/', $scan, $m);
        // Undici cifre senza prefisso internazionale sono quasi sempre una partita IVA o un codice, non un telefono.
        $phones = [...$phones, ...array_filter($m[0], fn($p) => str_starts_with($p, '+') || strlen(preg_replace('/\D/', '', $p)) !== 11)];
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/i', $text, $m);
        $emails = [...$emails, ...array_map('strtolower', $m[0])];

        $uniquePhones = [];
        foreach ($phones as $phone) {
            $digits = preg_replace('/\D/', '', preg_replace('/^\+?39(?=[03])/', '', $phone));
            if (strlen($digits) >= 6 && strlen($digits) <= 13) $uniquePhones[$digits] ??= trim($phone);
        }
        $emails = array_filter(array_unique($emails), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && !preg_match('/\.(png|jpe?g|webp|gif|svg)$|sentry|wixpress|example\.|domain\.|@email\.|@sito\./', $e));

        $address = $org['address'];
        if ($address === '' && preg_match('/\b(?:Via|Viale|Piazza|Piazzale|Corso|Largo|Strada|Contrada|Località|Vicolo|Borgo)\s+[^,;|]{2,50}?,?\s*\d{1,4}[a-zA-Z]?\b[^;|]{0,40}?\b\d{5}\b[^;|.]{0,40}/u', $text, $m)) {
            // Dopo città e provincia di solito segue altro testo del piè di pagina, separato da un trattino o da una barra.
            $address = preg_split('/\s+[-–|•·]\s+/u', $m[0])[0];
        }

        return [
            'phones' => array_slice(array_values($uniquePhones), 0, 3),
            'emails' => array_slice(array_values($emails), 0, 3),
            'whatsapp' => $whatsapp,
            'address' => mb_substr(trim($address, " ,-"), 0, 160),
            'vat' => $vat,
        ];
    }

    /**
     * Strumenti e funzioni del sito che contano per il marketing: piattaforma, misurazione, modi di contatto e acquisto,
     * più alcuni dati tecnici (telefono, velocità, immagini senza descrizione).
     * @return array<string, mixed>
     */
    private static function signals(string $html, DOMXPath $xpath, array $structured, float $seconds, array $contacts, string $links, string $text): array
    {
        $has = fn(string $pattern) => (bool)preg_match($pattern, $html);
        $first = function (array $map) use ($has): ?string {
            foreach ($map as $name => $pattern) if ($has($pattern)) return $name;
            return null;
        };
        $platform = $first(self::PLATFORMS);
        if ($platform === 'WordPress' && $has('#woocommerce#i')) $platform = 'WordPress con WooCommerce';

        $contactForm = $newsletter = false;
        foreach ($xpath->query('//form') as $form) {
            $email = $xpath->query('.//input[@type="email" or contains(@name,"mail")]', $form)->length > 0;
            if ($email && $xpath->query('.//textarea', $form)->length > 0) $contactForm = true;
            elseif ($email && $xpath->query('.//input[not(@type="hidden") and not(@type="submit") and not(@type="checkbox")]', $form)->length <= 2) $newsletter = true;
        }

        $hreflang = [];
        foreach ($xpath->query('//link[@hreflang]/@hreflang') as $node) $hreflang[strtolower(substr((string)$node->nodeValue, 0, 2))] = true;
        unset($hreflang['x-']);

        return [
            'platform' => $platform,
            'tools' => array_keys(array_filter(self::TRACKERS, $has)),
            'consent' => $first(self::CONSENT),
            'chat' => $first(self::CHATS),
            'features' => [
                'ecommerce' => in_array($platform, ['Shopify', 'WordPress con WooCommerce', 'PrestaShop', 'Magento', 'Salesforce Commerce'], true)
                    || (bool)preg_match('#add[-_]to[-_]cart|aggiungi al carrello|/cart\b|/carrello\b#', $links . ' ' . $text),
                'booking' => (bool)preg_match('/prenota|booking|thefork|quandoo|opentable|calendly|treatwell|appuntamento/', $links),
                'contact_form' => $contactForm,
                'newsletter' => $newsletter || $has('#list-manage\.com|klaviyo|sibforms|mailerlite#i'),
                'whatsapp' => $contacts['whatsapp'] !== null,
                'reviews' => $structured['organization']['rating'] !== null || $has('#trustpilot|trustindex|elfsight|google-reviews#i')
                    || (bool)preg_match('/recensioni|testimonianze|dicono di noi|testimonial|cosa dicono/u', $text),
                'video' => $has('#youtube(-nocookie)?\.com/embed|player\.vimeo\.com|<video\b#i'),
            ],
            'mobile' => $xpath->query('//meta[@name="viewport"]')->length > 0,
            'seconds' => $seconds,
            'page_kb' => (int)round(strlen($html) / 1024),
            'languages' => count($hreflang),
            'h1' => $xpath->query('//h1')->length,
            'images' => $xpath->query('//body//img')->length,
            'images_without_alt' => $xpath->query('//body//img[not(@alt) or normalize-space(@alt)=""]')->length,
            'structured_data' => $structured['types'] !== [] || $xpath->query('//*[@itemtype]')->length > 0,
            'schema_types' => array_slice($structured['types'], 0, 8),
            'open_graph' => $xpath->query('//meta[@property="og:image" or @property="og:title"]')->length > 0,
        ];
    }

    /**
     * Profili social collegati dal sito (il primo per ciascuna rete), esclusi i link di condivisione.
     * @param array<string, string> $networks chiave => espressione regolare del profilo
     * @return array<string, string>
     */
    private static function socialProfiles(array $hrefs, array $networks): array
    {
        $found = [];
        foreach ($hrefs as $href) {
            $href = trim((string)$href);
            if (str_starts_with($href, '//')) $href = 'https:' . $href;
            foreach ($networks as $key => $pattern) {
                if (!isset($found[$key]) && preg_match($pattern, $href, $match)) {
                    $found[$key] = rtrim(strtok($match[0], '?#'), '/');
                }
            }
        }
        return $found;
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

    /** @return array{bytes: string, converted: bool}|null */
    private static function download(string $url, int $minBytes, bool $allowSvg = false): ?array
    {
        try {
            $file = SafeHttp::get($url, 5_000_000, 8);
        } catch (SiteException) {
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($file['body']) ?: '';
        if ($allowSvg && ($mime === 'image/svg+xml' || str_contains(substr($file['body'], 0, 2000), '<svg'))) return self::rasterize($file['body']);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) || strlen($file['body']) < $minBytes) return null;
        return ['bytes' => $file['body'], 'converted' => false];
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

    /** @return list<string> fino a 4 font: prima quelli caricati da Google Fonts, poi i più usati nel CSS */
    private static function fonts(string $css, DOMXPath $xpath): array
    {
        $counts = [];
        foreach ($xpath->query('//link[contains(@href,"fonts.googleapis.com")]/@href') as $node) {
            preg_match_all('/family=([^&:]+)/', urldecode((string)$node->nodeValue), $families);
            foreach ($families[1] as $family) {
                foreach (explode('|', $family) as $name) $counts[trim(str_replace('+', ' ', $name))] = 1000;
            }
        }
        preg_match_all('/font-family\s*:\s*([^;}{]+)/i', $css, $matches);
        foreach ($matches[1] as $stack) {
            $name = trim(explode(',', $stack)[0], " \t\n\"'");
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        arsort($counts);
        $fonts = array_filter(array_map('strval', array_keys($counts)), fn($f) => preg_match('/^[\p{L}0-9 ]{2,60}$/u', $f) && !preg_match(self::NOT_BRAND_FONTS, $f));
        return array_slice(array_values($fonts), 0, 4);
    }

    private static function isNeutral(string $hex): bool
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return max($r, $g, $b) - min($r, $g, $b) < 24;
    }

    private static function absolute(string $base, string $href): ?string
    {
        $href = trim($href);
        // Virgolette o barre rovesciate: attributo scritto male (spesso JSON finito nell'HTML), non un indirizzo.
        if ($href === '' || preg_match('#^(mailto|tel|javascript|data):#i', $href) || preg_match('/["\\\\<>]/', $href)) return null;
        if (preg_match('#^https?://#i', $href)) return $href;
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($href, '//')) return $parts['scheme'] . ':' . $href;
        if (str_starts_with($href, '/')) return $origin . $href;
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        return $origin . $dir . $href;
    }
}
