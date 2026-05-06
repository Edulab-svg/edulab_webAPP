<?php
// ============================================================
// まんてん個別プラス シミュレーター — DB接続設定
// ============================================================
// ★ 以下の3か所をご自身の環境に書き換えてください ★

define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_plusmodel');   // ← ★ DB名に書き換え
define('DB_USER', 'xs047468_pmodel');         // ← ★ DBユーザー名に書き換え
define('DB_PASS', 'qcXX5U/:m*P^');     // ← ★ DBパスワードに書き換え
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 以下は変更不要
// ============================================================
function getDB(): PDO {
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
