<?php
// ============================================
// API エントリポイント
// GET  ?action=xxx  → 取得系
// POST body{action} → 更新系（action パラメータで区別）
// PHP 7.4以上対応・mixed型宣言を除去済み
// ============================================

require_once __DIR__ . '/config.php';

// ---------- レスポンスヘッダー ----------
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------- ヘルパー ----------
// ※ mixed型・never型はPHP8以上専用のため、型宣言を外しています
function json_ok($data = null) {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function body() {
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b   = json_decode($raw, true) ?? [];
        if (empty($b) && !empty($_POST)) $b = $_POST;
    }
    return $b;
}
function str_req($b, $key, $max = 255) {
    $v = trim($b[$key] ?? '');
    if ($v === '') json_err("{$key} は必須です");
    if (mb_strlen($v) > $max) json_err("{$key} が長すぎます");
    return $v;
}

// ---------- ルーティング ----------
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    switch ($_GET['action'] ?? '') {
        case 'schools': action_schools(); break;
        case 'school':  action_school();  break;
        default:        json_err('不明なアクション');
    }
} elseif ($method === 'POST') {
    $b = body();
    switch ($b['action'] ?? '') {
        case 'create_school':  action_create_school($b);  break;
        case 'update_school':  action_update_school($b);  break;
        case 'delete_school':  action_delete_school($b);  break;
        case 'save_simulator': action_save_simulator($b); break;
        default:               json_err('不明なアクション');
    }
} else {
    json_err('許可されていないメソッドです', 405);
}

// ============================================================
// アクション実装
// ============================================================

function action_schools() {
    $pdo  = get_pdo();
    $rows = $pdo->query("
        SELECT s.id, s.name, s.open_date, s.staff_name,
               ss.seats          AS normal_seats,
               ss.default_buffer AS normal_default_buffer,
               ss.slot_buffers   AS normal_slot_buffers,
               ss.students       AS normal_students
        FROM schools s
        LEFT JOIN simulator_states ss
               ON ss.school_id = s.id AND ss.season = 'normal'
        ORDER BY s.id DESC
    ")->fetchAll();

    foreach ($rows as &$r) {
        $r['id']                    = (int)$r['id'];
        $r['normal_seats']          = $r['normal_seats'] !== null ? (int)$r['normal_seats'] : 10;
        $r['normal_default_buffer'] = $r['normal_default_buffer'] !== null ? (int)$r['normal_default_buffer'] : 90;
        $r['normal_slot_buffers']   = $r['normal_slot_buffers'] ? json_decode($r['normal_slot_buffers'], true) : null;
        $r['normal_students']       = $r['normal_students']     ? json_decode($r['normal_students'],     true) : null;
    }
    json_ok($rows);
}

function action_school() {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_err('id が必要です');

    $pdo = get_pdo();
    $st  = $pdo->prepare('SELECT * FROM schools WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $school = $st->fetch();
    if (!$school) json_err('教室が見つかりません', 404);
    $school['id'] = (int)$school['id'];

    $st2 = $pdo->prepare(
        'SELECT season, seats, default_buffer, slot_buffers, students
         FROM simulator_states WHERE school_id = ?'
    );
    $st2->execute([$id]);
    $states = [];
    foreach ($st2->fetchAll() as $row) {
        $states[$row['season']] = [
            'seats'         => (int)$row['seats'],
            'defaultBuffer' => (int)$row['default_buffer'],
            'slotBuffers'   => json_decode($row['slot_buffers'], true),
            'students'      => json_decode($row['students'],     true),
        ];
    }
    $school['states'] = $states;
    json_ok($school);
}

function action_create_school($b) {
    $name  = str_req($b, 'name', 100);
    $open  = trim($b['open_date']  ?? '');
    $staff = trim($b['staff_name'] ?? '');

    $pdo = get_pdo();
    $st  = $pdo->prepare(
        'INSERT INTO schools (name, open_date, staff_name) VALUES (?, ?, ?)'
    );
    $st->execute([$name, $open, $staff]);
    $newId = (int)$pdo->lastInsertId();
    json_ok(['id' => $newId, 'name' => $name, 'open_date' => $open, 'staff_name' => $staff]);
}

function action_update_school($b) {
    $id    = (int)($b['id'] ?? 0);
    $name  = str_req($b, 'name', 100);
    $open  = trim($b['open_date']  ?? '');
    $staff = trim($b['staff_name'] ?? '');
    if (!$id) json_err('id が必要です');

    $pdo = get_pdo();
    $pdo->prepare(
        'UPDATE schools SET name = ?, open_date = ?, staff_name = ? WHERE id = ?'
    )->execute([$name, $open, $staff, $id]);
    json_ok();
}

function action_delete_school($b) {
    $id = (int)($b['id'] ?? 0);
    if (!$id) json_err('id が必要です');

    $pdo = get_pdo();
    $pdo->prepare('DELETE FROM simulator_states WHERE school_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM schools WHERE id = ?')->execute([$id]);
    json_ok();
}

function action_save_simulator($b) {
    $school_id     = (int)($b['school_id']     ?? 0);
    $season        =       $b['season']         ?? '';
    $seats         = (int)($b['seats']          ?? 10);
    $defaultBuffer = (int)($b['default_buffer'] ?? 90);
    $slotBuffers   =       $b['slot_buffers']   ?? [];
    $students      =       $b['students']       ?? [];

    if (!$school_id) json_err('school_id が必要です');
    if (!in_array($season, ['normal', 'intensive'], true)) json_err('season が不正です');
    if ($seats < 1 || $seats > 40)   json_err('seats は 1〜40 の範囲です');
    if (!is_array($slotBuffers))     json_err('slot_buffers が不正です');
    if (!is_array($students))        json_err('students が不正です');

    $pdo = get_pdo();
    $ch  = $pdo->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
    $ch->execute([$school_id]);
    if (!$ch->fetch()) json_err('教室が見つかりません', 404);

    $pdo->prepare("
        INSERT INTO simulator_states
            (school_id, season, seats, default_buffer, slot_buffers, students)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            seats          = VALUES(seats),
            default_buffer = VALUES(default_buffer),
            slot_buffers   = VALUES(slot_buffers),
            students       = VALUES(students)
    ")->execute([
        $school_id,
        $season,
        $seats,
        $defaultBuffer,
        json_encode($slotBuffers, JSON_UNESCAPED_UNICODE),
        json_encode($students,    JSON_UNESCAPED_UNICODE),
    ]);

    json_ok();
}
