<?php
// ============================================================
// DB接続設定 — 以下の3箇所を書き換えてください
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_jinjikouka');       // ← ①データベース名に書き換え
define('DB_USER', 'xs047468_jinji');       // ← ②データベースユーザー名に書き換え
define('DB_PASS', 'jinji2026');   // ← ③データベースパスワードに書き換え
define('DB_CHARSET', 'utf8mb4');

// セッショントークンの有効期限（秒）
define('SESSION_TTL', 60 * 60 * 8); // 8時間

// ============================================================
// DB接続（PDO）— 通常は変更不要
// ============================================================
function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
