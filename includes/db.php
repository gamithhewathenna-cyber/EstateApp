<?php
require_once __DIR__ . '/config.php';

class DB {
    private static $pdo = null;

    public static function connect() {
        if (self::$pdo === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
            }
        }
        return self::$pdo;
    }

    public static function query($sql, $params = []) {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne($sql, $params = []) {
        return self::query($sql, $params)->fetch();
    }

    public static function insert($sql, $params = []) {
        self::query($sql, $params);
        return self::connect()->lastInsertId();
    }

    public static function execute($sql, $params = []) {
        return self::query($sql, $params)->rowCount();
    }
}
