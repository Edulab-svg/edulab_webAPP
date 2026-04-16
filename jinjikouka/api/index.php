<?php
// ============================================================
// 人事考課システム API（PHP 7.x / 8.x 両対応）
// ============================================================
require_once __DIR__ . '/config.php';

// すべての出力をバッファリング（PHPエラーがHTMLで混入するのを防ぐ）
ob_start();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// エラーをすべてJSONで返す
function fatalHandler() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $err['message']], JSON_UNESCAPED_UNICODE);
    } else {
        $buf = ob_get_clean();
        echo $buf;
    }
}
register_shutdown_function('fatalHandler');

set_exception_handler(function($e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
} elseif ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = array();
    $action = isset($body['action']) ? $body['action'] : '';
} else {
    jsonError('Method not allowed', 405);
    $action = '';
    $body   = array();
}

switch ($action) {
    case 'get_staff':       handleGetStaff();              break;
    case 'add_staff':       handleAddStaff($body);         break;
    case 'delete_staff':    handleDeleteStaff($body);      break;
    case 'update_staff':    handleUpdateStaff($body);      break;
    case 'update_assigned': handleUpdateAssigned($body);   break;
    case 'get_master':      handleGetMaster();             break;
    case 'get_master_all':  handleGetMasterForCalc();      break;
    case 'save_master':     handleSaveMaster($body);        break;
    case 'add_master_item': handleAddMasterItem($body);     break;
    case 'del_master_item': handleDeleteMasterItem($body);  break;
    case 'get_evals':       handleGetEvals();              break;
    case 'set_eval':        handleSetEval($body);          break;
    default: jsonError('Unknown action', 400);
}

// ============================================================
// areaカラム存在確認
// ============================================================
function hasAreaColumn() {
    static $result = null;
    if ($result !== null) return $result;
    try {
        $cols = getPDO()->query("SHOW COLUMNS FROM staff LIKE 'area'")->fetchAll();
        $result = count($cols) > 0;
    } catch (Exception $e) {
        $result = false;
    }
    return $result;
}

function ensureAreaColumn() {
    if (!hasAreaColumn()) {
        try {
            getPDO()->exec("ALTER TABLE staff ADD COLUMN area VARCHAR(50) NOT NULL DEFAULT '' AFTER sheet");
        } catch (Exception $e) {
            // 追加失敗しても続行
        }
    }
}

