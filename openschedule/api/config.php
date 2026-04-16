<?php
// ═══════════════════════════════════════
// データベース接続設定
// ★ Xserverの管理画面で確認した情報に書き換えてください
// ═══════════════════════════════════════

// Xserver「MySQL設定」→「MySQL情報」タブで確認できます
define('DB_HOST', 'localhost');               // ← Xserverは localhost でOK
define('DB_NAME', 'xs047468_openschedule');
define('DB_USER', 'xs047468_opemsch');
define('DB_PASS', 'manten10');
define('DB_CHARSET', 'utf8mb4');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function uid() {
    return sprintf('%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(4)), bin2hex(random_bytes(4)));
}