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
 * Bağlantı bazlı EN KRİTİK eşlenmemiş kodlar — tedarikçi ana sayfası dağıtım sağlık kartı için.
 * Görülen kodlardan (son N işlem) hiçbir eşleştirmeye bağlı olmayanları bulur, görülme sayısına
 * göre azalan sıralar; ilk `top` adet oda + fiyat planı kodunu ve toplam sayıyı döndürür.
 * Tablo/kolon yoksa veya hata olursa boş sonuç (sayfa çökmez).
 *
 * @return array{room: array, plan: array, total: int}
 */
function connection_top_unmatched_codes(PDO $pdo, int $connectionId, int $window = 200, int $top = 2): array
{
    $out = ['room' => [], 'plan' => [], 'total' => 0];
    try {
        $seen = $pdo->prepare(
            "SELECT request_payload, created_at FROM channel_sync_logs
             WHERE channel_connection_id = ? AND direction = 'pull' AND request_payload IS NOT NULL
             ORDER BY id DESC LIMIT ?"
        );
        $seen->execute([$connectionId, $window]);
        $roomSeen = [];
        $planSeen = [];
        foreach ($seen->fetchAll() as $sl) {
            $dec = json_decode((string) $sl['request_payload'], true);
            if (!is_array($dec) || !isset($dec['entries']) || !is_array($dec['entries'])) continue;
            foreach ($dec['entries'] as $en) {
                if (!is_array($en)) continue;
                if (isset($en['external_room_id']) && trim((string) $en['external_room_id']) !== '') {
                    $code = trim((string) $en['external_room_id']);
                    if (!isset($roomSeen[$code])) $roomSeen[$code] = ['c' => 0, 't' => (string) $sl['created_at']];
                    $roomSeen[$code]['c']++;
                }
                if (isset($en['external_rate_plan_id']) && trim((string) $en['external_rate_plan_id']) !== '') {
                    $code = trim((string) $en['external_rate_plan_id']);
                    if (!isset($planSeen[$code])) $planSeen[$code] = ['c' => 0, 't' => (string) $sl['created_at']];
                    $planSeen[$code]['c']++;
                }
            }
        }
        if ($roomSeen === [] && $planSeen === []) return $out;
        // Eşlenmiş (herhangi bir durumda) + karalistede olan kodlar "görmezden" sayılır.
        $done = [];
        $rm = $pdo->prepare('SELECT external_room_id FROM channel_room_mappings WHERE channel_connection_id = ?');
        $rm->execute([$connectionId]);
        foreach ($rm->fetchAll() as $r) $done['room|' . $r['external_room_id']] = true;
        $pm = $pdo->prepare('SELECT external_rate_plan_id FROM channel_rate_plan_mappings WHERE channel_connection_id = ?');
        $pm->execute([$connectionId]);
        foreach ($pm->fetchAll() as $r) $done['plan|' . $r['external_rate_plan_id']] = true;
        try {
            $bl = $pdo->prepare('SELECT code_type, external_code FROM channel_mapping_blacklist WHERE channel_connection_id = ?');
            $bl->execute([$connectionId]);
            foreach ($bl->fetchAll() as $r) $done[$r['code_type'] . '|' . $r['external_code']] = true;
        } catch (Throwable $e) {
        }
        $roomList = [];
        $planList = [];
        foreach ($roomSeen as $code => $info) {
            if (!isset($done['room|' . $code])) $roomList[] = ['code' => $code, 'count' => (int) $info['c'], 'last' => (string) $info['t']];
        }
        foreach ($planSeen as $code => $info) {
            if (!isset($done['plan|' . $code])) $planList[] = ['code' => $code, 'count' => (int) $info['c'], 'last' => (string) $info['t']];
        }
        // Görülme sayısı (azalan), eşitse son görülme (azalan) — bölüm 1 sıralamasıyla aynı.
        usort($roomList, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0) ?: strcmp((string) ($b['last'] ?? ''), (string) ($a['last'] ?? '')));
        usort($planList, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0) ?: strcmp((string) ($b['last'] ?? ''), (string) ($a['last'] ?? '')));
        $out['total'] = count($roomList) + count($planList);
        $out['room'] = array_slice($roomList, 0, $top);
        $out['plan'] = array_slice($planList, 0, $top);
    } catch (Throwable $e) {
        $out = ['room' => [], 'plan' => [], 'total' => 0];
    }
    return $out;
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
