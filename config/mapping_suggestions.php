<?php

declare(strict_types=1);

// config/mapping_suggestions.php — onay bekleyen eşleştirme önerilerinin hover detayını
// üreten ortak yardımcı (tedarikçi ana sayfası + dağıtım merkezi rozetleri).
// Tablo/kolon yoksa veya hata olursa boş dizi döner — sayfa asla çökmez
// (verify-platform / dağıtım merkezi şema korumasıyla aynı güvenlik deseni).

/**
 * Tedarikçinin TÜM kanallarındaki onay bekleyen oda + fiyat planı önerilerini döndürür.
 *
 * @return array{rooms: array, plans: array, roomCount: int, planCount: int}
 */
function pending_mapping_suggestions(PDO $pdo, int $supplierId, int $maxPerType = 5): array
{
    $out = ['rooms' => [], 'plans' => [], 'roomCount' => 0, 'planCount' => 0];
    try {
        $rm = $pdo->prepare(
            "SELECT m.external_room_id, m.suggestion_score, m.suggested_at, rt.name room_name, c.display_name channel
             FROM channel_room_mappings m
             JOIN channel_connections c ON c.id = m.channel_connection_id
             LEFT JOIN room_types rt ON rt.id = m.room_type_id
             WHERE c.supplier_id = ? AND m.status = 'suggested'
             ORDER BY m.suggested_at DESC, m.id DESC LIMIT ?"
        );
        $rm->execute([$supplierId, $maxPerType]);
        $out['rooms'] = $rm->fetchAll();
        $rc = $pdo->prepare(
            "SELECT COUNT(*) FROM channel_room_mappings m
             JOIN channel_connections c ON c.id = m.channel_connection_id
             WHERE c.supplier_id = ? AND m.status = 'suggested'"
        );
        $rc->execute([$supplierId]);
        $out['roomCount'] = (int) $rc->fetchColumn();

        $pm = $pdo->prepare(
            "SELECT p.external_rate_plan_id, p.suggestion_score, p.suggested_at, rp.name plan_name, c.display_name channel
             FROM channel_rate_plan_mappings p
             JOIN channel_connections c ON c.id = p.channel_connection_id
             LEFT JOIN rate_plans rp ON rp.id = p.rate_plan_id
             WHERE c.supplier_id = ? AND p.status = 'suggested'
             ORDER BY p.suggested_at DESC, p.id DESC LIMIT ?"
        );
        $pm->execute([$supplierId, $maxPerType]);
        $out['plans'] = $pm->fetchAll();
        $pc = $pdo->prepare(
            "SELECT COUNT(*) FROM channel_rate_plan_mappings p
             JOIN channel_connections c ON c.id = p.channel_connection_id
             WHERE c.supplier_id = ? AND p.status = 'suggested'"
        );
        $pc->execute([$supplierId]);
        $out['planCount'] = (int) $pc->fetchColumn();
    } catch (Throwable $e) {
        $out = ['rooms' => [], 'plans' => [], 'roomCount' => 0, 'planCount' => 0];
    }
    return $out;
}

/**
 * Admin ana sayfası — TÜM tedarikçilerin onay bekleyen öneri toplamı + tedarikçi bazlı kırılım.
 * Tablo/kolon yoksa veya hata olursa boş sonuç (sayfa çökmez).
 *
 * @return array{roomCount: int, planCount: int, total: int, suppliers: array}
 */
