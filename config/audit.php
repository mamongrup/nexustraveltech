<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Yönetim işlemini denetim kaydına yazar (best-effort; asla iş akışını bozmaz).
 */
function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?string $username = null): void
{
    try {
        db()->prepare('INSERT INTO admin_audit_logs(admin_username,action,entity_type,entity_id,details,ip) VALUES(?,?,?,?,?::jsonb,?)')
            ->execute([
                mb_substr((string) ($username ?? ($_SESSION['admin_username'] ?? 'admin')), 0, 190),
                mb_substr($action, 0, 80),
                $entityType !== null ? mb_substr($entityType, 0, 40) : null,
                $entityId,
                json_encode($details, JSON_UNESCAPED_UNICODE) ?: '{}',
                mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            ]);
    } catch (Throwable $e) {
        // Denetim kaydı best-effort'tur; yönetici işlemi engellenmemelidir.
    }
}
