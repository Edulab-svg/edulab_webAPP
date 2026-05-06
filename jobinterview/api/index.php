<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../_auth/bootstrap_session.php';
require_once __DIR__ . '/../../_auth/auth.php';

header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = '';
$body   = [];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} elseif ($method === 'POST') {
    if (!empty($_FILES)) {
        $action = $_POST['action'] ?? '';
    } else {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';
    }
}

try {
    if ($action === 'check') {
        if (!portal_is_logged_in() && portal_is_document_navigation_request()) {
            portal_redirect_to_login();
        }
        echo json_encode(['ok' => true, 'authenticated' => portal_is_logged_in()]);
        exit;
    }

    portal_require_api_session_json(false);

    switch ($action) {
        case 'list':        echo json_encode(action_list()); break;
        case 'get':         echo json_encode(action_get((int)($_GET['id'] ?? 0))); break;
        case 'create':      echo json_encode(action_create($body)); break;
        case 'update':      echo json_encode(action_update($body)); break;
        case 'delete':      echo json_encode(action_delete($body)); break;
        case 'upload':      echo json_encode(action_upload()); break;
        case 'delete_file': echo json_encode(action_delete_file($body)); break;
        case 'files':       echo json_encode(action_files((int)($_GET['id'] ?? 0))); break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ════════════════════════════════════════
// データアクション
// ════════════════════════════════════════

function action_list(): array {
    $stmt = get_pdo()->query(
        'SELECT id,name,age,interview_date,interviewer,venue,venue_other,
                media,status,score,personality,personality_note,similar_employee,created_at
         FROM candidates ORDER BY created_at DESC'
    );
    return ['ok' => true, 'data' => $stmt->fetchAll()];
}

function action_get(int $id): array {
    if ($id <= 0) return ['ok' => false, 'error' => 'invalid id'];
    $stmt = get_pdo()->prepare('SELECT * FROM candidates WHERE id = ?');
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    if (!$row) return ['ok' => false, 'error' => 'not found'];
    $row['iv_data'] = $row['iv_data'] ? json_decode($row['iv_data'], true) : null;
    $row['files']   = fetch_files($id);
    return ['ok' => true, 'data' => $row];
}

function action_create(array $body): array {
    validate_candidate($body);
    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO candidates
         (name,age,interview_date,interviewer,venue,venue_other,media,status,
          score,score_comm,score_tech,score_motiv,score_culture,
          personality,personality_note,similar_employee,handover,iv_data)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $body['name'], (int)$body['age'],
        date_or_null($body['interview_date'] ?? null),
        $body['interviewer'] ?? null, $body['venue'] ?? null, $body['venue_other'] ?? null,
        $body['media'] ?? null, $body['status'] ?? '未設定',
        (int)($body['score'] ?? 0), (int)($body['score_comm'] ?? 0),
        (int)($body['score_tech'] ?? 0), (int)($body['score_motiv'] ?? 0),
        (int)($body['score_culture'] ?? 0),
        $body['personality'] ?? null, $body['personality_note'] ?? null,
        $body['similar_employee'] ?? null, $body['handover'] ?? null,
        isset($body['iv_data']) ? json_encode($body['iv_data'], JSON_UNESCAPED_UNICODE) : null,
    ]);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
}

function action_update(array $body): array {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) return ['ok' => false, 'error' => 'invalid id'];
    validate_candidate($body);
    $stmt = get_pdo()->prepare(
        'UPDATE candidates SET
           name=?,age=?,interview_date=?,interviewer=?,venue=?,venue_other=?,media=?,status=?,
           score=?,score_comm=?,score_tech=?,score_motiv=?,score_culture=?,
           personality=?,personality_note=?,similar_employee=?,handover=?,iv_data=?
         WHERE id=?'
    );
    $stmt->execute([
        $body['name'], (int)$body['age'],
        date_or_null($body['interview_date'] ?? null),
        $body['interviewer'] ?? null, $body['venue'] ?? null, $body['venue_other'] ?? null,
        $body['media'] ?? null, $body['status'] ?? '未設定',
        (int)($body['score'] ?? 0), (int)($body['score_comm'] ?? 0),
        (int)($body['score_tech'] ?? 0), (int)($body['score_motiv'] ?? 0),
        (int)($body['score_culture'] ?? 0),
        $body['personality'] ?? null, $body['personality_note'] ?? null,
        $body['similar_employee'] ?? null, $body['handover'] ?? null,
        isset($body['iv_data']) ? json_encode($body['iv_data'], JSON_UNESCAPED_UNICODE) : null,
        $id,
    ]);
    return ['ok' => true];
}

