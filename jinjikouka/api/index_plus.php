<?php
// ============================================================
// 人事考課システム API - まんてん個別プラス版
// PHP 7.x / 8.x 両対応
// ============================================================
require_once __DIR__ . '/config.php';

ob_start();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    $action = isset($body['action']) ? $body['action'] : '';
} else {
    jsonError('Method not allowed', 405);
    exit;
    $body = [];
    $action = '';
}

// プラス用テーブルプレフィックス（まんてん個別と分離）
define('TABLE_PREFIX', 'plus_');

switch ($action) {
    case 'get_staff':       handleGetStaff();              break;
    case 'add_staff':       handleAddStaff($body);         break;
    case 'delete_staff':    handleDeleteStaff($body);      break;
    case 'update_staff':    handleUpdateStaff($body);      break;
    case 'get_master':      handleGetMaster();             break;
    case 'get_master_all':  handleGetMasterAll();          break;
    case 'save_master':     handleSaveMaster($body);       break;
    case 'add_master_item': handleAddMasterItem($body);    break;
    case 'del_master_item': handleDeleteMasterItem($body); break;
    case 'get_evals':       handleGetEvals();              break;
    case 'set_eval':        handleSetEval($body);          break;
    case 'save_evals_bulk': handleSaveEvalsBulk($body);    break;
    case 'get_locks':       handleGetLocks();              break;
    case 'set_lock':        handleSetLock($body);          break;
    case 'get_years':       handleGetYears();              break;
    case 'new_year':        handleNewYear($body);          break;
    default: jsonError('Unknown action', 400);
}

// ============================================================
// テーブル初期化
// ============================================================

function getCurrentYear() {
    // GETまたはPOSTから年度を取得、なければ現在年
    global $body;
    if (isset($_GET['year']) && ctype_digit($_GET['year'])) return (int)$_GET['year'];
    if (isset($body['year']) && is_numeric($body['year'])) return (int)$body['year'];
    return (int)date('Y');
}

function ensureStaffTable() {
    static $done = false;
    if ($done) return;
    $pdo = getPDO();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "staff` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `year` SMALLINT UNSIGNED NOT NULL DEFAULT " . (int)date('Y') . ",
        `name` VARCHAR(100) NOT NULL,
        `room` VARCHAR(100) NOT NULL DEFAULT '',
        `area` VARCHAR(50) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_year` (`year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 既存テーブルにyearカラムがない場合は追加
    try {
        $pdo->exec("ALTER TABLE `" . TABLE_PREFIX . "staff` ADD COLUMN `year` SMALLINT UNSIGNED NOT NULL DEFAULT " . (int)date('Y') . " AFTER `id`");
        $pdo->exec("ALTER TABLE `" . TABLE_PREFIX . "staff` ADD KEY `idx_year` (`year`)");
    } catch (Exception $e) { /* already exists */ }
    $done = true;
}

function ensureEvalsTable() {
    static $done = false;
    if ($done) return;
    $pdo = getPDO();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "evaluations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `year` SMALLINT UNSIGNED NOT NULL DEFAULT " . (int)date('Y') . ",
        `staff_id` INT UNSIGNED NOT NULL,
        `period` TINYINT UNSIGNED NOT NULL,
        `item_id` VARCHAR(32) NOT NULL,
        `eval_value` VARCHAR(20) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_eval` (`year`, `staff_id`, `period`, `item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 既存テーブルにyearカラムがない場合は追加
    try {
        $pdo->exec("ALTER TABLE `" . TABLE_PREFIX . "evaluations` ADD COLUMN `year` SMALLINT UNSIGNED NOT NULL DEFAULT " . (int)date('Y') . " AFTER `id`");
        $pdo->exec("ALTER TABLE `" . TABLE_PREFIX . "evaluations` DROP KEY `uq_eval`");
        $pdo->exec("ALTER TABLE `" . TABLE_PREFIX . "evaluations` ADD UNIQUE KEY `uq_eval` (`year`, `staff_id`, `period`, `item_id`)");
    } catch (Exception $e) { /* already exists */ }
    $done = true;
}

