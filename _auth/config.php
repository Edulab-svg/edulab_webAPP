<?php
// ポータル認証 — DB（xs047468_mantan）接続
// 既存の全事業部用DBと同じ。認証専用テーブルは portal_users。

define('PORTAL_DB_HOST', 'localhost');
define('PORTAL_DB_NAME', 'xs047468_mantan');
define('PORTAL_DB_USER', 'xs047468_mantan');
define('PORTAL_DB_PASS', '0Ra^Bx:TH0_C');
define('PORTAL_DB_CHARSET', 'utf8mb4');

function getPortalPdo() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . PORTAL_DB_HOST . ';dbname=' . PORTAL_DB_NAME . ';charset=' . PORTAL_DB_CHARSET;
    $pdo = new PDO($dsn, PORTAL_DB_USER, PORTAL_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
