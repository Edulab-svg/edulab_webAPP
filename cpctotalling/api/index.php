<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/config.php';

$input = [];
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    $action = $input['action'] ?? '';
} else {
    $action = $_GET['action'] ?? '';
}

function ok($data) { echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function ng($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

switch ($action) {

    // ────────── 月データ取得 ──────────
    case 'get_data':
        $month = $_GET['month'] ?? ($input['month'] ?? '');
        if (!$month) ng('month必要');
        $stmt = $pdo->prepare('SELECT account, cost, top_display, remarketing, other_cost, yahoo, sort_order FROM monthly_data WHERE month = ? ORDER BY sort_order');
        $stmt->execute([$month]);
        ok($stmt->fetchAll());
        break;

    // ────────── 全月サマリ ──────────
    case 'get_all_summary':
        $stmt = $pdo->query('SELECT month, SUM(cost+top_display+remarketing+other_cost) AS google, SUM(yahoo) AS yahoo FROM monthly_data GROUP BY month');
        $result = [];
        foreach ($stmt->fetchAll() as $r) $result[$r['month']] = ['google'=>(int)$r['google'],'yahoo'=>(int)$r['yahoo'],'total'=>(int)$r['google']+(int)$r['yahoo']];
        ok($result);
        break;

    // ────────── セル更新 ──────────
    case 'save_cell':
        $month=$input['month']??''; $account=$input['account']??''; $field=$input['field']??''; $value=(int)($input['value']??0);
        $allowed = ['cost','top_display','remarketing','other_cost','yahoo'];
        if (!$month||!$account||!in_array($field,$allowed)) ng('パラメータ不正');
        $stmt = $pdo->prepare("UPDATE monthly_data SET {$field}=? WHERE month=? AND account=?");
        $stmt->execute([$value,$month,$account]);
        if ($stmt->rowCount()===0) {
            $stmt2 = $pdo->prepare("INSERT INTO monthly_data (month,account,{$field},sort_order) VALUES(?,?,?,99) ON DUPLICATE KEY UPDATE {$field}=?");
            $stmt2->execute([$month,$account,$value,$value]);
        }
        ok(['updated'=>true]);
        break;

    // ────────── 月データ一括保存 ──────────
    case 'save_month':
        $month=$input['month']??''; $rows=$input['rows']??[]; $src=$input['source']??'google';
        if (!$month||!is_array($rows)) ng('パラメータ不正');
        $pdo->beginTransaction();
        try {
            foreach ($rows as $i=>$row) {
                $acc=$row['account']??''; if (!$acc) continue;
                if ($src==='google') {
                    $stmt=$pdo->prepare('INSERT INTO monthly_data (month,account,cost,top_display,remarketing,other_cost,sort_order) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE cost=VALUES(cost),top_display=VALUES(top_display),remarketing=VALUES(remarketing),other_cost=VALUES(other_cost),sort_order=VALUES(sort_order)');
                    $stmt->execute([$month,$acc,(int)($row['cost']??0),(int)($row['top_display']??0),(int)($row['remarketing']??0),(int)($row['other_cost']??0),$i]);
                } else {
                    $stmt=$pdo->prepare('INSERT INTO monthly_data (month,account,yahoo,sort_order) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE yahoo=VALUES(yahoo)');
                    $stmt->execute([$month,$acc,(int)($row['yahoo']??0),$i]);
                }
            }
            $pdo->commit();
            ok(['saved'=>count($rows)]);
        } catch (Exception $e) { $pdo->rollBack(); ng('保存エラー:'.$e->getMessage(),500); }
        break;

    // ────────── 月初期化 ──────────
    case 'init_month':
        $month=$input['month']??''; $accounts=$input['accounts']??[];
        if (!$month||!is_array($accounts)) ng('パラメータ不正');
        $pdo->beginTransaction();
        try {
            foreach ($accounts as $i=>$acc) {
                $pdo->prepare('INSERT IGNORE INTO monthly_data (month,account,sort_order) VALUES(?,?,?)')->execute([$month,$acc,$i]);
            }
            $pdo->commit(); ok(['initialized'=>true]);
        } catch (Exception $e) { $pdo->rollBack(); ng('エラー:'.$e->getMessage(),500); }
        break;

    // ────────── 存在月一覧 ──────────
    case 'get_months':
        $months = array_column($pdo->query('SELECT DISTINCT month FROM monthly_data')->fetchAll(), 'month');
        ok($months);
        break;

    // ────────── 按分ルール取得 ──────────
    case 'get_rules':
        $stmt = $pdo->query('SELECT rule_id,label,match_keywords,column_name,targets,all_options FROM allocation_rules ORDER BY id');
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['match_keywords']=json_decode($r['match_keywords'],true); $r['targets']=json_decode($r['targets'],true); $r['all_options']=json_decode($r['all_options'],true); }
        ok($rows);
        break;

    // ────────── 按分ルール更新 ──────────
    case 'save_rules':
        $rules=$input['rules']??[];
        $pdo->beginTransaction();
        try {
            foreach ($rules as $rule) {
                $rid=$rule['rule_id']??''; $tgts=$rule['targets']??[];
                if (!$rid) continue;
                $pdo->prepare('UPDATE allocation_rules SET targets=? WHERE rule_id=?')->execute([json_encode($tgts,JSON_UNESCAPED_UNICODE),$rid]);
            }
            $pdo->commit(); ok(['updated'=>true]);
        } catch (Exception $e) { $pdo->rollBack(); ng('エラー:'.$e->getMessage(),500); }
        break;

    // ════════════ キャンペーンマッピング CRUD ════════════

    // ────────── マッピング全取得 ──────────
    case 'get_mappings':
        $src = $_GET['source'] ?? '';
        $sql = 'SELECT id,campaign_name,match_type,source,action,target_account,target_column,auto_top_display,enabled,notes FROM campaign_mappings';
        if ($src) { $stmt=$pdo->prepare($sql.' WHERE source=? ORDER BY source,action DESC,campaign_name'); $stmt->execute([$src]); }
        else { $stmt=$pdo->query($sql.' ORDER BY source,action DESC,campaign_name'); }
        ok($stmt->fetchAll());
        break;

    // ────────── マッピング追加 ──────────
    case 'add_mapping':
        $cn=$input['campaign_name']??''; $mt=$input['match_type']??'exact'; $src=$input['source']??'';
        $act=$input['map_action']??($input['action_type']??'map'); $ta=$input['target_account']??''; $tc=$input['target_column']??'cost';
        $atd=(int)($input['auto_top_display']??0); $notes=$input['notes']??'';
        if (!$cn||!$src) ng('キャンペーン名とソースは必須');
        $stmt=$pdo->prepare('INSERT INTO campaign_mappings (campaign_name,match_type,source,action,target_account,target_column,auto_top_display,notes) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE match_type=VALUES(match_type),action=VALUES(action),target_account=VALUES(target_account),target_column=VALUES(target_column),auto_top_display=VALUES(auto_top_display),notes=VALUES(notes),enabled=1');
        $stmt->execute([$cn,$mt,$src,$act,$ta,$tc,$atd,$notes]);
        ok(['id'=>$pdo->lastInsertId()]);
        break;

    // ────────── マッピング更新 ──────────
    case 'update_mapping':
        $id=(int)($input['id']??0);
        if (!$id) ng('ID必要');
        $fields=[]; $vals=[];
        foreach (['campaign_name','match_type','source','action','target_account','target_column','auto_top_display','enabled','notes'] as $f) {
            if (isset($input[$f])) { $fields[]="{$f}=?"; $vals[]=$input[$f]; }
        }
        if (empty($fields)) ng('更新フィールドなし');
        $vals[]=$id;
        $pdo->prepare('UPDATE campaign_mappings SET '.implode(',',$fields).' WHERE id=?')->execute($vals);
        ok(['updated'=>true]);
        break;

    // ────────── マッピング削除 ──────────
    case 'delete_mapping':
        $id=(int)($input['id']??0);
        if (!$id) ng('ID必要');
        $pdo->prepare('DELETE FROM campaign_mappings WHERE id=?')->execute([$id]);
        ok(['deleted'=>true]);
        break;

    // ────────── マッピング一括追加（未登録キャンペーン用） ──────────
    case 'add_mappings_bulk':
        $mappings=$input['mappings']??[];
        if (!is_array($mappings)) ng('パラメータ不正');
        $pdo->beginTransaction();
        try {
            $count=0;
            foreach ($mappings as $m) {
                $cn=$m['campaign_name']??''; $src=$m['source']??'';
                if (!$cn||!$src) continue;
                $stmt=$pdo->prepare('INSERT INTO campaign_mappings (campaign_name,match_type,source,action,target_account,target_column,auto_top_display,notes) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE action=VALUES(action),target_account=VALUES(target_account),target_column=VALUES(target_column),enabled=1');
                $stmt->execute([$cn,$m['match_type']??'exact',$src,$m['action']??'map',$m['target_account']??'',$m['target_column']??'cost',(int)($m['auto_top_display']??0),$m['notes']??'']);
                $count++;
            }
            $pdo->commit();
            ok(['added'=>$count]);
        } catch (Exception $e) { $pdo->rollBack(); ng('エラー:'.$e->getMessage(),500); }
        break;

    default:
        ng('不明なアクション: '.$action, 404);
}
