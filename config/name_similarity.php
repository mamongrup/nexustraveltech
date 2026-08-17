<?php

declare(strict_types=1);

/**
 * İsim benzerliği: dış kod ile oda tipi / fiyat planı adını karşılaştırır
 * (Türkçe normalizasyon + token/bigram + Levenshtein).
 *
 * Webhook otomatik önerisi (config/channel_webhook.php) ve dağıtım merkezinin
 * manuel eşleştirme formu (tedarikci/dagitim-merkezi.php) aynı mantığı kullanır —
 * tek kaynak bu fonksiyondur.
 *
 * @return float 0.0 – 1.0 arası benzerlik skoru
 */
function name_similarity(string $a, string $b): float
{
    $norm = fn(string $s): string => strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', strtr($s, ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'i', 'I' => 'i', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U'])));
    $at = preg_split('/\s+/', trim((string) $norm($a))) ?: [];
    $bt = preg_split('/\s+/', trim((string) $norm($b))) ?: [];
    $at = array_values(array_filter($at, fn($t) => $t !== ''));
    $bt = array_values(array_filter($bt, fn($t) => $t !== ''));
    if (!$at || !$bt) return 0.0;
    // Token eşleşmesi: ortak token / toplam (kısmi token eşleşmesi dahil).
    $tokScore = 0.0;
    foreach ($at as $ta) {
        $best = 0.0;
        foreach ($bt as $tb) {
            $la = strlen($ta);
            $lb = strlen($tb);
            if ($la === 0 || $lb === 0) continue;
            if ($ta === $tb) { $best = 1.0; break; }
            if (str_starts_with($ta, $tb) || str_starts_with($tb, $ta)) { $best = max($best, 0.8); continue; }
            // bigram Jaccard
            $big = function (string $t): array { $g = []; for ($i = 0; $i < strlen($t) - 1; $i++) $g[] = substr($t, $i, 2); return $g; };
            $ga = $big($ta); $gb = $big($tb);
            $inter = count(array_intersect($ga, $gb));
            $union = count(array_unique(array_merge($ga, $gb)));
            if ($union > 0) $best = max($best, $inter / $union);
        }
        $tokScore += $best;
    }
    $tokScore = $tokScore / max(1, count($at));
    // Karakter düzeyi benzerlik (tam kod/kelime uzunluğu küçükse anlamlı).
    $len = max(strlen((string) $norm($a)), strlen((string) $norm($b)));
    $lev = $len > 0 ? 1 - (levenshtein(trim((string) $norm($a)), trim((string) $norm($b))) / $len) : 0.0;
    return max($tokScore, $lev);
}
