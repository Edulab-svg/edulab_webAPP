<?php
// ============================================================
// まんてん個別 広告配布管理 API
// GET  /api/?action=xxx   データ取得
// POST /api/              action パラメータで操作を区別
// ============================================================

require_once __DIR__ . '/config.php';

// --- CORSとキャッシュ制御 ---
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// --- リクエスト振り分け ---
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        handle_get();
    } elseif ($method === 'POST') {
        handle_post();
    } else {
        json_error('Method Not Allowed', 405);
    }
} catch (PDOException $e) {
    json_error('DB Error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    json_error($e->getMessage(), 400);
}

// ============================================================
// GET ハンドラ
// ============================================================
function handle_get(): void {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'sheets':
            get_sheets();
            break;
        case 'classrooms':
            $sheet_id = (int)($_GET['sheet_id'] ?? 0);
            if (!$sheet_id) throw new Exception('sheet_id required');
            get_classrooms($sheet_id);
            break;
        case 'distributions':
            $classroom_id = (int)($_GET['classroom_id'] ?? 0);
            if (!$classroom_id) throw new Exception('classroom_id required');
            get_distributions($classroom_id);
            break;
        case 'all':
            // シート・教室・配布情報をまとめて取得（初期ロード用）
            $sheet_id = (int)($_GET['sheet_id'] ?? 0);
            if (!$sheet_id) throw new Exception('sheet_id required');
            get_all($sheet_id);
            break;
        default:
            throw new Exception('Unknown action: ' . $action);
    }
}

// ============================================================
// POST ハンドラ
// ============================================================
function handle_post(): void {
    $body   = file_get_contents('php://input');
    $data   = json_decode($body, true);
    $action = $data['action'] ?? ($_POST['action'] ?? '');

    switch ($action) {
        // --- シート ---
        case 'add_sheet':
            add_sheet($data);
            break;
        case 'delete_sheet':
            delete_sheet($data);
            break;

        // --- 教室 ---
        case 'add_classroom':
            add_classroom($data);
            break;
        case 'delete_classroom':
            delete_classroom($data);
            break;

        // --- 配布情報 ---
        case 'add_distribution':
            add_distribution($data);
            break;
        case 'update_distribution':
            update_distribution($data);
            break;
        case 'delete_distribution':
            delete_distribution($data);
            break;
        case 'toggle_pp':
            toggle_pp($data);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }
}

// ============================================================
// GET 関数群
// ============================================================

function get_sheets(): void {
    $pdo  = get_pdo();
    $stmt = $pdo->query('SELECT id, name, sort_order FROM sheets ORDER BY sort_order, id');
    json_ok($stmt->fetchAll());
}

function get_classrooms(int $sheet_id): void {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT id, sheet_id, name, sort_order FROM classrooms WHERE sheet_id=? ORDER BY sort_order, id'
    );
    $stmt->execute([$sheet_id]);
    json_ok($stmt->fetchAll());
}

function get_distributions(int $classroom_id): void {
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT * FROM distributions WHERE classroom_id=? ORDER BY sort_order, id'
    );
    $stmt->execute([$classroom_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['pp_registered'] = (bool)$r['pp_registered'];
        $r['pp_delivered']  = (bool)$r['pp_delivered'];
        $r['copies']        = (int)$r['copies'];
        $r['order_qty']     = (int)$r['order_qty'];
        $r['print_cost']    = (int)$r['print_cost'];
        $r['dist_cost']     = (int)$r['dist_cost'];
        $r['total_cost']    = (int)$r['total_cost'];
    }
    json_ok($rows);
}

