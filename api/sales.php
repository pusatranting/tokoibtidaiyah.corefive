<?php
/**
 * Kasir Ibtidaiyah - API Penjualan
 * Endpoint untuk transaksi POS, Pending Order, dan Retur
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonResponse(['success' => false, 'message' => 'Data tidak valid.'], 400);
    }
    
    $action = $input['action'] ?? 'create_sale';
    
    // =============================================
    // ACTION: CREATE SALE (Bayar langsung)
    // =============================================
    if ($action === 'create_sale') {
        try {
            $db->beginTransaction();
            
            $items = $input['items'] ?? [];
            $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
            $customerName = !empty($input['customer_name']) ? sanitize($input['customer_name']) : null;
            $cashierId = $_SESSION['user_id'] ?? null;
            $saleSource = $input['sale_source'] ?? 'POS';
            $paymentMethod = $input['payment_method'] ?? 'Tunai';
            $paidAmount = (float)($input['paid_amount'] ?? 0);
            $isDebt = $input['is_debt'] ?? false;
            $notes = $input['notes'] ?? '';
            $discountPercent = isset($input['discount_percent']) ? max(0, min(100, (float)$input['discount_percent'])) : 0;
            $additionalFee = (float)($input['additional_fee'] ?? 0);
            $dueDate = $input['due_date'] ?? date('Y-m-d', strtotime('+' . getSetting('default_due_days', 30) . ' days'));
            
            if ($additionalFee > 0) {
                $notes = trim($notes . " (Biaya Tambahan: " . formatRupiah($additionalFee) . ")");
            }
            
            if (empty($items)) {
                throw new Exception('Keranjang kosong.');
            }
            
            // Calculate totals
            $totalAmount = 0;
            $processedItems = [];
            
            foreach ($items as $item) {
                $varId = (int)$item['variation_id'];
                $qty = (int)$item['qty'];
                
                if ($qty <= 0) continue;
                
                // Get variation info
                $stmt = $db->prepare("SELECT pv.*, p.name as product_name FROM product_variations pv JOIN products p ON pv.product_id = p.id WHERE pv.id = ? FOR UPDATE");
                $stmt->execute([$varId]);
                $variation = $stmt->fetch();
                
                if (!$variation) {
                    throw new Exception("Produk tidak ditemukan (ID: $varId).");
                }
                
                if ($variation['stock_qty'] < $qty) {
                    throw new Exception("Stok {$variation['product_name']} tidak cukup. Tersisa: {$variation['stock_qty']}");
                }
                
                // Get tiered price
                $priceType = 'Umum';
                if ($customerId) {
                    $stmtPt = $db->prepare("SELECT pt.name FROM price_types pt JOIN customers c ON c.price_type_id = pt.id WHERE c.id = ?");
                    $stmtPt->execute([$customerId]);
                    $pt = $stmtPt->fetchColumn();
                    if ($pt) $priceType = $pt;
                }
                $unitPrice = getSellingPrice($varId, $qty, $priceType);
                $subtotal = $unitPrice * $qty;
                $totalAmount += $subtotal;
                
                $processedItems[] = [
                    'variation_id' => $varId,
                    'product_name' => $variation['product_name'],
                    'variation_name' => $variation['variation_name'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ];
            }
            
            if (empty($processedItems)) {
                throw new Exception('Tidak ada item valid.');
            }
            
            // Generate invoice number
            $invoiceNumber = generateInvoiceNumber();
            $discountAmount = ($totalAmount * $discountPercent) / 100;
            $grandTotal = max(0, $totalAmount - $discountAmount + $additionalFee);
            $status = $isDebt ? 'Debt' : 'Paid';
            
            // Validate debt
            if ($isDebt) {
                if (!$customerId) {
                    throw new Exception('Kasbon harus memilih pelanggan terdaftar.');
                }
                
                // Check credit limit removed
            }
            
            // Insert sale
            $stmt = $db->prepare("INSERT INTO sales (invoice_number, customer_id, customer_name, cashier_id, sale_source, total_amount, discount_amount, grand_total, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$invoiceNumber, $customerId, $customerName, $cashierId, $saleSource, $totalAmount, $discountAmount, $grandTotal, $status, $notes]);
            $saleId = $db->lastInsertId();
            
            // Insert sale details & reduce stock
            foreach ($processedItems as $item) {
                $stmt = $db->prepare("INSERT INTO sale_details (sale_id, product_variation_id, product_name, variation_name, qty, unit_price) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$saleId, $item['variation_id'], $item['product_name'], $item['variation_name'], $item['qty'], $item['unit_price']]);
                
                // Reduce stock
                $stmt = $db->prepare("UPDATE product_variations SET stock_qty = stock_qty - ? WHERE id = ?");
                $stmt->execute([$item['qty'], $item['variation_id']]);
                
                // Sinkronisasi stok SKU induk
                $pidStmt = $db->prepare("SELECT product_id FROM product_variations WHERE id = ?");
                $pidStmt->execute([$item['variation_id']]);
                $pId = $pidStmt->fetchColumn();
                if ($pId) syncProductStock($pId);
            }
            
            // Insert payment
            if (!$isDebt && $paidAmount > 0) {
                $stmt = $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, created_by) VALUES (?, 'Incoming', ?, ?, ?)");
                $stmt->execute([$saleId, $paymentMethod, $paidAmount, $cashierId]);
            } elseif ($isDebt && $paidAmount > 0) {
                $stmt = $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, created_by) VALUES (?, 'Incoming', ?, ?, ?)");
                $stmt->execute([$saleId, $paymentMethod, $paidAmount, $cashierId]);
            }
            
            // Create receivable if debt
            if ($isDebt) {
                $debtAmount = $grandTotal - $paidAmount;
                $stmt = $db->prepare("INSERT INTO receivables (customer_id, sale_id, total_debt, paid_amount, due_date, status) VALUES (?,?,?,?,?,?)");
                $paidOnDebt = max(0, $paidAmount);
                $recStatus = $paidOnDebt > 0 ? 'Partial' : 'Unpaid';
                $stmt->execute([$customerId, $saleId, $grandTotal, $paidOnDebt, $dueDate, $recStatus]);
            }
            
            $db->commit();
            
            logActivity('Penjualan', 'Sales', "Invoice: $invoiceNumber, Total: " . formatRupiah($grandTotal));
            
            // Return receipt data
            $receiptData = [
                'sale_id' => $saleId,
                'invoice_number' => $invoiceNumber,
                'items' => $processedItems,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'change' => max(0, $paidAmount - $grandTotal),
                'payment_method' => $paymentMethod,
                'status' => $status,
                'is_debt' => $isDebt,
                'customer_id' => $customerId,
                'date' => date('Y-m-d H:i:s'),
                'store_name' => getSetting('store_name', APP_NAME),
                'store_tagline' => getSetting('store_tagline', ''),
                'store_address' => getSetting('store_address', ''),
                'store_phone' => getSetting('store_phone', ''),
                'store_logo' => getSetting('store_logo', ''),
                'receipt_footer' => getSetting('receipt_footer', 'Terima kasih!'),
                'cashier_name' => $_SESSION['full_name'] ?? '',
            ];
            
            // Get customer name if exists
            if ($customerId) {
                $stmt = $db->prepare("SELECT name FROM customers WHERE id = ?");
                $stmt->execute([$customerId]);
                $receiptData['customer_name'] = $stmt->fetchColumn();
            } else if ($customerName) {
                $receiptData['customer_name'] = $customerName;
            } else {
                $receiptData['customer_name'] = 'Umum';
            }

            // Add outstanding debt for kasbon receipts
            if ($isDebt && $customerId) {
                $stmt = $db->prepare("SELECT COALESCE(SUM(total_debt - paid_amount), 0) FROM receivables WHERE customer_id = ? AND status != 'Paid'");
                $stmt->execute([$customerId]);
                $receiptData['outstanding_debt'] = (float)$stmt->fetchColumn();
                $receiptData['down_payment'] = $paidAmount; // uang muka yang dibayar
                $receiptData['debt_amount'] = $grandTotal - $paidAmount; // sisa utang transaksi ini
            }
            
            jsonResponse(['success' => true, 'message' => 'Transaksi berhasil!', 'data' => $receiptData]);
            
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: SAVE PENDING ORDER
    // =============================================
    if ($action === 'save_pending') {
        try {
            $db->beginTransaction();
            
            $items = $input['items'] ?? [];
            $pendingLabel = sanitize($input['pending_label'] ?? '');
            $customerName = sanitize($input['customer_name'] ?? '');
            $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : null;
            $cashierId = $_SESSION['user_id'] ?? null;
            
            if (empty($items)) {
                throw new Exception('Keranjang kosong.');
            }
            if (empty($pendingLabel) && empty($customerName)) {
                throw new Exception('Nama pelanggan atau nomor meja harus diisi.');
            }
            
            $totalAmount = 0;
            $processedItems = [];
            
            foreach ($items as $item) {
                $varId = (int)$item['variation_id'];
                $qty = (int)$item['qty'];
                if ($qty <= 0) continue;
                
                $stmt = $db->prepare("SELECT pv.*, p.name as product_name FROM product_variations pv JOIN products p ON pv.product_id = p.id WHERE pv.id = ?");
                $stmt->execute([$varId]);
                $variation = $stmt->fetch();
                if (!$variation) continue;
                
                $priceType = 'Umum';
                if ($customerId) {
                    $stmtPt = $db->prepare("SELECT pt.name FROM price_types pt JOIN customers c ON c.price_type_id = pt.id WHERE c.id = ?");
                    $stmtPt->execute([$customerId]);
                    $pt = $stmtPt->fetchColumn();
                    if ($pt) $priceType = $pt;
                }
                $unitPrice = getSellingPrice($varId, $qty, $priceType);
                $subtotal = $unitPrice * $qty;
                $totalAmount += $subtotal;
                
                $processedItems[] = [
                    'variation_id' => $varId,
                    'product_name' => $variation['product_name'],
                    'variation_name' => $variation['variation_name'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                ];
            }
            
            if (empty($processedItems)) {
                throw new Exception('Tidak ada item valid.');
            }
            
            $invoiceNumber = generateInvoiceNumber();
            $label = $pendingLabel ?: $customerName;
            
            // Cek nama pending order (pending_label) tidak boleh sama
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM sales WHERE status = 'Pending' AND LOWER(TRIM(pending_label)) = LOWER(TRIM(?))");
            $stmtCheck->execute([$label]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception('Nama Pending (Pending Label) "' . $label . '" sudah digunakan. Harap gunakan nama yang berbeda.');
            }
            
            // Insert sale with status Pending (stok TIDAK dikurangi)
            $stmt = $db->prepare("INSERT INTO sales (invoice_number, customer_id, customer_name, pending_label, cashier_id, sale_source, total_amount, grand_total, status, notes) VALUES (?,?,?,?,?,'POS',?,?,'Pending','')");
            $stmt->execute([$invoiceNumber, $customerId, $customerName, $label, $cashierId, $totalAmount, $totalAmount]);
            $saleId = $db->lastInsertId();
            
            foreach ($processedItems as $item) {
                $stmt = $db->prepare("INSERT INTO sale_details (sale_id, product_variation_id, product_name, variation_name, qty, unit_price) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$saleId, $item['variation_id'], $item['product_name'], $item['variation_name'], $item['qty'], $item['unit_price']]);
            }
            
            $db->commit();
            logActivity('Simpan Pesanan', 'Sales', "Pending: $label, Invoice: $invoiceNumber");
            
            jsonResponse(['success' => true, 'message' => 'Pesanan disimpan.', 'data' => ['sale_id' => $saleId, 'label' => $label]]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: LIST PENDING ORDERS
    // =============================================
    if ($action === 'list_pending') {
        $cashierId = $_SESSION['user_id'] ?? null;
        $stmt = $db->prepare("
            SELECT s.id, s.invoice_number, s.pending_label, s.customer_name, s.grand_total, s.created_at,
                   (SELECT COUNT(*) FROM sale_details WHERE sale_id = s.id) as item_count
            FROM sales s
            WHERE s.status = 'Pending' AND s.sale_source = 'POS'
            ORDER BY s.created_at DESC
        ");
        $stmt->execute();
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }
    
    // =============================================
    // ACTION: RESUME PENDING ORDER (load items to cart)
    // =============================================
    if ($action === 'resume_pending') {
        $saleId = (int)($input['sale_id'] ?? 0);
        
        $stmt = $db->prepare("SELECT * FROM sales WHERE id = ? AND status = 'Pending'");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        
        if (!$sale) {
            jsonResponse(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
        }
        
        // Get items with current stock & tiered prices
        $stmt = $db->prepare("
            SELECT sd.product_variation_id as variation_id, sd.product_name, sd.variation_name, sd.qty, sd.unit_price,
                   pv.stock_qty
            FROM sale_details sd
            JOIN product_variations pv ON sd.product_variation_id = pv.id
            WHERE sd.sale_id = ?
        ");
        $stmt->execute([$saleId]);
        $items = $stmt->fetchAll();
        
        // Attach tiers for each item
        foreach ($items as &$item) {
            $stmt2 = $db->prepare("SELECT label, min_qty, selling_price FROM tiered_prices WHERE product_variation_id = ? ORDER BY min_qty");
            $stmt2->execute([$item['variation_id']]);
            $item['tiers'] = $stmt2->fetchAll();
        }
        unset($item);
        
        jsonResponse(['success' => true, 'data' => [
            'sale_id' => $sale['id'],
            'customer_id' => $sale['customer_id'],
            'customer_name' => $sale['customer_name'],
            'items' => $items,
        ]]);
    }
    
    // =============================================
    // ACTION: DELETE PENDING ORDER
    // =============================================
    if ($action === 'delete_pending') {
        $saleId = (int)($input['sale_id'] ?? 0);
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT id, status FROM sales WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            
            if (!$sale) {
                throw new Exception('Pesanan tidak ditemukan atau sudah diproses.');
            }
            
            // Update status to Cancelled
            $db->prepare("UPDATE sales SET status = 'Cancelled' WHERE id = ?")->execute([$saleId]);
            
            $db->commit();
            logActivity('Hapus Pending', 'Sales', "Sale ID: $saleId");
            jsonResponse(['success' => true, 'message' => 'Pesanan dibatalkan.']);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: SEARCH INVOICE (untuk Retur)
    // =============================================
    if ($action === 'search_invoice') {
        $invoiceNo = sanitize($input['invoice_number'] ?? '');
        
        if (empty($invoiceNo)) {
            jsonResponse(['success' => false, 'message' => 'Nomor invoice harus diisi.'], 400);
        }
        
        $stmt = $db->prepare("
            SELECT s.*, u.full_name as cashier_name
            FROM sales s 
            LEFT JOIN users u ON s.cashier_id = u.id
            WHERE s.invoice_number = ? AND s.status IN ('Paid', 'Debt', 'Completed', 'Confirmed', 'Processing', 'Ready', 'Shipped')
        ");
        $stmt->execute([$invoiceNo]);
        $sale = $stmt->fetch();
        
        if (!$sale) {
            jsonResponse(['success' => false, 'message' => 'Invoice tidak ditemukan atau belum lunas.'], 404);
        }
        
        // Get items with already-returned qty
        $stmt = $db->prepare("
                 SELECT sd.id as sale_detail_id, sd.product_variation_id as variation_id, sd.product_name, sd.variation_name, sd.qty, sd.unit_price,
                     pv.stock_qty,
                   COALESCE(
                       (SELECT SUM(rd.qty_returned) FROM return_details rd 
                        JOIN returns r ON rd.return_id = r.id 
                        WHERE rd.sale_detail_id = sd.id), 0
                   ) as qty_returned
            FROM sale_details sd
            JOIN product_variations pv ON pv.id = sd.product_variation_id
            WHERE sd.sale_id = ?
        ");
        $stmt->execute([$sale['id']]);
        $items = $stmt->fetchAll();
        
        // Calculate returnable qty
        foreach ($items as &$item) {
            $item['qty_returnable'] = $item['qty'] - $item['qty_returned'];
        }
        unset($item);
        
        jsonResponse(['success' => true, 'data' => [
            'sale' => $sale,
            'items' => $items,
        ]]);
    }

    // =============================================
    // ACTION: UPDATE SALE (Edit transaksi + retur)
    // =============================================
    if ($action === 'update_sale') {
        try {
            $db->beginTransaction();
            $saleId = (int)($input['sale_id'] ?? 0);
            $editItems = $input['items'] ?? [];
            $reason = sanitize($input['reason'] ?? 'Perubahan transaksi');
            $cashierId = $_SESSION['user_id'] ?? null;

            $stmt = $db->prepare("SELECT * FROM sales WHERE id = ? AND status IN ('Paid', 'Debt', 'Completed', 'Confirmed', 'Processing', 'Ready', 'Shipped') FOR UPDATE");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            if (!$sale) throw new Exception('Transaksi tidak valid.');

            $stmt = $db->prepare("SELECT sd.*, COALESCE((SELECT SUM(rd.qty_returned) FROM return_details rd JOIN returns r ON rd.return_id = r.id WHERE rd.sale_detail_id = sd.id), 0) AS qty_returned FROM sale_details sd WHERE sd.sale_id = ?");
            $stmt->execute([$saleId]);
            $existing = [];
            foreach ($stmt->fetchAll() as $detail) $existing[(int)$detail['id']] = $detail;
            $originalItems = [];
            foreach ($existing as $detail) {
                $originalItems[] = [
                    'product_name' => $detail['product_name'],
                    'variation_name' => $detail['variation_name'],
                    'qty' => (int)$detail['qty'] - (int)$detail['qty_returned'],
                    'unit_price' => (float)$detail['unit_price'],
                ];
            }

            $requested = [];
            foreach ($editItems as $item) {
                $detailId = (int)($item['sale_detail_id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                if ($qty <= 0) continue;
                $requested[$detailId ?: 'new_' . (int)$item['variation_id']] = [
                    'detail_id' => $detailId, 'variation_id' => (int)$item['variation_id'],
                    'qty' => $qty, 'unit_price' => (float)($item['unit_price'] ?? 0)
                ];
            }

            $returnItems = [];
            $addedItems = [];
            $increasedItems = [];
            $totalAmount = 0;
            foreach ($existing as $detailId => $detail) {
                $activeQty = (int)$detail['qty'] - (int)$detail['qty_returned'];
                $newQty = $requested[$detailId]['qty'] ?? 0;
                if ($newQty < $activeQty) $returnItems[] = [$detailId, $activeQty - $newQty, (int)$detail['product_variation_id']];
                $delta = $newQty - $activeQty;
                if ($delta > 0) {
                    $stock = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ? FOR UPDATE");
                    $stock->execute([(int)$detail['product_variation_id']]);
                    if ((int)$stock->fetchColumn() < $delta) throw new Exception("Stok {$detail['product_name']} tidak cukup.");
                    $db->prepare("UPDATE product_variations SET stock_qty = stock_qty - ? WHERE id = ?")->execute([$delta, $detail['product_variation_id']]);
                    $increasedItems[] = [
                        'product_name' => $detail['product_name'],
                        'variation_name' => $detail['variation_name'],
                        'qty' => $delta,
                        'unit_price' => (float)$detail['unit_price'],
                    ];
                } elseif ($delta < 0) {
                    $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ? WHERE id = ?")->execute([- $delta, $detail['product_variation_id']]);
                }
                if ($newQty > 0) {
                    $price = $requested[$detailId]['unit_price'] ?? (float)$detail['unit_price'];
                    // sale_details.qty menyimpan total qty historis; qty_returned dihitung terpisah.
                    $storedQty = $newQty + (int)$detail['qty_returned'];
                    $db->prepare("UPDATE sale_details SET qty = ?, unit_price = ? WHERE id = ?")->execute([$storedQty, $price, $detailId]);
                    $totalAmount += $newQty * $price;
                }
            }

            foreach ($requested as $item) {
                if ($item['detail_id']) continue;
                $stmt = $db->prepare("SELECT pv.*, p.name AS product_name FROM product_variations pv JOIN products p ON p.id = pv.product_id WHERE pv.id = ? FOR UPDATE");
                $stmt->execute([$item['variation_id']]);
                $variation = $stmt->fetch();
                if (!$variation || (int)$variation['stock_qty'] < $item['qty']) throw new Exception('Produk tambahan tidak tersedia atau stok tidak cukup.');
                $price = $item['unit_price'] > 0 ? $item['unit_price'] : getSellingPrice($item['variation_id'], $item['qty'], 'Umum');
                $db->prepare("INSERT INTO sale_details (sale_id, product_variation_id, product_name, variation_name, qty, unit_price) VALUES (?,?,?,?,?,?)")->execute([$saleId, $item['variation_id'], $variation['product_name'], $variation['variation_name'], $item['qty'], $price]);
                $db->prepare("UPDATE product_variations SET stock_qty = stock_qty - ? WHERE id = ?")->execute([$item['qty'], $item['variation_id']]);
                $totalAmount += $item['qty'] * $price;
                $addedItems[] = [
                    'product_name' => $variation['product_name'],
                    'variation_name' => $variation['variation_name'],
                    'qty' => $item['qty'],
                    'unit_price' => $price,
                ];
            }

            if ($returnItems) {
                $refund = 0;
                foreach ($returnItems as [$detailId, $qty, $variationId]) {
                    $stmt = $db->prepare("SELECT unit_price FROM sale_details WHERE id = ?"); $stmt->execute([$detailId]);
                    $refund += (float)$stmt->fetchColumn() * $qty;
                }
                $returnNumber = generateReturnNumber();
                $db->prepare("INSERT INTO returns (return_number, sale_id, cashier_id, reason, return_type, refund_amount, payment_method) VALUES (?,?,?,?,?,?,?)")->execute([$returnNumber, $saleId, $cashierId, $reason, 'Refund', $refund, 'Tunai']);
                $returnId = $db->lastInsertId();
                foreach ($returnItems as [$detailId, $qty]) $db->prepare("INSERT INTO return_details (return_id, sale_detail_id, qty_returned, item_condition) VALUES (?,?,?,'Bagus')")->execute([$returnId, $detailId, $qty]);
                if ($refund > 0) $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, notes, created_by) VALUES (?, 'Outgoing', 'Tunai', ?, ?, ?)")->execute([$saleId, $refund, "Refund: $returnNumber", $cashierId]);
            }

            $difference = $totalAmount - (float)$sale['grand_total'];
            $changeItems = [];
            foreach ($returnItems as [$detailId, $qty]) {
                $detail = $existing[$detailId];
                $changeItems[] = [
                    'type' => 'return', 'product_name' => $detail['product_name'],
                    'variation_name' => $detail['variation_name'], 'qty' => $qty,
                    'unit_price' => (float)$detail['unit_price'],
                ];
            }
            foreach ($addedItems as $item) {
                $item['type'] = 'addition';
                $changeItems[] = $item;
            }
            foreach ($increasedItems as $item) {
                $item['type'] = 'addition';
                $changeItems[] = $item;
            }
            $history = base64_encode(json_encode([
                'original_total' => (float)$sale['grand_total'],
                'original_items' => $originalItems,
                'changes' => $changeItems,
                'final_total' => $totalAmount,
                'difference' => $difference,
            ], JSON_UNESCAPED_UNICODE));
            $editNote = trim(($sale['notes'] ?? '') . "\nEdit transaksi: $reason [[EDIT_RECEIPT:$history]]");
            $db->prepare("UPDATE sales SET total_amount = ?, grand_total = ?, notes = ? WHERE id = ?")->execute([$totalAmount, $totalAmount, $editNote, $saleId]);
            if ($difference > 0) $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, notes, created_by) VALUES (?, 'Incoming', 'Tunai', ?, 'Tambahan edit transaksi', ?)")->execute([$saleId, $difference, $cashierId]);
            $db->commit();
            logActivity('Edit Penjualan', 'Sales', "Invoice ID: $saleId");
            jsonResponse(['success' => true, 'message' => 'Transaksi berhasil diperbarui.', 'data' => [
                'sale_id' => (int)$saleId,
                'invoice_number' => $sale['invoice_number'],
                'difference' => $difference,
            ]]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: CREATE RETURN (Retur Produk)
    // =============================================
    if ($action === 'create_return') {
        try {
            $db->beginTransaction();
            
            $saleId = (int)($input['sale_id'] ?? 0);
            $reason = sanitize($input['reason'] ?? '');
            $returnType = $input['return_type'] ?? 'Refund';
            $returnItems = $input['items'] ?? [];
            $paymentMethod = $input['payment_method'] ?? 'Tunai';
            $cashierId = $_SESSION['user_id'] ?? null;
            
            if (empty($reason)) {
                throw new Exception('Alasan retur harus diisi.');
            }
            if (empty($returnItems)) {
                throw new Exception('Pilih minimal satu item untuk diretur.');
            }
            
            // Validate sale
            $stmt = $db->prepare("SELECT id, status FROM sales WHERE id = ? AND status IN ('Paid', 'Debt', 'Completed', 'Confirmed', 'Processing', 'Ready', 'Shipped')");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                throw new Exception('Transaksi tidak valid untuk retur.');
            }
            
            $totalRefund = 0;
            $validItems = [];
            
            foreach ($returnItems as $ri) {
                $saleDetailId = (int)$ri['sale_detail_id'];
                $qtyReturn = (int)$ri['qty'];
                $condition = $ri['condition'] ?? 'Bagus';
                
                if ($qtyReturn <= 0) continue;
                
                // Get sale detail
                $stmt = $db->prepare("SELECT * FROM sale_details WHERE id = ? AND sale_id = ?");
                $stmt->execute([$saleDetailId, $saleId]);
                $detail = $stmt->fetch();
                if (!$detail) continue;
                
                // Check already returned qty
                $stmt = $db->prepare("SELECT COALESCE(SUM(qty_returned), 0) FROM return_details rd JOIN returns r ON rd.return_id = r.id WHERE rd.sale_detail_id = ?");
                $stmt->execute([$saleDetailId]);
                $alreadyReturned = (int)$stmt->fetchColumn();
                
                $maxReturnable = $detail['qty'] - $alreadyReturned;
                if ($qtyReturn > $maxReturnable) {
                    throw new Exception("Qty retur untuk {$detail['product_name']} melebihi batas. Maks: $maxReturnable");
                }
                
                $itemRefund = $detail['unit_price'] * $qtyReturn;
                $totalRefund += $itemRefund;
                
                $validItems[] = [
                    'sale_detail_id' => $saleDetailId,
                    'qty' => $qtyReturn,
                    'condition' => $condition,
                    'variation_id' => $detail['product_variation_id'],
                ];
            }
            
            if (empty($validItems)) {
                throw new Exception('Tidak ada item valid untuk diretur.');
            }
            
            // Generate return number
            $returnNumber = generateReturnNumber();
            
            // Insert return header
            $stmt = $db->prepare("INSERT INTO returns (return_number, sale_id, cashier_id, reason, return_type, refund_amount, payment_method) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$returnNumber, $saleId, $cashierId, $reason, $returnType, $totalRefund, $paymentMethod]);
            $returnId = $db->lastInsertId();
            
            // Insert return details & adjust stock
            foreach ($validItems as $vi) {
                $stmt = $db->prepare("INSERT INTO return_details (return_id, sale_detail_id, qty_returned, item_condition) VALUES (?,?,?,?)");
                $stmt->execute([$returnId, $vi['sale_detail_id'], $vi['qty'], $vi['condition']]);
                
                // If item condition is "Bagus", add back to stock
                if ($vi['condition'] === 'Bagus') {
                    $stmt = $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ? WHERE id = ?");
                    $stmt->execute([$vi['qty'], $vi['variation_id']]);
                }
            }
            
            // Record refund payment (outgoing)
            if ($returnType === 'Refund' && $totalRefund > 0) {
                $stmt = $db->prepare("INSERT INTO payments (sale_id, payment_type, payment_method, amount, notes, created_by) VALUES (?, 'Outgoing', ?, ?, ?, ?)");
                $stmt->execute([$saleId, $paymentMethod, $totalRefund, "Refund: $returnNumber", $cashierId]);
            }
            
            $db->commit();
            logActivity('Retur', 'Returns', "Return: $returnNumber, Refund: " . formatRupiah($totalRefund));
            
            jsonResponse(['success' => true, 'message' => 'Retur berhasil diproses.', 'data' => [
                'return_id' => $returnId,
                'return_number' => $returnNumber,
                'refund_amount' => $totalRefund,
                'return_type' => $returnType,
            ]]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: UPDATE ONLINE ORDER STATUS
    // =============================================
    if ($action === 'update_order_status') {
        try {
            $saleId = (int)($input['sale_id'] ?? 0);
            $newStatus = $input['status'] ?? '';
            $rejectReason = $input['reject_reason'] ?? '';
            $cashierId = $_SESSION['user_id'] ?? null;
            
            $validStatuses = ['Confirmed', 'Processing', 'Ready', 'Shipped', 'Completed', 'Cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception('Status tidak valid.');
            }
            
            $stmt = $db->prepare("SELECT * FROM sales WHERE id = ?");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            if (!$sale) {
                throw new Exception('Pesanan tidak ditemukan.');
            }
            
            $db->beginTransaction();
            
            // If confirming: reduce stock
            if ($newStatus === 'Confirmed' && in_array($sale['status'], ['Pending'])) {
                $stmt = $db->prepare("SELECT sd.product_variation_id, sd.qty FROM sale_details sd WHERE sd.sale_id = ?");
                $stmt->execute([$saleId]);
                $items = $stmt->fetchAll();
                
                foreach ($items as $item) {
                    $stmtStock = $db->prepare("SELECT stock_qty FROM product_variations WHERE id = ? FOR UPDATE");
                    $stmtStock->execute([$item['product_variation_id']]);
                    $currentStock = (int)$stmtStock->fetchColumn();
                    
                    if ($currentStock < $item['qty']) {
                        throw new Exception("Stok tidak cukup untuk memproses pesanan ini.");
                    }
                    
                    $db->prepare("UPDATE product_variations SET stock_qty = stock_qty - ? WHERE id = ?")
                       ->execute([$item['qty'], $item['product_variation_id']]);
                }
            }
            
            // If cancelling confirmed order: restore stock
            if ($newStatus === 'Cancelled' && in_array($sale['status'], ['Confirmed', 'Processing', 'Ready'])) {
                $stmt = $db->prepare("SELECT sd.product_variation_id, sd.qty FROM sale_details sd WHERE sd.sale_id = ?");
                $stmt->execute([$saleId]);
                $items = $stmt->fetchAll();
                
                foreach ($items as $item) {
                    $db->prepare("UPDATE product_variations SET stock_qty = stock_qty + ? WHERE id = ?")
                       ->execute([$item['qty'], $item['product_variation_id']]);
                }
            }
            
            $notes = $sale['notes'];
            if ($newStatus === 'Cancelled' && $rejectReason) {
                $notes = ($notes ? $notes . "\n" : '') . "Ditolak: $rejectReason";
            }
            
            $stmt = $db->prepare("UPDATE sales SET status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$newStatus, $notes, $saleId]);
            
            $db->commit();
            logActivity('Update Status', 'Sales', "Sale #$saleId: {$sale['status']} → $newStatus");
            
            jsonResponse(['success' => true, 'message' => "Status diperbarui menjadi $newStatus."]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    // =============================================
    // ACTION: LIST ONLINE ORDERS
    // =============================================
    if ($action === 'list_online_orders') {
        $status = $input['status'] ?? '';
        
        $where = "WHERE s.sale_source IN ('E-Commerce', 'Online') AND s.status != 'Pending'";
        $params = [];
        
        if ($status) {
            $where .= " AND s.status = ?";
            $params[] = $status;
        }
        
        $stmt = $db->prepare("
            SELECT s.*, u.full_name as cashier_name,
                   c.name as cust_name, c.phone as cust_phone,
                   (SELECT COUNT(*) FROM sale_details WHERE sale_id = s.id) as item_count
            FROM sales s
            LEFT JOIN users u ON s.cashier_id = u.id
            LEFT JOIN customers c ON s.customer_id = c.id
            $where
            ORDER BY s.created_at DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }
}

// =============================================
// GET REQUESTS
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    // Count new online orders (for badge)
    if ($action === 'count_new_online') {
        $stmt = $db->query("SELECT COUNT(*) FROM sales WHERE sale_source IN ('E-Commerce', 'Online') AND status = 'Confirmed'");
        $count = (int)$stmt->fetchColumn();
        jsonResponse(['success' => true, 'count' => $count]);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