function ensureMasterTable() {
    static $done = false;
    if ($done) return;
    $pdo = getPDO();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "master_settings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `period` TINYINT UNSIGNED NOT NULL,
        `item_id` VARCHAR(16) NOT NULL,
        `rate` TINYINT UNSIGNED DEFAULT NULL,
        `c_a` VARCHAR(200) NOT NULL DEFAULT '',
        `c_b` VARCHAR(200) NOT NULL DEFAULT '',
        `c_c` VARCHAR(200) NOT NULL DEFAULT '',
        `item_name` VARCHAR(100) NOT NULL DEFAULT '',
        `item_cat` VARCHAR(30) NOT NULL DEFAULT '',
        `raise_line` SMALLINT UNSIGNED NOT NULL DEFAULT 94,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_master` (`period`, `item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// ============================================================
// デフォルト評価項目（まんてん個別プラス専用）
// ============================================================

function getDefaultItems($period) {
    $isLower = $period >= 2;
    if (!$isLower) {
        // 上半期（第1回・第2回）
        return [
            // 集計
            ['c01','集計','問体験率（塾ナビ除く）',2,'90%以上','80%以上','80%未満'],
            ['c02','集計','体験成功率',2,'90%以上','80%以上','80%未満'],
            ['c03','集計','不満退塾率',3,'10%未満','15%未満','15%以上'],
            ['c04','集計','退会率',2,'3.0%未満','3.5%未満','3.5%以上'],
            ['c05','集計','授業料単価（税込）',4,'上期：27,000円以上','上期：25,000円以上','上期：25,000円未満'],
            ['c06','集計','入金率',1,'評価なし','未入金なし','未入金あり'],
            ['c07','集計','目標達成率',3,'110%以上','100%以上','100%未満'],
            ['c08','集計','高校生継続',4,'40%以上','25%以上','25%未満'],
            // 教務
            ['e01','教務','次回定期テストまでのスケジュール作成（進行表）',2,'評価なし','100%できている','100%未満'],
            ['e02','教務','進行表の運用確認（月次）',3,'90%以上記入','80%以上記入','80%未満'],
            ['e03','教務','確認テスト2回目の履行',3,'90%以上記入','80%以上記入','80%未満'],
            ['e04','教務','講習会のスケジュール作成',2,'評価なし','履行','履行なし'],
            ['e05','教務','講習会のスケジュール履行',2,'評価なし','履行','履行なし'],
            ['e06','教務','年3回の3者面談',2,'評価なし','履行','履行なし'],
            ['e07','教務','テスト対策の実施',1,'評価なし','履行','履行なし'],
            ['e08','教務','成績回収率',1,'95%以上','90%以上','90%未満'],
            ['e09','教務','入塾半年以内 点数アップ率',2,'50%以上','40%以上','40%未満'],
            ['e10','教務','点数アップ写真',2,'定期試験ごとに4枚以上','定期試験ごとに3枚','定期試験ごとに2枚以下'],
            ['e11','教務','第一志望合格率',2,'（第四回のみ）','90%以上','90%未満'],
            // 教室運営
            ['r01','教室運営','服装',1,'評価なし','適切な服装','不適切な服装'],
            ['r02','教室運営','遅刻・欠勤',1,'評価なし','遅刻・欠勤・打刻忘れなし','遅刻・欠勤・打刻忘れあり'],
            ['r03','教室運営','日報',1,'評価なし','提出忘れなし','提出忘れあり'],
            ['r04','教室運営','教室環境の整備',1,'優れている','可','不備'],
            ['r05','教室運営','月次業務の期限内の遂行',1,'評価なし','出来ている','出来ていない'],
        ];
    } else {
        // 下半期（第3回・第4回）— AIツールカテゴリ追加
        return [
            // 集計
            ['c01','集計','問体験率（塾ナビ除く）',2,'90%以上','80%以上','80%未満'],
            ['c02','集計','体験成功率',2,'90%以上','80%以上','80%未満'],
            ['c03','集計','不満退塾率',4,'10%未満','15%未満','15%以上'],
            ['c04','集計','退会率',2,'3.0%未満','3.5%未満','3.5%以上'],
            ['c05','集計','授業料単価（税込）',4,'上期：27,000円以上','上期：25,000円以上','上期：25,000円未満'],
            ['c06','集計','入金率',1,'評価なし','未入金なし','未入金あり'],
            ['c07','集計','目標達成率',3,'110%以上','100%以上','100%未満'],
            ['c08','集計','高校生継続',4,'40%以上','25%以上','25%未満'],
            // 教務
            ['e01','教務','次回定期テストまでのスケジュール作成（進行表）',2,'評価なし','100%できている','100%未満'],
            ['e02','教務','進行表の運用確認（月次）',3,'90%以上記入','80%以上記入','80%未満'],
            ['e03','教務','確認テスト2回目の履行',3,'90%以上記入','80%以上記入','80%未満'],
            ['e04','教務','講習会のスケジュール作成',2,'評価なし','履行','履行なし'],
            ['e05','教務','講習会のスケジュール履行',2,'評価なし','履行','履行なし'],
            ['e06','教務','年3回の3者面談',2,'評価なし','履行','履行なし'],
            ['e07','教務','テスト対策の実施',1,'評価なし','履行','履行なし'],
            ['e08','教務','成績回収率',1,'95%以上','90%以上','90%未満'],
            ['e09','教務','入塾半年以内 点数アップ率',2,'50%以上','40%以上','40%未満'],
            ['e10','教務','点数アップコンテンツ',2,'定期試験ごとに4枚以上','定期試験ごとに3枚','定期試験ごとに2枚以下'],
            ['e11','教務','第一志望合格率',2,'（第四回のみ）','90%以上','90%未満'],
            // AIツールの活用
            ['a01','AIツールの活用','定期試験回収率',1,'100%','95%以上','95%未満'],
            ['a02','AIツールの活用','AIツールの活用',1,'評価なし','出来ている','出来ていない'],
            // 教室運営
            ['r01','教室運営','服装',1,'評価なし','適切な服装','不適切な服装'],
            ['r02','教室運営','遅刻・欠勤',1,'評価なし','遅刻・欠勤・打刻忘れなし','遅刻・欠勤・打刻忘れあり'],
            ['r03','教室運営','日報',1,'評価なし','提出忘れなし','提出忘れあり'],
            ['r04','教室運営','教室環境の整備',1,'優れている','可','不備'],
            ['r05','教室運営','月次業務の期限内の遂行',1,'評価なし','出来ている','出来ていない'],
        ];
    }
}

function getDefaultRaiseLine($period) {
    // 上半期: 94点 / 下半期: 102点
    return $period >= 2 ? 102 : 94;
}

function seedMasterIfEmpty($period) {
    ensureMasterTable();
    $pdo = getPDO();
    $tbl = TABLE_PREFIX . 'master_settings';
    $count = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE period=?");
    $count->execute([$period]);
    if ((int)$count->fetchColumn() > 0) return;

    $items = getDefaultItems($period);
    $rl = getDefaultRaiseLine($period);
    $stmt = $pdo->prepare("INSERT IGNORE INTO `$tbl` (period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($items as $item) {
        $stmt->execute([$period, $item[0], $item[3], $item[4], $item[5], $item[6], $item[2], $item[1], $rl]);
    }
    $pdo->prepare("INSERT IGNORE INTO `$tbl` (period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line) VALUES (?,'_raise_line',NULL,'','','','昇給ライン','設定',?)")
        ->execute([$period, $rl]);
}

// ============================================================
// スタッフ
// ============================================================
function handleGetStaff() {
    ensureStaffTable();
    $year = getCurrentYear();
    $tbl = TABLE_PREFIX . 'staff';
    $stmt = getPDO()->prepare("SELECT * FROM `$tbl` WHERE year=? ORDER BY id ASC");
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['area'] = $r['area'] ?? ''; }
    jsonOk($rows);
}

