<?php
/**
 * Kasir Ibtidaiyah - Pengaturan Sistem & Hak Akses
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Pengaturan';
$breadcrumbs = [['label' => 'Pengaturan']];

// Handle Delete QRIS
if (isset($_GET['action']) && $_GET['action'] === 'delete_qris') {
    $oldQris = getSetting('payment_qris_image');
    if ($oldQris) {
        // Asumsi deleteUploadedFile ada (digunakan juga di baris bawah)
        deleteUploadedFile($oldQris);
        updateSetting('payment_qris_image', '');
        logActivity('Delete', 'Pengaturan', 'Menghapus gambar QRIS.');
    }
    flashMessage('success', 'Gambar QR Code berhasil dihapus.');
    redirect(BASE_URL . '/admin/settings.php');
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actionType = $_POST['action_type'] ?? 'general';

    if ($actionType === 'toko') {
        // General Settings
        updateSetting('store_name', sanitize($_POST['store_name'] ?? ''));
        updateSetting('store_address', sanitize($_POST['store_address'] ?? ''));
        updateSetting('store_phone', sanitize($_POST['store_phone'] ?? ''));
        updateSetting('store_tagline', sanitize($_POST['store_tagline'] ?? ''));
        updateSetting('receipt_footer', sanitize($_POST['receipt_footer'] ?? ''));

        if (isset($_FILES['store_logo']) && $_FILES['store_logo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadImage($_FILES['store_logo'], 'settings');
            if ($upload['success']) {
                $oldLogo = getSetting('store_logo');
                if ($oldLogo) {
                    deleteUploadedFile($oldLogo);
                }
                updateSetting('store_logo', $upload['path']);
            } else {
                flashMessage('error', 'Gagal upload logo: ' . $upload['message']);
            }
        }


        logActivity('Update', 'Pengaturan', 'Memperbarui pengaturan toko.');
    } elseif ($actionType === 'api') {
        // API Settings
        updateSetting('enable_shipping_api', isset($_POST['enable_shipping_api']) ? '1' : '0');
        updateSetting('rajaongkir_api_key',  sanitize($_POST['rajaongkir_api_key']  ?? ''));
        updateSetting('rajaongkir_origin_city_id', (int)($_POST['rajaongkir_origin_city_id'] ?? 0));
        updateSetting('rajaongkir_origin_city_name', sanitize($_POST['rajaongkir_origin_city_name'] ?? ''));
        logActivity('Update', 'Pengaturan', 'Memperbarui pengaturan API.');
    } elseif ($actionType === 'roles') {
        // Role Permissions Checklist
        $db->exec("DELETE FROM role_permissions WHERE role_id != 1");
        if (!empty($_POST['permissions']) && is_array($_POST['permissions'])) {
            $stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?, ?)");
            foreach ($_POST['permissions'] as $roleId => $perms) {
                if ($roleId == 1) continue;
                foreach ($perms as $perm) {
                    $stmt->execute([$roleId, $perm]);
                }
            }
        }
        $db->exec("DELETE FROM settings WHERE setting_key = 'role_permissions'");
        logActivity('Update', 'Pengaturan', 'Memperbarui hak akses role.');
    } elseif ($actionType === 'payment_methods') {
        $payment_keys = [
            'payment_bank_bca_account', 'payment_bank_bca_name',
            'payment_bank_mandiri_account', 'payment_bank_mandiri_name',
            'payment_bank_bni_account', 'payment_bank_bni_name',
            'payment_bank_bri_account', 'payment_bank_bri_name',
            'payment_bank_bsi_account', 'payment_bank_bsi_name',
            'payment_bank_emaal_account', 'payment_bank_emaal_name',
            'payment_ewallet_dana', 'payment_ewallet_ovo', 'payment_ewallet_shopeepay', 'payment_ewallet_name',
            'payment_qris_name'
        ];
        foreach ($payment_keys as $key) {
            updateSetting($key, sanitize($_POST[$key] ?? ''));
        }

        if (isset($_FILES['payment_qris_image']) && $_FILES['payment_qris_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadImage($_FILES['payment_qris_image'], 'settings');
            if ($upload['success']) {
                $oldQris = getSetting('payment_qris_image');
                if ($oldQris) {
                    deleteUploadedFile($oldQris);
                }
                updateSetting('payment_qris_image', $upload['path']);
            } else {
                flashMessage('error', 'Gagal upload QRIS: ' . $upload['message']);
            }
        }
        logActivity('Update', 'Pengaturan', 'Memperbarui metode pembayaran e-commerce.');
    } elseif ($actionType === 'admin_wa') {
        // Admin WhatsApp untuk Konfirmasi Pesanan
        for ($i = 1; $i <= 3; $i++) {
            updateSetting("admin_wa_name_$i", sanitize($_POST["admin_wa_name_$i"] ?? ''));
            updateSetting("admin_wa_phone_$i", sanitize($_POST["admin_wa_phone_$i"] ?? ''));
        }
        logActivity('Update', 'Pengaturan', 'Memperbarui nomor WhatsApp admin konfirmasi pesanan.');
    }
    
    flashMessage('success', 'Pengaturan berhasil diperbarui.');
    redirect(BASE_URL . '/admin/settings.php');
}

// Fetch current values
$storeName = getSetting('store_name', APP_NAME);
$storeAddress = getSetting('store_address', '');
$storePhone = getSetting('store_phone', '');
$storeTagline = getSetting('store_tagline', '');
$receiptFooter = getSetting('receipt_footer', '');

// Fetch current role permissions from DB
$stmt = $db->query("SELECT role_id, permission_key FROM role_permissions");
$rolePermissions = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rolePermissions[$row['role_id']][] = $row['permission_key'];
}

// Fetch all roles except Pemilik and Pelanggan
$editableRoles = $db->query("SELECT id, role_name FROM roles WHERE id != 1 AND role_name != 'Pelanggan' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$allAvailablePermissions = [
    'menu_dashboard' => 'Dashboard',
    'menu_pos' => 'POS Kasir',
    'menu_produk' => 'Produk',
    'menu_harga_produk' => 'Harga Produk',
    'menu_monitor_stok' => 'Monitor Stok',
    'menu_impor_harga' => 'Impor Tipe Harga',
    'menu_kategori' => 'Kategori',
    'menu_pesanan_online' => 'Pesanan Online',
    'menu_pelanggan' => 'Pelanggan',
    'menu_supplier' => 'Supplier',
    'menu_pembelian' => 'Pembelian (PO)',
    'menu_surat_jalan' => 'Surat Jalan',
    'menu_opname' => 'Opname Produk',
    'menu_laporan' => 'Laporan (Keuangan & Penjualan)',
    'menu_arus_kas' => 'Arus Kas (Cash Flow)',
    'menu_setoran' => 'Setoran Shift',
    'menu_piutang' => 'Piutang',
    'menu_hutang' => 'Hutang',
    'menu_pengguna' => 'Kelola Pengguna',
    'menu_pengaturan' => 'Pengaturan Sistem & Hak Akses',
    'action_edit_prices' => 'Aksi: Mengubah Harga Produk',
    'action_view_stock' => 'Aksi: Melihat Stok Real-time',
    'action_print_receipt' => 'Aksi: Cetak Struk POS Sendiri'
];

// Fetch price types
$priceTypes = $db->query("SELECT * FROM price_types ORDER BY id ASC")->fetchAll();

include INCLUDES_PATH . '/header.php';
?>

<style>
    .settings-main-grid {
        display: flex;
        gap: 24px;
        align-items: flex-start;
    }
    .settings-col {
        display: flex;
        flex-direction: column;
        gap: 24px;
    }
    .col-1 { flex: 1; }
    .col-2 { flex: 1; }
    .col-3 { flex: 1.2; }
    .roles-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    @media (max-width: 768px) {
        .settings-main-grid {
            flex-direction: column !important;
            gap: 16px !important;
        }
        .settings-col {
            width: 100%;
        }
        .roles-grid {
            grid-template-columns: 1fr;
        }
        .bank-grid-mobile, .ewallet-grid-mobile {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<div class="settings-main-grid">
    
    <!-- Kolom 1 -->
    <div class="settings-col col-1">
        <!-- Toko -->
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action_type" value="toko">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Pengaturan Toko</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Logo Toko</label>
                        <?php $currentLogo = getSetting('store_logo'); if ($currentLogo): ?>
                            <div style="margin-bottom: 8px;">
                                <img src="<?= BASE_URL . '/' . htmlspecialchars($currentLogo) ?>" alt="Logo Toko" style="max-height: 80px; object-fit: contain; border-radius: 4px; border: 1px solid var(--border-color); padding: 4px; background: #fff;">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="store_logo" class="form-control" accept="image/*">
                        <small style="display: block; margin-top: 4px; color: var(--gray-500); font-size: 0.75rem;">Biarkan kosong jika tidak ingin mengubah. Maks. 2MB (JPG/PNG).</small>
                    </div>
                    

                    <div class="form-group">
                        <label class="form-label">Nama Toko</label>
                        <input type="text" name="store_name" class="form-control" value="<?= htmlspecialchars($storeName) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Slogan / Tagline</label>
                        <input type="text" name="store_tagline" class="form-control" value="<?= htmlspecialchars(htmlspecialchars_decode($storeTagline)) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="store_phone" class="form-control" value="<?= htmlspecialchars($storePhone) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Alamat Toko </label>
                        <textarea name="store_address" class="form-control" rows="2" placeholder="Contoh: Melayani dengan Sepenuh Hati"><?= htmlspecialchars($storeAddress) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catatan Kaki Struk</label>
                        <textarea name="receipt_footer" class="form-control" rows="2" placeholder="Terima kasih atas kunjungan Anda..."><?= htmlspecialchars($receiptFooter) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 12px;">
                        Simpan Pengaturan
                    </button>
                </div>
            </div>
        </form>

        <!-- Admin WA Konfirmasi Pesanan -->
        <form method="POST" action="">
            <input type="hidden" name="action_type" value="admin_wa">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📱 Admin WA Konfirmasi Pesanan</h3>
                </div>
                <div class="card-body">
                    <div style="background: var(--gray-50); border: 1.5px solid var(--border-color); border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 0.8125rem; color: var(--gray-600); line-height: 1.5;">
                        Nomor WhatsApp admin yang akan ditampilkan kepada pelanggan saat konfirmasi pesanan. Pelanggan bisa memilih admin mana yang akan dihubungi.
                    </div>
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                    <div style="background: var(--gray-50); border: 1px solid var(--border-color); border-radius: 10px; padding: 14px 16px; margin-bottom: 12px;">
                        <div style="font-weight: 600; font-size: 0.85rem; color: var(--gray-700); margin-bottom: 10px;">Admin <?= $i ?></div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Nama Admin</label>
                                <input type="text" name="admin_wa_name_<?= $i ?>" class="form-control" 
                                       value="<?= htmlspecialchars(getSetting("admin_wa_name_$i", '')) ?>" 
                                       placeholder="Contoh: Admin <?= $i ?>">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">No. WhatsApp</label>
                                <input type="text" name="admin_wa_phone_<?= $i ?>" class="form-control" 
                                       value="<?= htmlspecialchars(getSetting("admin_wa_phone_$i", '')) ?>" 
                                       placeholder="Contoh: 081234567890">
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 4px;">
                        Simpan Admin WA
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Kolom 2 -->
    <div class="settings-col col-2">
        <!-- Payment Methods Settings Card -->
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action_type" value="payment_methods">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">💳 Metode Pembayaran E-Commerce</h3>
                </div>
                <div class="card-body">
                    <div style="background: var(--gray-50); border: 1.5px solid var(--border-color); border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 0.8125rem; color: var(--gray-600); line-height: 1.5;">
                        Kosongkan nomor rekening/e-wallet jika Anda tidak ingin menampilkannya sebagai opsi pembayaran saat pelanggan checkout.
                    </div>

                    <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Bank Transfer</h4>
                    <?php 
                    $banks = ['bca' => 'BCA', 'mandiri' => 'Mandiri', 'bni' => 'BNI', 'bri' => 'BRI', 'bsi' => 'BSI', 'emaal' => 'Emaal'];
                    foreach ($banks as $bk => $bn): 
                    ?>
                    <div class="bank-grid-mobile" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">No. Rekening <?= $bn ?></label>
                            <input type="text" name="payment_bank_<?= $bk ?>_account" class="form-control" value="<?= htmlspecialchars(getSetting('payment_bank_'.$bk.'_account', '')) ?>" placeholder="Contoh: 1234567890">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Atas Nama (<?= $bn ?>)</label>
                            <input type="text" name="payment_bank_<?= $bk ?>_name" class="form-control" value="<?= htmlspecialchars(getSetting('payment_bank_'.$bk.'_name', '')) ?>" placeholder="Contoh: Sarung Santri Store">
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 12px; margin-top: 24px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">E-Wallet</h4>
                    <div class="form-group">
                        <label class="form-label">Atas Nama E-Wallet (Utama)</label>
                        <input type="text" name="payment_ewallet_name" class="form-control" value="<?= htmlspecialchars(getSetting('payment_ewallet_name', '')) ?>" placeholder="Contoh: Sarung Santri Store">
                    </div>
                    <div class="ewallet-grid-mobile" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">No. Dana</label>
                            <input type="text" name="payment_ewallet_dana" class="form-control" value="<?= htmlspecialchars(getSetting('payment_ewallet_dana', '')) ?>" placeholder="08...">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">No. OVO</label>
                            <input type="text" name="payment_ewallet_ovo" class="form-control" value="<?= htmlspecialchars(getSetting('payment_ewallet_ovo', '')) ?>" placeholder="08...">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">No. ShopeePay</label>
                            <input type="text" name="payment_ewallet_shopeepay" class="form-control" value="<?= htmlspecialchars(getSetting('payment_ewallet_shopeepay', '')) ?>" placeholder="08...">
                        </div>
                    </div>

                    <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 12px; margin-top: 24px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">QRIS</h4>
                    <div class="form-group">
                        <label class="form-label">Nama QRIS (Ditampilkan ke pelanggan)</label>
                        <input type="text" name="payment_qris_name" class="form-control" value="<?= htmlspecialchars(getSetting('payment_qris_name', '')) ?>" placeholder="Contoh: QRIS Sarung Santri Store">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gambar QR Code</label>
                        <?php $currentQris = getSetting('payment_qris_image'); if ($currentQris): ?>
                            <div style="margin-bottom: 8px; position: relative; display: inline-block;">
                                <img src="<?= BASE_URL . '/' . htmlspecialchars($currentQris) ?>" alt="QRIS" style="max-height: 150px; object-fit: contain; border-radius: 4px; border: 1px solid var(--border-color); padding: 4px; background: #fff;">
                                <a href="?action=delete_qris" onclick="return confirm('Apakah Anda yakin ingin menghapus gambar QR Code ini?')" style="position: absolute; top: -8px; right: -8px; background: var(--danger, #dc3545); color: #fff; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 18px; line-height: 1; box-shadow: 0 2px 4px rgba(0,0,0,0.2);" title="Hapus QR Code">&times;</a>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="payment_qris_image" class="form-control" accept="image/*">
                        <small style="display: block; margin-top: 4px; color: var(--gray-500); font-size: 0.75rem;">Maks. 2MB (JPG/PNG). Kosongkan jika tidak ada / tidak ingin ubah.</small>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top:12px;">
                        Simpan Metode Pembayaran
                    </button>
                </div>
            </div>
        </form>
        
        <!-- API Settings Card -->
        <form method="POST" action="">
            <input type="hidden" name="action_type" value="api">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">⚡ Pengaturan API</h3>
                </div>

                <div class="card-body">
                    <p style="font-size:0.8125rem; color:var(--gray-500); margin-bottom:16px;">Aktifkan integrasi API untuk memudahkan pengisian alamat pelanggan dan kalkulasi ongkos kirim otomatis saat checkout.</p>


                    <!-- Toggle API Ongkir -->
                    <div style="background:var(--gray-50); border:1.5px solid var(--border-color); border-radius:10px; padding:14px 16px; margin-bottom:14px;">
                        <label class="form-check" style="font-weight:600; font-size:0.875rem; margin-bottom:6px;">
                            <input type="checkbox" name="enable_shipping_api" id="enableShippingApi" value="1" <?= getSetting('enable_shipping_api') === '1' ? 'checked' : '' ?> onchange="document.getElementById('shippingApiFields').style.display=this.checked?'block':'none'">
                            <span>🚚 Aktifkan API Cek Ongkir (RajaOngkir)</span>
                        </label>

                        <div id="shippingApiFields" style="display:<?= getSetting('enable_shipping_api') === '1' ? 'block' : 'none' ?>;">
                            <div class="form-group" style="margin-bottom:12px;">
                                <label class="form-label">API Key RajaOngkir</label>
                                <input type="text" name="rajaongkir_api_key" class="form-control" value="<?= htmlspecialchars(getSetting('rajaongkir_api_key', 'uIAOZRuVa201624849c7c682aOW18xZb')) ?>" placeholder="Masukkan API Key RajaOngkir Anda">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Kota Asal Pengiriman</label>
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <input type="text" id="originCitySearch" placeholder="Cari nama kota..." class="form-control" style="flex:1;" autocomplete="off" oninput="searchOriginCity(this.value)" value="<?= htmlspecialchars(getSetting('rajaongkir_origin_city_name', '')) ?>">
                                    <input type="hidden" name="rajaongkir_origin_city_id" id="originCityId" value="<?= (int)getSetting('rajaongkir_origin_city_id', 0) ?>">
                                    <input type="hidden" name="rajaongkir_origin_city_name" id="originCityName" value="<?= htmlspecialchars(getSetting('rajaongkir_origin_city_name', '')) ?>">
                                </div>
                                <div id="originCitySuggestions" style="position:relative;"></div>
                                <small class="text-muted" style="display:block; margin-top:8px;">
                                    ID saat ini: <strong><span id="displayOriginId"><?= htmlspecialchars(getSetting('rajaongkir_origin_city_id', '0')) ?></span></strong> &bull; Ketik nama kota, kecamatan, atau <strong>kode pos</strong> untuk mencari.
                                </small>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top:4px;">
                        Simpan API
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Kolom 3 -->
    <div class="settings-col col-3">
        <!-- Roles Checklist -->
        <form method="POST" action="">
            <input type="hidden" name="action_type" value="roles">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Manajemen Hak Akses (Role Permissions)</h3>
                </div>
                <div class="card-body">
                    <div style="background: var(--gray-50); border: 1.5px solid var(--border-color); border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 0.8125rem; color: var(--gray-600); line-height: 1.5;">
                        💡 <strong>Hak Akses Pemilik (Owner):</strong> Sebagai keputusan tertinggi, pengguna dengan role Pemilik selalu memiliki akses penuh ke seluruh modul sistem dan tidak dapat dinonaktifkan atau dibatasi.
                    </div>

                    <div class="roles-grid">
                        
                        <?php foreach ($editableRoles as $role): 
                            $rId = $role['id'];
                            $rName = htmlspecialchars($role['role_name']);
                            // Pick a color based on role_id
                            $headerColor = $rId == 2 ? 'var(--primary-900)' : ($rId == 3 ? 'var(--primary-600)' : 'var(--gold-600)');
                            $icon = $rId == 2 ? '👑' : ($rId == 3 ? '💵' : '👤');
                        ?>
                        <div style="background: #fff; border: 1.5px solid var(--border-color); border-radius: 12px; overflow: hidden;">
                            <div style="background: <?= $headerColor ?>; color: #fff; padding: 12px; font-weight: 700; font-size: 0.9375rem; text-align: center;">
                                <?= $icon ?> <?= $rName ?>
                            </div>
                            <div style="padding: 16px; display: flex; flex-direction: column; gap: 12px;">
                                <?php foreach ($allAvailablePermissions as $permKey => $permLabel): 
                                    $isChecked = in_array($permKey, $rolePermissions[$rId] ?? []) ? 'checked' : '';
                                ?>
                                <label class="form-check" style="font-size: 0.8125rem;">
                                    <input type="checkbox" name="permissions[<?= $rId ?>][]" value="<?= htmlspecialchars($permKey) ?>" <?= $isChecked ?>>
                                    <span><?= htmlspecialchars($permLabel) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                    </div>

                    <div style="text-align: right; margin-top: 24px;">
                        <button type="submit" class="btn btn-primary btn-lg">
                            Simpan Hak Akses
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>

<script>
// =============================================
// Pencarian Kota Asal (RajaOngkir) di Pengaturan
// =============================================
var allCities = [];

// function loadCities() dihapus karena sekarang pencarian dinamis
function loadCities() {}

function searchOriginCity(query) {
    var box = document.getElementById('originCitySuggestions');
    if (!query || query.length < 2) { box.innerHTML = ''; return; }

    fetch('<?= BASE_URL ?>/api/ongkir.php?action=search_city&q=' + encodeURIComponent(query))
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data || !data.data.length) {
                box.innerHTML = ''; 
                return; 
            }
            
            var html = '<div style="position:absolute;left:0;right:0;top:2px;background:#fff;border:1px solid var(--border-color);border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:9999;overflow:hidden;">';
            data.data.forEach(c => {
                var displayName = c.type ? (c.type + ' ' + c.city_name) : c.city_name;
                var provinceStr = c.province ? ' &bull; ' + c.province : '';
                html += '<div onclick="selectOriginCity(' + c.city_id + ', \'' + displayName.replace(/'/g, "\\'") + '\')" '
                      + 'style="padding:10px 14px;cursor:pointer;font-size:0.875rem;border-bottom:1px solid var(--gray-100);" '
                      + 'onmouseover="this.style.background=\'#f0fdf4\'" onmouseout="this.style.background=\'#fff\'">'
                      + '<strong>' + displayName + '</strong>'
                      + '<span style="color:var(--gray-500);font-size:0.75rem;">' + provinceStr + ' &bull; ID: ' + c.city_id + '</span>'
                      + '</div>';
            });
            html += '</div>';
            box.innerHTML = html;
        })
        .catch(() => { box.innerHTML = ''; });
}

function selectOriginCity(id, name) {
    document.getElementById('originCitySearch').value = name;
    document.getElementById('originCityId').value = id;
    document.getElementById('originCityName').value = name;
    document.getElementById('displayOriginId').textContent = id;
    document.getElementById('originCitySuggestions').innerHTML = '';
}

// Tutup saran jika klik di luar
document.addEventListener('click', function(e) {
    var box = document.getElementById('originCitySuggestions');
    var input = document.getElementById('originCitySearch');
    if (box && input && !box.contains(e.target) && e.target !== input) {
        box.innerHTML = '';
    }
});

// Load daftar kota saat checkbox ongkir aktif dicentang
var shippingCheckbox = document.getElementById('enableShippingApi');
if (shippingCheckbox) {
    if (shippingCheckbox.checked) loadCities();
    shippingCheckbox.addEventListener('change', function() {
        if (this.checked && allCities.length === 0) loadCities();
    });
}
</script>
