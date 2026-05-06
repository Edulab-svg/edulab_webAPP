<?php
// ============================================================
// DB接続設定
// 以下の3か所を書き換えてください
// ============================================================

define('DB_HOST', 'localhost');          // 通常このまま
define('DB_NAME', 'xs047468_koukokukanri');      // ← ★ DB名を書き換え
define('DB_USER', 'xs047468_koukoku');      // ← ★ ユーザー名を書き換え
define('DB_PASS', 'Vu.,Z2f&.D$N');  // ← ★ パスワードを書き換え
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 接続関数（変更不要）
// ============================================================
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