function handleAddStaff($body) {
    $name = isset($body['name']) ? trim($body['name']) : '';
    $room = isset($body['room']) ? trim($body['room']) : '';
    $area = isset($body['area']) ? trim($body['area']) : '';
    $year = getCurrentYear();
    if (!$name) { jsonError('Name required', 400); return; }
    ensureStaffTable();
    $tbl = TABLE_PREFIX . 'staff';
    $pdo = getPDO();
    $pdo->prepare("INSERT INTO `$tbl` (year, name, room, area) VALUES (?,?,?,?)")->execute([$year, $name, $room, $area]);
    $id = (int)$pdo->lastInsertId();
    jsonOk(['id' => $id, 'year' => $year, 'name' => $name, 'room' => $room, 'area' => $area]);
}

function handleUpdateStaff($body) {
    $id   = isset($body['id'])   ? (int)$body['id']    : 0;
    $room = isset($body['room']) ? trim($body['room']) : '';
    $area = isset($body['area']) ? trim($body['area']) : '';
    if (!$id) { jsonError('ID required', 400); return; }
    $tbl = TABLE_PREFIX . 'staff';
    getPDO()->prepare("UPDATE `$tbl` SET room=?, area=? WHERE id=?")->execute([$room, $area, $id]);
    jsonOk(['updated' => $id]);
}

