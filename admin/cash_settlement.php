<?php
/**
 * Kasir Ibtidaiyah - Setoran Penjualan (Cash Settlement)
 * Rekap shift / tutup toko dengan blind close
 */
require_once __DIR__ . '/../config/app.php';
requireRole(['Pemilik', 'Admin', 'Kasir']);

$db = Database::conn();
$pageTitle = 'Setoran Penjualan';
$breadcrumbs = [['label' => 'Setoran Penjualan']];

$userId = $_SESSION['user_id'];

// =============================================
// HANDLE POST ACTIONS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Open new shift
    if ($action === 'open_shift') {
        // Check if there's already an open shift
        $stmt = $db->prepare("SELECT id FROM cash_settlements WHERE cashier_id = ? AND status = 'Open'");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) {
            flashMessage('warning', 'Anda masih memiliki shift yang belum ditutup.');
        } else {
            $stmt = $db->prepare("INSERT INTO cash_settlements (cashier_id, shift_start, status) VALUES (?, NOW(), 'Open')");
            $stmt->execute([$userId]);
            flashMessage('success', 'Shift berhasil dibuka.');
            logActivity('Buka Shift', 'Settlement', 'Shift dibuka');
        }
        redirect(BASE_URL . '/admin/cash_settlement.php');
    }
    
    // Close shift (blind close)
    if ($action === 'close_shift') {
        $settlementId = (int)$_POST['settlement_id'];
        $actualCash = (float)str_replace(['.', ','], '', $_POST['actual_cash']);
        $notes = sanitize($_POST['notes'] ?? '');
        
        $actualTransfer = 0;
        $actualEmaal = 0;
        $actualBmt = 0;
        
        if (isset($_POST['actual_noncash']) && is_array($_POST['actual_noncash'])) {
            $nonCashNotes = [];
            foreach ($_POST['actual_noncash'] as $method => $amountStr) {
                $amount = (float)str_replace(['.', ','], '', $amountStr);
                $nonCashNotes[] = "- $method: Rp " . number_format($amount, 0, ',', '.');
                
                if (strpos($method, 'E-Wallet') === 0 || in_array($method, ['Emaal', 'Bank Emaal'])) {
                    $actualEmaal += $amount;
                } elseif ($method === 'QRIS' || in_array($method, ['BSI', 'Bank BSI'])) {
                    $actualBmt += $amount;
                } else {
                    $actualTransfer += $amount;
                }
            }
            if (!empty($nonCashNotes)) {
                $notes .= "\n\nRincian Aktual Non-Tunai:\n" . implode("\n", $nonCashNotes);
            }
        }

        
        try {
            $db->beginTransaction();
            
            // Get settlement
            $stmt = $db->prepare("SELECT * FROM cash_settlements WHERE id = ? AND cashier_id = ? AND status = 'Open'");
            $stmt->execute([$settlementId, $userId]);
            $settlement = $stmt->fetch();
            
            if (!$settlement) {
                throw new Exception('Shift tidak ditemukan atau sudah ditutup.');
            }
            
            $shiftStart = $settlement['shift_start'];
            
            // Calculate expected amounts from transactions during this shift
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN p.payment_method = 'Tunai' THEN p.amount ELSE 0 END), 0) as cash_total,
                    COALESCE(SUM(CASE WHEN p.payment_method NOT IN ('Tunai', 'Emaal', 'Bank Emaal', 'BSI', 'Bank BSI') THEN p.amount ELSE 0 END), 0) as transfer_total,
                    COALESCE(SUM(CASE WHEN p.payment_method IN ('Emaal', 'Bank Emaal') THEN p.amount ELSE 0 END), 0) as emaal_total,
                    COALESCE(SUM(CASE WHEN p.payment_method IN ('BSI', 'Bank BSI') THEN p.amount ELSE 0 END), 0) as bsi_total,
                    COUNT(DISTINCT p.sale_id) as tx_count
                FROM payments p
                WHERE p.payment_type = 'Incoming' 
                  AND p.payment_date >= ?
                  AND p.created_by = ?
            ");
            $stmt->execute([$shiftStart, $userId]);
            $totals = $stmt->fetch();
            
            // Calculate total refunds during shift
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(p.amount), 0) as refund_total
                FROM payments p
                WHERE p.payment_type = 'Outgoing'
                  AND p.payment_date >= ?
                  AND p.created_by = ?
            ");
            $stmt->execute([$shiftStart, $userId]);
            $refundTotal = (float)$stmt->fetchColumn();
            
            $expectedCash = (float)$totals['cash_total'];
            

            $stmt = $db->prepare("
                UPDATE cash_settlements SET 
                    shift_end = NOW(),
                    expected_cash = ?,
                    actual_cash = ?,
                    total_refund = ?,
                    total_transfer = ?,
                    actual_transfer = ?,
                    total_emaal = ?,
                    actual_emaal = ?,
                    total_bsi = ?,
                    actual_bsi = ?,
                    total_transactions = ?,
                    status = 'Closed',
                    notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $expectedCash,
                $actualCash,
                $refundTotal,
                (float)$totals['transfer_total'],
                $actualTransfer,
                (float)$totals['emaal_total'],
                $actualEmaal,
                (float)$totals['bsi_total'],
                $actualBmt,
                (int)$totals['tx_count'],
                $notes,
                $settlementId
            ]);
            
            $db->commit();
            
            $diff = $actualCash - ($expectedCash - $refundTotal);
            $diffLabel = $diff >= 0 ? "lebih Rp " . number_format(abs($diff), 0, ',', '.') : "kurang Rp " . number_format(abs($diff), 0, ',', '.');
            flashMessage('success', "Shift ditutup. Selisih kas: $diffLabel");
            logActivity('Tutup Shift', 'Settlement', "Settlement #$settlementId, Selisih: $diff");
            
        } catch (Exception $e) {
            $db->rollBack();
            flashMessage('error', $e->getMessage());
        }
        
        redirect(BASE_URL . '/admin/cash_settlement.php');
    }
}

