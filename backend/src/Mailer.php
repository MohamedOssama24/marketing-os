<?php
declare(strict_types=1);

/**
 * Invio di email tramite un server SMTP (Brevo, Gmail con password per app, Aruba, ecc.).
 * Configurazione in .env: MAIL_HOST, MAIL_PORT (587 = STARTTLS, 465 = SSL), MAIL_USERNAME, MAIL_PASSWORD,
 * MAIL_FROM, MAIL_FROM_NAME. Senza MAIL_HOST l'invio è spento e send() restituisce false.
 */
final class Mailer
{
    public static function enabled(): bool
    {
        return trim((string)getenv('MAIL_HOST')) !== '' && self::from() !== '';
    }

    private static function from(): string
    {
        return trim((string)(getenv('MAIL_FROM') ?: getenv('MAIL_USERNAME') ?: ''));
    }

    /** Invia un'email con versione HTML e testo semplice. Lancia RuntimeException se il server SMTP rifiuta. */
    public static function send(string $to, string $subject, string $html, string $text): bool
    {
        if (!self::enabled()) return false;
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Destinatario non valido.');

        $host = trim((string)getenv('MAIL_HOST'));
        $port = (int)(getenv('MAIL_PORT') ?: 587);
        $user = (string)(getenv('MAIL_USERNAME') ?: '');
        $pass = (string)(getenv('MAIL_PASSWORD') ?: '');
        $from = self::from();
        $fromName = (string)(getenv('MAIL_FROM_NAME') ?: 'Marketing OS');

        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . "{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) throw new RuntimeException("Connessione SMTP non riuscita: {$errstr}");
        stream_set_timeout($socket, 20);

        try {
            self::expect($socket, 220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $features = self::command($socket, $ehlo, 250);
            if ($port !== 465 && stripos($features, 'STARTTLS') !== false) {
                self::command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('STARTTLS non riuscito.');
                }
                self::command($socket, $ehlo, 250);
            }
            if ($user !== '') {
                self::command($socket, 'AUTH LOGIN', 334);
                self::command($socket, base64_encode($user), 334);
                self::command($socket, base64_encode($pass), 235, hide: true);
            }
            self::command($socket, "MAIL FROM:<{$from}>", 250);
            self::command($socket, "RCPT TO:<{$to}>", [250, 251]);
            self::command($socket, 'DATA', 354);

            $boundary = 'mos-' . bin2hex(random_bytes(12));
            $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: ' . self::header($fromName) . " <{$from}>",
                "To: <{$to}>",
                'Subject: ' . self::header($subject),
                'Message-ID: <' . bin2hex(random_bytes(16)) . "@{$domain}>",
                'MIME-Version: 1.0',
                "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            ];
            $body = implode("\r\n", $headers) . "\r\n\r\n"
                . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
                . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
                . "--{$boundary}--\r\n";
            // Con base64 nessuna riga inizia con un punto: il corpo si può inviare così com'è.
            self::command($socket, $body . '.', 250);
            fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
        return true;
    }

    private static function header(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    /** @param int|int[] $expected */
    private static function command($socket, string $line, int|array $expected, bool $hide = false): string
    {
        fwrite($socket, $line . "\r\n");
        return self::expect($socket, $expected, $hide ? '[nascosto]' : strtok($line, "\r\n"));
    }

    /** @param int|int[] $expected */
    private static function expect($socket, int|array $expected, string $sent = ''): string
    {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, (array)$expected, true)) {
            throw new RuntimeException('Il server SMTP ha risposto ' . trim($response ?: 'nulla') . ($sent !== '' ? " a «{$sent}»" : ''));
        }
        return $response;
    }
}
