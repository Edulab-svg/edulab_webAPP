<?php
// ============================================================
// リスティング広告 集計ダッシュボード - API
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// CORS（同一ドメインなら不要だが念のため）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/config.php';

// アクション判定（POST優先）
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    $action = $input['action'] ?? '';
} else {
    $action = $_GET['action'] ?? '';
}

function ok($data) {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function ng($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ルーティング
switch ($action) {

    // ────────── 月データ取得 ──────────
    case 'get_data':
        $month = $_GET['month'] ?? ($input['month'] ?? '');
        if (!$month) ng('month パラメータが必要です');
        $stmt = $pdo->prepare('SELECT account, cost, top_display, remarketing, other_cost, yahoo, sort_order FROM monthly_data WHERE month = ? ORDER BY sort_order');
        $stmt->execute([$month]);
        ok($stmt->fetchAll());
        break;

    // ────────── 全月サマリ取得 ──────────
    case 'get_all_summary':
        $stmt = $pdo->query('SELECT month, SUM(cost + top_display + remarketing + other_cost) AS google, SUM(yahoo) AS yahoo FROM monthly_data GROUP BY month');
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $result[$r['month']] = [
                'google' => (int)$r['google'],
                'yahoo'  => (int)$r['yahoo'],
                'total'  => (int)$r['google'] + (int)$r['yahoo'],
            ];
        }
        ok($result);
        break;

    // ────────── セル更新 ──────────
    case 'save_cell':
        $month   = $input['month'] ?? '';
        $account = $input['account'] ?? '';
        $field   = $input['field'] ?? '';
        $value   = (int)($input['value'] ?? 0);
        $allowed = ['cost', 'top_display', 'remarketing', 'other_cost', 'yahoo'];
        if (!$month || !$account || !in_array($field, $allowed)) ng('パラメータ不正');
        $stmt = $pdo->prepare("UPDATE monthly_data SET {$field} = ? WHERE month = ? AND account = ?");
        $stmt->execute([$value, $month, $account]);
        if ($stmt->rowCount() === 0) {
            // 行がなければ INSERT
            $stmt2 = $pdo->prepare("INSERT INTO monthly_data (month, account, {$field}, sort_order) VALUES (?, ?, ?, 99) ON DUPLICATE KEY UPDATE {$field} = ?");
            $stmt2->execute([$month, $account, $value, $value]);
        }
        ok(['updated' => true]);
        break;

    // ────────── 月データ一括保存（CSVインポート用） ──────────
    case 'save_month':
        $month = $input['month'] ?? '';
        $rows  = $input['rows'] ?? [];
        $src   = $input['source'] ?? 'google'; // 'google' or 'yahoo'
        if (!$month || !is_array($rows)) ng('パラメータ不正');

        $pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $account = $row['account'] ?? '';
                if (!$account) continue;

                if ($src === 'google') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO monthly_data (month, account, cost, top_display, remarketing, other_cost, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE cost = VALUES(cost), top_display = VALUES(top_display),
                         remarketing = VALUES(remarketing), other_cost = VALUES(other_cost), sort_order = VALUES(sort_order)'
                    );
                    $stmt->execute([
                        $month, $account,
                        (int)($row['cost'] ?? 0),
                        (int)($row['top_display'] ?? 0),
                        (int)($row['remarketing'] ?? 0),
                        (int)($row['other_cost'] ?? 0),
                        $i
                    ]);
                } else {
                    // Yahoo: yahoo列のみ更新
                    $stmt = $pdo->prepare(
                        'INSERT INTO monthly_data (month, account, yahoo, sort_order)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE yahoo = VALUES(yahoo)'
                    );
                    $stmt->execute([$month, $account, (int)($row['yahoo'] ?? 0), $i]);
                }
            }
            $pdo->commit();
            ok(['saved' => count($rows)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            ng('保存エラー: ' . $e->getMessage(), 500);
        }
        break;

    // ────────── 月初期化（空データ作成） ──────────
    case 'init_month':
        $month    = $input['month'] ?? '';
        $accounts = $input['accounts'] ?? [];
        if (!$month || !is_array($accounts)) ng('パラメータ不正');

        $pdo->beginTransaction();
        try {
            foreach ($accounts as $i => $acc) {
                $stmt = $pdo->prepare(
                    'INSERT IGNORE INTO monthly_data (month, account, sort_order) VALUES (?, ?, ?)'
                );
                $stmt->execute([$month, $acc, $i]);
            }
            $pdo->commit();
            ok(['initialized' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            ng('初期化エラー: ' . $e->getMessage(), 500);
        }
        break;

    // ────────── 按分ルール取得 ──────────
    case 'get_rules':
        $stmt = $pdo->query('SELECT rule_id, label, match_keywords, column_name, targets, all_options FROM allocation_rules ORDER BY id');
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['match_keywords'] = json_decode($r['match_keywords'], true);
            $r['targets']        = json_decode($r['targets'], true);
            $r['all_options']    = json_decode($r['all_options'], true);
        }
        ok($rows);
        break;

    // ────────── 按分ルール更新 ──────────
    case 'save_rules':
        $rules = $input['rules'] ?? [];
        if (!is_array($rules)) ng('パラメータ不正');

        $pdo->beginTransaction();
        try {
            foreach ($rules as $rule) {
                $ruleId  = $rule['rule_id'] ?? '';
                $targets = $rule['targets'] ?? [];
                if (!$ruleId) continue;
                $stmt = $pdo->prepare('UPDATE allocation_rules SET targets = ? WHERE rule_id = ?');
                $stmt->execute([json_encode($targets, JSON_UNESCAPED_UNICODE), $ruleId]);
            }
            $pdo->commit();
            ok(['updated' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            ng('更新エラー: ' . $e->getMessage(), 500);
        }
        break;

    // ────────── 存在する月の一覧 ──────────
    case 'get_months':
        $stmt = $pdo->query('SELECT DISTINCT month FROM monthly_data');
        $months = array_column($stmt->fetchAll(), 'month');
        ok($months);
        break;

    default:
        ng('不明なアクション: ' . $action, 404);
}