function get_all(int $sheet_id): void {
    $pdo = get_pdo();

    // 教室一覧
    $stmt = $pdo->prepare(
        'SELECT id, name, sort_order FROM classrooms WHERE sheet_id=? ORDER BY sort_order, id'
    );
    $stmt->execute([$sheet_id]);
    $classrooms = $stmt->fetchAll();

    if (empty($classrooms)) {
        json_ok(['classrooms' => []]);
        return;
    }

    // 配布情報をまとめて取得
    $ids        = array_column($classrooms, 'id');
    $in         = implode(',', array_fill(0, count($ids), '?'));
    $stmt       = $pdo->prepare(
        "SELECT * FROM distributions WHERE classroom_id IN ($in) ORDER BY classroom_id, sort_order, id"
    );
    $stmt->execute($ids);
    $all_dists  = $stmt->fetchAll();

    // classroom_id でグルーピング
    $dist_map = [];
    foreach ($all_dists as $d) {
        $cid = $d['classroom_id'];
        $d['pp_registered'] = (bool)$d['pp_registered'];
        $d['pp_delivered']  = (bool)$d['pp_delivered'];
        $d['copies']        = (int)$d['copies'];
        $d['order_qty']     = (int)$d['order_qty'];
        $d['print_cost']    = (int)$d['print_cost'];
        $d['dist_cost']     = (int)$d['dist_cost'];
        $d['total_cost']    = (int)$d['total_cost'];
        $dist_map[$cid][]   = $d;
    }

    foreach ($classrooms as &$c) {
        $c['distributions'] = $dist_map[$c['id']] ?? [];
    }

    json_ok(['classrooms' => $classrooms]);
}

// ============================================================
// POST 関数群 — シート
// ============================================================

function add_sheet(array $d): void {
    $name = trim($d['name'] ?? '');
    if ($name === '') throw new Exception('name required');
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO sheets (name, sort_order) VALUES (?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM sheets s2))'
    );
    $stmt->execute([$name]);
    json_ok(['id' => (int)$pdo->lastInsertId(), 'name' => $name]);
}

function delete_sheet(array $d): void {
    $id = (int)($d['id'] ?? 0);
    if (!$id) throw new Exception('id required');
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('DELETE FROM sheets WHERE id=?');
    $stmt->execute([$id]);
    json_ok(['deleted' => $id]);
}

// ============================================================
// POST 関数群 — 教室
// ============================================================

