<?php
// まんてん個別プラス API
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

// 認証不要ルート
if ($route === 'test') {
    echo json_encode(['status' => 'ok', 'sim' => 'plus', 'time' => date('Y-m-d H:i:s')]);
    exit;
}
if ($route === 'debug_export') {
    // POSTされたdataをそのまま返す
    $raw = $_POST['data'] ?? 'not sent';
    $data = json_decode($raw, true);
    $info = [];
    if (is_array($data)) {
        foreach ($data as $row) {
            $info[] = [
                'fy' => $row['fy'] ?? null,
                'has_monthly_revenue' => array_key_exists('monthly_revenue', $row),
                'monthly_revenue_value' => substr($row['monthly_revenue'] ?? 'NULL', 0, 50),
                'monthly_revenue_len' => strlen($row['monthly_revenue'] ?? ''),
            ];
        }
    }
    echo json_encode(['raw_length' => strlen($raw), 'rows' => $info]);
    exit;
}
if ($route === 'auth') {
    $pw = $_POST['password'] ?? '';
    echo json_encode(['ok' => ($pw === SIMULATOR_PASSWORD)]);
    exit;
}

// 以降は認証必須（export_writeは認証なしで許可）
if ($route !== 'export_write') {
    checkAuth();
}

try {
    $db = getDB();

    switch ($route) {

        case 'all':
            $years = $db->query("SELECT * FROM plus_year_settings ORDER BY fiscal_year")->fetchAll();
            foreach ($years as &$y) {
                $y['fiscal_year'] = intval($y['fiscal_year']);
                $y['bonus_mult']  = floatval($y['bonus_mult']);
            }
            unset($y);

            $classrooms = $db->query("SELECT * FROM plus_classrooms ORDER BY fiscal_year, sort_order")->fetchAll();
            $byYear = [];
            $jsonFields  = ['prices','enroll','ads','enroll_base','wd_base'];
            $intFields   = ['start_st','start_wd','max_st','open_month','rent','water','electric','phone','travel',
                            'consume_r','promo_r','recruit_fee','sys_per_st','mat_per_st','fee_r','welfare_r',
                            'repair','lease_cost','insurance','sanitation','other_exp','parttime_cost','recruit_fee_part','recruit_fee_part',
                            'enroll_fee','salary1','salary2'];
            $floatFields = ['wd_rate','conv_r','exam_buy_r','exam_sell_r','legal_welf_r','tax_r'];

            foreach ($classrooms as &$c) {
                foreach ($jsonFields  as $f) $c[$f] = isset($c[$f]) ? json_decode($c[$f], true) : [];
                foreach ($intFields   as $f) $c[$f] = intval($c[$f] ?? 0);
                foreach ($floatFields as $f) $c[$f] = floatval($c[$f] ?? 0);
                $c['id']          = intval($c['id']);
                $c['fiscal_year'] = intval($c['fiscal_year']);
                $fy = $c['fiscal_year'];
                if (!isset($byYear[$fy])) $byYear[$fy] = [];
                $byYear[$fy][] = $c;
            }
            unset($c);

            echo json_encode(['years' => $years, 'classrooms' => $byYear], JSON_UNESCAPED_UNICODE);
            break;

        case 'classroom':
            if ($action === 'update') {
                $id    = intval($_POST['id']);
                $field = $_POST['field'] ?? '';
                $value = $_POST['value'] ?? '';

                $jsonF  = ['prices','enroll','ads','enroll_base','wd_base'];
                $intF   = ['start_st','start_wd','max_st','open_month','rent','water','electric','phone','travel',
                           'consume_r','promo_r','recruit_fee','sys_per_st','mat_per_st','fee_r','welfare_r',
                           'repair','lease_cost','insurance','sanitation','other_exp','parttime_cost','recruit_fee_part',
                           'enroll_fee','salary1','salary2'];
                $floatF = ['wd_rate','conv_r','exam_buy_r','exam_sell_r','legal_welf_r','tax_r'];
                $strF   = ['name'];
                $allowed = array_merge($jsonF, $intF, $floatF, $strF);

                if (!in_array($field, $allowed)) {
                    echo json_encode(['error' => 'invalid field']); break;
                }
                $safe = '`'.str_replace('`','',$field).'`';
                if (in_array($field, $jsonF))  $value = json_encode(json_decode($value, true));
                elseif (in_array($field, $intF))   $value = intval($value);
                elseif (in_array($field, $floatF)) $value = floatval($value);

                $db->prepare("UPDATE plus_classrooms SET $safe = ? WHERE id = ?")->execute([$value, $id]);
                echo json_encode(['status' => 'ok']);

            } elseif ($action === 'add') {
                $fy   = intval($_POST['fiscal_year']);
                $name = $_POST['name'] ?? '新規教室';
                $om   = intval($_POST['open_month'] ?? -1);
                $max  = $db->query("SELECT MAX(sort_order) as m FROM plus_classrooms WHERE fiscal_year=$fy")->fetch();
                $sort = ($max['m'] ?? -1) + 1;
                $db->prepare("INSERT INTO plus_classrooms (fiscal_year,sort_order,name,open_month,prices,enroll,ads) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$fy,$sort,$name,$om,
                     json_encode(array_fill(0,12,24000)),
                     json_encode(array_fill(0,12,0)),
                     json_encode(array_fill(0,12,0))]);
                $newId = $db->lastInsertId();
                echo json_encode(['status'=>'ok','id'=>$newId]);

            } elseif ($action === 'delete') {
                $id = intval($_POST['id']);
                $db->prepare("DELETE FROM plus_classrooms WHERE id=?")->execute([$id]);
                echo json_encode(['status'=>'ok']);
            }
            break;

        case 'year':
            if ($action === 'update') {
                $fy    = intval($_POST['fiscal_year']);
                $field = $_POST['field'] ?? '';
                $value = $_POST['value'] ?? '';
                if ($field === 'bonus_mult') {
                    $db->prepare("UPDATE plus_year_settings SET bonus_mult=? WHERE fiscal_year=?")->execute([floatval($value),$fy]);
                }
                echo json_encode(['status'=>'ok']);
            }
            break;

        case 'export':
            // ダッシュボード向け集計エクスポート
            $rows = $db->query("SELECT fiscal_year,revenue,expense,profit,rooms FROM sim_exports WHERE sim_type='plus' ORDER BY fiscal_year")->fetchAll();
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            break;

        case 'export_write':
            // 各シミュレーターから計算結果を書き込む
            $data = json_decode($_POST['data'] ?? '[]', true);
            $stmt = $db->prepare("INSERT INTO sim_exports (sim_type,fiscal_year,revenue,expense,profit,rooms,monthly_revenue,monthly_expense,monthly_profit) VALUES ('plus',?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revenue=VALUES(revenue),expense=VALUES(expense),profit=VALUES(profit),rooms=VALUES(rooms),monthly_revenue=VALUES(monthly_revenue),monthly_expense=VALUES(monthly_expense),monthly_profit=VALUES(monthly_profit),updated_at=NOW()");
            foreach ($data as $row) {
                $stmt->execute([intval($row['fy']),intval($row['revenue']),intval($row['expense']),intval($row['profit']),intval($row['rooms']),$row['monthly_revenue']??null,$row['monthly_expense']??null,$row['monthly_profit']??null]);
            }
            echo json_encode(['status'=>'ok']);
            break;

        default:
            echo json_encode(['error' => 'route not found: '.$route]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
