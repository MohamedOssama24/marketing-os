<?php
declare(strict_types=1);

/**
 * Email ai titolari e agli amministratori dell'agenzia quando la prova o l'abbonamento stanno per finire.
 * Il worker la chiama ogni ora; ogni promemoria parte una sola volta per data di scadenza.
 */
final class SubscriptionReminders
{
    /** Invia i promemoria dovuti. Restituisce quante email sono partite. */
    public static function run(): int
    {
        if (!Mailer::enabled()) return 0;
        $pdo = Database::connection();
        // tipo => [condizione SQL, colonna con la data di scadenza]
        $kinds = [
            'trial_3d' => ["plan='trial' AND trial_ends_at > UTC_TIMESTAMP() + INTERVAL 1 DAY AND trial_ends_at <= UTC_TIMESTAMP() + INTERVAL 3 DAY", 'trial_ends_at'],
            'trial_1d' => ["plan='trial' AND trial_ends_at > UTC_TIMESTAMP() AND trial_ends_at <= UTC_TIMESTAMP() + INTERVAL 1 DAY", 'trial_ends_at'],
            'trial_expired' => ["plan='trial' AND trial_ends_at <= UTC_TIMESTAMP() AND trial_ends_at > UTC_TIMESTAMP() - INTERVAL 7 DAY", 'trial_ends_at'],
            'canceled_3d' => ["plan IN ('monthly','yearly') AND cancel_at_period_end=1 AND current_period_end > UTC_TIMESTAMP() AND current_period_end <= UTC_TIMESTAMP() + INTERVAL 3 DAY", 'current_period_end'],
        ];
        $sent = 0;
        foreach ($kinds as $kind => [$where, $column]) {
            $rows = $pdo->query("SELECT o.id, o.name, o.{$column} AS due_at FROM organizations o
                WHERE o.status='active' AND {$where}
                AND NOT EXISTS (SELECT 1 FROM subscription_reminders r WHERE r.organization_id=o.id AND r.kind='{$kind}' AND r.due_at=o.{$column})")->fetchAll();
            foreach ($rows as $org) {
                try {
                    $sent += self::notify($org, $kind);
                    $pdo->prepare('INSERT IGNORE INTO subscription_reminders (organization_id, kind, due_at) VALUES (?, ?, ?)')
                        ->execute([$org['id'], $kind, $org['due_at']]);
                } catch (Throwable $e) {
                    // Si riprova al giro successivo (tra un'ora).
                    error_log("Promemoria {$kind} per l'organizzazione {$org['id']} non inviato: " . $e->getMessage());
                }
            }
        }
        return $sent;
    }

    private static function notify(array $org, string $kind): int
    {
        $stmt = Database::connection()->prepare("SELECT name, email FROM users WHERE organization_id=? AND role IN ('owner','admin')");
        $stmt->execute([$org['id']]);
        $recipients = $stmt->fetchAll();
        [$subject, $lines] = self::message($kind, strtotime($org['due_at'] . ' UTC'));
        $link = rtrim(getenv('FRONTEND_URL') ?: (getenv('CORS_ORIGIN') ?: 'http://localhost:4200'), '/') . '/subscription';
        $count = 0;
        foreach ($recipients as $user) {
            $greeting = 'Ciao ' . trim(explode(' ', (string)$user['name'])[0]) . ',';
            Mailer::send((string)$user['email'], $subject, self::html($greeting, $lines, $link), self::text($greeting, $lines, $link));
            $count++;
        }
        return $count;
    }

    /** @return array{0: string, 1: string[]} oggetto e paragrafi */
    public static function message(string $kind, int $dueAt): array
    {
        $date = self::date($dueAt);
        $prices = sprintf('%s € al mese o %s € all\'anno', self::euro(Subscription::priceCents('monthly')), self::euro(Subscription::priceCents('yearly')));
        return match ($kind) {
            'trial_3d' => ['La tua prova di Marketing OS finisce tra pochi giorni', [
                "La prova gratuita di Marketing OS termina il {$date}.",
                "Per continuare a usare l'app senza interruzioni, abbonati ora: {$prices}. Clienti, campagne e contenuti restano tutti al loro posto.",
            ]],
            'trial_1d' => ['Ultimo giorno di prova su Marketing OS', [
                "La prova gratuita di Marketing OS termina il {$date}: dopo quel momento l'app si blocca finché non ti abboni.",
                "Bastano due minuti: {$prices}. I tuoi dati restano tutti al loro posto.",
            ]],
            'trial_expired' => ['La tua prova di Marketing OS è terminata', [
                "La prova gratuita di Marketing OS è terminata il {$date}.",
                "I tuoi clienti, le campagne e i contenuti sono salvati: abbonati ({$prices}) e ritrovi tutto esattamente com'era.",
            ]],
            'canceled_3d' => ['Il tuo abbonamento a Marketing OS sta per scadere', [
                "Hai disdetto l'abbonamento a Marketing OS: l'accesso termina il {$date}.",
                "Se vuoi continuare, puoi riattivarlo dalla pagina Abbonamento prima della scadenza.",
            ]],
            default => throw new InvalidArgumentException("Promemoria sconosciuto: {$kind}"),
        };
    }

    private static function date(int $timestamp): string
    {
        $months = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        $local = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(getenv('APP_TIMEZONE') ?: 'Europe/Rome'));
        return $local->format('j') . ' ' . $months[(int)$local->format('n') - 1] . ' alle ' . $local->format('H:i');
    }

    private static function euro(int $cents): string
    {
        return $cents % 100 === 0 ? (string)intdiv($cents, 100) : number_format($cents / 100, 2, ',', '.');
    }

    private static function text(string $greeting, array $lines, string $link): string
    {
        return $greeting . "\n\n" . implode("\n\n", $lines) . "\n\nAbbonati qui: {$link}\n\nIl team di Marketing OS\n";
    }

    private static function html(string $greeting, array $lines, string $link): string
    {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $paragraphs = implode('', array_map(fn($l) => '<p style="margin:0 0 14px">' . $e($l) . '</p>', $lines));
        return '<!doctype html><html lang="it"><body style="margin:0;background:#f3f4f8;font-family:Arial,Helvetica,sans-serif;color:#17182a">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;padding:28px">'
            . '<tr><td style="font-size:20px;font-weight:bold;padding-bottom:18px">Marketing <span style="color:#5b4bdb">OS</span></td></tr>'
            . '<tr><td style="font-size:15px;line-height:1.55"><p style="margin:0 0 14px">' . $e($greeting) . '</p>' . $paragraphs
            . '<p style="margin:22px 0"><a href="' . $e($link) . '" style="background:#5b4bdb;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:bold;display:inline-block">Vai all\'abbonamento</a></p>'
            . '<p style="margin:0;color:#6b6f85;font-size:13px">Il team di Marketing OS</p></td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
