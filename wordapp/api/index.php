<?php
require_once __DIR__ . '/config.php';

// キャッシュ無効化
header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');

// CORS（同一ドメイン想定だが念のため）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// =============================================
// ルーティング
// =============================================
$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} elseif ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? ($_POST['action'] ?? '');
}

try {
    switch ($action) {

        // ── 単元一覧取得 ──────────────────────────
        case 'list_units':
            $db   = getDB();
            $stmt = $db->query('SELECT id, subject, unit_name, page_range, created_at FROM units ORDER BY id DESC');
            $rows = $stmt->fetchAll();
            jsonOk(['units' => $rows]);
            break;

        // ── 単元詳細＋問題取得 ────────────────────
        case 'get_unit':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) jsonError('IDが必要です', 400);

            $db   = getDB();
            $stmt = $db->prepare('SELECT * FROM units WHERE id = ?');
            $stmt->execute([$id]);
            $unit = $stmt->fetch();
            if (!$unit) jsonError('単元が見つかりません', 404);

            $stmt2 = $db->prepare('SELECT * FROM questions WHERE unit_id = ? ORDER BY sort_order, id');
            $stmt2->execute([$id]);
            $questions = $stmt2->fetchAll();

            jsonOk(['unit' => $unit, 'questions' => $questions]);
            break;

        // ── 単元作成 ──────────────────────────────
        case 'create_unit':
            $subject   = trim($input['subject']   ?? '');
            $unit_name = trim($input['unit_name'] ?? '');
            $page_range= trim($input['page_range']?? '');
            $questions = $input['questions'] ?? [];

            if (!$subject || !$unit_name) jsonError('教科と単元名は必須です', 400);

            $db = getDB();
            $db->beginTransaction();

            $stmt = $db->prepare('INSERT INTO units (subject, unit_name, page_range) VALUES (?, ?, ?)');
            $stmt->execute([$subject, $unit_name, $page_range]);
            $unit_id = $db->lastInsertId();

            insertQuestions($db, $unit_id, $questions);

            $db->commit();
            jsonOk(['id' => $unit_id, 'message' => '単元を作成しました']);
            break;

        // ── 単元更新（上書き保存）─────────────────
        case 'update_unit':
            $id        = intval($input['id']        ?? 0);
            $subject   = trim($input['subject']     ?? '');
            $unit_name = trim($input['unit_name']   ?? '');
            $page_range= trim($input['page_range']  ?? '');
            $questions = $input['questions']        ?? [];

            if (!$id || !$subject || !$unit_name) jsonError('ID・教科・単元名は必須です', 400);

            $db = getDB();
            $db->beginTransaction();

            $stmt = $db->prepare('UPDATE units SET subject=?, unit_name=?, page_range=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$subject, $unit_name, $page_range, $id]);

            // 既存の問題を削除して入れ直す
            $db->prepare('DELETE FROM questions WHERE unit_id = ?')->execute([$id]);
            insertQuestions($db, $id, $questions);

            $db->commit();
            jsonOk(['message' => '更新しました']);
            break;

        // ── 単元削除 ──────────────────────────────
        case 'delete_unit':
            $id = intval($input['id'] ?? 0);
            if (!$id) jsonError('IDが必要です', 400);

            $db   = getDB();
            $stmt = $db->prepare('DELETE FROM units WHERE id = ?');
            $stmt->execute([$id]);
            jsonOk(['message' => '削除しました']);
            break;

        // ── Claude API 中継（リスト生成）──────────
        // ※ Claude APIキーをサーバー側で持つ場合はここに実装
        // 今回はフロントから直接 Anthropic API を呼ぶ構成のためスキップ

        default:
            jsonError('不明なアクションです', 400);
    }
} catch (PDOException $e) {
    jsonError('DB エラー: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    jsonError('サーバーエラー: ' . $e->getMessage(), 500);
}

// =============================================
// ヘルパー関数
// =============================================
function insertQuestions(PDO $db, int $unit_id, array $questions): void {
    if (empty($questions)) return;
    $stmt = $db->prepare(
        'INSERT INTO questions (unit_id, sort_order, category, question, answer) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($questions as $i => $q) {
        $stmt->execute([
            $unit_id,
            $i + 1,
            trim($q['category'] ?? ''),
            trim($q['question'] ?? ''),
            trim($q['answer']   ?? ''),
        ]);
    }
}

function jsonOk(array $data): never {
    echo json_encode(['status' => 'ok'] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
