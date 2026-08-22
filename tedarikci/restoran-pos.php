<?php
require_once __DIR__ . '/layout.php';

$success = '';
$selected_table = $_GET['table'] ?? 'Masa 4';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'transfer_room') {
        $room = $_POST['room_no'] ?? '101';
        $amount = $_POST['amount'] ?? '850';
        $success = "{$selected_table} hesabındaki {$amount} TL tutarındaki adisyon Oda {$room} (Alexander Mueller) folyosuna başarıyla aktarıldı!";
    } elseif ($action === 'close_cash') {
        $success = "{$selected_table} hesabı nakit/kredi kartı olarak kapatıldı ve adisyon kesildi!";
    }
}

supply_start('Restoran & Bar POS Satış Programı', 'hotel_pos');
?>

<style>
  .pos-grid {
    display: grid;
    grid-template-columns: 240px 1fr 340px;
    gap: 20px;
    margin-top: 16px;
  }
  @media (max-width: 1100px) {
    .pos-grid { grid-template-columns: 1fr; }
  }
  .pos-card {
    background: #ffffff;
    border: 1px solid #d8ded8;
    border-radius: 14px;
    padding: 18px;
  }
  .table-btn {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 14px;
    margin-bottom: 8px;
    border-radius: 8px;
    border: 1px solid #e7ece7;
    background: #f8faf8;
    color: #10211f;
    text-decoration: none;
    font-weight: 700;
    font-size: 13.5px;
    transition: all 0.15s;
  }
  .table-btn.active {
    background: #071412;
    color: #d7ff48;
    border-color: #071412;
  }
  .table-btn.occupied {
    border-left: 4px solid #e85f42;
  }
  .table-btn.empty {
    border-left: 4px solid #2e7d32;
  }
  .menu-item-btn {
    background: #ffffff;
    border: 1px solid #e2e8e2;
    border-radius: 10px;
    padding: 14px;
    text-align: left;
    cursor: pointer;
    transition: all 0.15s;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .menu-item-btn:hover {
    border-color: #071412;
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0,0,0,0.06);
  }
</style>

<?php if ($success): ?>
  <div style="background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;padding:14px 20px;border-radius:10px;margin-bottom:18px;font-weight:600;display:flex;align-items:center;gap:10px;">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
  </div>
<?php endif; ?>

