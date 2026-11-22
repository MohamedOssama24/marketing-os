<?php
declare(strict_types=1);

/**
 * Accesso dell'agenzia a Marketing OS: prova gratuita dalla registrazione, poi abbonamento mensile o annuale.
 * Allo scadere della prova (o se l'abbonamento non è più pagato) l'app resta bloccata finché non ci si abbona.
 */
final class Subscription
{
    public const PLANS = ['monthly', 'yearly'];
    /** Stati di Stripe in cui l'abbonamento dà accesso (past_due: pagamento in ritardo, Stripe sta ancora riprovando). */
    private const PAID_STATUSES = ['active', 'trialing', 'past_due'];

    public static function trialDays(): int
    {
        return max(1, min(90, (int)(getenv('TRIAL_DAYS') ?: 10)));
    }

    /** Prezzo in centesimi di euro. */
    public static function priceCents(string $plan): int
    {
        return $plan === 'yearly'
            ? max(100, (int)(getenv('PRICE_YEARLY_CENTS') ?: 12000))
            : max(100, (int)(getenv('PRICE_MONTHLY_CENTS') ?: 1500));
    }

    /** Riga di organizations con le colonne dell'abbonamento. */
    public static function load(int $organizationId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, plan, trial_ends_at, subscription_status, current_period_end, cancel_at_period_end, stripe_customer_id, stripe_subscription_id FROM organizations WHERE id=?');
        $stmt->execute([$organizationId]);
        return $stmt->fetch() ?: [];
    }

    public static function hasAccess(array $org): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        return match ($org['plan'] ?? 'trial') {
            'free' => true,
            'trial' => !empty($org['trial_ends_at']) && $org['trial_ends_at'] > $now,
            // Un giorno di margine dopo la fine del periodo: il rinnovo di Stripe può arrivare con qualche ora di ritardo.
            default => in_array($org['subscription_status'] ?? '', self::PAID_STATUSES, true)
                && !empty($org['current_period_end'])
                && $org['current_period_end'] > gmdate('Y-m-d H:i:s', time() - 86400),
        };
    }

    /** Stato da mostrare nell'app. */
    public static function summary(array $org): array
    {
        $access = self::hasAccess($org);
        $plan = $org['plan'] ?? 'trial';
        $state = match (true) {
            $plan === 'free' => 'free',
            $plan === 'trial' => $access ? 'trial' : 'expired',
            default => $access ? 'active' : 'expired',
        };
        $daysLeft = null;
        if ($plan === 'trial' && !empty($org['trial_ends_at'])) {
            $daysLeft = max(0, (int)ceil((strtotime($org['trial_ends_at'] . ' UTC') - time()) / 86400));
        }
        return [
            'state' => $state,
            'plan' => $plan,
            'has_access' => $access,
            'trial_ends_at' => self::iso($org['trial_ends_at'] ?? null),
            'trial_days_left' => $daysLeft,
            'status' => $org['subscription_status'] ?? null,
            'current_period_end' => self::iso($org['current_period_end'] ?? null),
            'cancel_at_period_end' => (bool)($org['cancel_at_period_end'] ?? false),
            'can_manage_billing' => !empty($org['stripe_customer_id']),
            'prices' => ['monthly' => self::priceCents('monthly'), 'yearly' => self::priceCents('yearly'), 'currency' => 'eur'],
            'payments_enabled' => Stripe::enabled(),
        ];
    }

    /** Data UTC del database in formato ISO 8601 (il browser la mostra nel fuso dell'utente). */
    private static function iso(?string $utc): ?string
    {
        return $utc ? str_replace(' ', 'T', $utc) . 'Z' : null;
    }

    public static function blocked(array $org): never
    {
        Http::json([
            'error' => 'subscription_required',
            'message' => ($org['plan'] ?? 'trial') === 'trial'
                ? 'La prova gratuita è terminata. Abbonati per continuare a usare Marketing OS.'
                : 'L\'abbonamento non è attivo. Rinnovalo per continuare a usare Marketing OS.',
        ], 402);
    }

    /** Applica a un'organizzazione lo stato di un abbonamento letto da Stripe. */
    public static function applyStripeSubscription(int $organizationId, array $sub): void
    {
        $item = $sub['items']['data'][0] ?? [];
        $interval = $item['price']['recurring']['interval'] ?? ($item['plan']['interval'] ?? 'month');
        // Dalle versioni recenti dell'API la fine del periodo sta sulla voce dell'abbonamento, non più sull'abbonamento.
        $periodEnd = $item['current_period_end'] ?? ($sub['current_period_end'] ?? null);
        $status = (string)($sub['status'] ?? '');
        $stmt = Database::connection()->prepare('UPDATE organizations SET plan=IF(plan=\'free\', \'free\', ?), subscription_status=?, current_period_end=?, cancel_at_period_end=?,
            stripe_subscription_id=?, stripe_customer_id=COALESCE(?, stripe_customer_id) WHERE id=?');
        $stmt->execute([
            $interval === 'year' ? 'yearly' : 'monthly',
            $status,
            $periodEnd ? gmdate('Y-m-d H:i:s', (int)$periodEnd) : null,
            !empty($sub['cancel_at_period_end']) || !empty($sub['cancel_at']) ? 1 : 0,
            (string)$sub['id'],
            is_string($sub['customer'] ?? null) ? $sub['customer'] : null,
            $organizationId,
        ]);
    }
}