function handleDeleteStaff($body) {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    if (!$id) { jsonError('ID required', 400); return; }
    $pdo = getPDO();
    $stbl = TABLE_PREFIX . 'staff';
    $etbl = TABLE_PREFIX . 'evaluations';
    $pdo->prepare("DELETE FROM `$etbl` WHERE staff_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM `$stbl` WHERE id=?")->execute([$id]);
    jsonOk(['deleted' => $id]);
}

// ============================================================
// 評価
// ============================================================
function handleGetEvals() {
    ensureEvalsTable();
    $year = getCurrentYear();
    $tbl = TABLE_PREFIX . 'evaluations';
    $stmt = getPDO()->prepare("SELECT staff_id, period, item_id, eval_value FROM `$tbl` WHERE year=?");
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $r) {
        $result[$r['staff_id']][$r['period']][$r['item_id']] = $r['eval_value'];
    }
    jsonOk($result);
}

function handleSetEval($body) {
    $year    = getCurrentYear();
    $staffId = isset($body['staff_id'])   ? (int)$body['staff_id']   : 0;
    $period  = isset($body['period'])     ? (int)$body['period']      : -1;
    $itemId  = isset($body['item_id'])    ? trim($body['item_id'])    : '';
    $value   = isset($body['eval_value']) ? trim($body['eval_value']) : '';
    if (!$staffId || $period < 0 || !$itemId) { jsonError('staff_id, period, item_id required', 400); return; }
    ensureEvalsTable();
    $tbl = TABLE_PREFIX . 'evaluations';
    if ($value === '') {
        getPDO()->prepare("DELETE FROM `$tbl` WHERE year=? AND staff_id=? AND period=? AND item_id=?")->execute([$year, $staffId, $period, $itemId]);
        jsonOk(['deleted' => true]); return;
    }
    $valid = ['A','B','C','評価なし'];
    if (strpos($itemId, '_num_') === 0 || strpos($itemId, '_self') !== false) {
        // 数値・自己評価は自由文字列許可
    } else {
        if (!in_array($value, $valid)) { jsonError('Invalid eval_value', 400); return; }
    }
    getPDO()->prepare("INSERT INTO `$tbl` (year, staff_id, period, item_id, eval_value) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE eval_value=?")
            ->execute([$year, $staffId, $period, $itemId, $value, $value]);
    jsonOk(['saved' => true]);
}

// 一括保存（保存ボタン用）
function handleSaveEvalsBulk($body) {
    $year   = getCurrentYear();
    $items  = isset($body['items']) ? $body['items'] : [];
    if (empty($items)) { jsonOk(['saved' => 0]); return; }
    ensureEvalsTable();
    $tbl = TABLE_PREFIX . 'evaluations';
    $pdo = getPDO();
    $count = 0;
    foreach ($items as $item) {
        $staffId = isset($item['staff_id']) ? (int)$item['staff_id'] : 0;
        $period  = isset($item['period'])   ? (int)$item['period']   : -1;
        $itemId  = isset($item['item_id'])  ? trim($item['item_id']) : '';
        $value   = isset($item['eval_value']) ? trim($item['eval_value']) : '';
        if (!$staffId || $period < 0 || !$itemId) continue;
        if ($value === '') {
            $pdo->prepare("DELETE FROM `$tbl` WHERE year=? AND staff_id=? AND period=? AND item_id=?")->execute([$year, $staffId, $period, $itemId]);
        } else {
            $pdo->prepare("INSERT INTO `$tbl` (year, staff_id, period, item_id, eval_value) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE eval_value=?")
                ->execute([$year, $staffId, $period, $itemId, $value, $value]);
        }
        $count++;
    }
    jsonOk(['saved' => $count]);
}

