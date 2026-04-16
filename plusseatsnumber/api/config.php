<?php
// ============================================
// DB接続設定 — 3か所を書き換えてください
// ============================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'xs047468_plusseatsnumber');      // ← ① データベース名
define('DB_USER',    'xs047468_seatnum');      // ← ② ユーザー名
define('DB_PASS',    'J+^;Op#~=7L0');  // ← ③ パスワード
define('DB_CHARSET', 'utf8mb4');

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
