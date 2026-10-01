<?php
/**
 * Kasir Ibtidaiyah - E-Commerce: Halaman Utama Toko Online
 */
require_once __DIR__ . '/../config/app.php';

$db = Database::conn();

$priceType = getCurrentPriceTypeName();

$banner1 = getSetting('banner1_image', 'assets/img/hero_sarung.jpg');

$stmtCat = $db->prepare("
    SELECT c.* 
    FROM categories c 
    WHERE c.online_visibility = 1 
    AND c.parent_id IS NULL
    AND (
        EXISTS (
            SELECT 1 FROM products p
            WHERE p.category_id = c.id 
            AND p.is_active = 1 
            AND p.online_visibility = 'show'
            AND EXISTS (
                SELECT 1 FROM product_prices pp
                JOIN price_types pt ON pp.price_type_id = pt.id
                WHERE pp.product_id = p.id AND pt.name = ?
            )
        )
        OR EXISTS (
            SELECT 1 FROM categories subc 
            JOIN products p2 ON p2.category_id = subc.id
            WHERE subc.parent_id = c.id
            AND p2.is_active = 1 
            AND p2.online_visibility = 'show'
            AND EXISTS (
                SELECT 1 FROM product_prices pp2
                JOIN price_types pt2 ON pp2.price_type_id = pt2.id
                WHERE pp2.product_id = p2.id AND pt2.name = ?
            )
        )
    )
    ORDER BY c.sort_order, c.name
");
$stmtCat->execute([$priceType, $priceType]);
$categories = $stmtCat->fetchAll();

// Determine price type based on login session
// $priceType already set above

// Fetch featured products
$featuredProducts = $db->query("
    SELECT
        p.id, p.name, p.image AS prod_image, p.image2 AS prod_image2, p.is_featured, p.created_at, p.discount_percent,
        c.name AS category_name,
        (SELECT SUM(stock_qty) FROM product_variations WHERE product_id = p.id AND is_active = 1) AS stock_qty,
        COALESCE(
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = '$priceType' LIMIT 1),
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1),
            p.base_price
        ) AS price,
        (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1) AS umum_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1 AND p.is_featured = 1 AND p.online_visibility = 'show'
    AND (p.category_id IS NULL OR c.online_visibility = 1)
    AND EXISTS (SELECT 1 FROM product_variations WHERE product_id = p.id AND is_active = 1)
    AND EXISTS (SELECT 1 FROM product_prices pp JOIN price_types pt ON pp.price_type_id = pt.id WHERE pp.product_id = p.id AND pt.name = '$priceType')
    ORDER BY p.created_at DESC
    LIMIT 24
")->fetchAll();

// Fetch latest products
$latestProducts = $db->query("
    SELECT
        p.id, p.name, p.image AS prod_image, p.image2 AS prod_image2, p.created_at, p.discount_percent,
        c.name AS category_name,
        (SELECT SUM(stock_qty) FROM product_variations WHERE product_id = p.id AND is_active = 1) AS stock_qty,
        COALESCE(
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = '$priceType' LIMIT 1),
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1),
            p.base_price
        ) AS price,
        (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1) AS umum_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1 AND p.online_visibility = 'show'
    AND (p.category_id IS NULL OR c.online_visibility = 1)
    AND EXISTS (SELECT 1 FROM product_variations WHERE product_id = p.id AND is_active = 1)
    AND EXISTS (SELECT 1 FROM product_prices pp JOIN price_types pt ON pp.price_type_id = pt.id WHERE pp.product_id = p.id AND pt.name = '$priceType')
    ORDER BY p.created_at DESC
    LIMIT 18
")->fetchAll();