// ============================================================
// マスタ
// ============================================================
function handleGetMaster() {
    $period = isset($_GET['period']) ? (int)$_GET['period'] : 0;
    seedMasterIfEmpty($period);
    $tbl = TABLE_PREFIX . 'master_settings';
    $rows = getPDO()->prepare("SELECT * FROM `$tbl` WHERE period=? ORDER BY id ASC");
    $rows->execute([$period]);
    $data = $rows->fetchAll(PDO::FETCH_ASSOC);
    $rl = getDefaultRaiseLine($period);
    $items = [];
    foreach ($data as $r) {
        if ($r['item_id'] === '_raise_line') { $rl = (int)$r['raise_line']; }
        else { $items[] = $r; }
    }
    jsonOk(['items' => $items, 'raise_line' => $rl]);
}

function handleGetMasterAll() {
    $result = [];
    for ($p = 0; $p <= 3; $p++) {
        seedMasterIfEmpty($p);
        $tbl = TABLE_PREFIX . 'master_settings';
        $rows = getPDO()->prepare("SELECT * FROM `$tbl` WHERE period=?");
        $rows->execute([$p]);
        $data = $rows->fetchAll(PDO::FETCH_ASSOC);
        $rl = getDefaultRaiseLine($p);
        $items = [];
        foreach ($data as $r) {
            if ($r['item_id'] === '_raise_line') { $rl = (int)$r['raise_line']; }
            else { $items[] = $r; }
        }
        $result[$p] = ['items' => $items, 'raise_line' => $rl];
    }
    jsonOk($result);
}

