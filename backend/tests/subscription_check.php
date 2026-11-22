<?php
declare(strict_types=1);

// Prova gratuita e abbonamento: accesso per stato, firma dei webhook di Stripe, aggiornamento da un abbonamento Stripe.
// Uso: docker compose exec app php tests/subscription_check.php
require_once __DIR__ . '/../src/bootstrap.php';

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $failures++;
};
$past = gmdate('Y-m-d H:i:s', time() - 3600);
$future = gmdate('Y-m-d H:i:s', time() + 86400 * 5);

$check('prova in corso dà accesso', Subscription::hasAccess(['plan' => 'trial', 'trial_ends_at' => $future]));
$check('prova scaduta blocca', !Subscription::hasAccess(['plan' => 'trial', 'trial_ends_at' => $past]));
$check('accesso regalato', Subscription::hasAccess(['plan' => 'free']));
$check('abbonamento attivo', Subscription::hasAccess(['plan' => 'monthly', 'subscription_status' => 'active', 'current_period_end' => $future]));
$check('abbonamento disdetto blocca', !Subscription::hasAccess(['plan' => 'monthly', 'subscription_status' => 'canceled', 'current_period_end' => $future]));
$check('pagamento in ritardo: margine di un giorno', Subscription::hasAccess(['plan' => 'yearly', 'subscription_status' => 'past_due', 'current_period_end' => $past]));
$check('periodo finito da 2 giorni blocca', !Subscription::hasAccess(['plan' => 'yearly', 'subscription_status' => 'active', 'current_period_end' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]));

$secret = 'whsec_test_' . bin2hex(random_bytes(8));
$payload = '{"id":"evt_1","type":"customer.subscription.updated"}';
$t = time();
$sig = hash_hmac('sha256', "{$t}.{$payload}", $secret);
$check('firma webhook valida', Stripe::verifyWebhook($payload, "t={$t},v1={$sig}", $secret));
$check('firma webhook su contenuto alterato', !Stripe::verifyWebhook($payload . ' ', "t={$t},v1={$sig}", $secret));
$check('firma webhook con altro segreto', !Stripe::verifyWebhook($payload, "t={$t},v1={$sig}", $secret . 'x'));
$old = $t - 1000;
$check('webhook troppo vecchio', !Stripe::verifyWebhook($payload, "t={$old},v1=" . hash_hmac('sha256', "{$old}.{$payload}", $secret), $secret));

// Organizzazione temporanea per l'aggiornamento dal webhook.
$pdo = Database::connection();
$pdo->prepare("INSERT INTO organizations (name, slug, plan, trial_ends_at) VALUES ('Verifica abbonamento', ?, 'trial', ?)")->execute(['verifica-abb-' . bin2hex(random_bytes(3)), $past]);
$orgId = (int)$pdo->lastInsertId();
try {
    $check('organizzazione di prova scaduta', !Subscription::hasAccess(Subscription::load($orgId)));
    $periodEnd = time() + 365 * 86400;
    Subscription::applyStripeSubscription($orgId, [
        'id' => 'sub_test', 'customer' => 'cus_test', 'status' => 'active', 'cancel_at_period_end' => false,
        'items' => ['data' => [['current_period_end' => $periodEnd, 'price' => ['recurring' => ['interval' => 'year']]]]],
    ]);
    $org = Subscription::load($orgId);
    $check('dopo il pagamento: piano annuale attivo', $org['plan'] === 'yearly' && Subscription::hasAccess($org));
    $check('fine periodo salvata', $org['current_period_end'] === gmdate('Y-m-d H:i:s', $periodEnd));
    $check('cliente Stripe salvato', $org['stripe_customer_id'] === 'cus_test');
    Subscription::applyStripeSubscription($orgId, ['id' => 'sub_test', 'customer' => 'cus_test', 'status' => 'canceled',
        'items' => ['data' => [['current_period_end' => $periodEnd, 'price' => ['recurring' => ['interval' => 'year']]]]]]);
    $check('abbonamento cancellato su Stripe blocca', !Subscription::hasAccess(Subscription::load($orgId)));
    $summary = Subscription::summary(Subscription::load($orgId));
    $check('riepilogo: stato scaduto', $summary['state'] === 'expired');
} finally {
    $pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$orgId]);
}

echo $failures ? "\n{$failures} verifiche fallite.\n" : "\nTutte le verifiche superate.\n";
exit($failures ? 1 : 0);
