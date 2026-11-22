<?php
declare(strict_types=1);

/**
 * Abbonamento dell'agenzia: stato, pagamento con Stripe Checkout, portale di Stripe per carta, fatture e disdetta.
 * Queste chiamate funzionano anche a prova scaduta: servono proprio per abbonarsi.
 */
final class SubscriptionController
{
    public static function show(): never
    {
        $user = Auth::user(allowExpired: true);
        Http::json(['data' => Subscription::summary(Subscription::load($user['organization_id']))]);
    }

    /** Apre il pagamento su Stripe e restituisce l'indirizzo a cui mandare il browser. */
    public static function checkout(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS, allowExpired: true);
        if (!Stripe::enabled()) self::paymentsDisabled();
        $plan = Http::oneOf(Http::body(), 'plan', Subscription::PLANS);
        $org = Subscription::load($user['organization_id']);
        if (in_array($org['plan'], Subscription::PLANS, true) && Subscription::hasAccess($org)) {
            Http::json(['error' => 'already_subscribed', 'message' => 'L\'abbonamento è già attivo: per cambiarlo usa «Gestisci abbonamento».'], 409);
        }

        $priceId = (string)getenv($plan === 'yearly' ? 'STRIPE_PRICE_YEARLY' : 'STRIPE_PRICE_MONTHLY');
        $lineItem = $priceId !== '' ? ['price' => $priceId, 'quantity' => 1] : [
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => Subscription::priceCents($plan),
                'recurring' => ['interval' => $plan === 'yearly' ? 'year' : 'month'],
                'product_data' => ['name' => $plan === 'yearly' ? 'Marketing OS – abbonamento annuale' : 'Marketing OS – abbonamento mensile'],
            ],
        ];
        $params = [
            'mode' => 'subscription',
            'line_items' => [$lineItem],
            'client_reference_id' => (string)$user['organization_id'],
            'subscription_data' => ['metadata' => ['organization_id' => (string)$user['organization_id']]],
            'metadata' => ['organization_id' => (string)$user['organization_id']],
            'success_url' => self::frontendUrl() . '/subscription?esito=ok&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => self::frontendUrl() . '/subscription?esito=annullato',
            'locale' => 'it',
            'allow_promotion_codes' => 'true',
            'billing_address_collection' => 'required',
            'tax_id_collection' => ['enabled' => 'true'],
        ];
        if (!empty($org['stripe_customer_id'])) {
            $params['customer'] = $org['stripe_customer_id'];
            $params['customer_update'] = ['address' => 'auto', 'name' => 'auto'];
        } else {
            $params['customer_email'] = $user['email'];
        }
        try {
            $session = Stripe::post('checkout/sessions', $params);
        } catch (StripeException $e) {
            Http::json(['error' => 'payment_error', 'message' => $e->getMessage()], 502);
        }
        Audit::log($user, 'subscription.checkout_started', 'organization', $user['organization_id']);
        Http::json(['data' => ['url' => $session['url']]]);
    }

    /**
     * Al ritorno da Stripe il browser conferma il pagamento: così l'accesso si sblocca subito,
     * anche se il webhook arriva in ritardo (o non è configurato, come in locale).
     */
    public static function confirm(): never
    {
        $user = Auth::user(allowExpired: true);
        if (!Stripe::enabled()) self::paymentsDisabled();
        $sessionId = Http::requiredString(Http::body(), 'session_id', 255);
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) Http::validationError('Pagamento non riconosciuto.');
        try {
            $session = Stripe::get('checkout/sessions/' . $sessionId, ['expand' => ['subscription']]);
        } catch (StripeException $e) {
            Http::json(['error' => 'payment_error', 'message' => $e->getMessage()], 502);
        }
        // Una sessione di pagamento di un'altra organizzazione non sblocca nulla.
        if ((string)($session['client_reference_id'] ?? '') !== (string)$user['organization_id']) {
            Http::json(['error' => 'not_found', 'message' => 'Pagamento non trovato.'], 404);
        }
        if (is_array($session['subscription'] ?? null)) {
            Subscription::applyStripeSubscription($user['organization_id'], ['customer' => $session['customer'] ?? null] + $session['subscription']);
        }
        Http::json(['data' => Subscription::summary(Subscription::load($user['organization_id']))]);
    }

    /** Portale di Stripe: cambio carta, fatture, passaggio mensile/annuale, disdetta. */
    public static function portal(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS, allowExpired: true);
        if (!Stripe::enabled()) self::paymentsDisabled();
        $org = Subscription::load($user['organization_id']);
        if (empty($org['stripe_customer_id'])) {
            Http::json(['error' => 'no_subscription', 'message' => 'Non c\'è ancora un abbonamento da gestire.'], 409);
        }
        try {
            $session = Stripe::post('billing_portal/sessions', [
                'customer' => $org['stripe_customer_id'],
                'return_url' => self::frontendUrl() . '/subscription',
                'locale' => 'it',
            ]);
        } catch (StripeException $e) {
            Http::json(['error' => 'payment_error', 'message' => $e->getMessage()], 502);
        }
        Http::json(['data' => ['url' => $session['url']]]);
    }

    /** Notifiche di Stripe (pagamenti, rinnovi, disdette). Pubblico: l'autenticità è data dalla firma. */
    public static function webhook(): never
    {
        $secret = (string)getenv('STRIPE_WEBHOOK_SECRET');
        $payload = (string)file_get_contents('php://input');
        if ($secret === '' || !Stripe::verifyWebhook($payload, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret)) {
            Http::json(['error' => 'invalid_signature'], 400);
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id'])) Http::json(['error' => 'invalid_payload'], 400);

        $pdo = Database::connection();
        $seen = $pdo->prepare('SELECT 1 FROM stripe_events WHERE id=?');
        $seen->execute([$event['id']]);
        if ($seen->fetch()) Http::json(['received' => true, 'duplicate' => true]);

        $object = $event['data']['object'] ?? [];
        switch ($event['type'] ?? '') {
            case 'checkout.session.completed':
                $orgId = (int)($object['client_reference_id'] ?? 0);
                if ($orgId && !empty($object['subscription'])) {
                    $sub = Stripe::get('subscriptions/' . $object['subscription']);
                    Subscription::applyStripeSubscription($orgId, $sub);
                }
                break;
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $orgId = self::organizationFor($object);
                if ($orgId) Subscription::applyStripeSubscription($orgId, $object);
                break;
        }
        $pdo->prepare('INSERT IGNORE INTO stripe_events (id, type) VALUES (?, ?)')->execute([$event['id'], (string)($event['type'] ?? '')]);
        Http::json(['received' => true]);
    }

    private static function organizationFor(array $sub): ?int
    {
        $fromMetadata = (int)($sub['metadata']['organization_id'] ?? 0);
        $stmt = Database::connection()->prepare('SELECT id FROM organizations WHERE id=? OR stripe_subscription_id=? OR (stripe_customer_id IS NOT NULL AND stripe_customer_id=?) ORDER BY id=? DESC LIMIT 1');
        $stmt->execute([$fromMetadata, (string)($sub['id'] ?? ''), is_string($sub['customer'] ?? null) ? $sub['customer'] : '', $fromMetadata]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private static function frontendUrl(): string
    {
        return rtrim(getenv('FRONTEND_URL') ?: (getenv('CORS_ORIGIN') ?: 'http://localhost:4200'), '/');
    }

    private static function paymentsDisabled(): never
    {
        Http::json(['error' => 'payments_disabled', 'message' => 'I pagamenti non sono ancora attivi. Contatta l\'assistenza.'], 503);
    }
}
