<?php
declare(strict_types=1);

/**
 * Ziyaretçi soru konusu sınıflandırıcısı — anahtar kelime tabanlı (AI'sız).
 * Türkçe karakterler normalleştirilir ("tedarikçi" ile "tedarikci" aynı sayılır).
 *
 * SQL tarafında da kullanılabilmesi için normalizasyon kuralı şudur:
 *   translate(lower(btrim(metin)), 'çğıiöşüİ', 'cgiiosui')
 * PHP'de chat_topic_normalize() aynı sonucu üretir.
 */

function chat_topic_normalize(string $s): string
{
    return strtr(mb_strtolower($s, 'UTF-8'), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);
}

function chat_topic_defs(): array
{
    return [
        'Tedarikçi' => ['tedarikci', 'otel', 'tesis', 'villa', 'yat', 'restoran', 'supply', 'pansiyon', 'tur operatörü', 'turoperator'],
        'Acente' => ['acente', 'agency', 'komisyon', 'bayi'],
        'API' => ['api', 'xml', 'entegrasyon', 'baglanti', 'webservice'],
        'Rezervasyon' => ['rezervasyon', 'misafir', 'gezgin', 'kiralama', 'depozito', 'check-in', 'checkin', 'fatura', 'iade'],
        'Fiyat' => ['fiyat', 'ucret', 'ne kadar', 'maliyet', 'odeme'],
        'Kayıt/Giriş' => ['kayit', 'giris', 'login', 'uye', 'sifre'],
        'İletişim' => ['iletisim', 'ulas', 'telefon', 'email', 'mail', 'destek'],
    ];
}

/** Mesajın eşleştiği konu anahtarlarını döndürür (bir mesaj birden fazla konuya girebilir). */
function chat_classify(string $message): array
{
    $nm = chat_topic_normalize(trim($message));
    $matched = [];
    foreach (chat_topic_defs() as $topic => $kws) {
        foreach ($kws as $kw) {
            if (str_contains($nm, chat_topic_normalize($kw))) {
                $matched[] = $topic;
                break;
            }
        }
    }
    return $matched;
}
