<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

const LOYALTY_POINTS_PER_NIGHT = 10;
const LOYALTY_POINT_VALUE_EUR = 0.10; // 1 puan = 0,10 EUR folyo kredisi

/**
 * Konaklama tamamlandığında (check-out) sadakat puanı kazandırır.
 * Gece başına sabit puan; hesap yoksa oluşturur, tier'ı otomatik yükseltir.
 */
function award_loyalty_points(int $bookingId): array
{
    $pdo = db();
    $q = $pdo->prepare(
        "SELECT b.supplier_id,b.check_in,b.check_out,b.total_amount,b.currency,
                bg.guest_id
         FROM supplier_bookings b
         JOIN booking_guests bg ON bg.booking_id=b.id AND bg.is_primary=true
         WHERE b.id=?"
    );
    $q->execute([$bookingId]);
    $b = $q->fetch();
    if (!$b || !$b['guest_id']) return ['ok' => false, 'message' => 'Birincil misafir bulunamadı.'];

    $nights = max(1, (int) ((strtotime((string) $b['check_out']) - strtotime((string) $b['check_in'])) / 86400));
    $points = $nights * LOYALTY_POINTS_PER_NIGHT;

    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) $pdo->beginTransaction();
    try {
        $aq = $pdo->prepare('SELECT id FROM guest_loyalty_accounts WHERE guest_id=?');
        $aq->execute([$b['guest_id']]);
        $accountId = $aq->fetchColumn();
        if (!$accountId) {
            $ins = $pdo->prepare('INSERT INTO guest_loyalty_accounts(guest_id,points_balance,lifetime_nights,lifetime_revenue) VALUES(?,?,?,?) RETURNING id');
            $ins->execute([$b['guest_id'], 0, 0, 0]);
            $accountId = (int) $ins->fetchColumn();
        }
        $pdo->prepare('INSERT INTO loyalty_ledger(account_id,booking_id,transaction_type,points,description) VALUES(?,?,?,?,?)')
            ->execute([$accountId, $bookingId, 'earn', $points, $nights . ' gece konaklama']);
        $pdo->prepare('UPDATE guest_loyalty_accounts SET points_balance=points_balance+?,lifetime_nights=lifetime_nights+?,lifetime_revenue=lifetime_revenue+? WHERE id=?')
            ->execute([$points, $nights, (float) $b['total_amount'], $accountId]);
        sync_loyalty_tier((int) $accountId, $pdo);
        if ($ownsTx) $pdo->commit();
        return ['ok' => true, 'points' => $points, 'nights' => $nights];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Hesabı en yüksek hak ettiği kademeye taşır (lifetime_nights / lifetime_revenue bazlı).
 */
function sync_loyalty_tier(int $accountId, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $q = $pdo->prepare(
        "SELECT a.guest_id,a.lifetime_nights,a.lifetime_revenue,g.supplier_id
         FROM guest_loyalty_accounts a JOIN guest_profiles g ON g.id=a.guest_id
         WHERE a.id=?"
    );
    $q->execute([$accountId]);
    $a = $q->fetch();
    if (!$a) return;
    $tq = $pdo->prepare(
        "SELECT id FROM loyalty_tiers
         WHERE supplier_id=? AND min_nights<=? AND min_revenue<=?
         ORDER BY min_nights DESC, min_revenue DESC LIMIT 1"
    );
    $tq->execute([$a['supplier_id'], (int) $a['lifetime_nights'], (float) $a['lifetime_revenue']]);
    $tierId = $tq->fetchColumn();
    if ($tierId) {
        $pdo->prepare('UPDATE guest_loyalty_accounts SET tier_id=? WHERE id=?')->execute([$tierId, $accountId]);
    }
}

/**
 * Puanları açık bir folyoda kredi (indirim) olarak kullanır.
 * 1 puan = 0,10 EUR; yalnızca bakiyesi yeten ve folyosu açık olan misafirler için.
 */
function redeem_loyalty_points(int $accountId, int $folioId, float $points): array
{
    $pdo = db();
    $q = $pdo->prepare(
        "SELECT a.points_balance,a.guest_id,bf.id folio_id,bf.booking_id
         FROM guest_loyalty_accounts a
         JOIN booking_folios bf ON bf.booking_id IN (SELECT booking_id FROM booking_guests WHERE guest_id=a.guest_id)
         WHERE a.id=? AND bf.id=? AND bf.status='open'"
    );
    $q->execute([$accountId, $folioId]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('Açık folyo bulunamadı.');
    $points = max(1, (float) $points);
    if ($points > (float) $row['points_balance']) throw new RuntimeException('Yeterli puan yok.');

    $credit = round($points * LOYALTY_POINT_VALUE_EUR, 2);

    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO folio_transactions(folio_id,transaction_type,department,description,amount,transaction_at) VALUES(?, 'adjustment', 'loyalty', ?, ?, now())")
            ->execute([$folioId, 'Sadakat puanı ile indirim (' . number_format($points, 0) . ' puan)', -$credit]);
        $pdo->prepare('INSERT INTO loyalty_ledger(account_id,booking_id,transaction_type,points,description) VALUES(?,?,?,?,?)')
            ->execute([$accountId, $row['booking_id'], 'redeem', -$points, 'Folyo indirimi olarak kullanıldı']);
        $pdo->prepare('UPDATE guest_loyalty_accounts SET points_balance=points_balance-? WHERE id=?')
            ->execute([$points, $accountId]);
        if ($ownsTx) $pdo->commit();
        return ['ok' => true, 'credit' => $credit, 'points' => $points];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
