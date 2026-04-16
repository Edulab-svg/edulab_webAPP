<?php
// ============================================================
//  WBS ダッシュボード API  ―  PHP + MySQL
// ============================================================

$DB_HOST = 'localhost';
$DB_NAME = 'xs047468_wbs';   // ← データベース名
$DB_USER = 'xs047468_wbs';        // ← ユーザー名
$DB_PASS = 'V#BB1G*|/@<e';        // ← パスワード

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB接続エラー: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($method) {
    case 'GET':
        $stmt = $pdo->query('SELECT * FROM wbs_tasks ORDER BY id');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['ai'] = (bool)$r['ai']; }
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }
        $sql = 'INSERT INTO wbs_tasks (bu,cat,ai,owner,mis,kpiM,dlM,sub,kpiS,dlS,task,who,due,st,priority)
                VALUES (:bu,:cat,:ai,:owner,:mis,:kpiM,:dlM,:sub,:kpiS,:dlS,:task,:who,:due,:st,:priority)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':bu'=>$data['bu']??'', ':cat'=>$data['cat']??'', ':ai'=>($data['ai']??false)?1:0,
            ':owner'=>$data['owner']??'', ':mis'=>$data['mis']??'', ':kpiM'=>$data['kpiM']??'',
            ':dlM'=>$data['dlM']??'', ':sub'=>$data['sub']??'', ':kpiS'=>$data['kpiS']??'',
            ':dlS'=>$data['dlS']??'', ':task'=>$data['task']??'', ':who'=>$data['who']??'',
            ':due'=>$data['due']??'', ':st'=>$data['st']??'未着手', ':priority'=>$data['priority']??'中',
        ]);
        $data['id'] = (int)$pdo->lastInsertId();
        http_response_code(201);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    case 'PUT':
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }
        $sql = 'UPDATE wbs_tasks SET bu=:bu,cat=:cat,ai=:ai,owner=:owner,mis=:mis,kpiM=:kpiM,dlM=:dlM,
                sub=:sub,kpiS=:kpiS,dlS=:dlS,task=:task,who=:who,due=:due,st=:st,priority=:priority WHERE id=:id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id'=>$id, ':bu'=>$data['bu']??'', ':cat'=>$data['cat']??'', ':ai'=>($data['ai']??false)?1:0,
            ':owner'=>$data['owner']??'', ':mis'=>$data['mis']??'', ':kpiM'=>$data['kpiM']??'',
            ':dlM'=>$data['dlM']??'', ':sub'=>$data['sub']??'', ':kpiS'=>$data['kpiS']??'',
            ':dlS'=>$data['dlS']??'', ':task'=>$data['task']??'', ':who'=>$data['who']??'',
            ':due'=>$data['due']??'', ':st'=>$data['st']??'未着手', ':priority'=>$data['priority']??'中',
        ]);
        $data['id'] = $id;
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    case 'DELETE':
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); exit; }
        $stmt = $pdo->prepare('DELETE FROM wbs_tasks WHERE id=:id');
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