// =============================================
// GET CURRENT OPEN SHIFT
// =============================================
$stmt = $db->prepare("SELECT * FROM cash_settlements WHERE cashier_id = ? AND status = 'Open' LIMIT 1");
$stmt->execute([$userId]);
$openShift = $stmt->fetch();

// Live summary for open shift
$liveData = null;
if ($openShift) {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN p.payment_method = 'Tunai' AND p.payment_type = 'Incoming' THEN p.amount ELSE 0 END), 0) as cash_in,
            COALESCE(SUM(CASE WHEN p.payment_method NOT IN ('Tunai', 'QRIS', 'BSI', 'Bank BSI', 'Emaal', 'Bank Emaal') AND p.payment_method NOT LIKE 'E-Wallet%' AND p.payment_type = 'Incoming' THEN p.amount ELSE 0 END), 0) as transfer_in,
            COALESCE(SUM(CASE WHEN (p.payment_method LIKE 'E-Wallet%' OR p.payment_method IN ('Emaal', 'Bank Emaal')) AND p.payment_type = 'Incoming' THEN p.amount ELSE 0 END), 0) as emaal_in,
            COALESCE(SUM(CASE WHEN (p.payment_method = 'QRIS' OR p.payment_method IN ('BSI', 'Bank BSI')) AND p.payment_type = 'Incoming' THEN p.amount ELSE 0 END), 0) as bsi_in,
            COALESCE(SUM(CASE WHEN p.payment_type = 'Outgoing' THEN p.amount ELSE 0 END), 0) as refund_out,
            COUNT(DISTINCT CASE WHEN p.payment_type = 'Incoming' THEN p.sale_id END) as tx_count
        FROM payments p
        WHERE p.payment_date >= ?
          AND p.created_by = ?
    ");
    $stmt->execute([$openShift['shift_start'], $userId]);
    $liveData = $stmt->fetch();
    
    // Get breakdown by specific payment method
    $stmt = $db->prepare("
        SELECT payment_method, SUM(amount) as method_total
        FROM payments 
        WHERE payment_type = 'Incoming' 
          AND payment_date >= ?
          AND created_by = ?
        GROUP BY payment_method
    ");
    $stmt->execute([$openShift['shift_start'], $userId]);
    $methodTotals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// History
$stmt = $db->prepare("
    SELECT cs.*, u.full_name as cashier_name
    FROM cash_settlements cs
    JOIN users u ON cs.cashier_id = u.id
    WHERE cs.status = 'Closed'
    ORDER BY cs.shift_end DESC
    LIMIT 30
");
$stmt->execute();
$history = $stmt->fetchAll();

include INCLUDES_PATH . '/header.php';
?>

<style>
/* Tab styles */
.settlement-tabs {
    display: flex;
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 20px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.settlement-tab {
    padding: 12px 16px;
    cursor: pointer;
    font-weight: 600;
    color: var(--gray-500);
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
    white-space: nowrap;
    flex-shrink: 0;
}
.settlement-tab:hover {
    color: var(--gray-800);
}
.settlement-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}
.settlement-stat-card {
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    padding: 14px 16px;
    text-align: center;
    transition: all 0.2s;
}
.settlement-stat-card:hover {
    box-shadow: var(--shadow-sm);
    border-color: var(--primary-300);
}
.settlement-stat-label {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-bottom: 4px;
}
.settlement-stat-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--gray-800);
}