// Fetch best selling products
$bestSellingProducts = $db->query("
    SELECT
        p.id, p.name, p.image AS prod_image, p.image2 AS prod_image2, p.created_at, p.discount_percent,
        c.name AS category_name,
        pv.id AS var_id, pv.variation_name, pv.stock_qty, pv.image AS var_image, pv.image2 AS var_image2,
        COALESCE(
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = '$priceType' LIMIT 1),
            (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1),
            p.base_price
        ) AS price,
        (SELECT pp2.selling_price FROM product_prices pp2 JOIN price_types pt2 ON pp2.price_type_id = pt2.id WHERE pp2.product_id = p.id AND pp2.min_qty = 1 AND pt2.name = 'Umum' LIMIT 1) AS umum_price,
        (SELECT COALESCE(SUM(sd.qty), 0) FROM sale_details sd WHERE sd.product_variation_id = pv.id) AS sold_qty
    FROM product_variations pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1 AND pv.is_active = 1 AND p.online_visibility = 'show'
    AND (p.category_id IS NULL OR c.online_visibility = 1)
    AND pv.stock_qty > 0
    AND EXISTS (SELECT 1 FROM product_prices pp JOIN price_types pt ON pp.price_type_id = pt.id WHERE pp.product_id = p.id AND pt.name = '$priceType')
    ORDER BY sold_qty DESC, p.created_at DESC
    LIMIT 10
")->fetchAll();


// Cart count from session
$cartCount = 0;
if (isset($_SESSION['shop_cart'])) {
    $cartCount = array_sum(array_column($_SESSION['shop_cart'], 'qty'));
}

$storeName = APP_NAME;