<div class="pos-grid">
  
  <!-- 1. SOL: MASA KROKİSİ -->
  <div class="pos-card">
    <h3 style="margin:0 0 14px;font-size:15px;font-weight:800;text-transform:uppercase;letter-spacing:0.04em;">
      <i class="fa-solid fa-chair" style="color:#e65100;margin-right:6px;"></i> Masalar & Alanlar
    </h3>
    <div style="display:flex;gap:6px;margin-bottom:12px;">
      <span style="font-size:11px;background:#e8f5e9;color:#2e7d32;padding:3px 8px;border-radius:4px;font-weight:700">4 Boş</span>
      <span style="font-size:11px;background:#ffebee;color:#c62828;padding:3px 8px;border-radius:4px;font-weight:700">3 Dolu</span>
    </div>

    <a href="?table=Masa 1" class="table-btn empty <?= $selected_table === 'Masa 1' ? 'active' : '' ?>"><span>Masa 1</span><small>Boş</small></a>
    <a href="?table=Masa 2" class="table-btn occupied <?= $selected_table === 'Masa 2' ? 'active' : '' ?>"><span>Masa 2</span><small>₺420</small></a>
    <a href="?table=Masa 3" class="table-btn empty <?= $selected_table === 'Masa 3' ? 'active' : '' ?>"><span>Masa 3</span><small>Boş</small></a>
    <a href="?table=Masa 4" class="table-btn occupied <?= $selected_table === 'Masa 4' ? 'active' : '' ?>"><span>Masa 4 (Bahçe)</span><small>₺850</small></a>
    <a href="?table=Masa 5" class="table-btn occupied <?= $selected_table === 'Masa 5' ? 'active' : '' ?>"><span>Masa 5 (Havuz)</span><small>₺1.200</small></a>
    <a href="?table=Bar 1" class="table-btn empty <?= $selected_table === 'Bar 1' ? 'active' : '' ?>"><span>Bar Taburesi 1</span><small>Boş</small></a>
  </div>

  <!-- 2. ORTA: DOKUNMATİK MENÜ & HIZLI SİPARİŞ -->
  <div class="pos-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h3 style="margin:0;font-size:16px;font-weight:800;">
        <i class="fa-solid fa-utensils" style="color:#2e7d32;margin-right:6px;"></i> Hızlı Ürün Ekle
      </h3>
      <div style="display:flex;gap:6px;">
        <button class="button" style="padding:5px 12px;font-size:12px;background:#071412;color:#fff;border-radius:6px;border:none;">Tümü</button>
        <button class="button" style="padding:5px 12px;font-size:12px;background:#f0f3f0;color:#333;border-radius:6px;border:none;">Ana Yemek</button>
        <button class="button" style="padding:5px 12px;font-size:12px;background:#f0f3f0;color:#333;border-radius:6px;border:none;">İçecek</button>
        <button class="button" style="padding:5px 12px;font-size:12px;background:#f0f3f0;color:#333;border-radius:6px;border:none;">Tatlı</button>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:12px;">
      <button class="menu-item-btn" onclick="alert('Izgara Levrek adisyona eklendi')">
        <strong style="font-size:13.5px;color:#10211f;">Izgara Levrek</strong>
        <span style="font:700 13px 'DM Mono', monospace;color:#e85f42;margin-top:8px;">₺450</span>
      </button>
      <button class="menu-item-btn" onclick="alert('Dana Antrikot adisyona eklendi')">
        <strong style="font-size:13.5px;color:#10211f;">Dana Antrikot</strong>
        <span style="font:700 13px 'DM Mono', monospace;color:#e85f42;margin-top:8px;">₺580</span>
      </button>
      <button class="menu-item-btn" onclick="alert('Akdeniz Salatası adisyona eklendi')">
        <strong style="font-size:13.5px;color:#10211f;">Akdeniz Salatası</strong>
        <span style="font:700 13px 'DM Mono', monospace;color:#e85f42;margin-top:8px;">₺190</span>
      </button>
      <button class="menu-item-btn" onclick="alert('Mojito Kokteyl adisyona eklendi')">
        <strong style="font-size:13.5px;color:#10211f;">Mojito Kokteyl</strong>
        <span style="font:700 13px 'DM Mono', monospace;color:#e85f42;margin-top:8px;">₺260</span>
      </button>
      <button class="menu-item-btn" onclick="alert('Taze Portakal Suyu eklendi')">
        <strong style="font-size:13.5px;color:#10211f;">Portakal Suyu</strong>
        <span style="font:700 13px 'DM Mono', monospace;color:#e85f42;margin-top:8px;">₺90</span>
      </button>
      <button class="menu-item-btn" onclick="alert('Sufle & Dondurma eklendi')">
        <strong style="font-size:13.5px;color:#10211f;">Çikolatalı Sufle</strong>
        <span style="font:700 13px 'DM Mono', monospace;color:#e85f42;margin-top:8px;">₺160</span>
      </button>
    </div>
  </div>

  <!-- 3. SAĞ: AÇIK ADİSYON & ODAYA AKTARIM -->
  <div class="pos-card" style="background:#fcfdfc;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:2px solid #e7ece7;padding-bottom:8px;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#071412;"><?= htmlspecialchars($selected_table) ?> Adisyonu</h3>
      <span style="font:600 11px 'DM Mono', monospace;background:#ffebee;color:#c62828;padding:2px 6px;border-radius:4px;">AÇIK</span>
    </div>

    <div style="font-size:13px;color:#444;margin-bottom:14px;border-bottom:1px solid #f0f0f0;padding-bottom:10px;">
      <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
        <span>1x Izgara Levrek</span>
        <strong>₺450</strong>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
        <span>1x Akdeniz Salatası</span>
        <strong>₺190</strong>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
        <span>1x Mojito Kokteyl</span>
        <strong>₺260</strong>
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:10px;padding-top:8px;border-top:1px dashed #d8ded8;font-size:16px;font-weight:800;color:#071412;">
        <span>Toplam:</span>
        <span style="font-family:'DM Mono', monospace;color:#e85f42;">₺850</span>
      </div>
    </div>

    <!-- ODAYA FOLYO AKTAR FORMU -->
    <form method="POST" style="margin-bottom:10px;">
      <input type="hidden" name="action" value="transfer_room">
      <input type="hidden" name="amount" value="850">
      <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Oda Numarasına Aktar (Folyo)</label>
      <div style="display:flex;gap:8px;margin-bottom:8px;">
        <select name="room_no" class="admin-input" style="flex:1;height:38px;border:1px solid #d8ded8;border-radius:6px;padding:0 8px;font-weight:700;">
          <option value="101">Oda 101 - Alexander Mueller</option>
          <option value="102">Oda 102 - Mehmet Yılmaz</option>
          <option value="201">Oda 201 - Sarah Jenkins</option>
        </select>
      </div>
      <button type="submit" class="button" style="width:100%;background:#071412;color:#d7ff48;font-weight:700;padding:10px;border:none;border-radius:6px;cursor:pointer;font-size:13px;">
        <i class="fa-solid fa-arrow-right-to-bracket" style="margin-right:6px;"></i> Odaya Folyo Aktar (₺850)
      </button>
    </form>

    <form method="POST">
      <input type="hidden" name="action" value="close_cash">
      <button type="submit" class="button" style="width:100%;background:#eef4ee;color:#2e7d32;font-weight:700;padding:10px;border:1px solid #c8e6c9;border-radius:6px;cursor:pointer;font-size:13px;">
        <i class="fa-solid fa-credit-card" style="margin-right:6px;"></i> Nakit / POS ile Kapat
      </button>
    </form>
  </div>

</div>

<?php supply_end(); ?>
