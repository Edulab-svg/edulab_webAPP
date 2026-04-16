<?php
// ============================================================
//  API エンドポイント  /api/index.php
//  GET  ?action=...      データ取得
//  POST action=...       データ更新（body: JSON）
// ============================================================

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        handle_get();
    } elseif ($method === 'POST') {
        handle_post();
    } else {
        json_error('Method not allowed', 405);
    }
} catch (PDOException $e) {
    json_error('DB error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    json_error('Server error: ' . $e->getMessage(), 500);
}

// ============================================================
//  GET ハンドラ
// ============================================================
function handle_get(): void {
    $action = $_GET['action'] ?? '';

    switch ($action) {

        // --- 年度一覧 ---
        case 'years':
            $pdo  = get_pdo();
            $rows = $pdo->query(
                "SELECT DISTINCT `fiscal_year` FROM `ad_entries` ORDER BY `fiscal_year`"
            )->fetchAll();
            json_ok(['years' => array_column($rows, 'fiscal_year')]);
            break;

        // --- 求人広告一覧（fiscal_year必須、quarterは任意） ---
        case 'ad_entries':
            $fiscal_year = required_param('year');
            $quarter     = $_GET['quarter'] ?? null;

            $pdo    = get_pdo();
            $sql    = "SELECT * FROM `ad_entries` WHERE `fiscal_year` = ?";
            $params = [$fiscal_year];
            if ($quarter && $quarter !== 'all') {
                $sql     .= " AND `quarter` = ?";
                $params[] = $quarter;
            }
            // 期間順（4～6月→7～9月→10～12月→1～3月）でソート
            $sql .= " ORDER BY FIELD(`quarter`,'4～6月','7～9月','10～12月','1～3月'), `id`";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $entries = $stmt->fetchAll();

            // CPR / CPO 計算・数値キャスト（DB値は税込のためそのまま使用）
            foreach ($entries as &$r) {
                $r['cost']        = (int) $r['cost'];
                $r['apply_count'] = (int) $r['apply_count'];
                $r['interview']   = (int) $r['interview'];
                $r['offer_count'] = (int) $r['offer_count'];
                $r['hire_count']  = (int) $r['hire_count'];
                $r['cpr'] = $r['apply_count'] > 0
                    ? (int) round($r['cost'] / $r['apply_count']) : null;
                $r['cpo'] = $r['hire_count'] > 0
                    ? (int) round($r['cost'] / $r['hire_count']) : null;
            }
            unset($r);

            // 期間の一覧（4→8→1 の順）
            $qstmt = $pdo->prepare(
                "SELECT DISTINCT `quarter` FROM `ad_entries` WHERE `fiscal_year` = ?
                 ORDER BY FIELD(`quarter`,'4～6月','7～9月','10～12月','1～3月')"
            );
            $qstmt->execute([$fiscal_year]);
            $quarters = array_column($qstmt->fetchAll(), 'quarter');

            json_ok(['entries' => $entries, 'quarters' => $quarters]);
            break;

        // --- 応募者一覧 ---
        case 'applicants':
            $pdo  = get_pdo();
            $rows = $pdo->query(
                "SELECT * FROM `applicants` ORDER BY `recv_date` DESC, `id` DESC"
            )->fetchAll();
            json_ok(['applicants' => $rows]);
            break;

        default:
            json_error('Unknown action: ' . $action, 400);
    }
}

// ============================================================
//  POST ハンドラ
// ============================================================
function handle_post(): void {
    $body   = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? ($_POST['action'] ?? '');

    switch ($action) {

        case 'ad_add':
            $d   = validate_ad($body);
            $pdo = get_pdo();
            $pdo->prepare("
                INSERT INTO `ad_entries`
                  (`fiscal_year`,`quarter`,`media`,`period_str`,`debit_date`,`cost`,
                   `apply_count`,`interview`,`offer_count`,`hire_count`,`hire_names`)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $d['fiscal_year'], $d['quarter'], $d['media'], $d['period_str'],
                $d['debit_date'], $d['cost'],
                $d['apply_count'], $d['interview'], $d['offer_count'],
                $d['hire_count'], $d['hire_names'],
            ]);
            json_ok(['id' => (int) $pdo->lastInsertId(), 'message' => '追加しました']);
            break;

        case 'ad_update':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) json_error('id is required', 400);
            $d   = validate_ad($body);
            $pdo = get_pdo();
            $pdo->prepare("
                UPDATE `ad_entries` SET
                  `fiscal_year`=?, `quarter`=?, `media`=?, `period_str`=?, `debit_date`=?,
                  `cost`=?, `apply_count`=?, `interview`=?, `offer_count`=?,
                  `hire_count`=?, `hire_names`=?
                WHERE `id` = ?
            ")->execute([
                $d['fiscal_year'], $d['quarter'], $d['media'], $d['period_str'],
                $d['debit_date'], $d['cost'],
                $d['apply_count'], $d['interview'], $d['offer_count'],
                $d['hire_count'], $d['hire_names'], $id,
            ]);
            json_ok(['message' => '更新しました']);
            break;

        case 'ad_delete':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) json_error('id is required', 400);
            get_pdo()->prepare("DELETE FROM `ad_entries` WHERE `id` = ?")->execute([$id]);
            json_ok(['message' => '削除しました']);
            break;

        case 'appl_add':
            $d   = validate_appl($body);
            $pdo = get_pdo();
            $pdo->prepare("
                INSERT INTO `applicants`
                  (`recv_date`,`route`,`name`,`gender`,`age`,`current_job`,
                   `has_exp`,`doc_result`,`first_int`,`final_int`,`offer`,`hire`)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $d['recv_date'], $d['route'], $d['name'], $d['gender'], $d['age'],
                $d['current_job'], $d['has_exp'], $d['doc_result'],
                $d['first_int'], $d['final_int'], $d['offer'], $d['hire'],
            ]);
            json_ok(['id' => (int) $pdo->lastInsertId(), 'message' => '追加しました']);
            break;

        case 'appl_update':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) json_error('id is required', 400);
            $d   = validate_appl($body);
            $pdo = get_pdo();
            $pdo->prepare("
                UPDATE `applicants` SET
                  `recv_date`=?, `route`=?, `name`=?, `gender`=?, `age`=?,
                  `current_job`=?, `has_exp`=?, `doc_result`=?,
                  `first_int`=?, `final_int`=?, `offer`=?, `hire`=?
                WHERE `id` = ?
            ")->execute([
                $d['recv_date'], $d['route'], $d['name'], $d['gender'], $d['age'],
                $d['current_job'], $d['has_exp'], $d['doc_result'],
                $d['first_int'], $d['final_int'], $d['offer'], $d['hire'], $id,
            ]);
            json_ok(['message' => '更新しました']);
            break;

        case 'appl_delete':
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) json_error('id is required', 400);
            get_pdo()->prepare("DELETE FROM `applicants` WHERE `id` = ?")->execute([$id]);
            json_ok(['message' => '削除しました']);
            break;

        default:
            json_error('Unknown action: ' . $action, 400);
    }
}