// Function to get better category icons based on name
function getCategoryIcon($name) {
    $lower = strtolower(trim($name));
    $icons = [
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.38 3.46L16 2a8.59 8.59 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="7"/><polyline points="12 9 12 12 13.5 13.5"/><path d="M16.51 17.35l-.35 3.83a2 2 0 0 1-2 1.82H9.83a2 2 0 0 1-2-1.82l-.35-3.83m.01-10.7l.35-3.83A2 2 0 0 1 9.83 1h4.35a2 2 0 0 1 2 1.82l.35 3.83"/></svg>',
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13"/><path d="M13 3l3 6-4 13"/></svg>'
    ];
    // Pastikan icon statis berdasarkan nama
    return $icons[abs(crc32($lower)) % count($icons)];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $storeName ?> - Belanja online mudah dan terpercaya">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title><?= $storeName ?> - Toko Online</title>
    
    <?php
    $storeLogo = getSetting('store_logo');
    $favicon = $storeLogo ? BASE_URL . '/' . $storeLogo : ASSETS_URL . '/img/tokoibtidaiyah.png';
    ?>
    <link rel="icon" href="<?= $favicon ?>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/main.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/ecommerce.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="shop-layout">
        <!-- Navbar Mobile-First -->
        <?php include INCLUDES_PATH . '/shop_navbar.php'; ?>
        
        <!-- Main Content -->
        <div class="shop-content">
            <?= renderFlashMessage() ?>
            
            <style>
                .banner-text-container {
                    position: relative;
                    z-index: 2;
                    max-width: 55%;
                }
                @media (max-width: 768px) {
                    .banner-text-container {
                        max-width: 80%;
                        transform: scale(0.85);
                        transform-origin: left center;
                        text-align: left !important;
                    }
                }
                @media (max-width: 480px) {
                    .banner-text-container {
                        max-width: 95%;
                        transform: scale(0.65) translateY(0%);
                        transform-origin: left center;
                        text-align: left !important;
                        margin-left: -10px;
                    }
                    .banner-text-container h1 {
                        margin-bottom: 6px !important;
                        transform: translateY(12px);
                    }
                    .banner-text-container p {
                        margin-bottom: 4px !important;
                        line-height: 1.3 !important;
                        transform: translateY(12px);
                    }
                    .hero-buttons {
                        display: flex;
                        gap: 6px;
                    }
                    .hero-btn {
                        padding: 6px 12px !important;
                        font-size: 0.75rem !important;
                    }
                    .hero-btn svg {
                        width: 14px !important;
                        height: 14px !important;
                    }
                }
            </style>
            <!-- Hero Banner -->
            <div class="hero-banner" style="position: relative; overflow: hidden; border-radius: 16px; margin: 0 auto 32px auto; max-width: 1200px; aspect-ratio: 2000/800; background: linear-gradient(135deg, #022c16 0%, #064e3b 100%); display: flex; align-items: center; padding: 0 5%; box-shadow: 0 10px 30px rgba(2, 44, 22, 0.15);">
                <div style="position: absolute; right: 0; top: 0; bottom: 0; width: 55%; background-image: url('<?= ASSETS_URL ?>/img/kitab.jpg'); background-size: cover; background-position: right center; opacity: 0.8; mix-blend-mode: overlay; -webkit-mask-image: linear-gradient(to right, transparent 0%, black 75%); mask-image: linear-gradient(to right, transparent 0%, black 75%);">
                </div>
                <div class="banner-text-container" style="position: relative; z-index: 2;">
                    <h1 style="line-height: 1.2; margin-bottom: 16px; font-weight: 800;">
                        <span style="font-size: clamp(1.5rem, 4vw, 2.5rem); font-weight: 800; color: #fff;">Selamat Belanja</span>
                    </h1>
                    <p style="font-size: clamp(0.85rem, 1.2vw, 1rem); line-height: 1.6; margin-bottom: 24px; opacity: 0.95; color: rgba(255, 255, 255, 0.9); max-width: 600px;">Temukan berbagai Kitab Pelajaran Madrasah Miftahul Ulum Ibtidaiyah Pondok Pesantren Sidogiri. Dapatkan harga spesial untuk pembelian grosir!</p>
                    <div class="hero-buttons">
                        <a href="<?= BASE_URL ?>/shop/category.php" class="btn btn-gold hero-btn" style="padding: 10px 24px; font-weight: 600; border: none; background: #d97706; color: #fff;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            Lihat Semua Produk
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Best Selling Products -->
            <?php if (!empty($bestSellingProducts)): ?>
                <h2 class="section-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: text-bottom; margin-right: 8px; color: #ef4444;"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    Produk Terlaris
                </h2>
                <div class="shop-product-row" style="margin-bottom: 30px;">
                    <?php foreach ($bestSellingProducts as $prod): 
                        $prodName = $prod['name'];
                        if (!empty($prod['variation_name']) && !in_array(strtolower($prod['variation_name']), ['random', 'default', ''])) {
                            $prodName .= ' - ' . $prod['variation_name'];
                        }
                        $finalPrice = $prod['price'] ?? 0;
                        $hasDiscount = ($priceType === 'Umum' && !empty($prod['discount_percent']) && $prod['discount_percent'] > 0);
                        if ($hasDiscount) {
                            $finalPrice = $finalPrice - ($finalPrice * $prod['discount_percent'] / 100);
                        }
                    ?>
                        <a href="<?= BASE_URL ?>/shop/product.php?id=<?= $prod['id'] ?>&var_id=<?= $prod['var_id'] ?>" class="shop-product-card">
                            <div class="product-image">
                                <?php if ($hasDiscount): ?>
                                    <div class="discount-badge"><?= $prod['discount_percent'] ?>% OFF</div>
                                <?php endif; ?>
                                <?php 
                                $mainImage = $prod['var_image'] ?: $prod['prod_image'];
                                $hoverImage = $prod['var_image2'] ?: $prod['prod_image2'];
                                if ($mainImage): ?>
                                    <img src="<?= BASE_URL . '/' . $mainImage ?>"
                                         alt="<?= htmlspecialchars($prod['name']) ?>"
                                         loading="lazy"
                                         decoding="async"
                                         width="300" height="300">
                                    <?php if ($hoverImage): ?>
                                        <img src="<?= BASE_URL . '/' . $hoverImage ?>" class="hover-img" loading="lazy" width="300" height="300" alt="">
                                    <?php endif; ?>
                                <?php else: ?>
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                <?php endif; ?>
                                <?php if ($prod['stock_qty'] <= 0): ?>
                                    <div class="out-of-stock-overlay"><span>Sold<br>Out</span></div>
                                <?php endif; ?>
                                <div class="buy-now-btn">Buy now</div>
                            </div>
                            <div class="product-details">
                                <?php if ($prod['category_name']): ?>
                                    <span class="product-category"><?= htmlspecialchars($prod['category_name']) ?></span>
                                <?php endif; ?>
                                <h3 class="product-name"><?= htmlspecialchars($prodName) ?></h3>
                                <div class="product-price">
                                    <?php 
                                    if ($hasDiscount): ?>
                                        <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through; margin-bottom: 2px;">
                                            <?= formatRupiah($prod['price']) ?>
                                        </div>
                                    <?php elseif (isset($prod['umum_price']) && $prod['umum_price'] > $prod['price']): ?>
                                        <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through;"><?= formatRupiah($prod['umum_price']) ?></div>
                                    <?php endif; ?>
                                    <span style="<?= $hasDiscount ? 'color:var(--danger); font-weight:800;' : '' ?>">
                                        <?= formatRupiah($finalPrice) ?>
                                    </span>
                                </div>
                                <div class="product-stock">Terjual <?= $prod['sold_qty'] ?> | Stok: <?= $prod['stock_qty'] ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Categories -->
            <?php if (!empty($categories)): ?>
                <h2 class="section-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: text-bottom; margin-right: 8px; color: var(--primary-600);"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>
                    Kategori Produk
                </h2>
                <div class="category-grid">
                    <?php foreach ($categories as $i => $cat): ?>
                        <a href="<?= BASE_URL ?>/shop/category.php?category=<?= $cat['id'] ?>" class="category-card">
                            <span class="category-card-icon">
                                <?php if (!empty($cat['icon'])): ?>
                                    <img src="<?= BASE_URL ?>/assets/uploads/categories/<?= htmlspecialchars($cat['icon']) ?>" 
                                         alt="<?= htmlspecialchars($cat['name']) ?>"
                                         style="width:56px; height:56px; object-fit:cover; border-radius:50%; border:2px solid rgba(255,255,255,0.3);"
                                         loading="lazy">
                                <?php else: ?>
                                    <?= getCategoryIcon($cat['name']) ?>
                                <?php endif; ?>
                            </span>
                            <span class="category-card-name"><?= htmlspecialchars($cat['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Featured Products -->
            <?php if (!empty($featuredProducts)): ?>
                <h2 class="section-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: text-bottom; margin-right: 8px; color: #f59e0b;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Produk Unggulan
                </h2>
                <div class="shop-product-row">
                    <?php foreach ($featuredProducts as $prod): 
                        $prodName = $prod['name'];
                        $finalPrice = $prod['price'] ?? 0;
                        $hasDiscount = ($priceType === 'Umum' && !empty($prod['discount_percent']) && $prod['discount_percent'] > 0);
                        if ($hasDiscount) {
                            $finalPrice = $finalPrice - ($finalPrice * $prod['discount_percent'] / 100);
                        }
                    ?>
                        <a href="<?= BASE_URL ?>/shop/product.php?id=<?= $prod['id'] ?>" class="shop-product-card">
                            <div class="product-image">
                                <?php if ($hasDiscount): ?>
                                    <div class="discount-badge"><?= $prod['discount_percent'] ?>% OFF</div>
                                <?php endif; ?>
                                <?php 
                                $mainImage = $prod['prod_image'];
                                $hoverImage = $prod['prod_image2'];
                                if ($mainImage): ?>
                                    <img src="<?= BASE_URL . '/' . $mainImage ?>"
                                         alt="<?= htmlspecialchars($prod['name']) ?>"
                                         loading="lazy"
                                         decoding="async"
                                         width="300" height="300">
                                    <?php if ($hoverImage): ?>
                                        <img src="<?= BASE_URL . '/' . $hoverImage ?>" class="hover-img" loading="lazy" width="300" height="300" alt="">
                                    <?php endif; ?>
                                <?php else: ?>
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                <?php endif; ?>
                                <?php if ($prod['stock_qty'] <= 0): ?>
                                    <div class="out-of-stock-overlay"><span>Sold<br>Out</span></div>
                                <?php endif; ?>
                                <div class="buy-now-btn">Buy now</div>
                            </div>
                            <div class="product-details">
                                <?php if ($prod['category_name']): ?>
                                    <span class="product-category"><?= htmlspecialchars($prod['category_name']) ?></span>
                                <?php endif; ?>
                                <h3 class="product-name"><?= htmlspecialchars($prodName) ?></h3>
                                <div class="product-price">
                                    <?php 
                                    if ($hasDiscount): ?>
                                        <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through; margin-bottom: 2px;">
                                            <?= formatRupiah($prod['price']) ?>
                                        </div>
                                    <?php elseif (isset($prod['umum_price']) && $prod['umum_price'] > $prod['price']): ?>
                                        <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through;"><?= formatRupiah($prod['umum_price']) ?></div>
                                    <?php endif; ?>
                                    <span style="<?= $hasDiscount ? 'color:var(--danger); font-weight:800;' : '' ?>">
                                        <?= formatRupiah($finalPrice) ?>
                                    </span>
                                </div>
                                <div class="product-stock">Stok: <?= $prod['stock_qty'] ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Latest Products -->
            <h2 class="section-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 8px; color: var(--primary-600);"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                Produk Terbaru
            </h2>
            <div class="shop-product-grid">
                <?php if (empty($latestProducts)): ?>
                    <div style="grid-column:1/-1; text-align:center; padding:40px; color: var(--gray-400);">
                        Belum ada produk tersedia
                    </div>
                <?php endif; ?>
                <?php foreach ($latestProducts as $prod): 
                    $prodName = $prod['name'];
                    $finalPrice = $prod['price'] ?? 0;
                    $hasDiscount = ($priceType === 'Umum' && !empty($prod['discount_percent']) && $prod['discount_percent'] > 0);
                    if ($hasDiscount) {
                        $finalPrice = $finalPrice - ($finalPrice * $prod['discount_percent'] / 100);
                    }
                ?>
                    <a href="<?= BASE_URL ?>/shop/product.php?id=<?= $prod['id'] ?>" class="shop-product-card">
                        <div class="product-image">
                            <?php if ($hasDiscount): ?>
                                <div class="discount-badge"><?= $prod['discount_percent'] ?>% OFF</div>
                            <?php endif; ?>
                            <?php 
                            $mainImage = $prod['prod_image'];
                            $hoverImage = $prod['prod_image2'];
                            if ($mainImage): ?>
                                <img src="<?= BASE_URL . '/' . $mainImage ?>"
                                     alt="<?= htmlspecialchars($prod['name']) ?>"
                                     loading="lazy"
                                     decoding="async"
                                     width="300" height="300">
                                <?php if ($hoverImage): ?>
                                    <img src="<?= BASE_URL . '/' . $hoverImage ?>" class="hover-img" loading="lazy" width="300" height="300" alt="">
                                <?php endif; ?>
                            <?php else: ?>
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            <?php endif; ?>
                            <?php if ($prod['stock_qty'] <= 0): ?>
                                <div class="out-of-stock-overlay"><span>Sold<br>Out</span></div>
                            <?php endif; ?>
                            <div class="buy-now-btn">Buy now</div>
                        </div>
                        <div class="product-details">
                            <?php if ($prod['category_name']): ?>
                                <span class="product-category"><?= htmlspecialchars($prod['category_name']) ?></span>
                            <?php endif; ?>
                            <h3 class="product-name"><?= htmlspecialchars($prodName) ?></h3>
                            <div class="product-price">
                                <?php if ($hasDiscount): ?>
                                    <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through; margin-bottom: 2px;">
                                        <?= formatRupiah($prod['price']) ?>
                                    </div>
                                <?php elseif (isset($prod['umum_price']) && $prod['umum_price'] > $prod['price']): ?>
                                    <div style="font-size: 0.8rem; color: #9ca3af; text-decoration: line-through;"><?= formatRupiah($prod['umum_price']) ?></div>
                                <?php endif; ?>
                                <span style="<?= $hasDiscount ? 'color:var(--danger); font-weight:800;' : '' ?>">
                                    <?= formatRupiah($finalPrice) ?>
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Footer -->
        <footer class="shop-footer">
            <div class="shop-footer-inner">
                <div class="footer-col-brand">
                    <h4 style="display: flex; align-items: center; gap: 8px;" class="footer-brand-title">
                        <img src="<?= BASE_URL ?>/assets/img/tokoibtidaiyah.png" alt="Logo" style="width: 24px; height: 24px;">
                        <?= $storeName ?>
                    </h4>
                    <p style="margin-top: -10px; margin-bottom: 2px; font-size: 0.85rem; color: #a1a1aa;">Toko Kitab MMU Ibtidaiyah</p>
                    <p class="footer-address">Sidogiri Kraton Pasuruan</p>
                </div>
                <div class="footer-col-navs">
                    <div class="footer-col-nav">
                        <h4>Bantuan</h4>
                        <p><a href="<?= BASE_URL ?>/shop/">Beranda</a></p>
                    <p><a href="<?= BASE_URL ?>/shop/category.php">Semua Produk</a></p>
                    <p><a href="<?= BASE_URL ?>/shop/cart.php">Keranjang</a></p>
                    </div>
                    <div class="footer-col-nav">
                        <h4>Akun</h4>
                        <p><a href="<?= BASE_URL ?>/login.php">Login</a></p>
                    <p><a href="<?= BASE_URL ?>/shop/account/orders.php">Pesanan Saya</a></p>
                    <p><a href="<?= BASE_URL ?>/shop/account/profile.php">Profil</a></p>
                    </div>
                </div>
            </div>
            <div class="shop-footer-bottom footer-bottom-flex">
                <div class="footer-copyright">
                    &copy; <?= date('Y') ?> <?= $storeName ?>. Toko Online Resmi Milik <?= $storeName ?> v<?= APP_VERSION ?>
                </div>
            </div>
        </footer>
    </div>
    
    <div class="toast-container" id="toastContainer"></div>
    <script src="<?= ASSETS_URL ?>/js/app.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const slider = document.getElementById('heroSlider');
            if (!slider) return;
            
            const dots = document.querySelectorAll('.slider-dot');
            const slideCount = dots.length;
            let currentSlide = 0;
            let slideInterval;
            
            function goToSlide(index) {
                currentSlide = index;
                if (currentSlide >= slideCount) currentSlide = 0;
                if (currentSlide < 0) currentSlide = slideCount - 1;
                
                slider.style.transform = `translateX(-${currentSlide * 33.3333}%)`;
                
                dots.forEach((dot, i) => {
                    dot.style.opacity = i === currentSlide ? '1' : '0.4';
                });
            }
            
            function nextSlide() {
                goToSlide(currentSlide + 1);
            }
            
            function startSlideShow() {
                stopSlideShow();
                slideInterval = setInterval(nextSlide, 7000);
            }
            
            function stopSlideShow() {
                if (slideInterval) clearInterval(slideInterval);
            }
            
            dots.forEach((dot, i) => {
                dot.addEventListener('click', () => {
                    goToSlide(i);
                    startSlideShow();
                });
            });
            
            // Touch / Drag events
            let startX = 0;
            let currentX = 0;
            let isDragging = false;
            
            slider.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
                isDragging = true;
                stopSlideShow();
                slider.style.transition = 'none';
            }, {passive: true});
            
            slider.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                currentX = e.touches[0].clientX;
                const diff = currentX - startX;
                const percentDiff = (diff / slider.offsetWidth) * 100;
                const baseTranslate = -(currentSlide * 33.3333);
                slider.style.transform = `translateX(${baseTranslate + percentDiff}%)`;
            }, {passive: true});
            
            slider.addEventListener('touchend', (e) => {
                if (!isDragging) return;
                isDragging = false;
                slider.style.transition = 'transform 0.5s ease-in-out';
                const diff = currentX - startX;
                
                if (Math.abs(diff) > 50 && currentX !== 0) { // minimum threshold for swipe
                    if (diff > 0) goToSlide(currentSlide - 1); // swiped right
                    else goToSlide(currentSlide + 1); // swiped left
                } else {
                    goToSlide(currentSlide); // snap back
                }
                
                currentX = 0;
                startSlideShow();
            });
            
            // Mouse Drag Events
            const wrapper = document.querySelector('.hero-slider-wrapper');
            wrapper.addEventListener('mousedown', (e) => {
                startX = e.clientX;
                isDragging = true;
                stopSlideShow();
                slider.style.transition = 'none';
                wrapper.style.cursor = 'grabbing';
            });
            
            window.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                currentX = e.clientX;
                const diff = currentX - startX;
                const percentDiff = (diff / slider.offsetWidth) * 100;
                const baseTranslate = -(currentSlide * 33.3333);
                slider.style.transform = `translateX(${baseTranslate + percentDiff}%)`;
            });
            
            window.addEventListener('mouseup', (e) => {
                if (!isDragging) return;
                isDragging = false;
                slider.style.transition = 'transform 0.5s ease-in-out';
                wrapper.style.cursor = 'grab';
                
                const diff = currentX - startX;
                if (Math.abs(diff) > 50 && currentX !== 0) {
                    if (diff > 0) goToSlide(currentSlide - 1);
                    else goToSlide(currentSlide + 1);
                } else {
                    goToSlide(currentSlide);
                }
                
                currentX = 0;
                startSlideShow();
            });
            
            startSlideShow();
        });
    </script>
</body>
</html>