/* Payment methods grid */
.payment-methods-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
}

@media (max-width: 1024px) {
    .payment-methods-grid {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 10px;
    }
}

@media (max-width: 768px) {
    .settlement-tabs {
        padding: 0 -16px;
        margin: 0 -16px 20px -16px;
    }
    .settlement-tab {
        padding: 10px 12px;
        font-size: 0.9rem;
    }
    
    .stats-grid {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .settlement-stat-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        text-align: left;
    }
    .settlement-stat-label {
        font-size: 0.875rem;
        margin-bottom: 0;
    }
    
    .form-grid-2 {
        display: flex !important;
        flex-direction: column;
        gap: 20px;
    }
    
    .payment-methods-grid {
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 8px;
    }
    
    .table-responsive {
        font-size: 0.85rem;
    }
    .table thead {
        display: none;
    }
    .table tr {
        display: block;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        margin-bottom: 12px;
        padding: 12px;
    }
    .table td {
        display: block;
        text-align: left;
        padding: 8px 0;
        border: none;
    }
    .table td:before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--gray-600);
        display: inline-block;
        width: 100px;
        margin-right: 10px;
    }
}

@media (max-width: 480px) {
    .settlement-tab {
        padding: 8px 10px;
        font-size: 0.85rem;
    }
    
    .settlement-stat-card {
        padding: 10px 12px;
    }
    .settlement-stat-value {
        font-size: 1rem;
    }
    
    .payment-methods-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
    
    .input-group {
        flex-wrap: wrap;
    }
}
</style>

<!-- HEADER ACTIONS (GLOBAL) -->
<div style="display: flex; justify-content: <?= $openShift ? 'flex-end' : 'space-between' ?>; align-items: center; margin-bottom: 20px;">
    <?php if (!$openShift): ?>
    <form method="POST" style="margin: 0;">
        <input type="hidden" name="action" value="open_shift">
        <button type="submit" class="btn btn-primary">Buka Shift Sekarang</button>
    </form>
    <?php endif; ?>
</div>

<!-- TABS NAVIGATION -->
<div class="settlement-tabs">
    <div class="settlement-tab active" onclick="switchSettlementTab('tunai', this)">Setoran Tunai</div>
    <div class="settlement-tab" onclick="switchSettlementTab('nontunai', this)">Setoran Non Tunai</div>
</div>

