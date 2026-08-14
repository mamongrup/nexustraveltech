<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function listing_duplicate_key(string $type, string $name, string $city, string $country): string
{
    $value = mb_strtolower(trim($type . '|' . $name . '|' . $city . '|' . $country), 'UTF-8');
    $value = strtr($value, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
    return hash('sha256', trim($value));
}

function record_audit_event(string $actorType, ?int $actorId, string $action, string $entityType, ?int $entityId, array $meta = []): void
{
    db()->prepare('INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,meta) VALUES (?,?,?,?,?,?::jsonb)')
        ->execute([$actorType, $actorId, $action, $entityType, $entityId, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}
