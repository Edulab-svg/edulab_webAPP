<?php
// Mantan Simulator API
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['error' => "PHP Error: $errstr (line $errline)"]);
    exit;
});

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Exception: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/config.php';

$route = isset($_GET['route']) ? $_GET['route'] : (isset($_POST['route']) ? $_POST['route'] : '');
$action = isset($_POST['action']) ? $_POST['action'] : '';

try {
    $db = getDB();

    switch ($route) {

        case 'test':
            echo json_encode(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]);
            break;

        case 'all':
            $years = $db->query("SELECT * FROM year_settings ORDER BY fiscal_year")->fetchAll();
            foreach ($years as &$y) {
                $y['wd_rates'] = json_decode($y['wd_rates'], true);
                $y['bonus_mult'] = floatval($y['bonus_mult']);
                $y['fiscal_year'] = intval($y['fiscal_year']);
            }
            unset($y);

            $classrooms = $db->query("SELECT * FROM classrooms ORDER BY fiscal_year, sort_order")->fetchAll();
            $byYear = [];
            foreach ($classrooms as &$c) {
                $c['prices'] = json_decode($c['prices'], true);
                $c['enroll'] = json_decode($c['enroll'], true);
                $c['ads'] = json_decode($c['ads'], true);
                $c['id'] = intval($c['id']);
                $c['fiscal_year'] = intval($c['fiscal_year']);
                foreach (['start_st','start_wd','annual_wd','open_month','max_st','rent','water','electric','phone','travel',
                          'repair','lease_cost','insurance','sanitation','other_exp','recruit_fee','recruit_fee_oct','recruit_fee_part',
                          'staff_recruit','consume_r','promo_r','sys_per_st','sys_base',
                          'salary_pm','salary_mgr','salary_sub',
                          'salary_pm2','salary_mgr2','salary_sub2',
                          'open_fiscal_year'] as $f) {
                    $c[$f] = isset($c[$f]) ? intval($c[$f]) : 0;
                }
                foreach (['conv_r','wd_rate','fee_r','welfare_r','mat_buy_r','exam_buy_r',
                          'teacher_r','legal_welf_r','tax_r'] as $f) {
                    $c[$f] = floatval($c[$f]);
                }
                // 氏名フィールドは文字列のまま（nullの場合は空文字に）
                foreach (['name_pm','name_mgr','name_sub'] as $f) {
                    $c[$f] = isset($c[$f]) ? (string)$c[$f] : '';
                }
                $fy = $c['fiscal_year'];
                if (!isset($byYear[$fy])) $byYear[$fy] = [];
                $byYear[$fy][] = $c;
            }
            unset($c);

            echo json_encode(['years' => $years, 'classrooms' => $byYear], JSON_UNESCAPED_UNICODE);
            break;

        case 'classroom':
            if ($action === 'update') {
                $id = intval($_POST['id']);
                $field = $_POST['field'];
                $value = $_POST['value'];

                $jsonFields = ['prices', 'enroll', 'ads'];
                $intFields = ['start_st','start_wd','annual_wd','open_month','max_st','rent','water','electric','phone','travel',
                              'repair','lease_cost','insurance','sanitation','other_exp','recruit_fee','recruit_fee_oct','recruit_fee_part','recruit_fee_part',
                              'staff_recruit','consume_r','promo_r','sys_per_st','sys_base',
                              'salary_pm','salary_mgr','salary_sub',
                              'salary_pm2','salary_mgr2','salary_sub2',
                              'open_fiscal_year'];
                $floatFields = ['conv_r','wd_rate','fee_r','welfare_r','mat_buy_r','exam_buy_r',
                                'teacher_r','legal_welf_r','tax_r'];
                $allowed = array_merge($jsonFields, $intFields, $floatFields, ['name', 'name_pm', 'name_mgr', 'name_sub']);

                if (!in_array($field, $allowed)) {
                    echo json_encode(['error' => 'invalid field: ' . $field]);
                    break;
                }

                $safeField = '`' . str_replace('`', '', $field) . '`';

                if (in_array($field, $jsonFields)) {
                    $decoded = json_decode($value, true);
                    $value = json_encode($decoded);
                } elseif (in_array($field, $intFields)) {
                    $value = intval($value);
                } elseif (in_array($field, $floatFields)) {
                    $value = floatval($value);
                }

                $stmt = $db->prepare("UPDATE classrooms SET $safeField = ? WHERE id = ?");
                $stmt->execute([$value, $id]);
                echo json_encode(['status' => 'ok', 'updated' => $id]);
            } else {
                echo json_encode(['error' => 'unknown action']);
            }
            break;

        case 'year':
            if ($action === 'update') {
                $fiscal_year = intval($_POST['fiscal_year']);
                $field = $_POST['field'];
                $value = $_POST['value'];

                if ($field === 'bonus_mult') {
                    $stmt = $db->prepare("UPDATE year_settings SET bonus_mult = ? WHERE fiscal_year = ?");
                    $stmt->execute([floatval($value), $fiscal_year]);
                } elseif ($field === 'wd_rates') {
                    $decoded = json_decode($value, true);
                    $stmt = $db->prepare("UPDATE year_settings SET wd_rates = ? WHERE fiscal_year = ?");
                    $stmt->execute([json_encode($decoded), $fiscal_year]);
                }
                echo json_encode(['status' => 'ok']);
            }
            break;

        case 'export_write':
            $data = json_decode($_POST['data'] ?? '[]', true);
            $stmt = $db->prepare("INSERT INTO sim_exports (sim_type,fiscal_year,revenue,expense,profit,rooms,monthly_revenue,monthly_expense,monthly_profit) VALUES ('individual',?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revenue=VALUES(revenue),expense=VALUES(expense),profit=VALUES(profit),rooms=VALUES(rooms),monthly_revenue=VALUES(monthly_revenue),monthly_expense=VALUES(monthly_expense),monthly_profit=VALUES(monthly_profit),updated_at=NOW()");
            foreach($data as $row) {
                $stmt->execute([intval($row['fy']),intval($row['revenue']),intval($row['expense']),intval($row['profit']),intval($row['rooms']),$row['monthly_revenue']??null,$row['monthly_expense']??null,$row['monthly_profit']??null]);
            }
            echo json_encode(['status' => 'ok']);
            break;

        default:
            echo json_encode(['error' => 'route not found: ' . $route]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
