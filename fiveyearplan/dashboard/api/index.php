<?php
// 全社合算ダッシュボード API
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Sim-Token, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';

$route = $_GET['route'] ?? '';

if ($route === 'test') {
    echo json_encode(['status' => 'ok', 'sim' => 'dashboard', 'time' => date('Y-m-d H:i:s')]);
    exit;
}
if ($route === 'auth') {
    $pw = $_POST['password'] ?? $_GET['password'] ?? '';
    echo json_encode(['ok' => ($pw === SIMULATOR_PASSWORD)]);
    exit;
}

checkAuth();

try {
    $db = getDB();

    switch ($route) {

        case 'all':
            // sim_exports から全事業部の集計を取得
            $rows = $db->query("SELECT sim_type, fiscal_year, revenue, expense, profit, rooms, monthly_revenue, monthly_expense, monthly_profit FROM sim_exports ORDER BY sim_type, fiscal_year")->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $type = $r['sim_type'];
                if (!isset($result[$type])) $result[$type] = [];
                $result[$type][] = [
                    'fy'               => intval($r['fiscal_year']),
                    'revenue'          => intval($r['revenue']),
                    'expense'          => intval($r['expense']),
                    'profit'           => intval($r['profit']),
                    'rooms'            => intval($r['rooms']),
                    'monthly_revenue'  => $r['monthly_revenue'],
                    'monthly_expense'  => $r['monthly_expense'],
                    'monthly_profit'   => $r['monthly_profit'],
                ];
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['error' => 'route not found: '.$route]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