function add_classroom(array $d): void {
    $sheet_id = (int)($d['sheet_id'] ?? 0);
    $name     = trim($d['name'] ?? '');
    if (!$sheet_id || $name === '') throw new Exception('sheet_id and name required');
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO classrooms (sheet_id, name, sort_order)
         VALUES (?, ?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM classrooms c2 WHERE c2.sheet_id=?))'
    );
    $stmt->execute([$sheet_id, $name, $sheet_id]);
    json_ok(['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'sheet_id' => $sheet_id, 'distributions' => []]);
}

function delete_classroom(array $d): void {
    $id = (int)($d['id'] ?? 0);
    if (!$id) throw new Exception('id required');
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('DELETE FROM classrooms WHERE id=?');
    $stmt->execute([$id]);
    json_ok(['deleted' => $id]);
}

// ============================================================
// POST 関数群 — 配布情報
// ============================================================

function add_distribution(array $d): void {
    $cid = (int)($d['classroom_id'] ?? 0);
    if (!$cid) throw new Exception('classroom_id required');

    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO distributions
           (classroom_id, type, vendor, copies, order_qty, times,
            order_date, arrival_date, delivery_date,
            print_cost, dist_cost, total_cost,
            campaign, period, pp_registered, pp_delivered, sort_order)
         VALUES
           (?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, 0, 0,
            (SELECT COALESCE(MAX(sort_order),0)+1 FROM distributions d2 WHERE d2.classroom_id=?))'
    );
    $stmt->execute([
        $cid,
        $d['type']          ?? 'ポスティング',
        $d['vendor']        ?? '',
        (int)($d['copies']      ?? 0),
        (int)($d['order_qty']   ?? 0),
        (int)($d['times']       ?? 1),
        nullify($d['order_date']    ?? ''),
        nullify($d['arrival_date']  ?? ''),
        nullify($d['delivery_date'] ?? ''),
        (int)($d['print_cost']  ?? 0),
        (int)($d['dist_cost']   ?? 0),
        (int)($d['total_cost']  ?? 0),
        $d['campaign']      ?? '',
        $d['period']        ?? '',
        $cid,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    // 挿入したレコードを返す
    $stmt2 = $pdo->prepare('SELECT * FROM distributions WHERE id=?');
    $stmt2->execute([$new_id]);
    $row = $stmt2->fetch();
    $row['pp_registered'] = false;
    $row['pp_delivered']  = false;
    $row['copies']        = (int)$row['copies'];
    $row['order_qty']     = (int)$row['order_qty'];
    $row['print_cost']    = (int)$row['print_cost'];
    $row['dist_cost']     = (int)$row['dist_cost'];
    $row['total_cost']    = (int)$row['total_cost'];
    json_ok($row);
}

function update_distribution(array $d): void {
    $id = (int)($d['id'] ?? 0);
    if (!$id) throw new Exception('id required');

    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE distributions SET
           type=?, vendor=?, copies=?, order_qty=?, times=?,
           order_date=?, arrival_date=?, delivery_date=?,
           print_cost=?, dist_cost=?, total_cost=?,
           campaign=?, period=?
         WHERE id=?'
    );
    $stmt->execute([
        $d['type']          ?? 'ポスティング',
        $d['vendor']        ?? '',
        (int)($d['copies']      ?? 0),
        (int)($d['order_qty']   ?? 0),
        (int)($d['times']       ?? 1),
        nullify($d['order_date']    ?? ''),
        nullify($d['arrival_date']  ?? ''),
        nullify($d['delivery_date'] ?? ''),
        (int)($d['print_cost']  ?? 0),
        (int)($d['dist_cost']   ?? 0),
        (int)($d['total_cost']  ?? 0),
        $d['campaign']      ?? '',
        $d['period']        ?? '',
        $id,
    ]);
    json_ok(['updated' => $id]);
}

function delete_distribution(array $d): void {
    $id = (int)($d['id'] ?? 0);
    if (!$id) throw new Exception('id required');
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('DELETE FROM distributions WHERE id=?');
    $stmt->execute([$id]);
    json_ok(['deleted' => $id]);
}

function toggle_pp(array $d): void {
    $id    = (int)($d['id']    ?? 0);
    $field = $d['field'] ?? '';
    if (!$id || !in_array($field, ['pp_registered', 'pp_delivered'])) {
        throw new Exception('id and valid field required');
    }
    $value = (int)(bool)($d['value'] ?? false);

    $pdo  = get_pdo();

    if ($field === 'pp_delivered' && $value === 1) {
        // 納品ON → 登録も自動ON
        $stmt = $pdo->prepare('UPDATE distributions SET pp_registered=1, pp_delivered=1 WHERE id=?');
        $stmt->execute([$id]);
    } elseif ($field === 'pp_registered' && $value === 0) {
        // 登録OFF → 納品も自動OFF
        $stmt = $pdo->prepare('UPDATE distributions SET pp_registered=0, pp_delivered=0 WHERE id=?');
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("UPDATE distributions SET {$field}=? WHERE id=?");
        $stmt->execute([$value, $id]);
    }

    // 最新値を返す
    $stmt2 = $pdo->prepare('SELECT pp_registered, pp_delivered FROM distributions WHERE id=?');
    $stmt2->execute([$id]);
    $row = $stmt2->fetch();
    json_ok([
        'id'            => $id,
        'pp_registered' => (bool)$row['pp_registered'],
        'pp_delivered'  => (bool)$row['pp_delivered'],
    ]);
}

// ============================================================
// ユーティリティ
// ============================================================

function nullify(string $s): ?string {
    $s = trim($s);
    return $s === '' ? null : $s;
}

function json_ok(mixed $data): void {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
