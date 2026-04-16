<?php
// ============================================================
// DB接続設定（全事業部共通）
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_mantan');
define('DB_USER', 'xs047468_mantan');
define('DB_PASS', '0Ra^Bx:TH0_C');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// シミュレーター認証パスワード（任意の文字列に変更してください）
// ============================================================
define('SIMULATOR_PASSWORD', 'manten2025');

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

function checkAuth() {
    $token = $_SERVER['HTTP_X_SIM_TOKEN'] ?? '';
    if ($token !== SIMULATOR_PASSWORD) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}
