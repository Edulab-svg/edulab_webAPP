<?php
// 本部 API
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Sim-Token, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['error' => "PHP Error: $errstr (line $errline)"]);
    exit;
});
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/config.php';

$route  = $_GET['route']  ?? ($_POST['route']  ?? '');
$action = $_POST['action'] ?? '';

if ($route === 'test') {
    echo json_encode(['status' => 'ok', 'sim' => 'honbu', 'time' => date('Y-m-d H:i:s')]);
    exit;
}
if ($route === 'auth') {
    $pw = $_POST['password'] ?? '';
    echo json_encode(['ok' => ($pw === SIMULATOR_PASSWORD)]);
    exit;
}

if ($route !== 'export_write') {
    checkAuth();
}

try {
    $db = getDB();

    switch ($route) {

        case 'all':
            // repay は月次12要素のJSON。列がINTのままだと保存時に不整合になるため、JSON または TEXT への変更を推奨
            $rows = $db->query("SELECT * FROM honbu_year_settings ORDER BY fiscal_year")->fetchAll();
            $years = [];
            // repay は従来INT1件のDB列も想定: intFを外し、配列化は下の正規化で対応
            $jsonF  = ['ads','fee_monthly','recruit','recruit_part','borrow','repay','staff_json'];
            $intF   = ['salary1','salary2','rent','water','electric','phone','travel',
                       'consume','sys','welfare','repair','lease','insurance','sanitation','other'];
            $floatF = ['legal_welf_r'];
            $strF   = ['name1','name2'];
            foreach ($rows as &$r) {
                foreach ($jsonF as $f) {
                    if ($f === 'staff_json') {
                        $r[$f] = isset($r[$f]) && $r[$f] !== null ? json_decode($r[$f], true) : null;
                    } else {
                        $r[$f] = isset($r[$f]) ? json_decode($r[$f], true) : array_fill(0,12,0);
                    }
                }
                foreach ($intF   as $f) $r[$f] = intval($r[$f] ?? 0);
                foreach ($floatF as $f) $r[$f] = floatval($r[$f] ?? 0);
                foreach ($strF   as $f) $r[$f] = (string)($r[$f] ?? '');
                $r['fiscal_year'] = intval($r['fiscal_year']);
                // 返済: 月次12要素。旧1か月定額int・JSON数値1件の場合は全月同一額に展開
                if (!isset($r['repay']) || !is_array($r['repay']) || count($r['repay']) != 12) {
                    $n = 1700000;
                    if (is_array($r['repay'] ?? null) && count($r['repay']) > 0) {
                        $n = (int) $r['repay'][0];
                    } elseif (is_numeric($r['repay'] ?? null) && $r['repay'] !== null && $r['repay'] !== '') {
                        $n = (int) $r['repay'];
                    }
                    $r['repay'] = array_fill(0, 12, $n);
                } else {
                    for ($i = 0; $i < 12; $i++) { $r['repay'][$i] = (int) ($r['repay'][$i] ?? 0); }
                }
                $years[] = $r;
            }
            unset($r);
            echo json_encode(['years' => $years], JSON_UNESCAPED_UNICODE);
            break;

        case 'year':
            if ($action === 'update') {
                $fy    = intval($_POST['fiscal_year']);
                $field = $_POST['field'] ?? '';
                $value = $_POST['value'] ?? '';

                $jsonF  = ['ads','fee_monthly','recruit','recruit_part','borrow','repay','staff_json'];
                $intF   = ['salary1','salary2','rent','water','electric','phone','travel',
                           'consume','sys','welfare','repair','lease','insurance','sanitation','other'];
                $floatF = ['legal_welf_r'];
                $strF   = ['name1','name2'];
                $allowed = array_merge($jsonF,$intF,$floatF,$strF);

                if (!in_array($field, $allowed)) { echo json_encode(['error'=>'invalid field']); break; }
                $safe = '`'.str_replace('`','',$field).'`';
                if (in_array($field,$jsonF))       $value = json_encode(json_decode($value,true));
                elseif (in_array($field,$intF))    $value = intval($value);
                elseif (in_array($field,$floatF))  $value = floatval($value);

                $db->prepare("UPDATE honbu_year_settings SET $safe=? WHERE fiscal_year=?")->execute([$value,$fy]);
                echo json_encode(['status'=>'ok']);
            }
            break;

        case 'export':
            $rows=$db->query("SELECT fiscal_year,revenue,expense,profit,rooms FROM sim_exports WHERE sim_type='honbu' ORDER BY fiscal_year")->fetchAll();
            echo json_encode($rows,JSON_UNESCAPED_UNICODE);
            break;

        case 'export_write':
            $data=json_decode($_POST['data']??'[]',true);
            $stmt=$db->prepare("INSERT INTO sim_exports (sim_type,fiscal_year,revenue,expense,profit,rooms,monthly_revenue,monthly_expense,monthly_profit) VALUES ('honbu',?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revenue=VALUES(revenue),expense=VALUES(expense),profit=VALUES(profit),rooms=VALUES(rooms),monthly_revenue=VALUES(monthly_revenue),monthly_expense=VALUES(monthly_expense),monthly_profit=VALUES(monthly_profit),updated_at=NOW()");
            foreach($data as $row) $stmt->execute([intval($row['fy']),intval($row['revenue']),intval($row['expense']),intval($row['profit']),0,$row['monthly_revenue']??null,$row['monthly_expense']??null,$row['monthly_profit']??null]);
            echo json_encode(['status'=>'ok']);
            break;

        default:
            echo json_encode(['error'=>'route not found: '.$route]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}