function action_delete(array $body): array {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) return ['ok' => false, 'error' => 'invalid id'];
    foreach (fetch_files($id) as $f) {
        $p = UPLOAD_DIR . $f['saved_name'];
        if (file_exists($p)) @unlink($p);
    }
    get_pdo()->prepare('DELETE FROM candidates WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

function action_upload(): array {
    $cid = (int)($_POST['candidate_id'] ?? 0);
    if ($cid <= 0)        return ['ok' => false, 'error' => 'invalid candidate_id'];
    if (empty($_FILES['file'])) return ['ok' => false, 'error' => 'no file'];
    $file = $_FILES['file'];
    $mime = mime_content_type($file['tmp_name']);
    $orig = basename($file['name']);
    if (!in_array($mime, ALLOWED_MIME, true)) return ['ok' => false, 'error' => '許可されていないファイル形式です'];
    if ($file['size'] > MAX_FILE_SIZE)        return ['ok' => false, 'error' => 'ファイルが大きすぎます（上限10MB）'];
    if ($file['error'] !== UPLOAD_ERR_OK)     return ['ok' => false, 'error' => 'アップロードエラー: ' . $file['error']];
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $saved = uniqid('f_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $saved))
        return ['ok' => false, 'error' => 'ファイル保存に失敗しました'];
    $pdo  = get_pdo();
    $pdo->prepare('INSERT INTO candidate_files (candidate_id,original_name,saved_name,mime_type,file_size) VALUES (?,?,?,?,?)')
        ->execute([$cid, $orig, $saved, $mime, $file['size']]);
    return ['ok' => true, 'file' => ['id' => (int)$pdo->lastInsertId(), 'original_name' => $orig, 'saved_name' => $saved, 'mime_type' => $mime, 'url' => UPLOAD_URL . $saved]];
}

function action_delete_file(array $body): array {
    $fid = (int)($body['file_id'] ?? 0);
    if ($fid <= 0) return ['ok' => false, 'error' => 'invalid file_id'];
    $pdo  = get_pdo();
    $stmt = $pdo->prepare('SELECT saved_name FROM candidate_files WHERE id = ?');
    $stmt->execute([$fid]);
    $row  = $stmt->fetch();
    if (!$row) return ['ok' => false, 'error' => 'not found'];
    $p = UPLOAD_DIR . $row['saved_name'];
    if (file_exists($p)) @unlink($p);
    $pdo->prepare('DELETE FROM candidate_files WHERE id = ?')->execute([$fid]);
    return ['ok' => true];
}

function action_files(int $id): array {
    if ($id <= 0) return ['ok' => false, 'error' => 'invalid id'];
    return ['ok' => true, 'data' => fetch_files($id)];
}

// ════════════════════════════════════════
// ユーティリティ
// ════════════════════════════════════════

function fetch_files(int $cid): array {
    $stmt = get_pdo()->prepare('SELECT id,original_name,saved_name,mime_type,file_size,created_at FROM candidate_files WHERE candidate_id=? ORDER BY id ASC');
    $stmt->execute([$cid]);
    return array_map(function($r){ $r['url'] = UPLOAD_URL . $r['saved_name']; return $r; }, $stmt->fetchAll());
}
function validate_candidate(array $b): void {
    if (empty($b['name'])) throw new InvalidArgumentException('名前は必須です');
    if (empty($b['age']))  throw new InvalidArgumentException('年齢は必須です');
}
function date_or_null(?string $v): ?string {
    if (!$v) return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d ? $d->format('Y-m-d') : null;
}
