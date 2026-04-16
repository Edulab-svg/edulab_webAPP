<?php
require_once __DIR__ . '/config.php';

/** タスク行をカテゴリ順→sort_order→created_at で並べ替え */
function sortTasksByCategoryOrder(array $rows, $orderJson) {
    $order = json_decode($orderJson ?? '[]', true);
    if (!is_array($order)) $order = [];
    $catsInTasks = [];
    $seen = [];
    foreach ($rows as $r) {
        $c = $r['category'] ?? '';
        if (!isset($seen[$c])) { $seen[$c] = true; $catsInTasks[] = $c; }
    }
    $orderedCats = [];
    foreach ($order as $c) {
        if (in_array($c, $catsInTasks, true) && !in_array($c, $orderedCats, true)) $orderedCats[] = $c;
    }
    foreach ($catsInTasks as $c) {
        if (!in_array($c, $orderedCats, true)) $orderedCats[] = $c;
    }
    usort($rows, function ($a, $b) use ($orderedCats) {
        $ia = array_search($a['category'] ?? '', $orderedCats, true);
        $ib = array_search($b['category'] ?? '', $orderedCats, true);
        if ($ia !== $ib) return $ia <=> $ib;
        $so = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
        if ($so !== 0) return $so;
        return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
    });
    return $rows;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$path = trim($_GET['route'] ?? '', '/');
$action = $_GET['action'] ?? '';
$db = getDB();

$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {

// ==========================================
// GET
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($path === 'dashboard') {
        $projects = $db->query("SELECT * FROM projects WHERE status != 'アーカイブ' ORDER BY open_date ASC")->fetchAll();
        $result = [];
        foreach ($projects as $p) {
            $pid = $p['id'];
            $tc = $db->prepare("SELECT COUNT(*) as total, SUM(status='完了') as done FROM tasks WHERE project_id=?");
            $tc->execute([$pid]); $t = $tc->fetch();
            $ec = $db->prepare("SELECT COUNT(*) as total, SUM(checked=1) as done FROM equipment WHERE project_id=?");
            $ec->execute([$pid]); $e = $ec->fetch();
            $p['staff'] = json_decode($p['staff'] ?? '[]', true);
            $p['taskTotal'] = (int)$t['total']; $p['taskDone'] = (int)($t['done'] ?? 0);
            $p['equipTotal'] = (int)$e['total']; $p['equipDone'] = (int)($e['done'] ?? 0);
            $result[] = $p;
        }
        jsonResponse($result);
    }

    if ($path === 'projects') {
        $rows = $db->query("SELECT * FROM projects ORDER BY FIELD(status,'準備中','オープン済','アーカイブ'), open_date ASC")->fetchAll();
        foreach ($rows as &$r) $r['staff'] = json_decode($r['staff'] ?? '[]', true);
        jsonResponse($rows);
    }

    if (preg_match('#^projects/([^/]+)/version$#', $path, $m)) {
        $stmt = $db->prepare("SELECT version FROM projects WHERE id=?"); $stmt->execute([$m[1]]);
        jsonResponse(['version' => (int)($stmt->fetch()['version'] ?? 0)]);
    }

    if (preg_match('#^projects/([^/]+)/tasks$#', $path, $m)) {
        $stmt = $db->prepare("SELECT * FROM tasks WHERE project_id=? ORDER BY sort_order,created_at"); $stmt->execute([$m[1]]);
        $rows = $stmt->fetchAll();
        $pco = null;
        try {
            $pstmt = $db->prepare("SELECT task_category_order FROM projects WHERE id=?"); $pstmt->execute([$m[1]]); $pco = $pstmt->fetchColumn();
        } catch (Exception $e) { /* 列未追加時は sort_order のみ */ }
        if ($pco !== null && $pco !== false) $rows = sortTasksByCategoryOrder($rows, $pco);
        jsonResponse($rows);
    }

    if (preg_match('#^projects/([^/]+)/equipment$#', $path, $m)) {
        $stmt = $db->prepare("SELECT * FROM equipment WHERE project_id=? ORDER BY sort_order,created_at"); $stmt->execute([$m[1]]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['checked'] = (bool)$r['checked']; $r['arrived'] = (bool)$r['arrived']; }
        jsonResponse($rows);
    }

    if (preg_match('#^projects/([^/]+)$#', $path, $m)) {
        $stmt = $db->prepare("SELECT * FROM projects WHERE id=?"); $stmt->execute([$m[1]]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['error'=>'Not found'], 404);
        $row['staff'] = json_decode($row['staff'] ?? '[]', true);
        $tco = $row['task_category_order'] ?? null;
        $row['task_category_order'] = is_string($tco) ? json_decode($tco, true) : (is_array($tco) ? $tco : null);
        if (!is_array($row['task_category_order'])) $row['task_category_order'] = null;
        jsonResponse($row);
    }

    jsonResponse(['error'=>'GET not found','path'=>$path], 404);
}

// ==========================================
// POST (action determines operation)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Delete project ---
    if ($action === 'delete' && preg_match('#^projects/([^/]+)$#', $path, $m)) {
        $db->prepare("DELETE FROM projects WHERE id=?")->execute([$m[1]]);
        jsonResponse(['ok'=>true]);
    }

    // --- Create project ---
    if ($action === '' && $path === 'projects') {
        $id = uid(); $tpl = $input['template_name'] ?? 'デフォルト'; $od = $input['open_date'];
        $db->beginTransaction();
        $db->prepare("INSERT INTO projects (id,name,open_date,staff) VALUES (?,?,?,?)")
           ->execute([$id, $input['name'], $od, json_encode($input['staff'] ?? [], JSON_UNESCAPED_UNICODE)]);
        $tpls = $db->prepare("SELECT * FROM task_templates WHERE template_name=? ORDER BY sort_order"); $tpls->execute([$tpl]);
        $ins = $db->prepare("INSERT INTO tasks (id,project_id,category,name,start_date,end_date,duration,status,assignee,note,sort_order) VALUES (?,?,?,?,DATE_ADD(?,INTERVAL ? DAY),DATE_ADD(?,INTERVAL ? DAY),?,'未着手','',?,?)");
        foreach ($tpls->fetchAll() as $t) { $ins->execute([uid(),$id,$t['category'],$t['name'],$od,$t['offset_days'],$od,$t['offset_days']+$t['duration']-1,$t['duration'],$t['note'],$t['sort_order']]); }
        $etpls = $db->prepare("SELECT * FROM equip_templates WHERE template_name=? ORDER BY sort_order"); $etpls->execute([$tpl]);
        $eins = $db->prepare("INSERT INTO equipment (id,project_id,category,name,qty,source,assignee,sort_order) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($etpls->fetchAll() as $e) { $eins->execute([uid(),$id,$e['category'],$e['name'],$e['qty'],$e['source'],$e['assignee'],$e['sort_order']]); }
        $db->commit();
        jsonResponse(['ok'=>true,'id'=>$id]);
    }

    // --- Copy project ---
    if ($action === 'copy' && preg_match('#^projects/([^/]+)$#', $path, $m)) {
        $srcId=$m[1]; $newId=uid(); $dd=(int)$input['daysDiff'];
        $db->beginTransaction();
        $src=$db->prepare("SELECT * FROM projects WHERE id=?"); $src->execute([$srcId]); $s=$src->fetch();
        if(!$s){$db->rollBack();jsonResponse(['error'=>'Not found'],404);}
        $tco = $s['task_category_order'] ?? null;
        try {
            $db->prepare("INSERT INTO projects (id,name,open_date,staff,task_category_order) VALUES (?,?,?,?,?)")->execute([$newId,$input['name'],$input['open_date'],$s['staff'],$tco]);
        } catch (Exception $e) {
            $db->prepare("INSERT INTO projects (id,name,open_date,staff) VALUES (?,?,?,?)")->execute([$newId,$input['name'],$input['open_date'],$s['staff']]);
        }
        $tasks=$db->prepare("SELECT * FROM tasks WHERE project_id=?"); $tasks->execute([$srcId]);
        $ti=$db->prepare("INSERT INTO tasks (id,project_id,category,name,start_date,end_date,duration,status,assignee,note,sort_order) VALUES (?,?,?,?,DATE_ADD(?,INTERVAL ? DAY),DATE_ADD(?,INTERVAL ? DAY),?,'未着手',?,?,?)");
        foreach($tasks->fetchAll() as $t){$ti->execute([uid(),$newId,$t['category'],$t['name'],$t['start_date'],$dd,$t['end_date'],$dd,$t['duration'],$t['assignee'],$t['note'],$t['sort_order']]);}
        $equips=$db->prepare("SELECT * FROM equipment WHERE project_id=?"); $equips->execute([$srcId]);
        $ei=$db->prepare("INSERT INTO equipment (id,project_id,category,name,qty,source,assignee,checked,arrived,note,sort_order) VALUES (?,?,?,?,?,?,?,0,0,?,?)");
        foreach($equips->fetchAll() as $e){$ei->execute([uid(),$newId,$e['category'],$e['name'],$e['qty'],$e['source'],$e['assignee'],$e['note'],$e['sort_order']]);}
        $db->commit();
        jsonResponse(['ok'=>true,'id'=>$newId]);
    }

    // --- Update project ---
    if ($action === 'update' && preg_match('#^projects/([^/]+)$#', $path, $m)) {
        $name = $input['name']; $od = $input['open_date'];
        $staff = json_encode($input['staff']??[], JSON_UNESCAPED_UNICODE);
        $st = $input['status'] ?? null;
        $id = $m[1];
        if (array_key_exists('task_category_order', $input)) {
            $tco = json_encode($input['task_category_order'], JSON_UNESCAPED_UNICODE);
            try {
                $db->prepare("UPDATE projects SET name=?,open_date=?,staff=?,task_category_order=?,status=COALESCE(?,status),version=version+1 WHERE id=?")
                   ->execute([$name,$od,$staff,$tco,$st,$id]);
            } catch (Exception $e) {
                $db->prepare("UPDATE projects SET name=?,open_date=?,staff=?,status=COALESCE(?,status),version=version+1 WHERE id=?")
                   ->execute([$name,$od,$staff,$st,$id]);
            }
        } else {
            $db->prepare("UPDATE projects SET name=?,open_date=?,staff=?,status=COALESCE(?,status),version=version+1 WHERE id=?")
               ->execute([$name,$od,$staff,$st,$id]);
        }
        jsonResponse(['ok'=>true]);
    }

    // --- Create task ---
    if ($action === '' && preg_match('#^projects/([^/]+)/tasks$#', $path, $m)) {
        $pid=$m[1]; $t=$input;
        $db->prepare("INSERT INTO tasks (id,project_id,category,name,start_date,end_date,duration,status,assignee,note,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$t['id']??uid(),$pid,$t['category'],$t['name'],$t['start_date'],$t['end_date'],$t['duration'],$t['status']??'未着手',$t['assignee']??'',$t['note']??null,$t['sort_order']??0]);
        $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$pid]);
        jsonResponse(['ok'=>true]);
    }

    // --- Shift tasks ---
    if ($action === 'shift' && preg_match('#^projects/([^/]+)/tasks$#', $path, $m)) {
        $pid=$m[1];
        $db->prepare("UPDATE tasks SET start_date=DATE_ADD(start_date,INTERVAL ? DAY),end_date=DATE_ADD(end_date,INTERVAL ? DAY) WHERE project_id=? AND category=?")
           ->execute([$input['days'],$input['days'],$pid,$input['category']]);
        $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$pid]);
        jsonResponse(['ok'=>true]);
    }

    // --- Update task ---
    if ($action === 'update' && preg_match('#^tasks/([^/]+)$#', $path, $m)) {
        $t=$input;
        $db->prepare("UPDATE tasks SET category=?,name=?,start_date=?,end_date=?,duration=?,status=?,assignee=?,note=?,sort_order=? WHERE id=?")
           ->execute([$t['category'],$t['name'],$t['start_date'],$t['end_date'],$t['duration'],$t['status'],$t['assignee']??'',$t['note']??null,$t['sort_order']??0,$m[1]]);
        $r=$db->prepare("SELECT project_id FROM tasks WHERE id=?"); $r->execute([$m[1]]); $row=$r->fetch();
        if($row) $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$row['project_id']]);
        jsonResponse(['ok'=>true]);
    }

    // --- Delete task ---
    if ($action === 'delete' && preg_match('#^tasks/([^/]+)$#', $path, $m)) {
        $r=$db->prepare("SELECT project_id FROM tasks WHERE id=?"); $r->execute([$m[1]]); $row=$r->fetch();
        $db->prepare("DELETE FROM tasks WHERE id=?")->execute([$m[1]]);
        if($row) $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$row['project_id']]);
        jsonResponse(['ok'=>true]);
    }

    // --- Delete task category ---
    if ($action === 'delete_category' && preg_match('#^projects/([^/]+)/tasks$#', $path, $m)) {
        $db->prepare("DELETE FROM tasks WHERE project_id=? AND category=?")->execute([$m[1],$input['category']??'']);
        $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$m[1]]);
        jsonResponse(['ok'=>true]);
    }

    // --- Create equipment ---
    if ($action === '' && preg_match('#^projects/([^/]+)/equipment$#', $path, $m)) {
        $pid=$m[1]; $e=$input;
        $db->prepare("INSERT INTO equipment (id,project_id,category,name,qty,source,assignee,checked,arrived,note,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$e['id']??uid(),$pid,$e['category'],$e['name'],$e['qty']??'',$e['source']??'',$e['assignee']??'',!empty($e['checked'])?1:0,!empty($e['arrived'])?1:0,$e['note']??null,$e['sort_order']??0]);
        $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$pid]);
        jsonResponse(['ok'=>true]);
    }

    // --- Update equipment ---
    if ($action === 'update' && preg_match('#^equipment/([^/]+)$#', $path, $m)) {
        $e=$input;
        $db->prepare("UPDATE equipment SET category=?,name=?,qty=?,source=?,assignee=?,checked=?,arrived=?,note=? WHERE id=?")
           ->execute([$e['category'],$e['name'],$e['qty']??'',$e['source']??'',$e['assignee']??'',!empty($e['checked'])?1:0,!empty($e['arrived'])?1:0,$e['note']??null,$m[1]]);
        $r=$db->prepare("SELECT project_id FROM equipment WHERE id=?"); $r->execute([$m[1]]); $row=$r->fetch();
        if($row) $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$row['project_id']]);
        jsonResponse(['ok'=>true]);
    }

    // --- Patch equipment ---
    if ($action === 'patch' && preg_match('#^equipment/([^/]+)$#', $path, $m)) {
        $sets=[]; $vals=[];
        foreach($input as $k=>$v){
            if(in_array($k,['checked','arrived'])){$sets[]="$k=?";$vals[]=$v?1:0;}
            elseif(in_array($k,['category','name','qty','source','assignee','note'])){$sets[]="$k=?";$vals[]=(string)$v;}
        }
        if($sets){$vals[]=$m[1];$db->prepare("UPDATE equipment SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
            $r=$db->prepare("SELECT project_id FROM equipment WHERE id=?");$r->execute([$m[1]]);$row=$r->fetch();
            if($row) $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$row['project_id']]);
        }
        jsonResponse(['ok'=>true]);
    }

    // --- Delete equipment ---
    if ($action === 'delete' && preg_match('#^equipment/([^/]+)$#', $path, $m)) {
        $r=$db->prepare("SELECT project_id FROM equipment WHERE id=?"); $r->execute([$m[1]]); $row=$r->fetch();
        $db->prepare("DELETE FROM equipment WHERE id=?")->execute([$m[1]]);
        if($row) $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$row['project_id']]);
        jsonResponse(['ok'=>true]);
    }

    // --- Delete equipment category ---
    if ($action === 'delete_category' && preg_match('#^projects/([^/]+)/equipment$#', $path, $m)) {
        $db->prepare("DELETE FROM equipment WHERE project_id=? AND category=?")->execute([$m[1],$input['category']??'']);
        $db->prepare("UPDATE projects SET version=version+1 WHERE id=?")->execute([$m[1]]);
        jsonResponse(['ok'=>true]);
    }

    jsonResponse(['error'=>'POST not found','path'=>$path,'action'=>$action], 404);
}

jsonResponse(['error'=>'Method not allowed'], 405);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(['error'=>$e->getMessage()], 500);
}
