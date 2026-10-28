<?php
declare(strict_types=1);

final class DashboardController
{
    private const CALENDAR_SELECT = "SELECT c.id, c.project_id, c.channel, c.content_type, c.title, c.status, c.scheduled_at,
            p.name AS project_name, b.id AS brand_id, b.name AS brand_name, b.primary_color AS brand_color
        FROM content_items c
        JOIN projects p ON p.id=c.project_id AND p.organization_id=c.organization_id
        LEFT JOIN brand_profiles b ON b.id=p.brand_profile_id AND b.organization_id=p.organization_id
        WHERE c.organization_id=? AND c.scheduled_at >= ? AND c.scheduled_at < ?
        ORDER BY c.scheduled_at";

    public static function dashboard(): never
    {
        $user = Auth::user();
        $org = $user['organization_id'];
        $pdo = Database::connection();
        $count = function (string $sql, array $params = []) use ($pdo, $org): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$org, ...$params]);
            return (int)$stmt->fetchColumn();
        };
        $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
        $monthEnd = (new DateTimeImmutable('first day of next month'))->format('Y-m-d 00:00:00');
        $weekStart = new DateTimeImmutable('monday this week');

        $stmt = $pdo->prepare(self::CALENDAR_SELECT . ' LIMIT 100');
        $stmt->execute([$org, $weekStart->format('Y-m-d 00:00:00'), $weekStart->modify('+7 days')->format('Y-m-d 00:00:00')]);

        Http::json(['data' => [
            'clients' => $count('SELECT COUNT(*) FROM brand_profiles WHERE organization_id=?'),
            'active_campaigns' => $count("SELECT COUNT(*) FROM projects WHERE organization_id=? AND status='active'"),
            'scheduled_this_month' => $count('SELECT COUNT(*) FROM content_items WHERE organization_id=? AND scheduled_at >= ? AND scheduled_at < ?', [$monthStart, $monthEnd]),
            'pending_approval' => $count("SELECT COUNT(*) FROM content_items WHERE organization_id=? AND status='in_review'"),
            'ai_runs_this_month' => $count("SELECT COUNT(*) FROM jobs WHERE organization_id=? AND status='done' AND created_at >= ?", [$monthStart]),
            'week_start' => $weekStart->format('Y-m-d'),
            'week' => $stmt->fetchAll(),
        ]]);
    }

    /** Contenuti programmati tra due date (al massimo 62 giorni). */
    public static function calendar(): never
    {
        $user = Auth::user();
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['from'] ?? ''));
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['to'] ?? ''));
        if (!$from || !$to || $to <= $from) Http::validationError('Indica le date from e to nel formato AAAA-MM-GG.');
        if ($from->diff($to)->days > 62) Http::validationError('L\'intervallo massimo è di 62 giorni.');
        $stmt = Database::connection()->prepare(self::CALENDAR_SELECT . ' LIMIT 500');
        $stmt->execute([$user['organization_id'], $from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')]);
        Http::json(['data' => $stmt->fetchAll()]);
    }
}
