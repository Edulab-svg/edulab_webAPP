<?php
// ========================================================
// DB接続設定 — 以下の3か所を書き換えてください
// ========================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'xs047468_jobinterview');   // ← DB名に書き換え
define('DB_USER',    'xs047468_jobint');         // ← DBユーザー名に書き換え
define('DB_PASS',    'Uff^n#yypa;/');     // ← DBパスワードに書き換え
define('DB_CHARSET', 'utf8mb4');

// アップロードファイルの保存先
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', '/jobinterview/api/uploads/');     // ← サイトのパスに合わせて変更

// 許可するファイル種別
define('ALLOWED_MIME',  ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf']);
define('MAX_FILE_SIZE',  10 * 1024 * 1024); // 10MB

// ========================================================
// 以下は変更不要
// ========================================================
function get_pdo(): PDO {
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
