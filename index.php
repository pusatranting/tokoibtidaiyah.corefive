<?php
/**
 * Kasir Ibtidaiyah - Router / Index
 * Redirect berdasarkan status login & role
 */
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    if (isCustomer()) {
        redirect(BASE_URL . '/shop/index.php');
    }
    redirectAfterLogin();
} else {
    // Pengunjung default masuk ke halaman toko, bukan login.
    // Login hanya diperlukan saat checkout / akun.
    redirect(BASE_URL . '/shop/index.php');
}
