<?php
declare(strict_types=1);

// Promemoria di scadenza: testi, scelta delle organizzazioni, nessun doppio invio.
// Uso (con un server SMTP di prova, es. Mailpit): docker compose exec -e MAIL_HOST=mailpit -e MAIL_PORT=1025 -e MAIL_FROM=test@example.com app php tests/reminders_check.php
require_once __DIR__ . '/../src/bootstrap.php';

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $failures++;
};

[$subject, $lines] = SubscriptionReminders::message('trial_1d', strtotime('2026-03-05 10:00:00 UTC'));
$check('oggetto ultimo giorno', str_contains($subject, 'Ultimo giorno'));
$check('data in ora italiana', str_contains($lines[0], '5 marzo alle 11:00'));
$check('prezzi nel testo', str_contains($lines[1], '15 € al mese') && str_contains($lines[1], '120 € all\'anno'));

if (!Mailer::enabled()) {
    echo "\nMAIL_HOST non impostato: salto la prova di invio.\n";
    exit($failures ? 1 : 0);
}

$pdo = Database::connection();
$orgIds = [];
$makeOrg = function (string $sqlEnd, string $plan = 'trial', int $cancel = 0) use ($pdo, &$orgIds): int {
    $pdo->prepare("INSERT INTO organizations (name, slug, plan, trial_ends_at, subscription_status, current_period_end, cancel_at_period_end)
        VALUES ('Verifica promemoria', ?, ?, IF(?='trial', {$sqlEnd}, NULL), IF(?='trial', NULL, 'active'), IF(?='trial', NULL, {$sqlEnd}), ?)")
        ->execute(['verifica-prom-' . bin2hex(random_bytes(3)), $plan, $plan, $plan, $plan, $cancel]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (organization_id, name, email, password_hash, role) VALUES (?, 'Giulia Rossi', ?, 'x', 'owner')")
        ->execute([$id, "promemoria-{$id}@example.com"]);
    $orgIds[] = $id;
    return $id;
};
$kindsFor = function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT kind FROM subscription_reminders WHERE organization_id=?');
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};

try {
    $in2days = $makeOrg('UTC_TIMESTAMP() + INTERVAL 2 DAY');
    $in5hours = $makeOrg('UTC_TIMESTAMP() + INTERVAL 5 HOUR');
    $expired = $makeOrg('UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $farAway = $makeOrg('UTC_TIMESTAMP() + INTERVAL 8 DAY');
    $canceled = $makeOrg('UTC_TIMESTAMP() + INTERVAL 2 DAY', 'monthly', 1);
    $renewing = $makeOrg('UTC_TIMESTAMP() + INTERVAL 2 DAY', 'monthly', 0);

    $sent = SubscriptionReminders::run();
    $check('email inviate', $sent >= 4);
    $check('prova tra 2 giorni: avviso 3 giorni', $kindsFor($in2days) === ['trial_3d']);
    $check('prova tra 5 ore: ultimo giorno', $kindsFor($in5hours) === ['trial_1d']);
    $check('prova scaduta ieri: avviso scadenza', $kindsFor($expired) === ['trial_expired']);
    $check('prova tra 8 giorni: niente', $kindsFor($farAway) === []);
    $check('abbonamento disdetto: avviso', $kindsFor($canceled) === ['canceled_3d']);
    $check('abbonamento che si rinnova: niente', $kindsFor($renewing) === []);
    $check('secondo giro: nessun doppio invio', SubscriptionReminders::run() === 0);
} finally {
    foreach ($orgIds as $id) {
        $pdo->prepare('DELETE FROM users WHERE organization_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM organizations WHERE id=?')->execute([$id]);
    }
}

echo $failures ? "\n{$failures} verifiche fallite.\n" : "\nTutte le verifiche superate.\n";
exit($failures ? 1 : 0);
