<?php
/**
 * Kasir Ibtidaiyah - Impor Massal Harga Bertingkat & Tipe Pelanggan
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin']);

$db = Database::conn();
$pageTitle = 'Impor Harga';

// HANDLE MANAJEMEN TIPE HARGA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_price_type') {
        $name = sanitize($_POST['price_type_name'] ?? '');
        if ($name) {
            $stmt = $db->prepare("INSERT IGNORE INTO price_types (name) VALUES (?)");
            $stmt->execute([$name]);
            flashMessage('success', 'Tipe harga berhasil ditambahkan.');
            logActivity('Add', 'Tipe Harga', 'Menambahkan tipe harga: ' . $name);
        }
        redirect(BASE_URL . '/admin/import_prices.php');
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_price_type') {
        $id = (int)$_POST['price_type_id'];
        if ($id === 1 || $id === 2) {
            flashMessage('error', 'Tipe harga bawaan sistem tidak dapat dihapus.');
        } else {
            $stmt = $db->prepare("SELECT name FROM price_types WHERE id = ?");
            $stmt->execute([$id]);
            $pt = $stmt->fetch();
            if ($pt) {
                $db->prepare("UPDATE customers SET price_type_id = 1 WHERE price_type_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM tiered_prices WHERE price_type_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM price_types WHERE id = ?")->execute([$id]);
                flashMessage('success', 'Tipe harga berhasil dihapus beserta data terkait.');
                logActivity('Delete', 'Tipe Harga', 'Menghapus tipe harga: ' . $pt['name']);
            }
        }
        redirect(BASE_URL . '/admin/import_prices.php');
    }
}

// 1. DOWNLOAD TEMPLATE CSV
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=template_harga_bertingkat_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    // Tambahkan BOM untuk kompabilitas Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Ambil semua tipe harga aktif
    $priceTypes = $db->query("SELECT * FROM price_types ORDER BY id")->fetchAll();
    
    // Tulis Header
    $headers = ['SKU Induk', 'Nama Produk', 'Kategori', 'Label Harga', 'Min. Qty'];
    foreach ($priceTypes as $pt) {
        $headers[] = ($pt['name'] === 'Umum') ? 'Harga Umum' : 'Harga ' . $pt['name'];
    }
    fputcsv($output, $headers);
    
    // Ambil data produk (SKU Induk)
    $stmt = $db->query("
        SELECT p.id as product_id, p.sku, p.name as product_name, c.name as category, p.base_price
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY p.name
    ");
    $products = $stmt->fetchAll();
    
    // Ambil seluruh harga bertingkat yang ada di database (group by product_id)
    $stmt = $db->query("
        SELECT p.id as product_id, tp.label, tp.min_qty, tp.price_type_id, tp.selling_price 
        FROM tiered_prices tp
        JOIN product_variations pv ON tp.product_variation_id = pv.id
        JOIN products p ON pv.product_id = p.id
    ");
    $allTiers = [];
    while ($row = $stmt->fetch()) {
        $key = $row['product_id'] . '_' . $row['min_qty'];
        if (!isset($allTiers[$key])) {
            $allTiers[$key] = [
                'label' => $row['label'],
                'min_qty' => $row['min_qty'],
                'prices' => []
            ];
        }
        $allTiers[$key]['prices'][$row['price_type_id']] = $row['selling_price'];
    }
    
    // Tulis data per baris
    foreach ($products as $p) {
        // Cari tier yang terkait dengan produk ini
        $prodTiers = [];
        foreach ($allTiers as $key => $t) {
            $parts = explode('_', $key);
            if ($parts[0] == $p['product_id']) {
                $prodTiers[] = $t;
            }
        }
        
        // Jika tidak ada data harga bertingkat sama sekali, buat baris default sebagai contoh (Ecer, Grosir1, Partai)
        if (empty($prodTiers)) {
            $prodTiers = [
                [
                    'label' => 'Ecer',
                    'min_qty' => 1,
                    'prices' => []
                ],
                [
                    'label' => 'Grosir1',
                    'min_qty' => 10,
                    'prices' => []
                ],
                [
                    'label' => 'Partai',
                    'min_qty' => 50,
                    'prices' => []
                ]
            ];
        }
        
        // Urutkan tier berdasarkan jumlah minimal Qty
        usort($prodTiers, function($a, $b) {
            return $a['min_qty'] <=> $b['min_qty'];
        });
        
        foreach ($prodTiers as $t) {
            $row = [$p['sku'], $p['product_name'], $p['category'], $t['label'], $t['min_qty']];
            foreach ($priceTypes as $pt) {
                $row[] = isset($t['prices'][$pt['id']]) ? (float)$t['prices'][$pt['id']] : '';
            }
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

// 2. PROSES UNGGAH & IMPORT CSV
$importSuccess = null;
$importErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $importErrors[] = 'Gagal mengunggah file.';
    } else {
        $filename = $file['tmp_name'];
        if (($handle = fopen($filename, 'r')) !== FALSE) {
            // Ambil data tipe harga dari DB untuk pemetaan kolom
            $priceTypes = $db->query("SELECT * FROM price_types ORDER BY id")->fetchAll();
            $priceTypeMap = [];
            foreach ($priceTypes as $pt) {
                $priceTypeMap[strtolower(trim($pt['name']))] = $pt['id'];
            }
            
            // Baca baris pertama untuk deteksi delimiter (koma atau titik koma)
            $firstLine = fgets($handle);
            $delimiter = ',';
            if ($firstLine !== false) {
                $delimiter = strpos($firstLine, ';') !== false ? ';' : ',';
                rewind($handle);
            }
            
            // Baca Header
            $headers = fgetcsv($handle, 10000, $delimiter);
            
            if ($headers === FALSE) {
                $importErrors[] = 'File CSV kosong atau tidak valid.';
            } else {
                // Hapus BOM (Byte Order Mark) UTF-8 dari elemen pertama jika ada
                $headers[0] = preg_replace('/^[\xef\xbb\xbf]+/', '', $headers[0]);
                
                // Temukan indeks kolom wajib
                $skuIndex = array_search('SKU Induk', $headers);
                if ($skuIndex === FALSE) {
                    $skuIndex = array_search('SKU Varian', $headers); // fallback format lama
                }
                $minQtyIndex = array_search('Min. Qty', $headers);
                $labelIndex = array_search('Label Harga', $headers);
                
                if ($skuIndex === FALSE || $minQtyIndex === FALSE || $labelIndex === FALSE) {
                    $importErrors[] = 'Kolom "SKU Induk", "Min. Qty", dan "Label Harga" wajib ada pada header CSV.';
                } else {
                    // Petakan kolom tipe harga di CSV ke price_type_id
                    $columnPriceTypeMap = [];
                    foreach ($priceTypes as $ptInfo) {
                        $expectedHeader = ($ptInfo['name'] === 'Umum') ? 'Harga Umum' : 'Harga ' . $ptInfo['name'];
                        $idx = array_search($expectedHeader, $headers);
                        if ($idx !== FALSE) {
                            $columnPriceTypeMap[$idx] = $ptInfo;
                        }
                    }
                    
                    if (empty($columnPriceTypeMap)) {
                        $importErrors[] = 'Tidak ditemukan kolom tipe harga yang cocok di CSV (misal: Harga Umum, Harga Ranting).';
                    } else {
                        try {
                            $db->beginTransaction();
                            
                            $successCount = 0;
                            $rowCount = 0;
                            
                            // Siapkan kueri
                            $stmtGetProd = $db->prepare("SELECT id FROM products WHERE sku = ?");
                            $stmtGetVars = $db->prepare("SELECT id FROM product_variations WHERE product_id = ?");
                            
                            $stmtCheckTier = $db->prepare("SELECT id FROM tiered_prices WHERE product_variation_id = ? AND min_qty = ? AND price_type_id = ?");
                            $stmtInsertTier = $db->prepare("INSERT INTO tiered_prices (product_variation_id, label, price_type_id, min_qty, selling_price) VALUES (?, ?, ?, ?, ?)");
                            $stmtUpdateTier = $db->prepare("UPDATE tiered_prices SET selling_price = ?, label = ? WHERE id = ?");
                            
                            // Untuk update product_prices (harga per produk)
                            $stmtCheckProdPrice = $db->prepare("SELECT id FROM product_prices WHERE product_id = ? AND label = ? AND price_type_id = ?");
                            $stmtInsertProdPrice = $db->prepare("INSERT INTO product_prices (product_id, label, price_type_id, min_qty, selling_price) VALUES (?, ?, ?, ?, ?)");
                            $stmtUpdateProdPrice = $db->prepare("UPDATE product_prices SET selling_price = ? WHERE id = ?");
                            
                            while (($row = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                                $rowCount++;
                                if (empty($row) || !isset($row[$skuIndex]) || trim($row[$skuIndex]) === '') {
                                    continue;
                                }
                                
                                $sku = trim($row[$skuIndex]);
                                $minQty = isset($row[$minQtyIndex]) ? (int)$row[$minQtyIndex] : 1;
                                if ($minQty < 1) $minQty = 1;
                                $label = isset($row[$labelIndex]) ? trim($row[$labelIndex]) : 'Ecer';
                                if ($minQty == 1) $label = 'Ecer';
                                elseif (empty($label)) $label = 'Grosir1';
                                
                                // Cari product_id
                                $stmtGetProd->execute([$sku]);
                                $prodId = $stmtGetProd->fetchColumn();
                                
                                if (!$prodId) {
                                    // coba cari di variasi sebagai fallback
                                    $stmtGetVar = $db->prepare("SELECT id, product_id FROM product_variations WHERE sku = ?");
                                    $stmtGetVar->execute([$sku]);
                                    $varRow = $stmtGetVar->fetch(PDO::FETCH_ASSOC);
                                    if ($varRow) {
                                        $prodId = $varRow['product_id'];
                                    }
                                }
                                
                                if (!$prodId) {
                                    $importErrors[] = "Baris {$rowCount}: SKU Induk '{$sku}' tidak ditemukan.";
                                    continue;
                                }
                                
                                // Ambil semua variation_id untuk product_id ini
                                $stmtGetVars->execute([$prodId]);
                                $variationIds = $stmtGetVars->fetchAll(PDO::FETCH_COLUMN);
                                
                                if (empty($variationIds)) {
                                    continue;
                                }
                                
                                // Proses update harga untuk tiap kolom tipe harga yang terpetakan
                                foreach ($columnPriceTypeMap as $colIndex => $ptInfo) {
                                    if (!isset($row[$colIndex]) || trim($row[$colIndex]) === '') {
                                        continue;
                                    }
                                    
                                    $price = (float)str_replace(['.', ','], ['', '.'], trim($row[$colIndex]));
                                    if ($price < 0) {
                                        $importErrors[] = "Baris {$rowCount}: Harga tidak boleh negatif.";
                                        continue;
                                    }
                                    
                                    $ptId = $ptInfo['id'];
                                    
                                    // Terapkan ke semua varian produk ini
                                    foreach ($variationIds as $varId) {
                                        // Cek apakah data harga untuk kombinasi (varian + min_qty + tipe_harga) ini sudah ada
                                        $stmtCheckTier->execute([$varId, $minQty, $ptId]);
                                        $tierId = $stmtCheckTier->fetchColumn();
                                        
                                        if ($tierId) {
                                            $stmtUpdateTier->execute([$price, $label, $tierId]);
                                        } else {
                                            $stmtInsertTier->execute([$varId, $label, $ptId, $minQty, $price]);
                                        }
                                    }
                                    
                                    // Juga update product_prices untuk harga per produk (tidak per varian)
                                    $stmtCheckProdPrice->execute([$prodId, $label, $ptId]);
                                    $prodPriceId = $stmtCheckProdPrice->fetchColumn();
                                    
                                    if ($prodPriceId) {
                                        $stmtUpdateProdPrice->execute([$price, $prodPriceId]);
                                    } else {
                                        $stmtInsertProdPrice->execute([$prodId, $label, $ptId, $minQty, $price]);
                                    }
                                    
                                    $successCount++;
                                }
                            }
                            
                            $db->commit();
                            $importSuccess = "Berhasil memproses impor harga bertingkat. Sebanyak {$successCount} data harga diperbarui.";
                            logActivity('Impor', 'Harga', "Memproses {$rowCount} baris CSV");
                        } catch (Exception $e) {
                            $db->rollBack();
                            $importErrors[] = 'Terjadi kesalahan database: ' . $e->getMessage();
                        }
                    }
                }
            }
            fclose($handle);
        } else {
            $importErrors[] = 'Gagal membuka file.';
        }
    }
}

$priceTypes = $db->query("SELECT * FROM price_types ORDER BY id")->fetchAll();

$breadcrumbs = [
    ['label' => 'Produk', 'url' => BASE_URL . '/admin/products.php'],
    ['label' => 'Impor Tipe Harga Bertingkat']
];
include INCLUDES_PATH . '/header.php';
?>

<div class="container">
    <div class="toolbar">
        <div style="flex:1;"></div>
        <a href="?download_template=1" class="btn btn-outline" style="gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Ekspor Harga
        </a>
    </div>

    <?php if ($importSuccess): ?>
        <div class="alert alert-success" style="margin-bottom: 24px;">
            <div class="alert-icon">✓</div>
            <div class="alert-message"><?= $importSuccess ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($importErrors)): ?>
        <div class="alert alert-danger" style="margin-bottom: 24px;">
            <div class="alert-icon">⚠️</div>
            <div class="alert-message">
                <strong>Beberapa baris gagal diimpor:</strong>
                <ul style="margin: 8px 0 0 20px; padding: 0;">
                    <?php foreach (array_slice($importErrors, 0, 10) as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($importErrors) > 10): ?>
                        <li>... dan <?= count($importErrors) - 10 ?> error lainnya.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 24px;">
        <!-- Card Panduan -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📖 Petunjuk Impor Harga Bertingkat</h3>
            </div>
            <div class="card-body">
                <ol style="margin: 0; padding-left: 20px; line-height: 1.6;">
                    <li>Unduh template CSV terlebih dahulu menggunakan tombol <strong>Ekspor Harga</strong> di kanan atas.</li>
                    <li>Berkas CSV memuat kolom data produk: <strong>SKU Induk, Kategori, Label Harga, Min. Qty</strong>, serta kolom harga untuk tiap tipe pelanggan (contoh: <em>Harga Umum, Harga Ranting</em>).</li>
                    Kolom: <code>SKU Induk | Nama Produk | Kategori | Label Harga | Min. Qty | Harga Umum ...</code><br>
                    <li>Anda diperbolehkan menambah baris baru di Excel/Google Sheets untuk mendefinisikan tier baru dengan Qty Minimal yang berbeda.</li>
                    <li>Jangan mengubah data pada kolom <strong>SKU Induk</strong> yang sudah ada. Simpan berkas kembali dalam format <strong>CSV (Comma delimited)</strong>.</li>
                    <li>Unggah berkas tersebut pada card di samping untuk memperbarui database secara instan.</li>
                </ol>
            </div>
        </div>

        <!-- Card Form Unggah -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📤 Unggah Berkas Harga</h3>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 20px;">
                    <div class="form-group">
                        <label class="form-label">Berkas CSV Template Harga</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required style="padding: 10px;">
                        <span class="form-hint">Hanya menerima format berkas .csv bertingkat yang diekspor dari sistem ini.</span>
                    </div>
                    <button type="submit" class="btn btn-primary" style="align-self: flex-start; gap: 8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Mulai Impor & Perbarui
                    </button>
                </form>
            </div>
        </div>
        </div>
    </div>

    <!-- Manajemen Tipe Harga -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header">
            <h3 class="card-title">🏷️ Manajemen Tipe Harga</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start;">
                <!-- Form Tambah -->
                <div style="flex: 1; min-width: 300px;">
                    <form method="POST" action="" style="background: var(--gray-50); padding: 16px; border-radius: 8px; border: 1px solid var(--border-color);">
                        <input type="hidden" name="action" value="add_price_type">
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label">Nama Tipe Harga Baru</label>
                            <input type="text" name="price_type_name" class="form-control" required placeholder="Contoh: Ranting VIP">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Tambah Tipe Harga</button>
                </div>
                
                <!-- Tabel Tipe Harga -->
                <div class="table-responsive" style="flex: 1; min-width: 300px; border: 1px solid var(--border-color); border-radius: 8px;">
                    <table class="table" style="margin-bottom: 0;">
                        <thead style="background: var(--gray-50);">
                            <tr>
                                <th>ID</th>
                                <th>Nama Tipe Harga</th>
                                <th width="100">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priceTypes as $pt): ?>
                            <tr>
                                <td><?= $pt['id'] ?></td>
                                <td>
                                    <?= htmlspecialchars($pt['name']) ?>
                                    <?php if ($pt['id'] == 1 || $pt['id'] == 2): ?>
                                        <span class="badge badge-primary" style="background: var(--primary-100); color: var(--primary-700); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; margin-left: 8px;">Bawaan Sistem</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($pt['id'] != 1 && $pt['id'] != 2): ?>
                                    <form method="POST" action="" onsubmit="return confirm('Yakin ingin menghapus tipe harga ini? Peringatan: Semua harga bertingkat terkait akan dihapus secara paksa, dan pelanggan yang menggunakan tipe harga ini akan otomatis dialihkan ke tipe Umum.');" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_price_type">
                                        <input type="hidden" name="price_type_id" value="<?= $pt['id'] ?>">
                                        <button type="submit" class="btn btn-danger" title="Hapus" style="padding: 2px 6px; line-height: 1; min-height: 20px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <span style="color: var(--gray-400); font-size: 0.8125rem;">Dilindungi</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