// ============================================================
//  バリデーション
// ============================================================
function validate_ad(array $b): array {
    $media = trim($b['media'] ?? '');
    if ($media === '') json_error('media is required', 400);
    $fiscal_year = trim($b['fiscal_year'] ?? $b['year'] ?? '');
    $quarter     = trim($b['quarter'] ?? '');
    if ($fiscal_year === '' || $quarter === '') json_error('fiscal_year and quarter are required', 400);

    $debit = trim($b['debit_date'] ?? '');
    if ($debit && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $debit)) $debit = '';

    return [
        'fiscal_year' => $fiscal_year,
        'quarter'     => $quarter,
        'media'       => $media,
        'period_str'  => trim($b['period_str'] ?? '') ?: null,
        'debit_date'  => $debit ?: null,
        'cost'        => max(0, (int) ($b['cost'] ?? 0)),
        'apply_count' => max(0, (int) ($b['apply_count'] ?? 0)),
        'interview'   => max(0, (int) ($b['interview'] ?? 0)),
        'offer_count' => max(0, (int) ($b['offer_count'] ?? 0)),
        'hire_count'  => max(0, (int) ($b['hire_count'] ?? 0)),
        'hire_names'  => trim($b['hire_names'] ?? '') ?: null,
    ];
}

function validate_appl(array $b): array {
    $name = trim($b['name'] ?? '');
    if ($name === '') json_error('name is required', 400);

    $recv_date = trim($b['recv_date'] ?? '');
    if ($recv_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $recv_date)) $recv_date = null;
    $age = isset($b['age']) && $b['age'] !== '' ? (int) $b['age'] : null;

    $allowed = ['○', '×', '辞退', ''];
    $clean   = function ($v) use ($allowed) {
        $v = trim($v ?? '');
        return in_array($v, $allowed, true) ? ($v === '' ? null : $v) : null;
    };

    return [
        'recv_date'   => $recv_date ?: null,
        'route'       => trim($b['route'] ?? '') ?: null,
        'name'        => $name,
        'gender'      => trim($b['gender'] ?? '') ?: null,
        'age'         => ($age !== null && $age > 0 && $age < 120) ? $age : null,
        'current_job' => trim($b['current_job'] ?? '') ?: null,
        'has_exp'     => trim($b['has_exp'] ?? '') ?: null,
        'doc_result'  => $clean($b['doc_result'] ?? null),
        'first_int'   => $clean($b['first_int'] ?? null),
        'final_int'   => $clean($b['final_int'] ?? null),
        'offer'       => $clean($b['offer'] ?? null),
        'hire'        => $clean($b['hire'] ?? null),
    ];
}

// ============================================================
//  ユーティリティ
// ============================================================
function required_param(string $key): string {
    $v = trim($_GET[$key] ?? '');
    if ($v === '') json_error("$key is required", 400);
    return $v;
}
function json_ok(array $data): never {
    echo json_encode(['ok' => true, ...$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_error(string $msg, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