function admin_pending_mapping_suggestions(PDO $pdo, int $maxSuppliers = 8): array
{
    $out = ['roomCount' => 0, 'planCount' => 0, 'total' => 0, 'suppliers' => []];
    try {
        $out['roomCount'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM channel_room_mappings m
             JOIN channel_connections c ON c.id = m.channel_connection_id
             WHERE m.status = 'suggested'"
        )->fetchColumn();
        $out['planCount'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM channel_rate_plan_mappings p
             JOIN channel_connections c ON c.id = p.channel_connection_id
             WHERE p.status = 'suggested'"
        )->fetchColumn();
        $out['total'] = $out['roomCount'] + $out['planCount'];
        if ($out['total'] > 0) {
            $rm = $pdo->query(
                "SELECT s.id, s.company_name, COUNT(*) cnt FROM channel_room_mappings m
                 JOIN channel_connections c ON c.id = m.channel_connection_id
                 JOIN suppliers s ON s.id = c.supplier_id
                 WHERE m.status = 'suggested'
                 GROUP BY s.id, s.company_name ORDER BY cnt DESC LIMIT " . $maxSuppliers
            )->fetchAll();
            $pm = $pdo->query(
                "SELECT s.id, s.company_name, COUNT(*) cnt FROM channel_rate_plan_mappings m
                 JOIN channel_connections c ON c.id = m.channel_connection_id
                 JOIN suppliers s ON s.id = c.supplier_id
                 WHERE m.status = 'suggested'
                 GROUP BY s.id, s.company_name ORDER BY cnt DESC LIMIT " . $maxSuppliers
            )->fetchAll();
            $byId = [];
            foreach ($rm as $r) {
                $byId[(int) $r['id']] = ['company_name' => (string) $r['company_name'], 'room' => (int) $r['cnt'], 'plan' => 0];
            }
            foreach ($pm as $p) {
                $id = (int) $p['id'];
                if (!isset($byId[$id])) {
                    $byId[$id] = ['company_name' => (string) $p['company_name'], 'room' => 0, 'plan' => 0];
                }
                $byId[$id]['plan'] = (int) $p['cnt'];
            }
            foreach ($byId as $id => $row) {
                $byId[$id]['total'] = $row['room'] + $row['plan'];
            }
            uasort($byId, fn($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));
            $out['suppliers'] = array_values($byId);
        }
    } catch (Throwable $e) {
        $out = ['roomCount' => 0, 'planCount' => 0, 'total' => 0, 'suppliers' => []];
    }
    return $out;
}

/**
 * Admin hover mini listesinin iç HTML'ini döndürür — tedarikçi bazında kırılım.
 */
function admin_mapping_suggestions_hover_html(array $d): string
{
    $rows = '';
    if (!empty($d['suppliers'])) {
        foreach ($d['suppliers'] as $s) {
            $rows .= '<div class="hover-list-row">' . htmlspecialchars((string) ($s['company_name'] ?? '—'))
                . ' <b>' . (int) ($s['total'] ?? 0) . '</b>'
                . ' <small>(oda ' . (int) ($s['room'] ?? 0) . ' · plan ' . (int) ($s['plan'] ?? 0) . ')</small>'
                . '</div>';
        }
    }
    if ($rows === '') {
        return '<div class="hover-list-row" style="color:#9fb3ad">Bekleyen öneri yok.</div>';
    }
    return '<div class="hover-list-title">Tedarikçi bazında (' . count($d['suppliers']) . ')</div>' . $rows;
}

/**
 * Hover mini listesinin iç HTML'ini döndürür — bekleyen oda ve plan önerileri ayrı bölümlerde.
 * Boşsa "Bekleyen öneri yok." satırı döner.
 */
function mapping_suggestions_hover_html(array $d): string
{
    $rows = '';
    if (!empty($d['rooms'])) {
        $rows .= '<div class="hover-list-title">Oda önerileri (' . (int) $d['roomCount'] . ')</div>';
        foreach ($d['rooms'] as $r) {
            $roomName = $r['room_name'] !== null && $r['room_name'] !== '' ? (string) $r['room_name'] : '—';
            $rows .= '<div class="hover-list-row"><code>' . htmlspecialchars((string) $r['external_room_id']) . '</code> → '
                . htmlspecialchars($roomName)
                . ($r['suggestion_score'] !== null ? ' <small>%' . (int) $r['suggestion_score'] . '</small>' : '')
                . (!empty($r['channel']) ? ' <small>· ' . htmlspecialchars((string) $r['channel']) . '</small>' : '')
                . '</div>';
        }
    }
    if (!empty($d['plans'])) {
        $rows .= '<div class="hover-list-title">Fiyat planı önerileri (' . (int) $d['planCount'] . ')</div>';
        foreach ($d['plans'] as $p) {
            $planName = $p['plan_name'] !== null && $p['plan_name'] !== '' ? (string) $p['plan_name'] : '—';
            $rows .= '<div class="hover-list-row"><code>' . htmlspecialchars((string) $p['external_rate_plan_id']) . '</code> → '
                . htmlspecialchars($planName)
                . ($p['suggestion_score'] !== null ? ' <small>%' . (int) $p['suggestion_score'] . '</small>' : '')
                . (!empty($p['channel']) ? ' <small>· ' . htmlspecialchars((string) $p['channel']) . '</small>' : '')
                . '</div>';
        }
    }
    if ($rows === '') {
        return '<div class="hover-list-row" style="color:#9fb3ad">Bekleyen öneri yok.</div>';
    }
    return $rows;
}