function handleSaveMaster($body) {
    $period = isset($body['period'])     ? (int)$body['period']      : 0;
    $items  = isset($body['items'])      ? $body['items']            : [];
    $rl     = isset($body['raise_line']) ? (int)$body['raise_line']  : getDefaultRaiseLine($period);
    ensureMasterTable();
    $tbl = TABLE_PREFIX . 'master_settings';
    $stmt = getPDO()->prepare("INSERT INTO `$tbl` (period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE rate=VALUES(rate),c_a=VALUES(c_a),c_b=VALUES(c_b),c_c=VALUES(c_c),item_name=VALUES(item_name),item_cat=VALUES(item_cat),raise_line=VALUES(raise_line)");
    foreach ($items as $item) {
        $stmt->execute([
            $period,
            $item['item_id'],
            isset($item['rate']) && $item['rate'] !== '' && $item['rate'] !== null ? (int)$item['rate'] : null,
            $item['c_a'], $item['c_b'], $item['c_c'],
            $item['item_name'], $item['item_cat'],
            $rl
        ]);
    }
    getPDO()->prepare("INSERT INTO `$tbl` (period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line)
        VALUES (?,'_raise_line',NULL,'','','','昇給ライン','設定',?)
        ON DUPLICATE KEY UPDATE raise_line=VALUES(raise_line)")->execute([$period, $rl]);
    jsonOk(['saved' => true]);
}

function handleAddMasterItem($body) {
    $period = isset($body['period'])    ? (int)$body['period']        : 0;
    $iid    = isset($body['item_id'])   ? trim($body['item_id'])      : '';
    $name   = isset($body['item_name']) ? trim($body['item_name'])    : '';
    $cat    = isset($body['item_cat'])  ? trim($body['item_cat'])     : '集計';
    if (!$iid || !$name) { jsonError('item_id and item_name required', 400); return; }
    ensureMasterTable();
    $tbl = TABLE_PREFIX . 'master_settings';
    $rl = getDefaultRaiseLine($period);
    getPDO()->prepare("INSERT IGNORE INTO `$tbl` (period,item_id,rate,c_a,c_b,c_c,item_name,item_cat,raise_line) VALUES (?,?,1,'','','',?,?,?)")
        ->execute([$period, $iid, $name, $cat, $rl]);
    jsonOk(['added' => $iid]);
}

function handleDeleteMasterItem($body) {
    $period = isset($body['period'])  ? (int)$body['period']  : 0;
    $iid    = isset($body['item_id']) ? trim($body['item_id']) : '';
    if (!$iid || $iid === '_raise_line') { jsonError('invalid item_id', 400); return; }
    $tbl = TABLE_PREFIX . 'master_settings';
    getPDO()->prepare("DELETE FROM `$tbl` WHERE period=? AND item_id=?")->execute([$period, $iid]);
    jsonOk(['deleted' => $iid]);
}

// ============================================================
// ヘルパー
// ============================================================
function jsonOk($data) {
    ob_clean();
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError($msg, $code = 400) {
    ob_clean();
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 確定ロック
// ============================================================
function ensureLockTable() {
    static $done = false;
    if ($done) return;
    $pdo = getPDO();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "period_locks` (
        `year` SMALLINT UNSIGNED NOT NULL DEFAULT " . (int)date('Y') . ",
        `period` TINYINT UNSIGNED NOT NULL,
        `locked` TINYINT(1) NOT NULL DEFAULT 0,
        `locked_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`year`, `period`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 既存テーブルにyearカラムがない場合は追加
    try {
        $pdo->exec("ALTER TABLE `" . TABLE_PREFIX . "period_locks` ADD COLUMN `year` SMALLINT UNSIGNED NOT NULL DEFAULT " . (int)date('Y') . " FIRST");
        $pdo->exec("ALTER TABLE `" . TABLE_PREFIX . "period_locks` DROP PRIMARY KEY");
        $pdo->exec("ALTER TABLE `" . TABLE_PREFIX . "period_locks` ADD PRIMARY KEY (`year`, `period`)");
    } catch (Exception $e) {}
    $done = true;
}

function handleGetLocks() {
    ensureLockTable();
    $year = getCurrentYear();
    $tbl = TABLE_PREFIX . 'period_locks';
    $stmt = getPDO()->prepare("SELECT period, locked FROM `$tbl` WHERE year=?");
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [0=>false, 1=>false, 2=>false, 3=>false];
    foreach ($rows as $r) {
        $result[(int)$r['period']] = (bool)$r['locked'];
    }
    jsonOk($result);
}

function handleSetLock($body) {
    $year   = getCurrentYear();
    $period = isset($body['period']) ? (int)$body['period'] : -1;
    $locked = isset($body['locked']) ? (bool)$body['locked'] : false;
    if ($period < 0 || $period > 3) { jsonError('Invalid period', 400); return; }
    ensureLockTable();
    $tbl = TABLE_PREFIX . 'period_locks';
    $lockedAt = $locked ? date('Y-m-d H:i:s') : null;
    getPDO()->prepare("INSERT INTO `$tbl` (year, period, locked, locked_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE locked=VALUES(locked), locked_at=VALUES(locked_at)")
            ->execute([$year, $period, $locked ? 1 : 0, $lockedAt]);
    jsonOk(['year' => $year, 'period' => $period, 'locked' => $locked]);
}

// ============================================================
// 年度管理
// ============================================================
function handleGetYears() {
    ensureStaffTable();
    $tbl = TABLE_PREFIX . 'staff';
    $rows = getPDO()->query("SELECT DISTINCT year FROM `$tbl` ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
    $years = array_map(function($r){ return (int)$r['year']; }, $rows);
    $currentYear = (int)date('Y');
    if (!in_array($currentYear, $years)) $years[] = $currentYear;
    rsort($years);
    jsonOk($years);
}

function handleNewYear($body) {
    $fromYear = isset($body['from_year']) ? (int)$body['from_year'] : (int)date('Y');
    $toYear   = isset($body['to_year'])   ? (int)$body['to_year']   : $fromYear + 1;
    if ($toYear <= $fromYear) { jsonError('to_year must be greater than from_year', 400); return; }
    ensureStaffTable();
    $pdo = getPDO();
    $stbl = TABLE_PREFIX . 'staff';
    // 既に新年度スタッフがいないか確認
    $check = $pdo->prepare("SELECT COUNT(*) FROM `$stbl` WHERE year=?");
    $check->execute([$toYear]);
    if ((int)$check->fetchColumn() > 0) { jsonError('新年度のスタッフデータが既に存在します', 400); return; }
    // 旧年度のスタッフ名だけ引き継ぎ（教室・エリアはリセット）
    $old = $pdo->prepare("SELECT name FROM `$stbl` WHERE year=? ORDER BY id ASC");
    $old->execute([$fromYear]);
    $names = $old->fetchAll(PDO::FETCH_ASSOC);
    $ins = $pdo->prepare("INSERT INTO `$stbl` (year, name, room, area) VALUES (?,?,?,?)");
    foreach ($names as $n) {
        $ins->execute([$toYear, $n['name'], '', '']);
    }
    jsonOk(['to_year' => $toYear, 'count' => count($names)]);
}
