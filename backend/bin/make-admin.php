<?php
declare(strict_types=1);

// Nomina (o revoca) un amministratore della piattaforma. Si esegue solo dal server, mai dall'app:
//   docker compose exec app php bin/make-admin.php email@esempio.it
//   docker compose exec app php bin/make-admin.php email@esempio.it --revoca
require_once __DIR__ . '/../src/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
$revoke = in_array('--revoca', $argv, true);
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/make-admin.php email@esempio.it [--revoca]\n");
    exit(1);
}
$pdo = Database::connection();
$stmt = $pdo->prepare('UPDATE users SET is_platform_admin=? WHERE email=?');
$stmt->execute([$revoke ? 0 : 1, $email]);
if ($stmt->rowCount() === 0) {
    $exists = $pdo->prepare('SELECT is_platform_admin FROM users WHERE email=?');
    $exists->execute([$email]);
    $current = $exists->fetchColumn();
    if ($current === false) {
        fwrite(STDERR, "Nessun utente con email {$email}.\n");
        exit(1);
    }
}
echo $revoke ? "{$email} non è più amministratore della piattaforma.\n" : "{$email} è ora amministratore della piattaforma. Esci e rientra nell'app per vedere il pannello Admin.\n";
