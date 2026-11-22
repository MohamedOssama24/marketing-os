<?php
declare(strict_types=1);

// Imposta una nuova password per un utente (es. se il titolare l'ha dimenticata). Si esegue solo dal server:
//   docker compose exec app php bin/set-password.php email@esempio.it
// La password si scrive nel terminale (non viene mostrata) e non passa mai dalla riga di comando.
require_once __DIR__ . '/../src/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/set-password.php email@esempio.it\n");
    exit(1);
}
$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT id, name FROM users WHERE email=?');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user) {
    fwrite(STDERR, "Nessun utente con email {$email}.\n");
    exit(1);
}

/** Legge una riga dal terminale senza mostrare quello che si scrive (se il terminale lo permette). */
function askHidden(string $prompt): string
{
    echo $prompt;
    $tty = stream_isatty(STDIN);
    if ($tty) shell_exec('stty -echo 2>/dev/null');
    $line = fgets(STDIN);
    if ($tty) shell_exec('stty echo 2>/dev/null');
    echo "\n";
    return rtrim((string)$line, "\r\n");
}

$password = askHidden("Nuova password per {$user['name']} <{$email}> (almeno 12 caratteri): ");
if (strlen($password) < 12) {
    fwrite(STDERR, "La password deve contenere almeno 12 caratteri. Nessuna modifica.\n");
    exit(1);
}
if (strlen($password) > 200) {
    fwrite(STDERR, "La password è troppo lunga. Nessuna modifica.\n");
    exit(1);
}
if (askHidden('Ripetila: ') !== $password) {
    fwrite(STDERR, "Le due password non coincidono. Nessuna modifica.\n");
    exit(1);
}

$pdo->beginTransaction();
$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
// Chi era collegato con la vecchia password deve rientrare.
$pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([$user['id']]);
// Sblocca il login se era fermo per troppi tentativi sbagliati (la tabella contiene solo contatori anonimi).
$pdo->exec('DELETE FROM auth_attempts');
$pdo->commit();

echo "Password aggiornata per {$email}. Ora puoi accedere con quella nuova.\n";
