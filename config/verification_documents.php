<?php

declare(strict_types=1);

function hotel_verification_documents(): array
{
    return [
        'tax_certificate' => 'Vergi levhası',
        'chamber_registration' => 'Oda kayıt belgesi',
        'manager_identity_front' => 'Yönetici kimliği · ön yüz',
        'manager_identity_back' => 'Yönetici kimliği · arka yüz',
        'signature_circular' => 'İmza sirküleri',
    ];
}

function verification_document_directory(int $supplierId): string
{
    return dirname(__DIR__) . '/private/verification-documents/' . $supplierId;
}
