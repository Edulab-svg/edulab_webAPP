<?php
/**
 * kintone Webhook受信エンドポイント
 * 【マスタ】社員の追加・編集・削除をHRシステムのDBに反映する
 *
 * 対応イベント:
 *   ADD_RECORD    → employees に INSERT（kintone_id を保持）
 *   EDIT_RECORD   → 氏名・入社年月日・週労働日数を UPDATE
 *   DELETE_RECORD → retired=1 に更新（論理削除・退職扱い）
 *
 * kintone設定:
 *   URL    : https://xs047468.xsrv.jp/hrsystem/api/kintone_webhook.php
 *   イベント: レコードの追加・編集・削除（3つすべて有効にする）
 *   トークン: config.php の KINTONE_WEBHOOK_TOKEN
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// ── Webhook トークン検証 ────────────────────────────────────────────
$token = $_SERVER['HTTP_X_CYBOZU_WEBHOOK_TOKEN'] ?? '';
if (KINTONE_WEBHOOK_TOKEN !== '' && !hash_equals(KINTONE_WEBHOOK_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── リクエストボディ取得 ────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// ── 送信元アプリIDを検証 ────────────────────────────────────────────
$app_id = (int)($data['app']['id'] ?? 0);
if ($app_id !== KINTONE_APP_ID) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'アプリIDが一致しません']);
    exit;
}

$event_type = $data['type']     ?? '';
$kintone_id = (int)($data['recordId'] ?? 0);

try {
    $pdo = get_pdo();

    // kintone_id カラムが存在しない場合は自動追加（初回のみALTER実行）
    $col_exists = $pdo->query("SHOW COLUMNS FROM employees LIKE 'kintone_id'")->fetch();
    if (!$col_exists) {
        $pdo->exec(
            "ALTER TABLE employees
             ADD COLUMN kintone_id INT NULL DEFAULT NULL AFTER name,
             ADD UNIQUE INDEX uq_kintone_id (kintone_id)"
        );
    }

    // ── ADD_RECORD ──────────────────────────────────────────────────
    if ($event_type === 'ADD_RECORD') {

        $record    = $data['record'] ?? [];
        $name      = trim($record[KINTONE_NAME_FIELD]['value']     ?? '');
        $hire_date = trim($record[KINTONE_HIREDATE_FIELD]['value'] ?? '');
        $wdpw_raw  = trim($record[KINTONE_WDPW_FIELD]['value']    ?? '');
        $wdpw      = ($wdpw_raw !== '') ? (int)$wdpw_raw : 5;

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
            $wdpw = 5;
        }

        // 同一 kintone_id が既に存在する場合はスキップ
        if ($kintone_id > 0) {
            $chk = $pdo->prepare('SELECT id FROM employees WHERE kintone_id = ?');
            $chk->execute([$kintone_id]);
            if ($chk->fetch()) {
                echo json_encode(['ok' => true, 'skipped' => true, 'reason' => '同一kintoneレコードが既に登録済みです']);
                exit;
            }
        }

        // 同名在籍チェック（後方互換）
        $chk = $pdo->prepare('SELECT id FROM employees WHERE name = ? AND retired = 0');
        $chk->execute([$name]);
        if ($chk->fetch()) {
            echo json_encode(['ok' => true, 'skipped' => true, 'reason' => '同名の在籍社員が既に存在します']);
            exit;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO employees (name, hire_date, work_days_per_week, kintone_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $hire_date, $wdpw, $kintone_id ?: null]);
        $new_id = (int)$pdo->lastInsertId();

        echo json_encode(['ok' => true, 'id' => $new_id, 'name' => $name, 'kintone_id' => $kintone_id]);

    // ── EDIT_RECORD ─────────────────────────────────────────────────
    } elseif ($event_type === 'EDIT_RECORD') {

        if ($kintone_id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'recordId が取得できません']);
            exit;
        }

        $record    = $data['record'] ?? [];
        $name      = trim($record[KINTONE_NAME_FIELD]['value']     ?? '');
        $hire_date = trim($record[KINTONE_HIREDATE_FIELD]['value'] ?? '');
        $wdpw_raw  = trim($record[KINTONE_WDPW_FIELD]['value']    ?? '');
        $wdpw      = ($wdpw_raw !== '') ? (int)$wdpw_raw : null;

        // kintone_id で社員を検索
        $stmt = $pdo->prepare('SELECT id, name FROM employees WHERE kintone_id = ? AND retired = 0');
        $stmt->execute([$kintone_id]);
        $emp = $stmt->fetch();

        // kintone_id で見つからない場合は氏名でフォールバック（旧データ対応）
        if (!$emp && $name !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, name FROM employees WHERE name = ? AND retired = 0 AND kintone_id IS NULL'
            );
            $stmt->execute([$name]);
            $emp = $stmt->fetch();
            if ($emp) {
                // kintone_id を紐づけて今後の更新に備える
                $pdo->prepare('UPDATE employees SET kintone_id = ? WHERE id = ?')
                    ->execute([$kintone_id, $emp['id']]);
            }
        }

        if (!$emp) {
            echo json_encode([
                'ok'      => true,
                'skipped' => true,
                'reason'  => '対応する社員がDBに見つかりません (kintone_id=' . $kintone_id . ')',
            ]);
            exit;
        }

        // 更新フィールドを動的に構築（値があるもののみ）
        $updates = [];
        $params  = [];

        if ($name !== '') {
            $updates[] = 'name = ?';
            $params[]  = $name;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date)) {
            $updates[] = 'hire_date = ?';
            $params[]  = $hire_date;
        }
        if ($wdpw !== null && $wdpw >= 1 && $wdpw <= 5) {
            $updates[] = 'work_days_per_week = ?';
            $params[]  = $wdpw;
        }

        if (empty($updates)) {
            echo json_encode(['ok' => true, 'skipped' => true, 'reason' => '更新対象フィールドなし']);
            exit;
        }

        $params[] = $emp['id'];
        $pdo->prepare('UPDATE employees SET ' . implode(', ', $updates) . ' WHERE id = ?')
            ->execute($params);

        echo json_encode(['ok' => true, 'updated_id' => $emp['id'], 'name' => $name]);

    // ── DELETE_RECORD ───────────────────────────────────────────────
    } elseif ($event_type === 'DELETE_RECORD') {

        if ($kintone_id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'recordId が取得できません']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, name FROM employees WHERE kintone_id = ? AND retired = 0');
        $stmt->execute([$kintone_id]);
        $emp = $stmt->fetch();

        if (!$emp) {
            echo json_encode([
                'ok'      => true,
                'skipped' => true,
                'reason'  => '対応する在籍社員がDBに見つかりません (kintone_id=' . $kintone_id . ')',
            ]);
            exit;
        }

        // 論理削除（退職扱い）
        $pdo->prepare('UPDATE employees SET retired = 1, retire_date = CURDATE() WHERE id = ?')
            ->execute([$emp['id']]);

        echo json_encode(['ok' => true, 'retired_id' => $emp['id'], 'name' => $emp['name']]);

    // ── その他のイベントは無視 ──────────────────────────────────────
    } else {
        echo json_encode(['ok' => true, 'skipped' => true, 'type' => $event_type]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
}
