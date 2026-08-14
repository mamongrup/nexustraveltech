<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function supplier_verification(int $supplierId): ?array
{
    $query = db()->prepare('SELECT * FROM supplier_verifications WHERE supplier_id = ? LIMIT 1');
    $query->execute([$supplierId]);
    return $query->fetch() ?: null;
}

function supplier_allowed_product_types(int $supplierId): array
{
    $verification = supplier_verification($supplierId);
    if (!$verification || $verification['review_status'] !== 'approved' || $verification['identity_status'] !== 'approved' || $verification['authority_status'] !== 'approved') {
        return [];
    }

    $types = json_decode((string) ($verification['approved_product_types'] ?? '[]'), true);
    return is_array($types) ? array_values(array_filter($types, 'is_string')) : [];
}

function supplier_can_add_product_type(int $supplierId, string $type): bool
{
    return in_array($type, supplier_allowed_product_types($supplierId), true);
}
