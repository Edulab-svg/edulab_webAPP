<?php
// ============================================================
// DB接続設定（全事業部共通）
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_mantan');
define('DB_USER', 'xs047468_mantan');
define('DB_PASS', '0Ra^Bx:TH0_C');
define('DB_CHARSET', 'utf8mb4');

require_once __DIR__ . '/../../../_auth/auth.php';

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function checkAuth(): void {
    portal_require_api_session_json(true);
}
