<?php
// =============================================
// DB接続設定 --- 以下3か所を書き換えてください
// =============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_hrsystem');      // ← データベース名
define('DB_USER', 'xs047468_hrsys');      // ← ユーザー名
define('DB_PASS', '/vlCOcD#12/R');  // ← パスワード
define('DB_CHARSET', 'utf8mb4');

// =============================================
// kintone連携設定
// =============================================
define('KINTONE_SUBDOMAIN',   '2f0arh5d0ae0');
define('KINTONE_APP_ID',      110);
define('KINTONE_API_TOKEN',   'gw21jETcrHkiUP9UF968YjYpclBvAEdRARzT1UXc');
define('KINTONE_NAME_FIELD',  '氏名');              // 氏名フィールドコード
define('KINTONE_LEAVE_FIELD', '有給残日数');         // 有給残日数フィールドコード

// =============================================
// 以下は変更不要
// =============================================
function get_pdo(): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, DB_USER, DB_PASS, $options);
}