<?php
/**
 * kintone Webhook受信エンドポイント
 * 【マスタ】社員にレコードが追加されたとき、HRシステムのDBに社員を登録する
 *
 * kintone設定:
 *   URL    : https://xs047468.xsrv.jp/hrsystem/api/kintone_webhook.php
 *   イベント: レコードの追加
 *   トークン: config.php の KINTONE_WEBHOOK_TOKEN
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// ── Webhook トークン検証（トークンが設定されている場合のみ検証）────
$token = $_SERVER['HTTP_X_CYBOZU_WEBHOOK_TOKEN'] ?? '';
if (KINTONE_WEBHOOK_TOKEN !== '' && !hash_equals(KINTONE_WEBHOOK_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── リクエストボディ取得 ────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// ── 送信元アプリIDを検証 ────────────────────────────────
$app_id = (int)($data['app']['id'] ?? 0);
if ($app_id !== KINTONE_APP_ID) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'アプリIDが一致しません']);
    exit;
}

// ── レコード追加イベントのみ処理 ────────────────────────
if (($data['type'] ?? '') !== 'ADD_RECORD') {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

// ── フィールド値を取得 ──────────────────────────────────
$record    = $data['record'] ?? [];
$name      = trim($record[KINTONE_NAME_FIELD]['value']    ?? '');
$hire_date = trim($record[KINTONE_HIREDATE_FIELD]['value'] ?? '');
$wdpw_raw  = trim($record[KINTONE_WDPW_FIELD]['value']    ?? '');
$wdpw      = ($wdpw_raw !== '') ? (int)$wdpw_raw : 5;

// ── バリデーション ──────────────────────────────────────
if ($name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '氏名が空です']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '入社年月日が不正です: ' . $hire_date]);
    exit;
}
if ($wdpw < 1 || $wdpw > 5) {
    $wdpw = 5; // 範囲外の場合はデフォルト5日
}

// ── DB に社員を登録 ────────────────────────────────────
try {
    $pdo = get_pdo();

    // 同名社員が既に在籍中でないか確認
    $chk = $pdo->prepare('SELECT id FROM employees WHERE name = ? AND retired = 0');
    $chk->execute([$name]);
    if ($chk->fetch()) {
        echo json_encode(['ok' => true, 'skipped' => true, 'reason' => '同名の在籍社員が既に存在します']);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO employees (name, hire_date, work_days_per_week) VALUES (?, ?, ?)'
    );
    $stmt->execute([$name, $hire_date, $wdpw]);
    $new_id = (int)$pdo->lastInsertId();

    echo json_encode(['ok' => true, 'id' => $new_id, 'name' => $name]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
}
