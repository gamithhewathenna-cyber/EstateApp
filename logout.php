<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

Auth::start();

// Clear remember me token from DB and cookie
if (!empty($_COOKIE['remember_token'])) {
    try {
        DB::execute("DELETE FROM remember_tokens WHERE token=?", [$_COOKIE['remember_token']]);
    } catch (Exception $e) {}
    setcookie('remember_token', '', time() - 3600, '/');
}

Auth::logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
