<?php
require_once __DIR__ . '/db.php';

class Auth {
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    public static function login($username, $password) {
        self::start();
        $user = DB::fetchOne("SELECT * FROM users WHERE username=? AND is_active=1", [$username]);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['login_time'] = time();
            unset($_SESSION['active_estate_id'], $_SESSION['active_estate_name'], $_SESSION['active_estate_role']);
            DB::execute("UPDATE users SET last_login=NOW() WHERE id=?", [$user['id']]);
            return true;
        }
        return false;
    }

    public static function logout() {
        self::start();
        session_destroy();
    }

    public static function check() {
        self::start();

        // Prevent browser from caching authenticated pages
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        if (time() - ($_SESSION['login_time'] ?? 0) > SESSION_TIMEOUT) {
            self::logout();
            header('Location: ' . BASE_URL . '/login.php?timeout=1');
            exit;
        }
        $_SESSION['login_time'] = time();

        // Re-read role from DB on every request — prevents stale session role issues
        try {
            $freshUser = DB::fetchOne("SELECT role, is_active FROM users WHERE id=?", [$_SESSION['user_id']]);
            if (!$freshUser || !$freshUser['is_active']) {
                self::logout();
                header('Location: ' . BASE_URL . '/login.php?deactivated=1');
                exit;
            }
            $_SESSION['user_role'] = $freshUser['role'];
        } catch (Exception $e) {}

        // Redirect to estate picker if no estate selected
        if (empty($_SESSION['active_estate_id'])) {
            $current = basename($_SERVER['PHP_SELF']);
            if (!in_array($current, ['estate-picker.php','logout.php','login.php'])) {
                header('Location: ' . BASE_URL . '/estate-picker.php');
                exit;
            }
        }
    }

    public static function requireAdmin() {
        self::check();
        $role = $_SESSION['active_estate_role'] ?? $_SESSION['user_role'] ?? '';
        if ($role !== 'admin') {
            header('Location: ' . BASE_URL . '/index.php?error=access_denied');
            exit;
        }
    }

    public static function isAdmin() {
        self::start();
        $role = $_SESSION['active_estate_role'] ?? $_SESSION['user_role'] ?? '';
        return $role === 'admin';
    }

    public static function user() {
        self::start();
        return [
            'id'   => $_SESSION['user_id']   ?? null,
            'name' => $_SESSION['user_name'] ?? '',
            'role' => $_SESSION['user_role'] ?? '',
        ];
    }

    public static function estateId() {
        self::start();
        if (empty($_SESSION['active_estate_id'])) {
            // No estate selected — default to 1 only as last resort
            // This should not happen in normal flow
            return 1;
        }
        return (int)$_SESSION['active_estate_id'];
    }

    public static function estateName() {
        self::start();
        return $_SESSION['active_estate_name'] ?? '';
    }
}
