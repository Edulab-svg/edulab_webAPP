<?php
// ============================================================
//  DB接続設定ファイル
//  ★ 以下の3か所を書き換えてください ★
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_kyuujinsyuukei');       // ★ データベース名に書き換え
define('DB_USER', 'xs047468_kyuujin');       // ★ MySQLユーザー名に書き換え
define('DB_PASS', '(3ScBNE%S3H}');   // ★ MySQLパスワードに書き換え
define('DB_CHARSET', 'utf8mb4');

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}
