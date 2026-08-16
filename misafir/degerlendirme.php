<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notifications.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '') { http_response_code(404); exit('Değerlendirme bağlantısı eksik.'); }

$q = db()->prepare('SELECT r.*, p.name property_name, b.booking_reference FROM guest_reviews r JOIN properties p ON p.id=r.property_id LEFT JOIN supplier_bookings b ON b.id=r.booking_id WHERE r.token_hash=?');
$q->execute([hash('sha256', $token)]);
$review = $q->fetch();
if (!$review) { http_response_code(404); exit('Bu değerlendirme bağlantısı geçersiz.'); }

$message = '';
if (in_array($review['status'], ['pending', 'published', 'hidden'], true)) {
    $message = 'Değerlendirmeniz alındı. Konaklamanız için teşekkür ederiz.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $review['status'] === 'invited') {
    $rating = (int) ($_POST['rating'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $guestName = trim((string) ($_POST['guest_name'] ?? ''));
    if ($rating < 1 || $rating > 5 || $body === '') {
        $message = 'Puan (1-5) ve yorum metni zorunludur.';
    } else {
        db()->prepare("UPDATE guest_reviews SET rating=?,title=?,body=?,guest_name=?,status='pending',submitted_at=now() WHERE id=? AND status='invited'")
            ->execute([$rating, $title ?: null, $body, $guestName ?: null, $review['id']]);
        try {
            $supplierQ = db()->prepare('SELECT supplier_id FROM properties WHERE id=?');
            $supplierQ->execute([(int) $review['property_id']]);
            $supplierId = $supplierQ->fetchColumn();
            if ($supplierId) {
                notify_supplier_users((int) $supplierId, 'review.new', 'Yeni misafir değerlendirmesi geldi' . ($rating ? ' (★ ' . $rating . ')' : ''), '/nexustraveltech/tedarikci/otel-yorumlar');
            }
        } catch (Throwable $e) {
            // Bildirim best-effort'tur.
        }
        $message = 'Değerlendirmeniz alındı. Konaklamanız için teşekkür ederiz.';
        $review = db()->prepare('SELECT r.*, p.name property_name, b.booking_reference FROM guest_reviews r JOIN properties p ON p.id=r.property_id LEFT JOIN supplier_bookings b ON b.id=r.booking_id WHERE r.id=?');
        $review->execute([$review['id']]);
        $review = $review->fetch();
    }
}
?>
<!doctype html>
<html lang="tr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Konaklama Değerlendirmesi | NEXUS</title>
<style>body{font-family:Arial;background:#f6f7f4;color:#14241f;margin:0}.w{max-width:560px;margin:40px auto;background:#fff;padding:28px;border-radius:10px}.w h1{font-size:26px;margin:0 0 6px}.w p{line-height:1.6;color:#4a5a54}label{display:block;margin:14px 0 6px;font-weight:700;font-size:13px}input,select,textarea,button{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d8ded8;border-radius:6px;font:inherit}textarea{min-height:110px}button{margin-top:18px;background:#14241f;color:#fff;border:0;font-weight:700;cursor:pointer}.ok{background:#e6f8c7;padding:12px;border-radius:6px}.stars{font-size:20px;letter-spacing:6px}.muted{color:#64716d;font-size:12px}</style>
</head>
<body>
<main class="w">
<h1><?= htmlspecialchars($review['property_name']) ?></h1>
<p>Konaklamanız nasıl geçti? Deneyiminizi paylaşarak diğer gezginlere yardımcı olun.</p>
<?php if ($message): ?><p class="ok"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($review['status'] === 'invited' && !$message): ?>
<form method="post">
<input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
<label>Genel puanınız<select name="rating" required><option value="">Seçin</option><option value="5">5 — Mükemmel</option><option value="4">4 — İyi</option><option value="3">3 — Orta</option><option value="2">2 — Zayıf</option><option value="1">1 — Çok zayıf</option></select></label>
<label>Adınız (opsiyonel)<input name="guest_name" maxlength="120" placeholder="Adınız ve soyadınız"></label>
<label>Başlık (opsiyonel)<input name="title" maxlength="190" placeholder="Örn. Harika deniz manzarası"></label>
<label>Yorumunuz<textarea name="body" required placeholder="Konaklamanız hakkında ne düşünüyorsunuz?"></textarea></label>
<button type="submit">Değerlendirmeyi gönder</button>
<p class="muted">Yorumunuz işletme onayından sonra yayınlanır. Kişisel bilgileriniz üçüncü kişilerle paylaşılmaz.</p>
</form>
<?php endif; ?>
</main>
</body>
</html>
