<?php
// ================================================================
//  api/index.php  ―  APIエンドポイント（マルチユーザー版）
//
//  GET  ?action=get_users               社員一覧を取得
//  GET  ?action=load&user_id=N          社員データを取得
//  POST {action:"add_user", name:"..."}            社員追加
//  POST {action:"delete_user", user_id:N}          社員削除
//  POST {action:"save", user_id:N, data:{...}}     データ保存
// ================================================================

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

// GET / POST のみ許可
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// DB接続
require_once __DIR__ . '/config.php';
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connection_failed']);
    exit;
}

// アクション・ボディ取得
$body   = [];
$action = '';
if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
} else {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];
    $action = isset($body['action']) ? (string)$body['action'] : '';
}

// user_id バリデーションヘルパー
function valid_uid($v) {
    $v = intval($v);
    return $v > 0 ? $v : false;
}

// templates テーブルが無ければ作成
function ensure_templates_table($pdo) {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS templates (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(200) NOT NULL,
            cats_data  MEDIUMTEXT   NOT NULL,
            created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4
    ');
}

// ================================================================
switch ($action) {

    // ---- 社員一覧 ----
    case 'get_users':
        $rows = $pdo->query(
            'SELECT id, display_name, created_at FROM users ORDER BY id ASC'
        )->fetchAll();
        echo json_encode(['ok' => true, 'users' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    // ---- 社員追加 ----
    case 'add_user':
        $name = isset($body['name']) ? trim((string)$body['name']) : '';
        if (!$name) {
            echo json_encode(['ok' => false, 'error' => 'name_required']);
            break;
        }
        if (mb_strlen($name) > 100) {
            echo json_encode(['ok' => false, 'error' => 'name_too_long']);
            break;
        }
        $stmt = $pdo->prepare('INSERT INTO users (display_name) VALUES (?)');
        $stmt->execute([$name]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode([
            'ok'   => true,
            'user' => ['id' => $newId, 'display_name' => $name]
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ---- 社員削除（関連データも CASCADE で削除）----
    case 'delete_user':
        $uid = valid_uid($body['user_id'] ?? 0);
        if (!$uid) {
            echo json_encode(['ok' => false, 'error' => 'invalid_user_id']);
            break;
        }
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        break;

    // ---- データ取得 ----
    case 'load':
        $uid = valid_uid($_GET['user_id'] ?? 0);
        if (!$uid) {
            echo json_encode(['ok' => false, 'error' => 'invalid_user_id']);
            break;
        }
        $stmt = $pdo->prepare(
            'SELECT snapshot_data, updated_at FROM app_data WHERE user_id = ?'
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode([
                'ok'         => true,
                'data'       => json_decode($row['snapshot_data'], true),
                'updated_at' => $row['updated_at'],
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => true, 'data' => null], JSON_UNESCAPED_UNICODE);
        }
        break;

    // ---- データ保存 ----
    case 'save':
        $uid = valid_uid($body['user_id'] ?? 0);
        if (!$uid) {
            echo json_encode(['ok' => false, 'error' => 'invalid_user_id']);
            break;
        }
        if (!isset($body['data'])) {
            echo json_encode(['ok' => false, 'error' => 'no_data']);
            break;
        }
        $json = json_encode($body['data'], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            echo json_encode(['ok' => false, 'error' => 'json_encode_failed']);
            break;
        }
        $stmt = $pdo->prepare('
            INSERT INTO app_data (user_id, snapshot_data)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
              snapshot_data = VALUES(snapshot_data),
              updated_at    = NOW()
        ');
        $stmt->execute([$uid, $json]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        break;

    // ---- テンプレート一覧 ----
    case 'get_templates':
        ensure_templates_table($pdo);
        $rows = $pdo->query(
            'SELECT id, name, cats_data, created_at FROM templates ORDER BY id ASC'
        )->fetchAll();
        $templates = array_map(function($r) {
            return [
                'id'         => (int)$r['id'],
                'name'       => $r['name'],
                'cats'       => json_decode($r['cats_data'], true) ?: [],
                'created_at' => $r['created_at'],
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'templates' => $templates], JSON_UNESCAPED_UNICODE);
        break;

    // ---- テンプレート追加 ----
    case 'add_template':
        ensure_templates_table($pdo);
        $name = isset($body['name']) ? trim((string)$body['name']) : '';
        $cats = isset($body['cats']) && is_array($body['cats']) ? $body['cats'] : [];
        if (!$name) {
            echo json_encode(['ok' => false, 'error' => 'name_required']);
            break;
        }
        if (mb_strlen($name) > 200) {
            echo json_encode(['ok' => false, 'error' => 'name_too_long']);
            break;
        }
        $catsJson = json_encode($cats, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare('INSERT INTO templates (name, cats_data) VALUES (?, ?)');
        $stmt->execute([$name, $catsJson]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode([
            'ok'       => true,
            'template' => ['id' => $newId, 'name' => $name, 'cats' => $cats],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ---- テンプレート更新 ----
    case 'update_template':
        ensure_templates_table($pdo);
        $id   = intval($body['id'] ?? 0);
        $name = isset($body['name']) ? trim((string)$body['name']) : '';
        $cats = isset($body['cats']) && is_array($body['cats']) ? $body['cats'] : [];
        if (!$id || !$name) {
            echo json_encode(['ok' => false, 'error' => 'invalid_params']);
            break;
        }
        $stmt = $pdo->prepare('UPDATE templates SET name = ?, cats_data = ? WHERE id = ?');
        $stmt->execute([$name, json_encode($cats, JSON_UNESCAPED_UNICODE), $id]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        break;

    // ---- テンプレート削除 ----
    case 'delete_template':
        ensure_templates_table($pdo);
        $id = intval($body['id'] ?? 0);
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'invalid_id']);
            break;
        }
        $stmt = $pdo->prepare('DELETE FROM templates WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown_action']);
        break;
}
