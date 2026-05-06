<?php
// ============================================================
// まんてん個別プラス シミュレーター — API
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../_auth/bootstrap_session.php';
require_once __DIR__ . '/../../_auth/auth.php';

// --- 共通ヘッダー ---
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// CORS（同一オリジン前提だが念のため）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// --- ルーティング ---
$method = $_SERVER['REQUEST_METHOD'];
$action = '';
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? ($_POST['action'] ?? '');
} elseif ($method === 'GET') {
    $action = $_GET['action'] ?? '';
}

if ($action === 'check') {
    if (!portal_is_logged_in() && portal_is_document_navigation_request()) {
        portal_redirect_to_login();
    }
    jsonOk(['authed' => portal_is_logged_in()]);
}

portal_require_api_session_json(true);

// 認証後アクション
switch ($action) {
    case 'list':         doList();              break;
    case 'save_school':  doSaveSchool($body ?? []);   break;
    case 'delete_school':doDeleteSchool($body ?? []); break;
    case 'save_all':     doSaveAll($body ?? []);      break;
    default:             jsonError(400, 'Unknown action');
}

// ============================================================
// 教室 CRUD
// ============================================================

/** 教室一覧取得 */
function doList(): void {
    $db   = getDB();
    $rows = $db->query('SELECT data_json FROM manten_schools ORDER BY id ASC')->fetchAll();
    $schools = array_map(fn($r) => json_decode($r['data_json'], true), $rows);
    jsonOk(['schools' => $schools]);
}

/** 1教室を保存（INSERT or UPDATE） */
function doSaveSchool(array $body): void {
    $school = $body['school'] ?? null;
    if (!$school || empty($school['id'])) {
        jsonError(400, 'school または id が不正です');
    }
    $key  = (string)$school['id'];
    $name = $school['schoolName'] ?? '';
    $date = $school['openDate']   ?? '';
    $staff= $school['staffName']  ?? '';
    $json = json_encode($school, JSON_UNESCAPED_UNICODE);

    $db = getDB();
    $db->prepare(
        'INSERT INTO manten_schools(school_key, school_name, open_date, staff_name, data_json)
         VALUES(?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           school_name=VALUES(school_name),
           open_date=VALUES(open_date),
           staff_name=VALUES(staff_name),
           data_json=VALUES(data_json),
           updated_at=CURRENT_TIMESTAMP'
    )->execute([$key, $name, $date, $staff, $json]);

    jsonOk(['message' => '保存しました', 'school_key' => $key]);
}

/** 1教室を削除 */
function doDeleteSchool(array $body): void {
    $key = (string)($body['id'] ?? '');
    if (!$key) jsonError(400, 'id が不正です');
    $db = getDB();
    $db->prepare('DELETE FROM manten_schools WHERE school_key=?')->execute([$key]);
    jsonOk(['message' => '削除しました']);
}

/** 全教室を一括上書き保存（旧データを全削除→再挿入） */
function doSaveAll(array $body): void {
    $schools = $body['schools'] ?? null;
    if (!is_array($schools)) jsonError(400, 'schools が不正です');

    $db = getDB();
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM manten_schools');
        $st = $db->prepare(
            'INSERT INTO manten_schools(school_key, school_name, open_date, staff_name, data_json)
             VALUES(?,?,?,?,?)'
        );
        foreach ($schools as $school) {
            $key  = (string)($school['id'] ?? '');
            if (!$key) continue;
            $st->execute([
                $key,
                $school['schoolName'] ?? '',
                $school['openDate']   ?? '',
                $school['staffName']  ?? '',
                json_encode($school, JSON_UNESCAPED_UNICODE),
            ]);
        }
        $db->commit();
        jsonOk(['message' => '全教室を保存しました', 'count' => count($schools)]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonError(500, 'DB保存エラー: ' . $e->getMessage());
    }
}

// ============================================================
// ヘルパー
// ============================================================
function jsonOk(array $data): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