// ============================================================
// スタッフ
// ============================================================
function handleGetStaff() {
    $rows = getPDO()->query('SELECT * FROM staff ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $hasArea = hasAreaColumn();
    foreach ($rows as &$r) {
        $r['assigned_staff'] = ($r['assigned_staff'] !== '')
            ? array_map('intval', explode(',', $r['assigned_staff']))
            : array();
        $r['area'] = $hasArea ? (isset($r['area']) ? $r['area'] : '') : '';
    }
    jsonOk($rows);
}

function handleAddStaff($body) {
    $name  = isset($body['name'])  ? trim($body['name'])  : '';
    $room  = isset($body['room'])  ? trim($body['room'])  : '';
    $sheet = isset($body['sheet']) ? $body['sheet']       : 'master40';
    $area  = isset($body['area'])  ? trim($body['area'])  : '';
    if (!$name) { jsonError('Name required', 400); return; }
    $validSheets = array('master40','master40under','masterMG','masterTochuu');
    if (!in_array($sheet, $validSheets)) { jsonError('Invalid sheet', 400); return; }

    ensureAreaColumn();
    $pdo = getPDO();
    if (hasAreaColumn()) {
        $pdo->prepare('INSERT INTO staff (name, room, sheet, area) VALUES (?,?,?,?)')->execute(array($name, $room, $sheet, $area));
    } else {
        $pdo->prepare('INSERT INTO staff (name, room, sheet) VALUES (?,?,?)')->execute(array($name, $room, $sheet));
    }
    $id = (int)$pdo->lastInsertId();
    jsonOk(array('id' => $id, 'name' => $name, 'room' => $room, 'sheet' => $sheet, 'area' => $area, 'assigned_staff' => array()));
}

function handleUpdateStaff($body) {
    $id    = isset($body['id'])    ? (int)$body['id']     : 0;
    $room  = isset($body['room'])  ? trim($body['room'])  : '';
    $area  = isset($body['area'])  ? trim($body['area'])  : '';
    $sheet = isset($body['sheet']) ? $body['sheet']       : 'master40';
    if (!$id) { jsonError('ID required', 400); return; }
    $validSheets = array('master40','master40under','masterMG','masterTochuu');
    if (!in_array($sheet, $validSheets)) { jsonError('Invalid sheet', 400); return; }

    ensureAreaColumn();
    if (hasAreaColumn()) {
        getPDO()->prepare('UPDATE staff SET room=?, area=?, sheet=? WHERE id=?')->execute(array($room, $area, $sheet, $id));
    } else {
        getPDO()->prepare('UPDATE staff SET room=?, sheet=? WHERE id=?')->execute(array($room, $sheet, $id));
    }
    jsonOk(array('updated' => $id));
}

function handleDeleteStaff($body) {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    if (!$id) { jsonError('ID required', 400); return; }
    $pdo = getPDO();
    $pdo->prepare("UPDATE staff SET assigned_staff = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', assigned_staff, ','), CONCAT(',', ?, ','), ',')) WHERE assigned_staff != ''")->execute(array($id));
    $pdo->prepare('DELETE FROM staff WHERE id=?')->execute(array($id));
    jsonOk(array('deleted' => $id));
}

function handleUpdateAssigned($body) {
    $mgId = isset($body['mg_id']) ? (int)$body['mg_id'] : 0;
    if (!$mgId) { jsonError('mg_id required', 400); return; }
    $raw = isset($body['assigned_staff']) ? (array)$body['assigned_staff'] : array();
    $assigned = array();
    foreach ($raw as $x) {
        $v = (int)$x;
        if ($v > 0 && $v !== $mgId && !in_array($v, $assigned)) $assigned[] = $v;
    }
    getPDO()->prepare('UPDATE staff SET assigned_staff=? WHERE id=?')->execute(array(implode(',', $assigned), $mgId));
    jsonOk(array('mg_id' => $mgId, 'assigned_staff' => $assigned));
}

// ============================================================
// 評価
// ============================================================
function handleGetEvals() {
    $rows   = getPDO()->query('SELECT staff_id, period, item_id, eval_value FROM evaluations')->fetchAll(PDO::FETCH_ASSOC);
    $result = array();
    foreach ($rows as $r) {
        $result[$r['staff_id']][$r['period']][$r['item_id']] = $r['eval_value'];
    }
    jsonOk($result);
}

function handleSetEval($body) {
    $staffId = isset($body['staff_id'])  ? (int)$body['staff_id']    : 0;
    $period  = isset($body['period'])    ? (int)$body['period']       : -1;
    $itemId  = isset($body['item_id'])   ? trim($body['item_id'])     : '';
    $value   = isset($body['eval_value'])? trim($body['eval_value'])  : '';

    if (!$staffId || $period < 0 || !$itemId) { jsonError('staff_id, period, item_id required', 400); return; }

    if ($value === '') {
        getPDO()->prepare('DELETE FROM evaluations WHERE staff_id=? AND period=? AND item_id=?')->execute(array($staffId, $period, $itemId));
        jsonOk(array('deleted' => true));
        return;
    }
    $valid = array('A','B','C','評価なし');
    if (!in_array($value, $valid)) { jsonError('Invalid eval_value', 400); return; }
    getPDO()->prepare('INSERT INTO evaluations (staff_id, period, item_id, eval_value) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE eval_value=?')
            ->execute(array($staffId, $period, $itemId, $value, $value));
    jsonOk(array('saved' => true));
}

// ============================================================
// ヘルパー
// ============================================================
function jsonOk($data) {
    ob_clean();
    echo json_encode(array('ok' => true, 'data' => $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError($msg, $code = 400) {
    ob_clean();
    http_response_code($code);
    echo json_encode(array('ok' => false, 'error' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 評価シートマスタ
// ============================================================

// デフォルト定義（JSと同じ内容）
function getDefaultItems($sheet) {
    $over = array(
        array('c01','集計','問体験率（純）',3,'90%以上','80%以上','80%未満'),
        array('c02','集計','体験成功率',4,'90%以上','80%以上','80%未満'),
        array('c03','集計','不満退塾率',4,'7%未満','10%未満','15%以上'),
        array('c04','集計','退会率',5,'1.0%未満','2.0%未満','2.0%以上'),
        array('c05','集計','授業料単価（税込）',4,'上期：20,500円以上','上期：20,000円以上','上期：20,000円未満'),
        array('c06','集計','講習会売上比率',3,'夏期：200%以上','夏期：170%以上','夏期：170%未満'),
        array('c07','集計','入金率',1,'評価なし','未入金なし','未入金あり'),
        array('c08','集計','目標達成率',10,'110%以上','100%以上','100%未満'),
        array('c09','集計','講師給与比率',2,'1人教室22%未満/2人20%未満','1人教室23%未満/2人22%未満','1人教室24%以上/2人22%以上'),
        array('c10','集計','授業比率',3,'40人以上:1対3が50%以上かつ1対4が40%以上','40人以上:1対3が50%以上かつ1対4が30%以上','左記クリアしていない'),
        array('c11','集計','高校生継続',2,'20%以上','15%以上','15%未満'),
        array('e01','教務','進行表の記入',2,'90%以上記入','80%以上記入','80%未満'),
        array('e02','教務','指導ルールの定着',2,'90%以上記入','80%以上記入','80%未満'),
        array('e03','教務','宿題の履行',2,'90%以上記入','80%以上記入','80%未満'),
        array('e04','教務','自立型個別の履行',2,'90%以上記入','80%以上記入','80%未満'),
        array('e05','教務','Monoxerの導入率',1,'英語受講者の80%以上','英語受講者の70%以上','英語受講者の70%未満'),
        array('e06','教務','Monoxerの週間アクティブ率',1,'70%以上','60%以上','60%未満'),
        array('e07','教務','テスト対策の実施',1,'評価なし','実施した','実施せず'),
        array('e08','教務','成績回収率',1,'95%以上','90%以上','90%未満'),
        array('e09','教務','入塾半年以内 点数アップ率',2,'50%以上','40%以上','40%未満'),
        array('e10','教務','点数アップ写真',2,'定期試験ごとに4枚以上','定期試験ごとに3枚','定期試験ごとに2枚以下'),
        array('e11','教務','講師研修の参加率',1,'（第一回はB）','90%以上','90%未満'),
        array('e12','教務','第一志望合格率',null,'（第四回のみ）','85%以上','85%未満'),
        array('r01','教室運営','服装',1,'評価なし','適切な服装','不適切な服装'),
        array('r02','教室運営','遅刻・欠勤',1,'評価なし','遅刻・欠勤なし','遅刻・欠勤あり'),
        array('r03','教室運営','日報',1,'評価なし','提出忘れなし','提出忘れあり'),
        array('r04','教室運営','教室環境の整備',2,'優れている','可','不備'),
        array('r05','教室運営','月次業務の期限内の遂行',1,'評価なし','出来ている','出来ていない'),
    );
    $under = array(
        array('c01','集計','問体験率（純）',3,'90%以上','80%以上','80%未満'),
        array('c02','集計','体験成功率',10,'90%以上','85%以上','85%未満'),
        array('c03','集計','不満退塾率',4,'5%未満','8%未満','9%以上'),
        array('c04','集計','退会率',4,'2%未満','3%未満','3%以上'),
        array('c05','集計','授業料単価（税込）',4,'上期：20,500円以上','上期：20,000円以上','上期：20,000円未満'),
        array('c06','集計','講習会売上比率',3,'夏期：200%以上','夏期：170%以上','夏期：170%未満'),
        array('c07','集計','入金率',1,'評価なし','未入金なし','未入金あり'),
        array('c08','集計','目標達成率',10,'110%以上','100%以上','100%未満'),
        array('c11','集計','高校生継続',2,'20%以上','15%以上','15%未満'),
        array('e01','教務','進行表の記入',2,'90%以上記入','80%以上記入','80%未満'),
        array('e02','教務','指導ルールの定着',2,'90%以上記入','80%以上記入','80%未満'),
        array('e03','教務','宿題の履行',2,'90%以上記入','80%以上記入','80%未満'),
        array('e04','教務','自立型個別の履行',2,'90%以上記入','80%以上記入','80%未満'),
        array('e05','教務','Monoxerの導入率',1,'英語受講者の80%以上','英語受講者の70%以上','英語受講者の70%未満'),
        array('e06','教務','Monoxerの週間アクティブ率',1,'90%以上','80%以上','80%未満'),
        array('e07','教務','テスト対策の履行',1,'評価なし','履行','履行なし'),
        array('e08','教務','成績回収率',1,'95%以上','90%以上','90%未満'),
        array('e09','教務','入塾半年以内 点数アップ',2,'50%以上','40%以上','40%未満'),
        array('e10','教務','点数アップ写真',4,'定期試験ごとに4枚以上','定期試験ごとに3枚','定期試験ごとに2枚以下'),
        array('e11','教務','講師研修の参加率',1,'（第一回はB）','90%以上','90%未満'),
        array('e12','教務','第一志望合格率',null,'（第四回のみ）','85%以上','85%未満'),
        array('r01','教室運営','服装',1,'評価なし','適切な服装','不適切な服装'),
        array('r02','教室運営','遅刻・欠勤',1,'評価なし','遅刻・欠勤なし','遅刻・欠勤あり'),
        array('r03','教室運営','日報',1,'評価なし','提出忘れなし','提出忘れあり'),
        array('r04','教室運営','教室環境の整備',1,'優れている','可','不備'),
        array('r05','教室運営','月次業務の期限内の遂行',1,'評価なし','出来ている','出来ていない'),
        array('r06','教室運営','教室コラムの更新',1,'評価なし','出来ている','出来ていない'),
    );
    return $sheet === 'master40under' ? $under : $over;
}

function getDefaultRaiseLine($sheet) {
    if ($sheet === 'masterMG') return 110;
    if ($sheet === 'master40under') return 132;
    return 128;
}

function ensureMasterTable() {
    static $checked = false;
    if ($checked) return;
    $pdo = getPDO();
    // テーブルが存在しなければ作成
    $pdo->exec("CREATE TABLE IF NOT EXISTS `master_settings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sheet` VARCHAR(20) NOT NULL,
        `period` TINYINT UNSIGNED NOT NULL,
        `item_id` VARCHAR(16) NOT NULL,
        `rate` TINYINT UNSIGNED DEFAULT NULL,
        `c_a` VARCHAR(200) NOT NULL DEFAULT '',
        `c_b` VARCHAR(200) NOT NULL DEFAULT '',
        `c_c` VARCHAR(200) NOT NULL DEFAULT '',
        `item_name` VARCHAR(100) NOT NULL DEFAULT '',
        `item_cat` VARCHAR(20) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_master` (`sheet`, `period`, `item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // raise_lineカラム追加（存在しなければ）
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM master_settings LIKE 'raise_line'")->fetchAll();
        if (!$cols) {
            $pdo->exec("ALTER TABLE master_settings ADD COLUMN `raise_line` SMALLINT UNSIGNED NOT NULL DEFAULT 128");
        }
    } catch(Exception $e) {}

    $checked = true;
}

function seedMasterIfEmpty($sheet, $period) {
    ensureMasterTable();
    $pdo = getPDO();
    $count = $pdo->prepare('SELECT COUNT(*) FROM master_settings WHERE sheet=? AND period=?');
    $count->execute(array($sheet, $period));
    if ((int)$count->fetchColumn() > 0) return;

    $items = getDefaultItems($sheet);
    $raiseLine = getDefaultRaiseLine($sheet);
    $stmt = $pdo->prepare('INSERT IGNORE INTO master_settings (sheet,period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($items as $item) {
        $stmt->execute(array($sheet, $period, $item[0], $item[3], $item[4], $item[5], $item[6], $item[2], $item[1], $raiseLine));
    }
    // raise_line行
    $pdo->prepare('INSERT IGNORE INTO master_settings (sheet,period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line) VALUES (?,?,\'_raise_line\',NULL,\'\',\'\',\'\',\'昇給ライン\',\'設定\',?)')
        ->execute(array($sheet, $period, $raiseLine));
}

function handleGetMaster() {
    $sheet  = isset($_GET['sheet'])  ? $_GET['sheet']  : 'master40';
    $period = isset($_GET['period']) ? (int)$_GET['period'] : 0;
    $validSheets = array('master40','master40under','masterMG','masterTochuu');
    if (!in_array($sheet, $validSheets)) { jsonError('Invalid sheet', 400); return; }

    seedMasterIfEmpty($sheet, $period);
    $rows = getPDO()->prepare('SELECT * FROM master_settings WHERE sheet=? AND period=? ORDER BY id ASC');
    $rows->execute(array($sheet, $period));
    $data = $rows->fetchAll(PDO::FETCH_ASSOC);
    // raise_line取得
    $rl = 128;
    foreach ($data as $r) {
        if ($r['item_id'] === '_raise_line') { $rl = (int)$r['raise_line']; break; }
    }
    $items = array_filter($data, function($r){ return $r['item_id'] !== '_raise_line'; });
    jsonOk(array('items' => array_values($items), 'raise_line' => $rl));
}

function handleSaveMaster($body) {
    $sheet   = isset($body['sheet'])      ? $body['sheet']      : 'master40';
    $period  = isset($body['period'])     ? (int)$body['period'] : 0;
    $items   = isset($body['items'])      ? $body['items']       : array();
    $rl      = isset($body['raise_line']) ? (int)$body['raise_line'] : 128;
    $validSheets = array('master40','master40under','masterMG','masterTochuu');
    if (!in_array($sheet, $validSheets)) { jsonError('Invalid sheet', 400); return; }

    ensureMasterTable();
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO master_settings (sheet,period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE rate=VALUES(rate),c_a=VALUES(c_a),c_b=VALUES(c_b),c_c=VALUES(c_c),item_name=VALUES(item_name),item_cat=VALUES(item_cat),raise_line=VALUES(raise_line)');

    foreach ($items as $item) {
        $stmt->execute(array(
            $sheet, $period,
            $item['item_id'],
            isset($item['rate']) && $item['rate'] !== '' && $item['rate'] !== null ? (int)$item['rate'] : null,
            $item['c_a'], $item['c_b'], $item['c_c'],
            $item['item_name'], $item['item_cat'],
            $rl
        ));
    }
    // raise_line行を更新
    $pdo->prepare('INSERT INTO master_settings (sheet,period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line)
        VALUES (?,?,\'_raise_line\',NULL,\'\',\'\',\'\',\'昇給ライン\',\'設定\',?)
        ON DUPLICATE KEY UPDATE raise_line=VALUES(raise_line)')
        ->execute(array($sheet, $period, $rl));

    jsonOk(array('saved' => true));
}

function handleAddMasterItem($body) {
    $sheet  = isset($body['sheet'])     ? $body['sheet']     : 'master40';
    $period = isset($body['period'])    ? (int)$body['period'] : 0;
    $iid    = isset($body['item_id'])   ? trim($body['item_id'])   : '';
    $name   = isset($body['item_name']) ? trim($body['item_name']) : '';
    $cat    = isset($body['item_cat'])  ? trim($body['item_cat'])  : '集計';
    if (!$iid || !$name) { jsonError('item_id and item_name required', 400); return; }
    ensureMasterTable();
    $rl = getDefaultRaiseLine($sheet);
    getPDO()->prepare('INSERT IGNORE INTO master_settings (sheet,period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line) VALUES (?,?,?,1,\'\',\'\',\'\',?,?,?)')
        ->execute(array($sheet, $period, $iid, $name, $cat, $rl));
    jsonOk(array('added' => $iid));
}

function handleDeleteMasterItem($body) {
    $sheet  = isset($body['sheet'])   ? $body['sheet']   : 'master40';
    $period = isset($body['period'])  ? (int)$body['period'] : 0;
    $iid    = isset($body['item_id']) ? trim($body['item_id']) : '';
    if (!$iid || $iid === '_raise_line') { jsonError('invalid item_id', 400); return; }
    getPDO()->prepare('DELETE FROM master_settings WHERE sheet=? AND period=? AND item_id=?')
        ->execute(array($sheet, $period, $iid));
    jsonOk(array('deleted' => $iid));
}

function handleGetMasterForCalc() {
    // スコア計算用：全sheet×全periodのマスタを一括返却
    ensureMasterTable();
    $sheets = array('master40','master40under','masterMG','masterTochuu');
    $result = array();
    foreach ($sheets as $sheet) {
        $result[$sheet] = array();
        for ($p = 0; $p <= 3; $p++) {
            seedMasterIfEmpty($sheet, $p);
            $rows = getPDO()->prepare('SELECT * FROM master_settings WHERE sheet=? AND period=?');
            $rows->execute(array($sheet, $p));
            $data = $rows->fetchAll(PDO::FETCH_ASSOC);
            $rl = 128;
            $items = array();
            foreach ($data as $r) {
                if ($r['item_id'] === '_raise_line') { $rl = (int)$r['raise_line']; }
                else { $items[] = $r; }
            }
            $result[$sheet][$p] = array('items' => $items, 'raise_line' => $rl);
        }
    }
    jsonOk($result);
}
