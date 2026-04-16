<?php
// ============================================================
//  api/config.php  — データベース接続設定
//  ★ 下記3か所を書き換えてから FTP アップロードしてください
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'xs047468_cf');      // ★ DB名を書き換え
define('DB_USER',    'xs047468_cf');      // ★ ユーザー名を書き換え
define('DB_PASS',    '3D.GD,fGRSdF');  // ★ パスワードを書き換え
define('DB_CHARSET', 'utf8mb4');

// ------------------------------------------------------------
//  PDO 接続ファクトリ
// ------------------------------------------------------------
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
                       DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ------------------------------------------------------------
//  共通レスポンスヘルパー
// ------------------------------------------------------------
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    header('Pragma: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['success' => false, 'error' => $message], $status);
}

function json_ok(array $data = []): void {
    json_response(array_merge(['success' => true], $data));
}