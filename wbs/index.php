<?php
// ============================================================
// DB設定
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'xs047468_wbs');
define('DB_USER', 'xs047468_wbs');
define('DB_PASS', 'Manten2024');

function getDB(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

// ============================================================
// API ハンドリング
// ============================================================
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $nd = fn($v) => ($v === '' || $v === null) ? null : $v;
    if (isset($in['ai'])) { $in['ai'] = ($in['ai'] === true || $in['ai'] === 1 || $in['ai'] === '1') ? 1 : 0; }

    try {
        $db = getDB();

        // --- 通常タスク取得（ゴミ箱除外）---
        if ($action === 'get') {
            $rows = $db->query('SELECT * FROM wbs_tasks WHERE deleted_at IS NULL ORDER BY id ASC')->fetchAll();
            foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['ai']=(bool)$r['ai']; }
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);

        // --- ゴミ箱取得 ---
        } elseif ($action === 'get_trash') {
            $rows = $db->query('SELECT * FROM wbs_tasks WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll();
            foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['ai']=(bool)$r['ai']; }
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);

        // --- 完了タスク取得 ---
        } elseif ($action === 'get_completed') {
            $rows = $db->query('SELECT * FROM wbs_tasks WHERE deleted_at IS NULL AND st=\'完了\' ORDER BY completed_at DESC')->fetchAll();
            foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['ai']=(bool)$r['ai']; }
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);

        // --- 追加 ---
        } elseif ($action === 'add') {
            $s = $db->prepare(
                'INSERT INTO wbs_tasks (bu,cat,ai,owner,mis,misPriority,kpiM,dlM,sub,kpiS,dlS,task,`who`,due,st,priority)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([
                $in['bu']??'', $in['cat']??'', $in['ai']??0,
                $in['owner']??'', $in['mis']??'', $in['misPriority']??'中',
                $in['kpiM']??'', $nd($in['dlM']??''), $in['sub']??'',
                $in['kpiS']??'', $nd($in['dlS']??''), $in['task']??'',
                $in['who']??'', $nd($in['due']??''),
                $in['st']??'未着手', $in['priority']??'中'
            ]);
            $newId = (int)$db->lastInsertId();
            $db->prepare('UPDATE wbs_tasks SET misPriority=? WHERE bu=? AND cat=? AND mis=? AND id!=? AND deleted_at IS NULL')
               ->execute([$in['misPriority']??'中', $in['bu']??'', $in['cat']??'', $in['mis']??'', $newId]);
            echo json_encode(['id'=>$newId], JSON_UNESCAPED_UNICODE);

        // --- 更新 ---
        } elseif ($action === 'update') {
            $db->prepare(
                'UPDATE wbs_tasks SET bu=?,cat=?,ai=?,owner=?,mis=?,misPriority=?,kpiM=?,dlM=?,sub=?,kpiS=?,dlS=?,task=?,`who`=?,due=?,st=?,priority=? WHERE id=?'
            )->execute([
                $in['bu']??'', $in['cat']??'', $in['ai']??0,
                $in['owner']??'', $in['mis']??'', $in['misPriority']??'中',
                $in['kpiM']??'', $nd($in['dlM']??''), $in['sub']??'',
                $in['kpiS']??'', $nd($in['dlS']??''), $in['task']??'',
                $in['who']??'', $nd($in['due']??''),
                $in['st']??'未着手', $in['priority']??'中', (int)($in['id']??0)
            ]);
            $db->prepare('UPDATE wbs_tasks SET misPriority=? WHERE bu=? AND cat=? AND mis=? AND deleted_at IS NULL')
               ->execute([$in['misPriority']??'中', $in['bu']??'', $in['cat']??'', $in['mis']??'']);
            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);

        // --- ゴミ箱へ移動（ソフト削除）---
        } elseif ($action === 'trash') {
            $db->prepare('UPDATE wbs_tasks SET deleted_at=NOW() WHERE id=?')->execute([(int)($in['id']??0)]);
            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);

        // --- ゴミ箱から復元 ---
        } elseif ($action === 'restore') {
            $db->prepare('UPDATE wbs_tasks SET deleted_at=NULL WHERE id=?')->execute([(int)($in['id']??0)]);
            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);

        // --- 完全削除 ---
        } elseif ($action === 'delete') {
            $db->prepare('DELETE FROM wbs_tasks WHERE id=?')->execute([(int)($in['id']??0)]);
            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);

        // --- 完了 ---
        } elseif ($action === 'complete') {
            $db->prepare('UPDATE wbs_tasks SET st=\'完了\', completed_at=NOW() WHERE id=?')->execute([(int)($in['id']??0)]);
            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);

        // --- 完了取消 ---
        } elseif ($action === 'uncomplete') {
            $db->prepare('UPDATE wbs_tasks SET st=\'進行中\', completed_at=NULL WHERE id=?')->execute([(int)($in['id']??0)]);
            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);

        // --- コピー ---
        } elseif ($action === 'copy') {
            $src = $db->prepare('SELECT * FROM wbs_tasks WHERE id=?');
            $src->execute([(int)($in['id']??0)]);
            $row = $src->fetch();
            if (!$row) { http_response_code(404); echo json_encode(['error'=>'元タスクが見つかりません']); exit; }
            $newBu = $in['bu'] ?? $row['bu'];
            $s = $db->prepare(
                'INSERT INTO wbs_tasks (bu,cat,ai,owner,mis,misPriority,kpiM,dlM,sub,kpiS,dlS,task,`who`,due,st,priority)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([
                $newBu, $row['cat'], $row['ai'], $row['owner'], $row['mis'], $row['misPriority']??'中',
                $row['kpiM'], $row['dlM'], $row['sub'], $row['kpiS'], $row['dlS'],
                $row['task'], $row['who'], $row['due'], '未着手', $row['priority']??'中'
            ]);
            echo json_encode(['id'=>(int)$db->lastInsertId()], JSON_UNESCAPED_UNICODE);

        // --- AI切替 ---
        } elseif ($action === 'toggleai') {
            $db->prepare('UPDATE wbs_tasks SET ai=? WHERE id=?')
               ->execute([$in['ai']??0, (int)($in['id']??0)]);
            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);

        } else {
            http_response_code(400);
            echo json_encode(['error'=>'Unknown action'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WBS ダッシュボード｜タスク管理</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;600;700;800;900&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#f5f3ef;--card:#fff;--border:#e2dfd9;--text:#1a1916;--text-s:#6b6860;--text-m:#a09c94;--r:10px;--green:#00b894;--purple:#6c5ce7;--orange:#e17055;--yellow:#f0c040;--green-l:#e8faf4;--purple-l:#f0ecfd;--orange-l:#fde8e2;--yellow-l:#fef9e7;--danger:#e74c3c;--complete:#3498db}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Noto Sans JP',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.header{background:#fff;border-bottom:1px solid var(--border);padding:0 24px;position:sticky;top:0;z-index:200}
.header-inner{max-width:2000px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;height:52px;gap:10px;flex-wrap:wrap}
.header-left{display:flex;align-items:center;gap:10px}
.logo{width:32px;height:32px;background:linear-gradient(135deg,#2d6a4f,#52b788);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:11px}
.h-title{font-size:15px;font-weight:800}
.h-sub{font-size:10px;color:var(--text-m)}
.header-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.tabs{display:flex;gap:4px;background:#eae8e3;border-radius:8px;padding:3px}
.tab{font-size:11px;padding:5px 14px;border:none;border-radius:6px;background:transparent;color:var(--text-s);cursor:pointer;font-family:inherit;font-weight:500;transition:all .15s}
.tab.active{background:#fff;color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.controls{padding:10px 24px;display:flex;gap:8px;align-items:center;max-width:2000px;margin:0 auto;flex-wrap:wrap;border-bottom:1px solid #eae8e3;background:#faf8f5}
.ctrl-btn{font-family:'Noto Sans JP';font-size:10px;padding:4px 12px;border:1px solid var(--border);border-radius:14px;background:#fff;color:var(--text-s);cursor:pointer;transition:all .15s;white-space:nowrap;font-weight:500}
.ctrl-btn:hover{border-color:#2d6a4f;color:#2d6a4f}
.ctrl-btn.active{background:#2d6a4f;border-color:#2d6a4f;color:#fff}
.ctrl-label{font-size:10px;color:var(--text-m);margin-right:2px;font-weight:600}
.sep{width:1px;height:18px;background:var(--border);margin:0 6px}
.kpi-toggle{display:flex;align-items:center;gap:4px;font-size:10px;color:var(--text-s);cursor:pointer;user-select:none;font-weight:500}
.kpi-toggle input{accent-color:#2d6a4f;width:13px;height:13px}
.btn{font-family:'Noto Sans JP';font-size:11px;padding:6px 14px;border:none;border-radius:8px;cursor:pointer;font-weight:600;transition:all .15s;display:inline-flex;align-items:center;gap:4px}
.btn-primary{background:#2d6a4f;color:#fff}.btn-primary:hover{background:#245a42}
.btn-danger{background:#fff;color:var(--danger);border:1px solid #f5c6cb}.btn-danger:hover{background:#fef2f2}
.btn-ghost{background:transparent;color:var(--text-s);border:1px solid var(--border)}.btn-ghost:hover{background:#fff;border-color:#aaa}
.btn-export{background:#f8f6f2;color:var(--text-s);border:1px solid var(--border)}.btn-export:hover{background:#fff}
.btn-sm{font-size:10px;padding:3px 8px;border-radius:5px}
.btn-complete{background:#3498db;color:#fff;border:none}.btn-complete:hover{background:#2980b9}
.btn-copy{background:#8e44ad;color:#fff;border:none}.btn-copy:hover{background:#7d3c98}
.btn-restore{background:#27ae60;color:#fff;border:none}.btn-restore:hover{background:#219a52}
.person-filter-bar{padding:10px 24px;display:flex;gap:12px;align-items:center;max-width:2000px;margin:0 auto;flex-wrap:wrap;border-bottom:1px solid #eae8e3;background:#f0f8f4}
.person-filter-bar .ctrl-label{color:#2d6a4f}
.who-select{font-family:'Noto Sans JP';font-size:12px;padding:6px 14px;border:2px solid #a8d8be;border-radius:10px;background:#fff;color:var(--text);min-width:200px;font-weight:600;cursor:pointer}
.who-select:focus{border-color:#2d6a4f;outline:none}
.cat-select{font-family:'Noto Sans JP';font-size:11px;padding:4px 10px;border:1px solid var(--border);border-radius:14px;background:#fff;color:var(--text);font-weight:500;cursor:pointer}
.cat-select:focus{border-color:#2d6a4f;outline:none}
.cat-select.filtered{background:#2d6a4f;color:#fff;border-color:#2d6a4f;font-weight:700}
.who-select.filtered{background:#2d6a4f;color:#fff;border-color:#2d6a4f}
.scope-toggle{display:flex;gap:0;border:2px solid #a8d8be;border-radius:10px;overflow:hidden}
.scope-btn{font-family:'Noto Sans JP';font-size:11px;padding:5px 14px;border:none;background:#fff;color:#2d6a4f;cursor:pointer;font-weight:600;transition:all .15s;white-space:nowrap}
.scope-btn:first-child{border-right:1px solid #a8d8be}
.scope-btn.active{background:#2d6a4f;color:#fff}
.scope-btn:hover:not(.active){background:#e8f5ee}
.stats{display:flex;gap:10px;padding:10px 24px;max-width:2000px;margin:0 auto;flex-wrap:wrap}
.stat-chip{font-size:10px;color:var(--text-s);background:#fff;border:1px solid var(--border);border-radius:12px;padding:3px 10px;font-weight:500}
.stat-chip b{color:var(--text);font-weight:700}
.legend{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.leg-item{display:flex;align-items:center;gap:4px;font-size:10px;color:var(--text-s);font-weight:500}
.leg-dot{width:12px;height:12px;border-radius:3px}
.canvas{padding:24px;overflow-x:auto;min-height:200px}
.canvas::-webkit-scrollbar{height:10px}
.canvas::-webkit-scrollbar-track{background:#eae8e3;border-radius:5px}
.canvas::-webkit-scrollbar-thumb{background:#c5c0b8;border-radius:5px}
.wbs-tree{display:flex;align-items:flex-start;width:fit-content}
.wbs-row{display:flex;align-items:flex-start}
.wbs-col{display:flex;flex-direction:column;gap:5px}
.wbs-conn{display:flex;align-items:center;width:32px;flex-shrink:0}
.node{border-radius:var(--r);display:inline-flex;flex-direction:column;justify-content:center;padding:8px 12px;position:relative;cursor:default;transition:all .15s;flex-shrink:0;line-height:1.35}
.node:hover{transform:translateY(-1px);box-shadow:0 3px 12px rgba(0,0,0,.07)}
.node-root{background:#d5d2cc;text-align:center;font-size:14px;font-weight:900;padding:22px 16px;min-width:120px;border-radius:12px;letter-spacing:.04em;line-height:1.5}
.node-dept{color:#fff;font-size:12px;font-weight:700;min-width:120px;text-align:center;padding:14px 12px}
.node-cat{font-size:10px;font-weight:600;min-width:100px;text-align:center;border:2px solid;padding:8px 10px}
.node-mis{font-size:9.5px;font-weight:500;min-width:160px;max-width:240px;text-align:left;border:1.5px solid;padding:7px 10px}
.node-sub{font-size:9px;font-weight:500;min-width:140px;max-width:220px;text-align:left;border:2px dashed;border-radius:7px;padding:6px 9px}
.node-task{font-size:9px;font-weight:400;min-width:180px;max-width:280px;text-align:left;border:1px solid;border-radius:5px;flex-direction:row;align-items:center;gap:5px;padding:5px 8px}
.bg-m{background:var(--green)}.bg-p{background:var(--purple)}.bg-a{background:var(--orange)}.bg-z{background:var(--yellow);color:#333!important}
.cat-m{background:var(--green-l);border-color:var(--green);color:#00866b}
.cat-p{background:var(--purple-l);border-color:var(--purple);color:#4a3ba0}
.cat-a{background:var(--orange-l);border-color:var(--orange);color:#b04530}
.cat-z{background:var(--yellow-l);border-color:#e0c850;color:#8a6d10}
.mis-m{background:#f2fcf8;border-color:#a3e4d0;color:#00866b}
.mis-p{background:#f8f5fe;border-color:#c4b8f0;color:#4a3ba0}
.mis-a{background:#fef5f2;border-color:#f0b8a8;color:#b04530}
.mis-z{background:#fefcf3;border-color:#f0dda0;color:#8a6d10}
.mis-pri-high{border-width:2.5px!important;border-color:#e74c3c!important;box-shadow:0 0 0 1px rgba(231,76,60,.15)}
.mis-pri-low{opacity:.75}
.sub-m{background:#f5fdf9;border-color:#80d4b2;color:#00866b}
.sub-p{background:#faf8ff;border-color:#b8aae8;color:#4a3ba0}
.sub-a{background:#fff8f6;border-color:#efc4b8;color:#b04530}
.sub-z{background:#fffdf5;border-color:#e8d898;color:#8a6d10}
.tsk-m{background:#f8fefb;border-color:#c8ede0;color:#1a6b50}
.tsk-p{background:#fcfaff;border-color:#ddd6f5;color:#4a3ba0}
.tsk-a{background:#fff9f7;border-color:#f5d0c5;color:#b04530}
.tsk-z{background:#fefdf8;border-color:#f5ecc0;color:#7a6a10}
.badge{font-family:'IBM Plex Mono',monospace;font-size:8px;font-weight:600;background:rgba(255,255,255,.7);padding:1px 5px;border-radius:5px;white-space:nowrap;margin-left:4px}
.ai-badge{font-size:7px;background:#6366f1;color:#fff;padding:1px 4px;border-radius:3px;margin-left:3px;font-weight:700}
.pri-badge{font-size:7px;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:3px;letter-spacing:.02em}
.pri-high{background:#e74c3c;color:#fff}.pri-mid{background:#f39c12;color:#fff}.pri-low{background:#95a5a6;color:#fff}
.mis-pri-badge{font-size:8px;padding:2px 6px;border-radius:4px;font-weight:700;display:inline-flex;align-items:center;gap:3px;margin-bottom:3px}
.pri-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:3px;vertical-align:middle}
.pri-dot-high{background:#e74c3c}.pri-dot-mid{background:#f39c12}.pri-dot-low{background:#95a5a6}
.task-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.dot-g{background:#2d6a4f}.dot-n{background:#bbb}.dot-o{background:#c0392b}.dot-c{background:#3498db}
.kpi{font-size:8px;color:inherit;opacity:.7;margin-top:2px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kpi-label{font-weight:600;margin-right:2px}
.kpi-hidden .kpi{display:none}
.who-label{font-size:8px;color:inherit;opacity:.5;flex-shrink:0;max-width:60px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.due-label{font-family:'IBM Plex Mono';font-size:7.5px;opacity:.4;flex-shrink:0}
.overdue-border{border-color:#c0392b!important;background:#fef2f0!important}
.node-count{font-family:'IBM Plex Mono';font-size:8px;opacity:.55;margin-top:2px}
.tip{display:none;position:absolute;left:calc(100% + 8px);top:0;background:#1a1916;color:#fff;font-size:9px;padding:8px 10px;border-radius:7px;white-space:pre-line;z-index:50;pointer-events:none;max-width:300px;line-height:1.5;box-shadow:0 4px 16px rgba(0,0,0,.25)}
.node:hover>.tip{display:block}
.table-wrap{padding:16px 24px;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:11px;min-width:1200px}
th{background:#f0eee9;padding:8px 10px;text-align:left;font-weight:600;color:var(--text-s);border-bottom:2px solid var(--border);position:sticky;top:0;white-space:nowrap;font-size:10px}
td{padding:6px 10px;border-bottom:1px solid #f0eee9;vertical-align:top}
tr:hover td{background:#faf8f5}
.del-btn{font-size:10px;color:#c0392b;cursor:pointer;border:none;background:none;padding:2px 6px;border-radius:4px;font-family:inherit}
.del-btn:hover{background:#fef2f2}
.clickable-person{cursor:pointer;border-bottom:1px dashed var(--border);transition:all .15s}
.clickable-person:hover{color:#2d6a4f;border-color:#2d6a4f}
.row-actions{display:flex;gap:4px;white-space:nowrap}
.person-view{padding:20px 24px;max-width:2000px;margin:0 auto}
.person-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.person-card{background:#fff;border-radius:12px;border:1px solid var(--border);overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.person-card-header{padding:14px 16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #f0eee9}
.person-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#2d6a4f,#52b788);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;flex-shrink:0}
.person-name{font-size:13px;font-weight:700}
.person-meta{font-size:10px;color:var(--text-m);margin-top:1px}
.person-task-list{padding:8px 0;max-height:400px;overflow-y:auto}
.person-task-list::-webkit-scrollbar{width:4px}
.person-task-list::-webkit-scrollbar-thumb{background:#d0cdc8;border-radius:2px}
.person-task-item{padding:7px 16px;border-bottom:1px solid #f8f6f2;display:flex;align-items:flex-start;gap:8px;cursor:pointer;transition:background .1s}
.person-task-item:hover{background:#f8fdf9}
.person-task-item:last-child{border-bottom:none}
.pt-info{flex:1;min-width:0}
.pt-name{font-size:10px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pt-meta{font-size:9px;color:var(--text-m);margin-top:2px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pt-bu{display:inline-block;width:7px;height:7px;border-radius:2px;margin-right:2px;vertical-align:middle}
.pt-due{font-family:'IBM Plex Mono';font-size:9px}
.pt-due.overdue{color:#c0392b;font-weight:600}
.modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:500;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
.modal{background:#fff;border-radius:14px;padding:28px;width:95%;max-width:660px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h2{font-size:16px;font-weight:800;margin-bottom:20px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:10px;font-weight:600;color:var(--text-s)}
.form-group input,.form-group select{font-family:'Noto Sans JP';font-size:12px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:#faf8f5;color:var(--text)}
.form-group input:focus,.form-group select:focus{border-color:#2d6a4f;outline:none;background:#fff}
.combo-wrap select,.combo-wrap input{font-family:'Noto Sans JP';font-size:12px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:#faf8f5;color:var(--text);width:100%}
.combo-wrap select:focus,.combo-wrap input:focus{border-color:#2d6a4f;outline:none;background:#fff}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;flex-wrap:wrap}
.form-section{grid-column:1/-1;font-size:10px;font-weight:700;color:#2d6a4f;border-bottom:1px solid #e8f5ee;padding-bottom:4px;margin-top:4px}
.hidden{display:none}
.loading-msg{padding:60px;text-align:center;color:var(--text-m);font-size:13px}
.db-badge{font-size:9px;background:#e8f5ee;color:#2d6a4f;border:1px solid #a8d8be;border-radius:10px;padding:2px 8px;font-weight:600}
.trash-view,.completed-view{padding:20px 24px;max-width:2000px;margin:0 auto}
.trash-item,.completed-item{background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.trash-item .task-info,.completed-item .task-info{flex:1;min-width:200px}
.trash-item .task-title,.completed-item .task-title{font-size:12px;font-weight:600}
.trash-item .task-meta,.completed-item .task-meta{font-size:10px;color:var(--text-m);margin-top:2px}
.completed-badge{font-size:9px;background:#e8f4fd;color:#2980b9;border:1px solid #a8d4f0;border-radius:8px;padding:2px 8px;font-weight:600}
.comp-filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;padding:12px 16px;background:#f8f6f2;border-radius:10px;border:1px solid var(--border)}
.comp-filter-bar select{font-family:'Noto Sans JP';font-size:11px;padding:5px 10px;border:1px solid var(--border);border-radius:8px;background:#fff;font-weight:500;cursor:pointer}
.comp-filter-bar select:focus{border-color:#2d6a4f;outline:none}
.comp-filter-bar select.filtered{background:#2d6a4f;color:#fff;border-color:#2d6a4f;font-weight:700}
.comp-filter-bar .ctrl-label{font-size:10px;color:var(--text-m);font-weight:600}
.comp-stats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.comp-stats .stat-chip{font-size:10px;color:var(--text-s);background:#fff;border:1px solid var(--border);border-radius:12px;padding:3px 10px;font-weight:500}
.comp-stats .stat-chip b{color:var(--text);font-weight:700}
.comp-group{margin-bottom:20px}
.comp-group-header{font-size:12px;font-weight:700;color:#2d6a4f;padding:8px 0;border-bottom:2px solid #e8f5ee;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.comp-group-header .badge{font-size:10px;background:#e8f5ee;color:#2d6a4f;padding:2px 8px;border-radius:10px}
.copy-modal-bu{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}
.copy-modal-bu button{font-family:'Noto Sans JP';font-size:12px;padding:10px 20px;border:2px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;font-weight:600;transition:all .15s}
.copy-modal-bu button:hover{border-color:#2d6a4f;background:#e8f5ee}
</style>
</head>
<body>

<div class="header">
  <div class="header-inner">
    <div class="header-left">
      <div class="logo">WBS</div>
      <div>
        <div class="h-title">WBS タスク構造図 <span class="db-badge">MySQL</span></div>
        <div class="h-sub">本部タスク管理 ダッシュボード</div>
      </div>
    </div>
    <div class="header-right">
      <div class="legend">
        <div class="leg-item"><div class="leg-dot" style="background:var(--green)"></div>まんてん個別</div>
        <div class="leg-item"><div class="leg-dot" style="background:var(--purple)"></div>プラス</div>
        <div class="leg-item"><div class="leg-dot" style="background:var(--orange)"></div>atama+FC</div>
        <div class="leg-item"><div class="leg-dot" style="background:var(--yellow)"></div>全社</div>
      </div>
      <div class="sep"></div>
      <div class="tabs">
        <button class="tab active" onclick="setView('tree')">ツリー</button>
        <button class="tab" onclick="setView('table')">テーブル</button>
        <button class="tab" onclick="setView('person')">担当者</button>
        <button class="tab" onclick="setView('completed')" style="color:var(--complete)">✅ 完了</button>
        <button class="tab" onclick="setView('trash')" style="color:#999">🗑 ゴミ箱</button>
      </div>
    </div>
  </div>
</div>

<div class="controls" id="mainControls">
  <button class="btn btn-primary" onclick="openAdd()">＋ タスク追加</button>
  <button class="btn btn-ghost" onclick="loadFromServer()" style="font-size:11px">🔄 更新</button>
  <div class="sep"></div>
  <span class="ctrl-label">階層:</span>
  <button class="ctrl-btn" onclick="setD(2)" id="d2">カテゴリ</button>
  <button class="ctrl-btn" onclick="setD(3)" id="d3">ミッション</button>
  <button class="ctrl-btn" onclick="setD(4)" id="d4">サブミッション</button>
  <button class="ctrl-btn active" onclick="setD(5)" id="d5">タスク</button>
  <div class="sep"></div>
  <label class="kpi-toggle"><input type="checkbox" id="kpiChk" checked onchange="toggleKpi()">KPI表示</label>
  <div class="sep"></div>
  <span class="ctrl-label">事業部:</span>
  <button class="ctrl-btn active" onclick="setF('all')" id="fall">すべて</button>
  <button class="ctrl-btn" onclick="setF('まんてん個別')" id="fm">まんてん個別</button>
  <button class="ctrl-btn" onclick="setF('まんてん個別プラス')" id="fp">プラス</button>
  <button class="ctrl-btn" onclick="setF('atama+FC')" id="fa">atama+FC</button>
  <button class="ctrl-btn" onclick="setF('全社')" id="fz">全社</button>
  <div class="sep"></div>
  <span class="ctrl-label">カテゴリ:</span>
  <select id="catSelect" class="cat-select" onchange="setCatFilter(this.value)">
    <option value="all">すべて</option>
  </select>
  <div class="sep"></div>
  <span class="ctrl-label">優先度(タスク):</span>
  <button class="ctrl-btn active" onclick="setPri('all')" id="pall">すべて</button>
  <button class="ctrl-btn" onclick="setPri('高')" id="phigh" style="border-color:#e74c3c;color:#e74c3c">高</button>
  <button class="ctrl-btn" onclick="setPri('中')" id="pmid" style="border-color:#f39c12;color:#f39c12">中</button>
  <button class="ctrl-btn" onclick="setPri('低')" id="plow" style="border-color:#95a5a6;color:#95a5a6">低</button>
  <div class="sep"></div>
  <span class="ctrl-label">優先度(M):</span>
  <button class="ctrl-btn active" onclick="setMisPri('all')" id="mpall">すべて</button>
  <button class="ctrl-btn" onclick="setMisPri('高')" id="mphigh" style="border-color:#e74c3c;color:#e74c3c">高</button>
  <button class="ctrl-btn" onclick="setMisPri('中')" id="mpmi" style="border-color:#f39c12;color:#f39c12">中</button>
  <button class="ctrl-btn" onclick="setMisPri('低')" id="mplow" style="border-color:#95a5a6;color:#95a5a6">低</button>
  <div class="sep"></div>
  <button class="btn btn-export" onclick="exportCSV()">📥 CSV出力</button>
</div>

<div class="person-filter-bar" id="personBar">
  <span class="ctrl-label" style="font-size:12px">🎯 絞り込み:</span>
  <select id="whoSelect" class="who-select" onchange="setWhoFilter(this.value)">
    <option value="all">全員のタスクを表示</option>
  </select>
  <div class="scope-toggle" id="scopeToggle" style="display:none">
    <button class="scope-btn active" id="scopeTask" onclick="setScope('task')">📋 自分のタスクのみ</button>
    <button class="scope-btn" id="scopeMis" onclick="setScope('mission')">📂 自分のミッション全体</button>
  </div>
  <button class="btn btn-sm" id="whoClear" style="display:none;background:#2d6a4f;color:#fff;border:none" onclick="setWhoFilter('all')">✕ 解除</button>
  <div class="sep"></div>
  <span id="whoInfo" style="font-size:11px;color:var(--text-m)"></span>
</div>

<div class="stats" id="stats"></div>
<div id="treeView" class="canvas"><div class="loading-msg">⏳ データ読み込み中...</div></div>
<div id="tableView" class="table-wrap hidden"></div>
<div id="personView" class="person-view hidden"></div>
<div id="completedView" class="completed-view hidden"></div>
<div id="trashView" class="trash-view hidden"></div>
<div id="modalOverlay" class="modal-overlay hidden" onclick="if(event.target===this)closeModal()"></div>

<script>
let DATA=[], TRASH=[], COMPLETED=[];
let nextId=1;
const TODAY=new Date();
TODAY.setHours(0,0,0,0);
let maxD=5, filter='all', catFilter='all', priFilter='all', misPriFilter='all', showKpi=true, view='tree', whoFilter='all', whoScope='task';

const BUS=['まんてん個別','まんてん個別プラス','atama+FC','全社'];
const CATS=['1.サービス','2.マーケティング','3.業務の効率化','4.採用','5.財務','9.その他'];
const STS=['未着手','進行中'];
const PRIS=['高','中','低'];
const PRI_CLS={'高':'high','中':'mid','低':'low'};
const DK={'まんてん個別':{c:'m',bg:'#00b894'},'まんてん個別プラス':{c:'p',bg:'#6c5ce7'},'atama+FC':{c:'a',bg:'#e17055'},'全社':{c:'z',bg:'#f0c040'}};

// ======== API ========
async function api(action, data={}){
    const res=await fetch('?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
    const json=await res.json(); if(!res.ok) throw new Error(json.error||'サーバーエラー'); return json;
}

function parseRows(rows){
    rows.forEach(r=>{r.id=parseInt(r.id);r.ai=r.ai===true||r.ai==1;if(!r.misPriority)r.misPriority='中';if(!r.priority)r.priority='中'});
    return rows;
}

async function loadFromServer(){
    try{
        const res=await fetch('?action=get&_t='+Date.now());
        if(!res.ok) throw new Error('HTTP '+res.status);
        DATA=parseRows(await res.json());
        nextId=DATA.length?Math.max(...DATA.map(d=>d.id))+1:1;
        syncWhoSelect(); syncCatSelect(); renderAll();
    }catch(e){
        document.getElementById('treeView').innerHTML='<div class="loading-msg" style="color:#e74c3c">❌ 読み込み失敗: '+e.message+'</div>';
    }
}

// ======== PERSONS ========
function splitPersons(s){ return (s||'').split(/[・\n,、]/).map(p=>p.trim()).filter(Boolean) }
function getAllPersons(){
    const set=new Set();
    DATA.forEach(r=>{splitPersons(r.who).forEach(p=>set.add(p));splitPersons(r.owner).forEach(p=>set.add(p))});
    return [...set].sort();
}

// 🎯 自分のタスク / ミッション（whoフィールド + scope切替）
function getTaskAssignees(){
    const set=new Set();
    DATA.forEach(r=>{splitPersons(r.who).forEach(p=>set.add(p))});
    return [...set].sort();
}
function setWhoFilter(p){
    whoFilter=p;
    whoScope='task'; // reset to task on new selection
    syncWhoSelect();
    renderAll();
}
function setScope(s){
    whoScope=s;
    document.getElementById('scopeTask').classList.toggle('active',s==='task');
    document.getElementById('scopeMis').classList.toggle('active',s==='mission');
    syncWhoSelect();
    renderAll();
}
function getMyMissions(person){
    // person が owner または who のタスクが属するミッションキーをすべて取得
    const misKeys=new Set();
    DATA.forEach(r=>{
        if(splitPersons(r.owner).includes(person)||splitPersons(r.who).includes(person)){
            misKeys.add(r.bu+'||'+r.cat+'||'+(r.mis||''));
        }
    });
    return misKeys;
}
function syncWhoSelect(){
    const sel=document.getElementById('whoSelect');
    if(!sel) return;
    const persons=getTaskAssignees();
    sel.innerHTML='<option value="all">全員のタスクを表示（'+persons.length+'名）</option>'
        +persons.map(p=>{
            const cnt=DATA.filter(r=>r.st!=='完了'&&splitPersons(r.who).includes(p)).length;
            return '<option value="'+esc(p)+'" '+(whoFilter===p?'selected':'')+'>'+esc(p)+'（'+cnt+'件）</option>';
        }).join('');
    sel.classList.toggle('filtered', whoFilter!=='all');
    const clr=document.getElementById('whoClear');
    if(clr) clr.style.display=whoFilter!=='all'?'inline-flex':'none';
    // scope toggle 表示/非表示
    const st=document.getElementById('scopeToggle');
    if(st) st.style.display=whoFilter!=='all'?'flex':'none';
    // 件数情報
    const info=document.getElementById('whoInfo');
    if(info){
        if(whoFilter!=='all'){
            const filtered=getFiltered();
            const over=filtered.filter(r=>ov(r.due)).length;
            const prog=filtered.filter(r=>r.st==='進行中').length;
            const todo=filtered.filter(r=>r.st==='未着手').length;
            if(whoScope==='task'){
                info.innerHTML='<b style="color:#2d6a4f">'+esc(whoFilter)+'</b> さんのタスク: <b>'+filtered.length+'</b>件（進行中 '+prog+' / 未着手 '+todo+(over?' / <span style="color:#e74c3c">期限超過 '+over+'</span>':'')+'）';
            } else {
                const misKeys=getMyMissions(whoFilter);
                info.innerHTML='<b style="color:#2d6a4f">'+esc(whoFilter)+'</b> さんのミッション: <b>'+misKeys.size+'</b>件 → タスク合計 <b>'+filtered.length+'</b>件（進行中 '+prog+' / 未着手 '+todo+(over?' / <span style="color:#e74c3c">期限超過 '+over+'</span>':'')+'）';
            }
        } else { info.innerHTML=''; }
    }
}

// ======== コンボ選択 + ミッション連動 ========
function getUniqueOwners(){ const s=new Set(); DATA.forEach(r=>{if(r.owner&&r.owner.trim())s.add(r.owner.trim())}); return [...s].sort() }
function getUniqueWhos(){ const s=new Set(); DATA.forEach(r=>{splitPersons(r.who).forEach(p=>s.add(p))}); return [...s].sort() }
function getUniqueMissions(){ const s=new Set(); DATA.forEach(r=>{if(r.mis&&r.mis.trim())s.add(r.mis.trim())}); return [...s].sort() }
function getUniqueSubs(){ const s=new Set(); DATA.forEach(r=>{if(r.sub&&r.sub.trim())s.add(r.sub.trim())}); return [...s].sort() }

// ミッション→数値目標・期限の連動データ
function getMissionData(misName){
    const r=DATA.find(d=>d.mis===misName);
    return r?{kpiM:r.kpiM||'',dlM:r.dlM||'',owner:r.owner||'',misPriority:r.misPriority||'中'}:null;
}
function getSubData(subName){
    const r=DATA.find(d=>d.sub===subName);
    return r?{kpiS:r.kpiS||'',dlS:r.dlS||''}:null;
}

function comboHTML(id, options, current, callbackName){
    const cur=(current||'').trim();
    const hasCur=cur&&options.includes(cur);
    const showInput=cur&&!hasCur;
    let h='<div class="combo-wrap" id="wrap-'+id+'">'
        +'<select id="sel-'+id+'" onchange="comboChange(\''+id+'\''+(callbackName?',\''+callbackName+'\'':'')+')" style="'+(showInput?'display:none':'')+'">'
        +'<option value="">-- 選択 --</option>';
    options.forEach(v=>{h+='<option value="'+esc(v)+'" '+(v===cur?'selected':'')+'>'+esc(v)+'</option>'});
    h+='<option value="__new__">✏️ 新しく入力...</option></select>'
        +'<div style="'+(showInput?'display:flex':'display:none')+';gap:4px;align-items:center">'
        +'<input id="inp-'+id+'" value="'+esc(showInput?cur:'')+'" placeholder="入力してください" style="flex:1">'
        +'<button type="button" class="btn btn-ghost btn-sm" onclick="comboBack(\''+id+'\')" style="white-space:nowrap;font-size:9px">一覧に戻す</button>'
        +'</div></div>';
    return h;
}
function comboChange(id, callback){
    const sel=document.getElementById('sel-'+id);
    if(sel.value==='__new__'){ sel.style.display='none'; sel.nextElementSibling.style.display='flex'; document.getElementById('inp-'+id).value=''; document.getElementById('inp-'+id).focus(); return; }
    // コールバック実行（ミッション・サブミッション連動）
    if(callback && window[callback]) window[callback]();
}
function comboBack(id){ const sel=document.getElementById('sel-'+id); sel.value=''; sel.style.display=''; sel.nextElementSibling.style.display='none'; }
function comboVal(id){ const sel=document.getElementById('sel-'+id); if(sel&&sel.style.display!=='none'&&sel.value!=='__new__') return sel.value; const inp=document.getElementById('inp-'+id); return inp?inp.value.trim():''; }

// ミッション選択時の連動
function onMisChangeF(){ _applyMisData('f') }
function onMisChangeE(){ _applyMisData('e') }
function _applyMisData(prefix){
    const v=comboVal(prefix+'-mis');
    if(!v) return;
    const d=getMissionData(v);
    if(d){
        document.getElementById(prefix+'-kpiM').value=d.kpiM;
        document.getElementById(prefix+'-dlM').value=d.dlM;
    }
}
function onSubChangeF(){ _applySubData('f') }
function onSubChangeE(){ _applySubData('e') }
function _applySubData(prefix){
    const v=comboVal(prefix+'-sub');
    if(!v) return;
    const d=getSubData(v);
    if(d){
        document.getElementById(prefix+'-kpiS').value=d.kpiS;
        document.getElementById(prefix+'-dlS').value=d.dlS;
    }
}

function getMisKey(r){ return r.bu+'||'+r.cat+'||'+(r.mis||'') }
function ov(d){ return d&&new Date(d)<TODAY }
function esc(s){ return s?s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'):'' }
function dotCls(st,due){ return st==='完了'?'dot-c':ov(due)?'dot-o':st==='進行中'?'dot-g':'dot-n' }

function getFiltered(){
    let d=DATA.filter(r=>r.st!=='完了');
    if(filter!=='all') d=d.filter(r=>r.bu===filter);
    if(catFilter!=='all') d=d.filter(r=>r.cat===catFilter);
    if(priFilter!=='all') d=d.filter(r=>(r.priority||'中')===priFilter);
    if(misPriFilter!=='all') d=d.filter(r=>(r.misPriority||'中')===misPriFilter);
    if(whoFilter!=='all'){
        if(whoScope==='task'){
            d=d.filter(r=>splitPersons(r.who).includes(whoFilter));
        } else {
            const misKeys=getMyMissions(whoFilter);
            d=d.filter(r=>misKeys.has(r.bu+'||'+r.cat+'||'+(r.mis||'')));
        }
    }
    return d;
}

// カテゴリフィルター
function setCatFilter(v){
    catFilter=v;
    syncCatSelect();
    renderAll();
}
function syncCatSelect(){
    const sel=document.getElementById('catSelect');
    if(!sel) return;
    sel.innerHTML='<option value="all">すべて</option>'+CATS.map(c=>'<option value="'+esc(c)+'" '+(catFilter===c?'selected':'')+'>'+esc(c.replace(/^\d+\./,''))+'</option>').join('');
    sel.classList.toggle('filtered',catFilter!=='all');
}

// ======== STATS ========
function updateStats(){
    const items=getFiltered();
    const t=items.length,p=items.filter(r=>r.st==='進行中').length,n=items.filter(r=>r.st==='未着手').length;
    const od=items.filter(r=>ov(r.due)).length;
    const ai=items.filter(r=>r.ai).length;
    const comp=DATA.filter(r=>r.st==='完了').length;
    let extra='';
    if(catFilter!=='all') extra+='<div class="stat-chip" style="border-color:#6c5ce7;color:#6c5ce7;background:#f0ecfd"><b>📁 '+esc(catFilter.replace(/^\d+\./,''))+'</b></div>';
    if(whoFilter!=='all'){
        const label=whoScope==='task'?'🎯 '+esc(whoFilter)+' のタスク':'📂 '+esc(whoFilter)+' のミッション';
        extra+='<div class="stat-chip" style="border-color:#2d6a4f;color:#2d6a4f;background:#e8f5ee"><b>'+label+'</b></div>';
    }
    document.getElementById('stats').innerHTML=extra
        +'<div class="stat-chip">全 <b>'+t+'</b>件</div>'
        +'<div class="stat-chip">進行中 <b>'+p+'</b></div>'
        +'<div class="stat-chip">未着手 <b>'+n+'</b></div>'
        +(od?'<div class="stat-chip" style="border-color:#e74c3c;color:#c0392b">期限超過 <b>'+od+'</b></div>':'')
        +'<div class="stat-chip" style="border-color:#3498db;color:#2980b9">完了 <b>'+comp+'</b></div>'
        +'<div class="stat-chip">AI活用 <b>'+ai+'</b></div>';
}

// ======== PERSON VIEW ========
function renderPersonView(){
    const items=getFiltered();
    // ミッション全体モードなら、フィルタ結果内の全担当者を表示
    let targets;
    if(whoFilter==='all'){
        targets=getTaskAssignees();
    } else if(whoScope==='mission'){
        const s=new Set(); items.forEach(r=>splitPersons(r.who).forEach(p=>s.add(p))); targets=[...s].sort();
    } else {
        targets=[whoFilter];
    }
    let h='<div class="person-cards">';
    targets.forEach(person=>{
        const my=items.filter(r=>splitPersons(r.who).includes(person));
        if(!my.length) return;
        const prog=my.filter(r=>r.st==='進行中').length,todo=my.filter(r=>r.st==='未着手').length,over=my.filter(r=>ov(r.due)).length;
        h+='<div class="person-card"><div class="person-card-header"><div class="person-avatar">'+person.charAt(0)+'</div>'
          +'<div style="flex:1"><div class="person-name">'+esc(person)+'</div>'
          +'<div class="person-meta">全'+my.length+'件 ・ 進行中'+prog+' ・ 未着手'+todo+(over?' ・ <span style="color:#c0392b">超過'+over+'</span>':'')+'</div></div></div><div class="person-task-list">';
        [...my].sort((a,b)=>({'高':0,'中':1,'低':2}[a.priority||'中']||1)-({'高':0,'中':1,'低':2}[b.priority||'中']||1)).forEach(t=>{
            const dk=DK[t.bu]||{bg:'#999'};const od=ov(t.due);
            h+='<div class="person-task-item" ondblclick="openEdit('+t.id+')"><span class="task-dot '+dotCls(t.st,t.due)+'" style="margin-top:4px"></span>'
              +'<div class="pt-info"><div class="pt-name">'+(t.priority==='高'?'<span class="pri-badge pri-high">高</span> ':'')+(t.ai?'<span class="ai-badge">AI</span> ':'')+esc(t.task)+'</div>'
              +'<div class="pt-meta"><span><span class="pt-bu" style="background:'+dk.bg+'"></span>'+esc(t.bu)+'</span><span style="opacity:.6">'+esc(t.mis)+'</span>'
              +(t.due?'<span class="pt-due'+(od?' overdue':'')+'">'+t.due.slice(5)+(od?' ⚠':'')+'</span>':'')+'</div></div></div>';
        });
        h+='</div></div>';
    });
    document.getElementById('personView').innerHTML=h+'</div>';
}

// ======== TREE VIEW ========
function arrow(color){
    const id='a'+color.replace('#','');
    return '<svg width="32" height="20" style="overflow:visible"><defs><marker id="'+id+'" markerWidth="5" markerHeight="5" refX="4" refY="2.5" orient="auto"><path d="M0,0 L5,2.5 L0,5Z" fill="'+color+'"/></marker></defs><line x1="0" y1="10" x2="32" y2="10" stroke="'+color+'" stroke-width="1.3" marker-end="url(#'+id+')"/></svg>';
}
function misPriBadgeHTML(pri){ if(!pri||pri==='中') return ''; return '<span class="mis-pri-badge pri-'+(PRI_CLS[pri]||'mid')+'">M:'+pri+'</span>'; }

function buildTree(items){
    const t={};
    items.forEach(r=>{
        const bu=r.bu,cat=r.cat,mis=r.mis||'（未設定）',sub=r.sub||'';
        if(!t[bu])t[bu]={};if(!t[bu][cat])t[bu][cat]={};
        if(!t[bu][cat][mis])t[bu][cat][mis]={_m:{kpiM:r.kpiM,dlM:r.dlM,owner:r.owner,misPriority:r.misPriority||'中'},subs:{}};
        const sk=sub||'_d';
        if(!t[bu][cat][mis].subs[sk])t[bu][cat][mis].subs[sk]={_m:{kpiS:r.kpiS,dlS:r.dlS},tasks:[]};
        t[bu][cat][mis].subs[sk].tasks.push(r);
    });return t;
}

function renderTree(){
    const items=getFiltered();const tree=buildTree(items);const total=items.length;
    let h='<div class="wbs-tree'+(showKpi?'':' kpi-hidden')+'"><div class="node node-root">プロジェクト<br>全体<br><span class="node-count">'+total+'件</span></div><div class="wbs-col" style="gap:10px">';
    BUS.forEach(bu=>{
        if(!tree[bu])return;const dk=DK[bu];const buI=items.filter(r=>r.bu===bu);const cats=Object.keys(tree[bu]).sort();
        h+='<div class="wbs-row"><div class="wbs-conn">'+arrow(dk.bg)+'</div><div class="node node-dept bg-'+dk.c+'">'+esc(bu)+'<br><span class="badge">'+buI.length+'</span></div>';
        if(maxD>=2){h+='<div class="wbs-col">';
            cats.forEach(cat=>{
                const mises=Object.keys(tree[bu][cat]).sort((a,b)=>{const pa={'高':0,'中':1,'低':2};return (pa[tree[bu][cat][a]._m.misPriority||'中']||1)-(pa[tree[bu][cat][b]._m.misPriority||'中']||1)});
                const cc=mises.reduce((s,m)=>s+Object.values(tree[bu][cat][m].subs).reduce((s2,sb)=>s2+sb.tasks.length,0),0);
                h+='<div class="wbs-row"><div class="wbs-conn">'+arrow(dk.bg)+'</div><div class="node node-cat cat-'+dk.c+'">'+esc(cat)+'<span class="badge">'+cc+'</span></div>';
                if(maxD>=3){h+='<div class="wbs-col">';
                    mises.forEach(mis=>{
                        const md=tree[bu][cat][mis];const sks=Object.keys(md.subs);const mc=sks.reduce((s,k)=>s+md.subs[k].tasks.length,0);const me=md._m;const mp=me.misPriority||'中';const priCls=mp==='高'?'mis-pri-high':mp==='低'?'mis-pri-low':'';
                        h+='<div class="wbs-row"><div class="wbs-conn">'+arrow(dk.bg)+'</div><div class="node node-mis mis-'+dk.c+' '+priCls+'">'
                          +'<div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap">'+misPriBadgeHTML(mp)+'<span>'+esc(mis)+'<span class="badge">'+mc+'</span></span></div>';
                        if(me.kpiM)h+='<div class="kpi"><span class="kpi-label">目標:</span>'+esc(me.kpiM)+'</div>';
                        if(me.owner)h+='<div class="kpi"><span class="kpi-label">責任者:</span>'+esc(me.owner)+'</div>';
                        h+='<div class="tip"><b>'+esc(mis)+'</b>\nM優先度: '+mp+'\n';
                        if(me.kpiM)h+='数値目標: '+esc(me.kpiM)+'\n';if(me.owner)h+='責任者: '+esc(me.owner)+'\n';if(me.dlM)h+='期限: '+me.dlM;h+='</div></div>';
                        if(maxD>=4){h+='<div class="wbs-col">';
                            sks.forEach(sk=>{
                                const sd=md.subs[sk];const sm=sd._m;const tasks=sd.tasks;
                                if(sk!=='_d'){
                                    h+='<div class="wbs-row"><div class="wbs-conn">'+arrow(dk.bg)+'</div><div class="node node-sub sub-'+dk.c+'"><div>▸ '+esc(sk)+'<span class="badge">'+tasks.length+'</span></div>';
                                    if(sm.kpiS)h+='<div class="kpi"><span class="kpi-label">目標:</span>'+esc(sm.kpiS)+'</div>';h+='</div>';
                                    if(maxD>=5){h+='<div class="wbs-col">';tasks.forEach(t=>h+=taskNode(t,dk));h+='</div>'}h+='</div>';
                                }else{if(maxD>=5)tasks.forEach(t=>h+=taskNode(t,dk))}
                            });h+='</div>'}h+='</div>';
                    });h+='</div>'}h+='</div>';
            });h+='</div>'}h+='</div>';
    });
    document.getElementById('treeView').innerHTML=h+'</div></div>';
}

function taskNode(t,dk){
    const od=ov(t.due);
    let h='<div class="wbs-row"><div class="wbs-conn">'+arrow(dk.bg)+'</div>'
      +'<div class="node node-task tsk-'+dk.c+' '+(od?'overdue-border':'')+'" ondblclick="openEdit('+t.id+')">'
      +'<span class="task-dot '+dotCls(t.st,t.due)+'"></span>'
      +'<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">';
    if(t.priority&&t.priority!=='中')h+='<span class="pri-badge pri-'+(PRI_CLS[t.priority]||'mid')+'">'+t.priority+'</span> ';
    if(t.ai)h+='<span class="ai-badge">AI</span> ';
    h+=esc(t.task)+'</span><span class="who-label">'+esc(splitPersons(t.who)[0]||'')+'</span>';
    if(t.due)h+='<span class="due-label" '+(od?'style="color:#c0392b;opacity:.8"':'')+'>'+t.due.slice(5)+'</span>';
    h+='<div class="tip"><b>'+esc(t.task)+'</b>\n担当: '+esc(t.who)+'\n期限: '+(t.due||'未設定')+'\n進捗: '+t.st+(od?'\n⚠ 期限超過':'')+'\n\n💡 ダブルクリックで編集</div></div></div>';
    return h;
}

// ======== TABLE VIEW ========
function renderTable(){
    const items=getFiltered();
    let h='<table><thead><tr><th></th><th>事業部</th><th>カテゴリ</th><th>M優先度</th><th>ミッション</th><th>サブミッション</th><th>タスク</th><th>T優先度</th><th>担当者</th><th>期限</th><th>進捗</th><th>AI</th><th>操作</th></tr></thead><tbody>';
    items.forEach(r=>{
        const od=ov(r.due);const dc=DK[r.bu]||{bg:'#999'};const mp=r.misPriority||'中';
        h+='<tr><td><span class="task-dot '+dotCls(r.st,r.due)+'" style="display:inline-block"></span></td>'
          +'<td><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:'+dc.bg+';margin-right:4px"></span>'+esc(r.bu)+'</td>'
          +'<td>'+esc(r.cat)+'</td>'
          +'<td><span class="pri-badge pri-'+(PRI_CLS[mp]||'mid')+'">'+mp+'</span></td>'
          +'<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(r.mis)+'</td>'
          +'<td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(r.sub)+'</td>'
          +'<td style="font-weight:500;'+(od?'color:#c0392b':'')+'">'+( r.ai?'<span class="ai-badge">AI</span> ':'')+esc(r.task)+'</td>'
          +'<td><span class="pri-badge pri-'+(PRI_CLS[r.priority]||'mid')+'">'+(r.priority||'中')+'</span></td>'
          +'<td>'+splitPersons(r.who).map(p=>'<span class="clickable-person" onclick="setWhoFilter(\''+esc(p)+'\')">'+esc(p)+'</span>').join('・')+'</td>'
          +'<td style="font-family:\'IBM Plex Mono\';font-size:10px;'+(od?'color:#c0392b;font-weight:600':'')+'">'+( r.due||'')+'</td>'
          +'<td>'+r.st+'</td>'
          +'<td><input type="checkbox" '+(r.ai?'checked':'')+' onchange="toggleAI('+r.id+',this.checked)" style="width:auto;accent-color:#6366f1"></td>'
          +'<td class="row-actions"><button class="btn btn-ghost btn-sm" onclick="openEdit('+r.id+')">✏️</button><button class="btn btn-complete btn-sm" onclick="completeTask('+r.id+')">✅</button></td></tr>';
    });
    document.getElementById('tableView').innerHTML=h+'</tbody></table>';
}

// ======== COMPLETED VIEW ========
let compBuFilter='all', compCatFilter='all', compWhoFilter='all', compGroupBy='cat';
async function loadCompleted(){
    try{
        const res=await fetch('?action=get_completed&_t='+Date.now());
        COMPLETED=parseRows(await res.json());
        renderCompleted();
    }catch(e){ document.getElementById('completedView').innerHTML='<div class="loading-msg" style="color:#e74c3c">読み込み失敗</div>'; }
}
function setCompFilter(type,v){
    if(type==='bu') compBuFilter=v;
    else if(type==='cat') compCatFilter=v;
    else if(type==='who') compWhoFilter=v;
    else if(type==='group') compGroupBy=v;
    renderCompleted();
}
function getCompFiltered(){
    let d=COMPLETED;
    if(compBuFilter!=='all') d=d.filter(r=>r.bu===compBuFilter);
    if(compCatFilter!=='all') d=d.filter(r=>r.cat===compCatFilter);
    if(compWhoFilter!=='all') d=d.filter(r=>splitPersons(r.who).includes(compWhoFilter));
    return d;
}
function renderCompleted(){
    if(!COMPLETED.length){ document.getElementById('completedView').innerHTML='<div class="loading-msg">完了したタスクはありません</div>'; return; }
    // 利用可能なフィルター値を収集
    const allBu=[...new Set(COMPLETED.map(r=>r.bu))].sort();
    const allCat=[...new Set(COMPLETED.map(r=>r.cat))].sort();
    const allWho=new Set(); COMPLETED.forEach(r=>splitPersons(r.who).forEach(p=>allWho.add(p)));
    const whoArr=[...allWho].sort();

    let h='<h3 style="margin-bottom:12px;font-size:14px">✅ 完了タスク振り返り</h3>';
    // フィルターバー
    h+='<div class="comp-filter-bar">';
    h+='<span class="ctrl-label">事業部:</span><select onchange="setCompFilter(\'bu\',this.value)" class="'+(compBuFilter!=='all'?'filtered':'')+'"><option value="all">すべて</option>'+allBu.map(b=>'<option value="'+esc(b)+'" '+(compBuFilter===b?'selected':'')+'>'+esc(b)+'</option>').join('')+'</select>';
    h+='<span class="ctrl-label">カテゴリ:</span><select onchange="setCompFilter(\'cat\',this.value)" class="'+(compCatFilter!=='all'?'filtered':'')+'"><option value="all">すべて</option>'+allCat.map(c=>'<option value="'+esc(c)+'" '+(compCatFilter===c?'selected':'')+'>'+esc(c.replace(/^\d+\./,''))+'</option>').join('')+'</select>';
    h+='<span class="ctrl-label">担当者:</span><select onchange="setCompFilter(\'who\',this.value)" class="'+(compWhoFilter!=='all'?'filtered':'')+'"><option value="all">すべて</option>'+whoArr.map(p=>'<option value="'+esc(p)+'" '+(compWhoFilter===p?'selected':'')+'>'+esc(p)+'</option>').join('')+'</select>';
    h+='<div class="sep"></div>';
    h+='<span class="ctrl-label">グループ:</span><select onchange="setCompFilter(\'group\',this.value)">';
    h+='<option value="cat" '+(compGroupBy==='cat'?'selected':'')+'>カテゴリ別</option>';
    h+='<option value="bu" '+(compGroupBy==='bu'?'selected':'')+'>事業部別</option>';
    h+='<option value="who" '+(compGroupBy==='who'?'selected':'')+'>担当者別</option>';
    h+='<option value="none" '+(compGroupBy==='none'?'selected':'')+'>グループなし</option>';
    h+='</select></div>';

    const filtered=getCompFiltered();

    // 統計
    const aiCnt=filtered.filter(r=>r.ai).length;
    h+='<div class="comp-stats">';
    h+='<div class="stat-chip">完了 <b>'+filtered.length+'</b>件 / 全'+COMPLETED.length+'件</div>';
    if(aiCnt) h+='<div class="stat-chip">AI活用 <b>'+aiCnt+'</b></div>';
    h+='</div>';

    if(!filtered.length){ h+='<div class="loading-msg">条件に一致する完了タスクはありません</div>'; }
    else if(compGroupBy==='none'){
        filtered.forEach(r=>h+=compItemHTML(r));
    } else {
        // グループ化
        const groups={};
        filtered.forEach(r=>{
            let key;
            if(compGroupBy==='cat') key=r.cat;
            else if(compGroupBy==='bu') key=r.bu;
            else { splitPersons(r.who).forEach(p=>{ if(!groups[p]) groups[p]=[]; groups[p].push(r); }); return; }
            if(!groups[key]) groups[key]=[];
            groups[key].push(r);
        });
        Object.keys(groups).sort().forEach(key=>{
            const items=groups[key];
            h+='<div class="comp-group"><div class="comp-group-header">';
            if(compGroupBy==='bu'){ const dk=DK[key]||{bg:'#999'}; h+='<span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:'+dk.bg+'"></span>'; }
            h+=esc(key)+' <span class="badge">'+items.length+'件</span></div>';
            items.forEach(r=>h+=compItemHTML(r));
            h+='</div>';
        });
    }
    document.getElementById('completedView').innerHTML=h;
}
function compItemHTML(r){
    const dk=DK[r.bu]||{bg:'#999'};
    return '<div class="completed-item"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:'+dk.bg+'"></span>'
      +'<div class="task-info"><div class="task-title">'+esc(r.task)+'</div>'
      +'<div class="task-meta">'+esc(r.bu)+' / '+esc(r.cat?.replace(/^\d+\./,'')||'')+' / '+esc(r.mis)+' ・ 担当: '+esc(r.who)+'</div></div>'
      +'<span class="completed-badge">完了: '+(r.completed_at?r.completed_at.replace('T',' ').slice(0,16):'不明')+'</span>'
      +'<button class="btn btn-ghost btn-sm" onclick="uncompleteTask('+r.id+')">↩ 戻す</button></div>';
}

// ======== TRASH VIEW ========
async function loadTrash(){
    try{
        const res=await fetch('?action=get_trash&_t='+Date.now());
        TRASH=parseRows(await res.json());
        renderTrash();
    }catch(e){ document.getElementById('trashView').innerHTML='<div class="loading-msg" style="color:#e74c3c">読み込み失敗</div>'; }
}
function renderTrash(){
    if(!TRASH.length){ document.getElementById('trashView').innerHTML='<div class="loading-msg">🗑 ゴミ箱は空です</div>'; return; }
    let h='<h3 style="margin-bottom:16px;font-size:14px">🗑 ゴミ箱（'+TRASH.length+'件）</h3>';
    TRASH.forEach(r=>{
        const dk=DK[r.bu]||{bg:'#999'};
        h+='<div class="trash-item"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:'+dk.bg+'"></span>'
          +'<div class="task-info"><div class="task-title">'+esc(r.task)+'</div>'
          +'<div class="task-meta">'+esc(r.bu)+' / '+esc(r.mis)+' ・ 担当: '+esc(r.who)+'</div></div>'
          +'<span style="font-size:9px;color:var(--text-m)">削除: '+(r.deleted_at?r.deleted_at.replace('T',' ').slice(0,16):'')+'</span>'
          +'<button class="btn btn-restore btn-sm" onclick="restoreTask('+r.id+')">↩ 復元</button>'
          +'<button class="btn btn-danger btn-sm" onclick="permanentDelete('+r.id+')">完全削除</button></div>';
    });
    document.getElementById('trashView').innerHTML=h;
}

// ======== CRUD ========
function misPriOptions(cur){ return PRIS.map(p=>'<option '+(p===(cur||'中')?'selected':'')+'>'+p+'</option>').join(''); }

function openAdd(){
    const m=document.getElementById('modalOverlay');
    m.innerHTML='<div class="modal"><h2>＋ 新規タスク追加</h2><div class="form-grid">'
        +'<div class="form-section">📌 ミッション情報</div>'
        +'<div class="form-group"><label>事業部 *</label><select id="f-bu">'+BUS.map(b=>'<option>'+b+'</option>').join('')+'</select></div>'
        +'<div class="form-group"><label>カテゴリ *</label><select id="f-cat">'+CATS.map(c=>'<option>'+c+'</option>').join('')+'</select></div>'
        +'<div class="form-group"><label>責任者</label>'+comboHTML('f-owner',getUniqueOwners(),'')+'</div>'
        +'<div class="form-group"><label>ミッション優先度</label><select id="f-mispri">'+misPriOptions('中')+'</select></div>'
        +'<div class="form-group full"><label>ミッション * <span style="font-weight:400;color:var(--text-m)">（選択で数値目標・期限を自動入力）</span></label>'+comboHTML('f-mis',getUniqueMissions(),'','onMisChangeF')+'</div>'
        +'<div class="form-group"><label>数値目標(M)</label><input id="f-kpiM"></div>'
        +'<div class="form-group"><label>期限(M)</label><input id="f-dlM" type="date"></div>'
        +'<div class="form-group full"><label>サブミッション <span style="font-weight:400;color:var(--text-m)">（選択で数値目標・期限を自動入力）</span></label>'+comboHTML('f-sub',getUniqueSubs(),'','onSubChangeF')+'</div>'
        +'<div class="form-group"><label>数値目標(SM)</label><input id="f-kpiS"></div>'
        +'<div class="form-group"><label>期限(SM)</label><input id="f-dlS" type="date"></div>'
        +'<div class="form-section">✅ タスク情報</div>'
        +'<div class="form-group full"><label>タスク名 *</label><input id="f-task" placeholder="具体的なタスク名"></div>'
        +'<div class="form-group"><label>担当者</label>'+comboHTML('f-who',getUniqueWhos(),'')+'</div>'
        +'<div class="form-group"><label>タスク期限</label><input id="f-due" type="date"></div>'
        +'<div class="form-group"><label>進捗</label><select id="f-st">'+STS.map(s=>'<option>'+s+'</option>').join('')+'</select></div>'
        +'<div class="form-group"><label>タスク優先度</label><select id="f-pri">'+PRIS.map(p=>'<option '+(p==='中'?'selected':'')+'>'+p+'</option>').join('')+'</select></div>'
        +'<div class="form-group"><label>AI活用</label><select id="f-ai"><option value="0">なし</option><option value="1">あり</option></select></div>'
        +'</div><div class="modal-actions"><button class="btn btn-ghost" onclick="closeModal()">キャンセル</button>'
        +'<button class="btn btn-primary" id="addBtn" onclick="saveAdd()">追加する</button></div></div>';
    m.classList.remove('hidden');
}

async function saveAdd(){
    const task=document.getElementById('f-task').value.trim();
    const mis=comboVal('f-mis');
    if(!task||!mis){alert('タスク名とミッションは必須です');return;}
    document.getElementById('addBtn').textContent='保存中...';document.getElementById('addBtn').disabled=true;
    const obj={bu:document.getElementById('f-bu').value,cat:document.getElementById('f-cat').value,
        ai:document.getElementById('f-ai').value==='1',owner:comboVal('f-owner'),mis,misPriority:document.getElementById('f-mispri').value,
        kpiM:document.getElementById('f-kpiM').value.trim(),dlM:document.getElementById('f-dlM').value,
        sub:comboVal('f-sub'),kpiS:document.getElementById('f-kpiS').value.trim(),dlS:document.getElementById('f-dlS').value,
        task,who:comboVal('f-who'),due:document.getElementById('f-due').value,st:document.getElementById('f-st').value,priority:document.getElementById('f-pri').value};
    try{ await api('add',obj); closeModal(); await loadFromServer(); }
    catch(e){ alert('追加失敗: '+e.message); document.getElementById('addBtn').textContent='追加する'; document.getElementById('addBtn').disabled=false; }
}

function openEdit(id){
    const r=DATA.find(d=>d.id===id); if(!r) return;
    const m=document.getElementById('modalOverlay');
    m.innerHTML='<div class="modal"><h2>✏️ タスク編集 <span style="font-size:12px;font-weight:400;color:var(--text-m)">#'+id+'</span></h2><div class="form-grid">'
        +'<div class="form-section">📌 ミッション情報</div>'
        +'<div class="form-group"><label>事業部</label><select id="e-bu">'+BUS.map(b=>'<option '+(b===r.bu?'selected':'')+'>'+b+'</option>').join('')+'</select></div>'
        +'<div class="form-group"><label>カテゴリ</label><select id="e-cat">'+CATS.map(c=>'<option '+(c===r.cat?'selected':'')+'>'+c+'</option>').join('')+'</select></div>'
        +'<div class="form-group"><label>責任者</label>'+comboHTML('e-owner',getUniqueOwners(),r.owner||'')+'</div>'
        +'<div class="form-group"><label>ミッション優先度 <span style="color:#e74c3c;font-size:9px">※同ミッション全タスクに反映</span></label><select id="e-mispri">'+misPriOptions(r.misPriority)+'</select></div>'
        +'<div class="form-group full"><label>ミッション <span style="font-weight:400;color:var(--text-m)">（選択で数値目標・期限を自動入力）</span></label>'+comboHTML('e-mis',getUniqueMissions(),r.mis||'','onMisChangeE')+'</div>'
        +'<div class="form-group"><label>数値目標(M)</label><input id="e-kpiM" value="'+esc(r.kpiM||'')+'"></div>'
        +'<div class="form-group"><label>期限(M)</label><input id="e-dlM" type="date" value="'+(r.dlM||'')+'"></div>'
        +'<div class="form-group full"><label>サブミッション <span style="font-weight:400;color:var(--text-m)">（選択で数値目標・期限を自動入力）</span></label>'+comboHTML('e-sub',getUniqueSubs(),r.sub||'','onSubChangeE')+'</div>'
        +'<div class="form-group"><label>数値目標(SM)</label><input id="e-kpiS" value="'+esc(r.kpiS||'')+'"></div>'
        +'<div class="form-group"><label>期限(SM)</label><input id="e-dlS" type="date" value="'+(r.dlS||'')+'"></div>'
        +'<div class="form-section">✅ タスク情報</div>'
        +'<div class="form-group full"><label>タスク名</label><input id="e-task" value="'+esc(r.task||'')+'"></div>'
        +'<div class="form-group"><label>担当者</label>'+comboHTML('e-who',getUniqueWhos(),r.who||'')+'</div>'
        +'<div class="form-group"><label>タスク期限</label><input id="e-due" type="date" value="'+(r.due||'')+'"></div>'
        +'<div class="form-group"><label>進捗</label><select id="e-st">'+STS.map(s=>'<option '+(s===r.st?'selected':'')+'>'+s+'</option>').join('')+'</select></div>'
        +'<div class="form-group"><label>タスク優先度</label><select id="e-pri">'+PRIS.map(p=>'<option '+(p===(r.priority||'中')?'selected':'')+'>'+p+'</option>').join('')+'</select></div>'
        +'<div class="form-group"><label>AI活用</label><select id="e-ai"><option value="0" '+(r.ai!==true&&r.ai!=1?'selected':'')+'>なし</option><option value="1" '+(r.ai===true||r.ai==1?'selected':'')+'>あり</option></select></div>'
        +'</div><div class="modal-actions">'
        +'<button class="btn btn-danger" onclick="trashTask('+id+')">🗑 ゴミ箱</button>'
        +'<button class="btn btn-copy" onclick="openCopy('+id+')">📋 コピー</button>'
        +'<div style="flex:1"></div>'
        +'<button class="btn btn-ghost" onclick="closeModal()">キャンセル</button>'
        +'<button class="btn btn-complete" onclick="completeTask('+id+')">✅ 完了</button>'
        +'<button class="btn btn-primary" id="saveBtn" onclick="saveEdit('+id+')">保存する</button>'
        +'</div></div>';
    m.classList.remove('hidden');
}

async function saveEdit(id){
    document.getElementById('saveBtn').textContent='保存中...';document.getElementById('saveBtn').disabled=true;
    const obj={id,bu:document.getElementById('e-bu').value,cat:document.getElementById('e-cat').value,
        ai:parseInt(document.getElementById('e-ai').value)===1,owner:comboVal('e-owner'),
        mis:comboVal('e-mis'),misPriority:document.getElementById('e-mispri').value,
        kpiM:document.getElementById('e-kpiM').value.trim(),dlM:document.getElementById('e-dlM').value,
        sub:comboVal('e-sub'),kpiS:document.getElementById('e-kpiS').value.trim(),dlS:document.getElementById('e-dlS').value,
        task:document.getElementById('e-task').value.trim(),who:comboVal('e-who'),due:document.getElementById('e-due').value,
        st:document.getElementById('e-st').value,priority:document.getElementById('e-pri').value};
    try{ await api('update',obj); closeModal(); await loadFromServer(); }
    catch(e){ alert('更新失敗: '+e.message); document.getElementById('saveBtn').textContent='保存する'; document.getElementById('saveBtn').disabled=false; }
}

// ゴミ箱へ
async function trashTask(id){
    if(!confirm('このタスクをゴミ箱に移動しますか？')) return;
    closeModal();
    try{ await api('trash',{id}); await loadFromServer(); }catch(e){ alert('失敗: '+e.message); }
}

// 復元
async function restoreTask(id){
    try{ await api('restore',{id}); await loadTrash(); await loadFromServer(); }catch(e){ alert('失敗: '+e.message); }
}

// 完全削除
async function permanentDelete(id){
    if(!confirm('完全に削除しますか？この操作は元に戻せません。')) return;
    try{ await api('delete',{id}); await loadTrash(); }catch(e){ alert('失敗: '+e.message); }
}

// 完了
async function completeTask(id){
    if(!confirm('このタスクを完了にしますか？')) return;
    closeModal();
    try{ await api('complete',{id}); await loadFromServer(); }catch(e){ alert('失敗: '+e.message); }
}

// 完了取消
async function uncompleteTask(id){
    try{ await api('uncomplete',{id}); await loadCompleted(); await loadFromServer(); }catch(e){ alert('失敗: '+e.message); }
}

// コピー
function openCopy(id){
    const r=DATA.find(d=>d.id===id); if(!r) return;
    const m=document.getElementById('modalOverlay');
    m.innerHTML='<div class="modal"><h2>📋 タスクをコピー</h2>'
        +'<p style="font-size:12px;color:var(--text-s);margin-bottom:8px">「'+esc(r.task)+'」をコピーします。<br>コピー先の事業部を選んでください：</p>'
        +'<div class="copy-modal-bu">'+BUS.map(b=>'<button onclick="doCopy('+id+',\''+b+'\')">'+b+'</button>').join('')+'</div>'
        +'<div class="modal-actions"><button class="btn btn-ghost" onclick="openEdit('+id+')">← 戻る</button></div></div>';
}

async function doCopy(id, bu){
    try{ await api('copy',{id,bu}); closeModal(); await loadFromServer(); alert('コピーしました'); }
    catch(e){ alert('コピー失敗: '+e.message); }
}

async function toggleAI(id, val){
    try{ await api('toggleai',{id,ai:val}); const r=DATA.find(d=>d.id===id); if(r) r.ai=val; renderAll(); }
    catch(e){ alert('更新失敗: '+e.message); }
}

function closeModal(){ document.getElementById('modalOverlay').classList.add('hidden') }

// ======== CSV出力 ========
function exportCSV(){
    const heads=['事業部','カテゴリ','M優先度','AI活用','責任者','ミッション','数値目標(M)','期限(M)','サブミッション','数値目標(SM)','期限(SM)','タスク','担当者','タスク期限','進捗','T優先度'];
    let csv='\uFEFF'+heads.join(',')+'\n';
    DATA.forEach(r=>{csv+=[r.bu,r.cat,r.misPriority||'中',r.ai?'○':'',r.owner,r.mis,r.kpiM,r.dlM,r.sub,r.kpiS,r.dlS,r.task,r.who,r.due,r.st,r.priority||'中'].map(v=>'"'+(v||'').replace(/"/g,'""')+'"').join(',')+'\n'});
    const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'})); a.download='WBS_タスク管理.csv'; a.click();
}

// ======== VIEW / FILTER ========
function setView(v){
    view=v;
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t=>{
        if((v==='tree'&&t.textContent==='ツリー')||(v==='table'&&t.textContent==='テーブル')||(v==='person'&&t.textContent==='担当者')||(v==='completed'&&t.textContent.includes('完了'))||(v==='trash'&&t.textContent.includes('ゴミ箱')))
            t.classList.add('active');
    });
    const views=['treeView','tableView','personView','completedView','trashView'];
    views.forEach(id=>document.getElementById(id).classList.add('hidden'));
    const showMain=v!=='trash'&&v!=='completed';
    document.getElementById('mainControls').style.display=showMain?'':'none';
    document.getElementById('personBar').style.display=showMain?'':'none';
    document.getElementById('stats').style.display=showMain?'':'none';
    if(v==='tree') document.getElementById('treeView').classList.remove('hidden');
    else if(v==='table') document.getElementById('tableView').classList.remove('hidden');
    else if(v==='person') document.getElementById('personView').classList.remove('hidden');
    else if(v==='completed'){ document.getElementById('completedView').classList.remove('hidden'); loadCompleted(); }
    else if(v==='trash'){ document.getElementById('trashView').classList.remove('hidden'); loadTrash(); }
    if(showMain) renderAll();
}
function setD(d){maxD=d;['d2','d3','d4','d5'].forEach(id=>document.getElementById(id).classList.remove('active'));document.getElementById('d'+d).classList.add('active');renderAll()}
function setF(f){filter=f;['fall','fm','fp','fa','fz'].forEach(id=>document.getElementById(id).classList.remove('active'));({'all':'fall','まんてん個別':'fm','まんてん個別プラス':'fp','atama+FC':'fa','全社':'fz'})[f]&&document.getElementById(({'all':'fall','まんてん個別':'fm','まんてん個別プラス':'fp','atama+FC':'fa','全社':'fz'})[f]).classList.add('active');renderAll()}
function setPri(p){priFilter=p;['pall','phigh','pmid','plow'].forEach(id=>{const el=document.getElementById(id);el.classList.remove('active');if(id!=='pall')el.style.background='transparent'});const mp={'all':'pall','高':'phigh','中':'pmid','低':'plow'};const el=document.getElementById(mp[p]);el.classList.add('active');if(p==='高'){el.style.background='#e74c3c';el.style.color='#fff'}else if(p==='中'){el.style.background='#f39c12';el.style.color='#fff'}else if(p==='低'){el.style.background='#95a5a6';el.style.color='#fff'};renderAll()}
function setMisPri(p){misPriFilter=p;['mpall','mphigh','mpmi','mplow'].forEach(id=>{const el=document.getElementById(id);el.classList.remove('active');if(id!=='mpall')el.style.background='transparent'});const mp={'all':'mpall','高':'mphigh','中':'mpmi','低':'mplow'};const el=document.getElementById(mp[p]);el.classList.add('active');if(p==='高'){el.style.background='#e74c3c';el.style.color='#fff'}else if(p==='中'){el.style.background='#f39c12';el.style.color='#fff'}else if(p==='低'){el.style.background='#95a5a6';el.style.color='#fff'};renderAll()}
function toggleKpi(){showKpi=document.getElementById('kpiChk').checked;renderAll()}

function renderAll(){ updateStats(); if(view==='tree') renderTree(); else if(view==='table') renderTable(); else if(view==='person') renderPersonView(); }

// ======== 初期化 ========
loadFromServer();
</script>
</body>
</html>
