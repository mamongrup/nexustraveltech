<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/audit.php';

require_admin();
$q = trim((string) ($_GET['q'] ?? ''));
$guestId = (int) ($_GET['guest'] ?? 0);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $err = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['guest_id'] ?? 0);
        if ($action === 'export' && $id > 0) {
            $pdo = db();
            $gq = $pdo->prepare('SELECT * FROM guest_profiles WHERE id=?');
            $gq->execute([$id]);
            $guest = $gq->fetch();
            if (!$guest) { $err = 'Misafir bulunamadı.'; }
            else {
                $data = ['guest_profile' => $guest];
                $q1 = $pdo->prepare('SELECT * FROM guest_loyalty_accounts WHERE guest_id=?');
                $q1->execute([$id]);
                $data['loyalty_account'] = $q1->fetchAll();
                $q2 = $pdo->prepare('SELECT bg.booking_id,b.* FROM booking_guests bg JOIN supplier_bookings b ON b.id=bg.booking_id WHERE bg.guest_id=?');
                $q2->execute([$id]);
                $data['bookings'] = $q2->fetchAll();
                $q3 = $pdo->prepare('SELECT * FROM guest_communications WHERE guest_id=?');
                $q3->execute([$id]);
                $data['communications'] = $q3->fetchAll();
                $q4 = $pdo->prepare('SELECT * FROM guest_document_records WHERE guest_id=?');
                $q4->execute([$id]);
                $data['documents'] = $q4->fetchAll();
                $q5 = $pdo->prepare('SELECT r.* FROM guest_reviews r JOIN booking_guests bg ON bg.booking_id=r.booking_id WHERE bg.guest_id=?');
                $q5->execute([$id]);
                $data['reviews'] = $q5->fetchAll();
                $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                audit_log('kvkk.export', 'guest', $id, ['q' => $q]);
                header('Content-Type: application/json; charset=UTF-8');
                header('Content-Disposition: attachment; filename="kvkk-misafir-' . $id . '.json"');
                echo $payload;
                exit;
            }
        }
        if ($action === 'anonymize' && $id > 0) {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE guest_profiles SET first_name='[Gizli]',last_name='[Gizli]',email=NULL,phone=NULL,nationality=NULL,birth_date=NULL,identity_type=NULL,identity_number=NULL,passport_country=NULL,vip_level=NULL,marketing_consent=false,preferences='{}'::jsonb WHERE id=?")
                ->execute([$id]);
            $pdo->prepare('DELETE FROM guest_loyalty_accounts WHERE guest_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM guest_communications WHERE guest_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM guest_document_records WHERE guest_id=?')->execute([$id]);
            $pdo->commit();
            audit_log('kvkk.anonymize', 'guest', $id);
            $msg = 'Misafir verisi anonimleştirildi: kimlik alanları silindi, sadakat ve iletişim kayıtları kaldırıldı. Rezervasyon geçmişi yasal saklama nedeniyle korundu.';
        }
    }
}

$matches = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $mq = db()->prepare("SELECT g.*,s.company_name supplier_name FROM guest_profiles g LEFT JOIN suppliers s ON s.id=g.supplier_id WHERE g.email ILIKE ? OR g.phone ILIKE ? OR g.first_name ILIKE ? OR g.last_name ILIKE ? ORDER BY g.id DESC LIMIT 30");
    $mq->execute([$like, $like, $like, $like]);
    $matches = $mq->fetchAll();
}
$detail = null;
if ($guestId > 0) {
    $gq = db()->prepare('SELECT * FROM guest_profiles WHERE id=?');
    $gq->execute([$guestId]);
    $detail = $gq->fetch() ?: null;
}
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KVKK veri aracı | NEXUS Admin</title>
  <style>
    body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(960px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}input,button{padding:9px;font:inherit;border:1px solid #d8ded8}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}button{background:#10211f;color:#fff;border:0;cursor:pointer;margin-top:8px}button.warn{background:#8e2410}
  </style>
</head>
<body>
<main class="w"><a href="/nexustraveltech/admin/">← Panel</a><h1>KVKK veri aracı</h1>
<p class="muted">Misafir başına veri dışa aktarma (taşınabilirlik) ve anonimleştirme (silme). Anonimleştirme kalıcıdır; rezervasyon kayıtları yasal saklama süresi nedeniyle tutulur.</p>
<?php if ($msg): ?><p class="ok"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="er"><?= htmlspecialchars($err) ?></p><?php endif; ?>
<section class="c"><h2>Misafir ara</h2>
<form method="get"><input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="E-posta, telefon veya ad soyad" style="width:320px" required><button>Ara</button></form>
<?php if ($q !== ''): ?><h3><?= count($matches) ?> sonuç</h3>
<?php foreach ($matches as $g): ?><p><a href="kvkk.php?guest=<?= (int) $g['id'] ?>"><b><?= htmlspecialchars((string) $g['first_name'] . ' ' . (string) $g['last_name']) ?></b></a> · <?= htmlspecialchars((string) ($g['email'] ?? '')) ?> · <?= htmlspecialchars((string) ($g['phone'] ?? '')) ?> · tedarikçi: <?= htmlspecialchars((string) ($g['supplier_name'] ?? '—')) ?></p><?php endforeach; ?>
<?php endif; ?></section>
<?php if ($detail): ?>
<section class="c"><h2>Misafir #<?= (int) $detail['id'] ?> — <?= htmlspecialchars((string) $detail['first_name'] . ' ' . (string) $detail['last_name']) ?></h2>
<p class="muted">E-posta: <?= htmlspecialchars((string) ($detail['email'] ?? '—')) ?> · telefon: <?= htmlspecialchars((string) ($detail['phone'] ?? '—')) ?> · kimlik: <?= htmlspecialchars((string) ($detail['identity_number'] ?? '—')) ?> · pazarlama izni: <?= $detail['marketing_consent'] ? 'var' : 'yok' ?></p>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="export"><input type="hidden" name="guest_id" value="<?= (int) $detail['id'] ?>"><button>JSON dışa aktar (KVKK taşınabilirlik)</button></form>
<form method="post" style="display:inline" onsubmit="return confirm('Bu işlem kalıcıdır: kimlik alanları silinir. Devam edilsin mi?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="anonymize"><input type="hidden" name="guest_id" value="<?= (int) $detail['id'] ?>"><button class="warn">Anonimleştir (KVKK silme)</button></form>
</section>
<?php endif; ?>
</main>
</body>
</html>
