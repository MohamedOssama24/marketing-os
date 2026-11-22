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
    /** Errori di rete passeggeri (sito lento, connessione caduta): vale la pena riprovare una volta. */
    private const TRANSIENT = [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_RECV_ERROR, CURLE_SEND_ERROR, CURLE_GOT_NOTHING];

    /**
     * Scarica una pagina seguendo i reindirizzamenti. Se il sito è lento o la connessione cade, riprova una volta con più tempo.
     * @return array{url: string, status: int, content_type: string, body: string, seconds: float}
     */
    public static function get(string $url, int $maxBytes = 2_000_000, int $timeoutSeconds = 10, bool $retry = true): array
    {
        try {
            return self::follow($url, $maxBytes, $timeoutSeconds);
        } catch (TransientSiteException $e) {
            if (!$retry) throw $e;
            return self::follow($url, $maxBytes, $timeoutSeconds * 2);
        }
    }

    /**
     * Scarica più pagine insieme, con gli stessi controlli di get(): il tempo totale è quello della più lenta.
     * Le pagine che non si possono scaricare valgono null.
     * @param list<string> $urls
     * @return array<string, array{url: string, status: int, content_type: string, body: string, seconds: float}|null>
     */
    public static function getMany(array $urls, int $maxBytes = 1_500_000, int $timeoutSeconds = 6, int $hops = 2): array
    {
        $redirects = [];
        $results = [];
        $pending = [];
        $multi = curl_multi_init();
        foreach (array_unique($urls) as $url) {
            try {
                $pending[$url] = self::handle($url, $maxBytes, $timeoutSeconds);
                curl_multi_add_handle($multi, $pending[$url][0]);
            } catch (SiteException) {
                $results[$url] = null;
            }
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 1.0);
        } while ($running && $status === CURLM_OK);

        // curl_multi non restituisce l'errore di ogni handle con curl_errno: va letto dai messaggi.
        $errors = [];
        while ($info = curl_multi_info_read($multi)) {
            foreach ($pending as $url => [$ch]) if ($ch === $info['handle']) $errors[$url] = $info['result'];
        }
        foreach ($pending as $url => [$ch, $buffer]) {
            curl_multi_remove_handle($multi, $ch);
            try {
                $response = self::result($ch, $url, $buffer, $errors[$url] ?? CURLE_OK);
                if (isset($response['location'])) $redirects[$url] = $response['location'];
                $results[$url] = isset($response['location']) ? null : $response;
            } catch (SiteException) {
                $results[$url] = null;
            }
        }
        curl_multi_close($multi);
        // Anche i reindirizzamenti (es. /chi-siamo → /chi-siamo/) si seguono tutti insieme, ricontrollando ogni indirizzo.
        if ($redirects && $hops > 0) {
            $followed = self::getMany(array_values($redirects), $maxBytes, $timeoutSeconds, $hops - 1);
            foreach ($redirects as $url => $location) $results[$url] = $followed[$location] ?? null;
        }
        return $results;
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

    /** @return array{url: string, status: int, content_type: string, body: string, seconds: float} */
    private static function follow(string $url, int $maxBytes, int $timeoutSeconds): array
    {
        $seconds = 0.0;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$ch, $buffer] = self::handle($url, $maxBytes, $timeoutSeconds);
            curl_exec($ch);
            $response = self::result($ch, $url, $buffer, curl_errno($ch));
            $seconds += $response['seconds'];
            if (!isset($response['location'])) return ['seconds' => round($seconds, 2)] + $response;
            $url = $response['location'];
        }
        throw new SiteException('Troppi reindirizzamenti.');
    }

    /** @return array{0: CurlHandle, 1: stdClass} la richiesta pronta e il contenitore in cui arriva la pagina */
    private static function handle(string $url, int $maxBytes, int $timeoutSeconds): array
    {
        [$host, $port, $ip] = self::resolvePublic($url);
        $buffer = new stdClass();
        $buffer->body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/css,image/*;q=0.8,*/*;q=0.5', 'Accept-Language: it,en;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use ($buffer, $maxBytes): int {
                $buffer->body .= $chunk;
                return strlen($buffer->body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        return [$ch, $buffer];
    }

    /** @return array{url: string, status: int, content_type: string, body: string, seconds: float, location?: string} */
    private static function result(CurlHandle $ch, string $url, stdClass $buffer, int $error): array
    {
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $seconds = round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        curl_close($ch);

        if ($error === CURLE_WRITE_ERROR) throw new SiteException('La pagina è troppo grande.');
        if ($error === CURLE_OPERATION_TIMEDOUT) throw new TransientSiteException('Il sito non ha risposto in tempo: è molto lento o in questo momento non funziona. Riprova tra poco.');
        // 35 connessione TLS fallita, 51/60 certificato non verificabile, 77 certificati di sistema illeggibili.
        if (in_array($error, [35, 51, 60, 77], true)) {
            throw new SiteException('Il certificato HTTPS del sito non è valido: prova a scrivere l\'indirizzo con http://.');
        }
        if (in_array($error, self::TRANSIENT, true)) throw new TransientSiteException('Impossibile raggiungere il sito: la connessione è stata rifiutata o interrotta. Riprova tra poco.');
        if ($error !== CURLE_OK) throw new SiteException('Impossibile raggiungere il sito. Controlla l\'indirizzo.');
        if ($status >= 300 && $status < 400 && $location !== '') {
            return ['url' => $url, 'status' => $status, 'content_type' => $contentType, 'body' => '', 'seconds' => $seconds, 'location' => $location];
        }
        if (in_array($status, [401, 403, 429], true)) throw new SiteException("Il sito blocca la lettura automatica (errore {$status}).");
        if ($status >= 500) throw new TransientSiteException("Il sito ha un problema temporaneo (errore {$status}). Riprova tra poco.");
        if ($status >= 400) throw new SiteException("Il sito ha risposto con un errore ({$status}).");
        return ['url' => $url, 'status' => $status, 'content_type' => $contentType, 'body' => $buffer->body, 'seconds' => $seconds];
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
        if (!in_array($port, [80, 443], true)) throw new BlockedAddressException('Sono ammesse solo le porte standard 80 e 443.');

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips && !str_contains($host, '.')) throw new SiteException('Dominio non trovato: manca l\'estensione del sito (ad esempio .it o .com).');
        if (!$ips) throw new SiteException('Dominio non trovato.');
        foreach ($ips as $ip) {
            $carrierNat = (ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
            if ($carrierNat || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new BlockedAddressException('Questo indirizzo non è consentito.');
            }
        }
        return [$host, $port, $ips[0]];
    }
}

class SiteException extends RuntimeException {}

/** Sito lento o connessione caduta: può andare bene al tentativo successivo. */
final class TransientSiteException extends SiteException {}

/** Indirizzo rifiutato per sicurezza (rete interna, porta non standard): non va mai ritentato in altre forme. */
final class BlockedAddressException extends SiteException {}
