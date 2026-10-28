<?php
declare(strict_types=1);

final class UserController
{
    public static function index(): never
    {
        $user = Auth::user();
        $stmt = Database::connection()->prepare('SELECT id, name, email, role, created_at FROM users WHERE organization_id=? ORDER BY created_at');
        $stmt->execute([$user['organization_id']]);
        Http::json(['data' => $stmt->fetchAll()]);
    }

    /** Aggiunge un membro del team. Solo l'owner può nominare altri admin. */
    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $data = Http::body();
        $name = Http::requiredString($data, 'name', 120);
        $email = strtolower(Http::requiredString($data, 'email', 190));
        $password = (string)($data['password'] ?? '');
        $role = Http::oneOf($data, 'role', ['admin', 'editor', 'viewer'], 'editor');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::validationError('Email non valida.');
        AuthController::assertPassword($password);
        if ($role === 'admin' && $user['role'] !== 'owner') {
            Http::json(['error' => 'forbidden', 'message' => 'Solo il titolare può aggiungere amministratori.'], 403);
        }

        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (organization_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$user['organization_id'], $name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') Http::json(['error' => 'email_already_exists', 'message' => 'Questa email è già registrata.'], 409);
            throw $e;
        }
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'user.created', 'user', $id);
        Http::json(['data' => Tenant::findOrFail('users', $id, $user['organization_id'], 'id, name, email, role, created_at')], 201);
    }
}
