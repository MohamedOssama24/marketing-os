<?php
declare(strict_types=1);

/**
 * Download di pagine esterne protetto da SSRF: solo http(s) su porte standard, solo IP pubblici
 * (ricontrollati a ogni redirect e fissati con CURLOPT_RESOLVE contro il DNS rebinding),
 * con limiti di tempo e di dimensione.
 */
final class SafeHttp
{
    private const MAX_REDIRECTS = 3;
    private const USER_AGENT = 'MarketingOS-SiteAnalyzer/1.0 (+https://marketing-os.local)';

    /** @return array{url: string, status: int, content_type: string, body: string} */
    public static function get(string $url, int $maxBytes = 2_000_000, int $timeoutSeconds = 10): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = self::resolvePublic($url);
            $body = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/css,image/*;q=0.8,*/*;q=0.5', 'Accept-Language: it,en;q=0.8'],
                CURLOPT_ENCODING => '',
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, $maxBytes): int {
                    $body .= $chunk;
                    return strlen($body) > $maxBytes ? 0 : strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error = curl_errno($ch);
            curl_close($ch);

            if ($error === CURLE_WRITE_ERROR) throw new SiteException('La pagina è troppo grande.');
            if ($ok === false) throw new SiteException('Impossibile raggiungere il sito. Controlla l\'indirizzo.');
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = $location;
                continue;
            }
            if ($status >= 400) throw new SiteException("Il sito ha risposto con un errore ({$status}).");
            return ['url' => $url, 'status' => $status, 'content_type' => $contentType, 'body' => $body];
        }
        throw new SiteException('Troppi reindirizzamenti.');
    }

    /** Normalizza l'indirizzo inserito dall'utente (aggiunge https:// se manca). */
    public static function normalizeUrl(string $input): string
    {
        $url = trim($input);
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !filter_var($url, FILTER_VALIDATE_URL)) throw new SiteException('Indirizzo del sito non valido.');
        return $url;
    }

    /** @return array{0: string, 1: int, 2: string} host, porta, IP pubblico */
    private static function resolvePublic(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') throw new SiteException('Indirizzo del sito non valido.');
        if (isset($parts['user']) || isset($parts['pass'])) throw new SiteException('Indirizzo del sito non valido.');
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) throw new SiteException('Sono ammesse solo le porte standard 80 e 443.');

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) throw new SiteException('Dominio non trovato.');
        foreach ($ips as $ip) {
            $carrierNat = (ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
            if ($carrierNat || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new SiteException('Questo indirizzo non è consentito.');
            }
        }
        return [$host, $port, $ips[0]];
    }
}

final class SiteException extends RuntimeException {}
