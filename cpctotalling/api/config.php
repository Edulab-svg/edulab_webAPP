<?php
// ============================================================
// リスティング広告 集計ダッシュボード - DB接続設定
// 以下の3か所を書き換えてください
// ============================================================

// ▼▼▼ ここを書き換え ▼▼▼
$db_name = 'xs047468_cpctotalling';   // ← DB名（例: xs123456_listing）
$db_user = 'xs047468_cpc';         // ← ユーザー名（例: xs123456_user）
$db_pass = 'k(Ia:C]fL9yG';         // ← パスワード
// ▲▲▲ ここまで ▲▲▲

$db_host = 'localhost'; // Xserverの場合は通常 localhost

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'DB接続エラー: ' . $e->getMessage()]);
    exit;
}