<!-- TAB: SETORAN TUNAI -->
<div id="tab-tunai" class="tab-content active">
    <?php if ($openShift): ?>
    <!-- ACTIVE SHIFT (TUNAI) -->
    <div class="card" style="border-left: 4px solid var(--success); margin-bottom:20px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3 class="card-title" style="color:var(--success);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Shift Aktif (Tunai)
            </h3>
            <span class="badge badge-success">Dibuka: <?= formatTanggal($openShift['shift_start'], true) ?></span>
        </div>
        <div class="card-body">
            <div class="stats-grid" style="margin-bottom:20px;">
                <div class="settlement-stat-card" style="background:#f0fdf4; border-color:#86efac;">
                    <div class="settlement-stat-label" style="color:#166534; font-weight:600;">Tunai Masuk</div>
                    <div class="settlement-stat-value" style="color:#14532d;"><?= formatRupiah($liveData['cash_in'] ?? 0) ?></div>
                </div>
                <div class="settlement-stat-card" style="background:#EAF7EE; border-color:#a8e5d8;">
                    <div class="settlement-stat-label" style="color:#0C3B2A; font-weight:600;">Retur/Refund (Semua)</div>
                    <div class="settlement-stat-value" style="color:#0C3B2A;">-<?= formatRupiah($liveData['refund_out'] ?? 0) ?></div>
                </div>
                <div class="settlement-stat-card" style="background:#f0f9ff; border-color:#bae6fd;">
                    <div class="settlement-stat-label" style="color:#0369a1; font-weight:600;">Total Transaksi (Semua)</div>
                    <div class="settlement-stat-value" style="color:#0c4a6e;"><?= $liveData['tx_count'] ?? 0 ?></div>
                </div>
            </div>
            
            <div style="border-top:2px solid var(--border-color); padding-top:20px;">
                <h4 style="margin-bottom:12px;">🔒 Tutup Shift</h4>
                <p style="font-size:0.8125rem; color:var(--gray-500); margin-bottom:16px;">
                    Masukkan jumlah uang fisik yang ada di laci kasir. Ini akan menutup shift untuk <b>semua jenis pembayaran</b> (Tunai & Non Tunai).
                </p>
                <form method="POST" onsubmit="return confirm('Tutup shift ini? Aksi tidak bisa dibatalkan.')">
                    <input type="hidden" name="action" value="close_shift">
                    <input type="hidden" name="settlement_id" value="<?= $openShift['id'] ?>">
                    
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" style="font-size:1.05rem; color:var(--gray-800);">Setor Uang Fisik</label>
                            <div class="input-group" style="max-width: 400px;">
                                <span class="input-group-text" style="font-size:1.25rem; font-weight:bold;">Rp</span>
                                <input type="text" name="actual_cash" class="form-control" 
                                       style="font-size:1.5rem; font-weight:700; text-align:right; height: 50px;" 
                                       placeholder="0" required
                                       oninput="this.value = this.value.replace(/[^\d]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                            </div>
                            <span class="form-hint" style="font-size:0.9rem; margin-top:8px;">Ekspektasi Sistem: <strong style="color:var(--gray-800);"><?= formatRupiah(($liveData['cash_in'] ?? 0) - ($liveData['refund_out'] ?? 0)) ?></strong></span>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" style="font-size:1.05rem; color:var(--gray-800);">Total Non Tunai</label>
                            <div class="input-group" style="max-width: 350px;">
                                <span class="input-group-text" style="font-size:1.15rem; font-weight:bold;">Rp</span>
                                <input type="text" id="total_noncash_display" class="form-control" 
                                       style="font-size:1.4rem; font-weight:700; text-align:right; height: 50px; background:#f8fffb;" 
                                       value="0" readonly>
                                <input type="hidden" name="actual_noncash_total" id="actual_noncash_total" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top:20px;">
                        <h5 style="margin-bottom: 16px; color: var(--gray-800); display: flex; align-items: center; gap: 8px; font-size: 1.1rem;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            Verifikasi Mutasi Non-Tunai
                        </h5>
                            
                            <?php
                            $paymentBanks = [];
                            foreach (['bca'=>'BCA', 'mandiri'=>'Mandiri', 'bni'=>'BNI', 'bri'=>'BRI', 'bsi'=>'BSI', 'emaal'=>'Emaal'] as $code => $name) {
                                if (getSetting("payment_bank_{$code}_account")) $paymentBanks[] = $name;
                            }
                            $paymentEwallets = [];
                            foreach (['dana'=>'Dana', 'ovo'=>'OVO', 'shopeepay'=>'ShopeePay'] as $code => $name) {
                                if (getSetting("payment_ewallet_{$code}")) $paymentEwallets[] = $name;
                            }
                            $hasQris = getSetting('payment_qris_image') ? true : false;
                            
                            $methods = [];
                            foreach ($paymentBanks as $b) $methods[] = "Bank $b";
                            foreach ($paymentEwallets as $e) $methods[] = "E-Wallet $e";
                            if ($hasQris) $methods[] = "QRIS";
                            
                            $activeMethods = [];
                            $inactiveMethods = [];
                            
                            foreach ($methods as $methodName) {
                                $expected = $methodTotals[$methodName] ?? 0;
                                if ($expected > 0) {
                                    $activeMethods[] = ['name' => $methodName, 'expected' => $expected];
                                } else {
                                    $inactiveMethods[] = ['name' => $methodName, 'expected' => 0];
                                }
                            }
                            ?>
                            
                            <?php if (!empty($activeMethods)): ?>
                            <div class="payment-methods-grid" style="margin-bottom: 16px;">
                                <?php foreach ($activeMethods as $m): ?>
                                <div class="form-group" style="margin-bottom: 0; background: white; padding: 12px; border-radius: 8px; border: 1px solid var(--gray-200); box-shadow: var(--shadow-sm);">
                                    <label class="form-label text-bold" style="color: var(--primary); margin-bottom: 8px; font-size: 0.85rem;"><?= htmlspecialchars($m['name']) ?></label>
                                    <div class="input-group">
                                        <span class="input-group-text" style="background:var(--gray-100); font-size:0.9rem;">Rp</span>
                                        <input type="text" name="actual_noncash[<?= $m['name'] ?>]" class="form-control" 
                                               style="font-size:0.95rem; font-weight:600; text-align:right;" 
                                               value="<?= number_format($m['expected'], 0, '', '.') ?>"
                                               oninput="this.value = this.value.replace(/[^\d]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 6px; text-align: right;">Sistem: <?= formatRupiah($m['expected']) ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p style="font-size: 0.95rem; color: var(--gray-500); margin-bottom: 16px; font-style: italic;">
                                Belum ada transaksi non-tunai pada shift ini.
                            </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($inactiveMethods)): ?>
                            <div style="border-top: 1px dashed var(--gray-300); padding-top: 16px;">
                                <button type="button" class="btn btn-sm" style="background: white; border: 1px solid var(--gray-300); color: var(--gray-600); font-weight: 500;" onclick="this.nextElementSibling.style.display='grid'; this.style.display='none';">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Tampilkan Metode Lainnya (Nihil)
                                </button>
                                <div class="payment-methods-grid" style="display:none; margin-top: 12px;">
                                    <?php foreach ($inactiveMethods as $m): ?>
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label class="form-label" style="font-size:0.8rem; color:var(--gray-600); white-space: normal; overflow: visible; text-overflow: clip;" title="<?= $m['name'] ?>"><?= htmlspecialchars($m['name']) ?></label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text" style="background:var(--gray-50); font-size:0.85rem;">Rp</span>
                                            <input type="text" name="actual_noncash[<?= $m['name'] ?>]" class="form-control" 
                                                   style="text-align:right; font-size:0.9rem;" 
                                                   value="0"
                                                   oninput="this.value = this.value.replace(/[^\d]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')">
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:20px;">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Catatan opsional (mis. jika ada selisih)..."></textarea>
                    </div>
                    
                    <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                        <button type="submit" class="btn btn-primary" style="background:var(--danger); border-color:var(--danger);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Tutup Shift & Setor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- HISTORY (TUNAI) -->

    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3 class="card-title">Riwayat Setoran Tunai</h3>
            <div class="toolbar" style="margin-bottom: 0;">
                <?php 
                $importType = 'cash_settlement';
                $hasImport = false;
                $hasExport = true;
                $hasTemplate = false;
                include INCLUDES_PATH . '/import_export_toolbar.php'; 
                ?>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kasir</th>
                            <th>Buka Shift</th>
                            <th>Tutup Shift</th>
                            <th>Ekspektasi Tunai</th>
                            <th>Uang Fisik</th>
                            <th>Retur</th>
                            <th>Selisih</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">Belum ada riwayat setoran</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $h): 
                                $diff = isset($h['cash_difference']) ? (float)$h['cash_difference'] : ($h['actual_cash'] - ($h['expected_cash'] - $h['total_refund']));
                            ?>
                                <tr>
                                    <td data-label="Kasir" class="text-bold"><?= htmlspecialchars($h['cashier_name']) ?></td>
                                    <td data-label="Buka Shift" class="text-sm"><?= formatTanggal($h['shift_start'], true) ?></td>
                                    <td data-label="Tutup Shift" class="text-sm"><?= formatTanggal($h['shift_end'], true) ?></td>
                                    <td data-label="Ekspektasi"><?= formatRupiah($h['expected_cash']) ?></td>
                                    <td data-label="Uang Fisik" class="text-bold"><?= formatRupiah($h['actual_cash']) ?></td>
                                    <td data-label="Retur" style="color:var(--danger);"><?= formatRupiah($h['total_refund']) ?></td>
                                    <td data-label="Selisih">
                                        <?php if ($diff > 0): ?>
                                            <span class="badge badge-success">+<?= formatRupiah($diff) ?></span>
                                        <?php elseif ($diff < 0): ?>
                                            <span class="badge badge-danger">-<?= formatRupiah(abs($diff)) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-primary">Pas</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Aksi" style="white-space: nowrap;">
                                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Pemilik'): ?>
                                        <a href="edit_settlement.php?id=<?= $h['id'] ?>" class="btn btn-sm btn-outline btn-icon" title="Edit Setoran">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline btn-icon" title="Cetak Slip" 
                                                onclick="printSettlement(<?= $h['id'] ?>)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TAB: SETORAN NON TUNAI -->
<div id="tab-nontunai" class="tab-content">
    <?php if ($openShift): ?>
    <!-- ACTIVE SHIFT (NON TUNAI) -->
    <div class="card" style="border-left: 4px solid var(--info); margin-bottom:20px;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h3 class="card-title" style="color:var(--info);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Shift Aktif (Non Tunai)
            </h3>
            <span class="badge badge-info">Dibuka: <?= formatTanggal($openShift['shift_start'], true) ?></span>
        </div>
        <div class="card-body">
            <div class="stats-grid" style="margin-bottom:0;">
                <?php foreach ($methodTotals as $method => $amount): ?>
                <div class="settlement-stat-card" style="background:#f0f9ff; border-color:#bae6fd;">
                    <div class="settlement-stat-label" style="color:#0369a1; font-weight:600;"><?= htmlspecialchars($method) ?></div>
                    <div class="settlement-stat-value" style="color:#0c4a6e;"><?= formatRupiah($amount) ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($methodTotals)): ?>
                <div class="settlement-stat-card" style="background:#f0f9ff; border-color:#bae6fd; grid-column:1/-1;">
                    <div class="settlement-stat-label" style="color:#0369a1; font-weight:600;">Belum ada transaksi non tunai</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- HISTORY (NON TUNAI) -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Riwayat Setoran Non Tunai</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kasir</th>
                            <th>Buka Shift</th>
                            <th>Tutup Shift</th>
                            <th>Transfer Bank<br><span style="font-size:0.75rem; font-weight:normal;">Ekspektasi / Aktual</span></th>
                            <th>E-Wallet<br><span style="font-size:0.75rem; font-weight:normal;">Ekspektasi / Aktual</span></th>
                            <th>QRIS<br><span style="font-size:0.75rem; font-weight:normal;">Ekspektasi / Aktual</span></th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Belum ada riwayat setoran non tunai</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td data-label="Kasir" class="text-bold"><?= htmlspecialchars($h['cashier_name']) ?></td>
                                    <td data-label="Buka Shift" class="text-sm"><?= formatTanggal($h['shift_start'], true) ?></td>
                                    <td data-label="Tutup Shift" class="text-sm"><?= formatTanggal($h['shift_end'], true) ?></td>
                                    <td data-label="Transfer Bank">
                                        <div style="font-size:0.875rem; color:var(--gray-500);"><?= formatRupiah($h['total_transfer']) ?></div>
                                        <div class="text-bold"><?= formatRupiah($h['actual_transfer'] ?? $h['total_transfer']) ?></div>
                                        <?php $diffT = ($h['actual_transfer'] ?? $h['total_transfer']) - $h['total_transfer']; ?>
                                        <?php if ($diffT != 0): ?>
                                            <span class="badge badge-<?= $diffT > 0 ? 'success' : 'danger' ?>" style="font-size:0.7rem; padding:2px 4px;"><?= $diffT > 0 ? '+' : '' ?><?= formatRupiah($diffT) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="E-Wallet">
                                        <div style="font-size:0.875rem; color:var(--gray-500);"><?= formatRupiah($h['total_emaal']) ?></div>
                                        <div class="text-bold"><?= formatRupiah($h['actual_emaal'] ?? $h['total_emaal']) ?></div>
                                        <?php $diffE = ($h['actual_emaal'] ?? $h['total_emaal']) - $h['total_emaal']; ?>
                                        <?php if ($diffE != 0): ?>
                                            <span class="badge badge-<?= $diffE > 0 ? 'success' : 'danger' ?>" style="font-size:0.7rem; padding:2px 4px;"><?= $diffE > 0 ? '+' : '' ?><?= formatRupiah($diffE) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="QRIS">
                                        <div style="font-size:0.875rem; color:var(--gray-500);"><?= formatRupiah($h['total_bsi']) ?></div>
                                        <div class="text-bold"><?= formatRupiah($h['actual_bsi'] ?? $h['total_bsi']) ?></div>
                                        <?php $diffB = ($h['actual_bsi'] ?? $h['total_bsi']) - $h['total_bsi']; ?>
                                        <?php if ($diffB != 0): ?>
                                            <span class="badge badge-<?= $diffB > 0 ? 'success' : 'danger' ?>" style="font-size:0.7rem; padding:2px 4px;"><?= $diffB > 0 ? '+' : '' ?><?= formatRupiah($diffB) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Aksi" style="white-space: nowrap;">
                                        <button class="btn btn-sm btn-outline btn-icon" title="Cetak Slip" 
                                                onclick="printSettlement(<?= $h['id'] ?>)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function switchSettlementTab(tabId, tabElement) {
    document.querySelectorAll('.settlement-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(p => p.classList.remove('active'));
    
    tabElement.classList.add('active');
    document.getElementById('tab-' + tabId).classList.add('active');
}

function updateNonCashTotal() {
    const fields = document.querySelectorAll('input[name^="actual_noncash["]');
    let total = 0;

    fields.forEach(function(field) {
        const rawValue = field.value || '0';
        const numericValue = Number(String(rawValue).replace(/[^\d]/g, '')) || 0;
        total += numericValue;
    });

    const display = document.getElementById('total_noncash_display');
    const hidden = document.getElementById('actual_noncash_total');
    if (display) {
        display.value = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(total);
    }
    if (hidden) {
        hidden.value = total;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const fields = document.querySelectorAll('input[name^="actual_noncash["]');
    fields.forEach(function(field) {
        field.addEventListener('input', updateNonCashTotal);
    });
    updateNonCashTotal();
});

function printSettlement(id) {
    window.open('print_settlement.php?id=' + id, '_blank', 'width=400,height=600');
}


<?php include INCLUDES_PATH . '/footer.php'; ?>
