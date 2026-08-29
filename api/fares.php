<?php
/*
 * fares.php — 「今いるバス停まで、どの停から乗るといくらか」の運賃表を返す。
 * 車内の運賃表示器と同じ考え方: 乗車停(from) → 現在停(to) の運賃を一覧で返す。
 * 取込済みの src_fare(区間運賃) を利用。読み取り専用(SELECT)。
 *
 * 【リクエスト】 GET fares.php?dia_course_id=2898&busstop_id=123
 *   busstop_id … 現在の対象バス停(降車地)。省略時は始発起点(from_seq=1)の一覧を返す。
 * 【突合】 m_dia_course の始発停+発時刻で src_trip を特定。無ければ始発停のみでフォールバック。
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$dia_course_id = isset($_GET['dia_course_id']) ? (int)$_GET['dia_course_id'] : 0;
$cur_busstop   = isset($_GET['busstop_id']) ? (int)$_GET['busstop_id'] : 0;
if ($dia_course_id <= 0) {
    http_response_code(400); echo json_encode(['error'=>'dia_course_id を指定してください'], JSON_UNESCAPED_UNICODE); exit;
}

$__cfg = require __DIR__ . '/config.php';
if (is_array($__cfg) && isset($__cfg['db'])) { $config = $__cfg; }
$db = isset($config['db']) ? $config['db'] : null;
if (!is_array($db) || !isset($db['host'])) {
    http_response_code(500); echo json_encode(['error'=>'config.php の db 設定を読めませんでした'], JSON_UNESCAPED_UNICODE); exit;
}
$mysqli = @new mysqli($db['host'], $db['user'], $db['pass'], $db['name'] ?? ($db['dbname'] ?? null));
if ($mysqli->connect_errno) {
    http_response_code(500); echo json_encode(['error'=>'DB接続に失敗しました: ' . $mysqli->connect_error], JSON_UNESCAPED_UNICODE); exit;
}
$mysqli->set_charset($db['charset'] ?? 'utf8mb4');

// 1) コースの始発停・発時刻
$q = $mysqli->prepare(
  "SELECT c.start_busstop_id, TIME_FORMAT(c.departure_time,'%H:%i:%s') AS dep,
          COALESCE(c.start_name, s.name) AS start_name
   FROM m_dia_course c LEFT JOIN m_busstop s ON s.busstop_id = c.start_busstop_id
   WHERE c.dia_course_id = ?");
$q->bind_param('i', $dia_course_id); $q->execute();
$course = $q->get_result()->fetch_assoc(); $q->close();
if (!$course) { echo json_encode(['error'=>'コースが見つかりません'], JSON_UNESCAPED_UNICODE); exit; }
$originId = (int)$course['start_busstop_id'];
$dep      = $course['dep'];

// 2) 始発停+発時刻 で src_trip を特定(なければ始発停のみで最新便)
$tripId = 0;
$t1 = $mysqli->prepare("SELECT src_trip_id FROM src_trip WHERE origin_busstop_id = ? AND first_departure = ? ORDER BY target_date DESC LIMIT 1");
if ($t1) { $t1->bind_param('is', $originId, $dep); $t1->execute();
           if ($r = $t1->get_result()->fetch_assoc()) $tripId = (int)$r['src_trip_id']; $t1->close(); }
if (!$tripId) {
    $t2 = $mysqli->prepare("SELECT src_trip_id FROM src_trip WHERE origin_busstop_id = ? ORDER BY target_date DESC LIMIT 1");
    if ($t2) { $t2->bind_param('i', $originId); $t2->execute();
               if ($r = $t2->get_result()->fetch_assoc()) $tripId = (int)$r['src_trip_id']; $t2->close(); }
}
if (!$tripId) {
    echo json_encode(['dia_course_id'=>$dia_course_id, 'count'=>0, 'fares'=>[],
                      'note'=>'この便の運賃データが見つかりませんでした'], JSON_UNESCAPED_UNICODE);
    $mysqli->close(); exit;
}

// 3) 現在停の seq を特定(指定が無ければ 始発からの一覧にフォールバック)
$toSeq = 0; $toName = '';
if ($cur_busstop > 0) {
    $s = $mysqli->prepare(
      "SELECT ts.seq, COALESCE(b.name,'') AS name
       FROM src_trip_stop ts LEFT JOIN m_busstop b ON b.busstop_id = ts.busstop_id
       WHERE ts.src_trip_id = ? AND ts.busstop_id = ? ORDER BY ts.seq LIMIT 1");
    $s->bind_param('ii', $tripId, $cur_busstop); $s->execute();
    if ($r = $s->get_result()->fetch_assoc()) { $toSeq = (int)$r['seq']; $toName = $r['name']; }
    $s->close();
}

$fares = [];
if ($toSeq > 1) {
    // 各乗車停(from) → 現在停(to) の運賃。from停の名前は src_trip_stop 経由で解決。
    $f = $mysqli->prepare(
      "SELECT f.from_seq, f.price, COALESCE(b.name, CONCAT('停', f.from_seq)) AS name
       FROM src_fare f
       LEFT JOIN src_trip_stop ts ON ts.src_trip_id = f.src_trip_id AND ts.seq = f.from_seq
       LEFT JOIN m_busstop b ON b.busstop_id = ts.busstop_id
       WHERE f.src_trip_id = ? AND f.to_seq = ?
       ORDER BY f.from_seq");
    $f->bind_param('ii', $tripId, $toSeq); $f->execute();
    $res = $f->get_result();
    while ($row = $res->fetch_assoc()) {
        $fares[] = ['seq'=>(int)$row['from_seq'], 'name'=>$row['name'], 'price'=>(int)$row['price']];
    }
    $f->close();
    $mode = 'to_current';
} else {
    // フォールバック: 始発から各停までの運賃
    $f = $mysqli->prepare(
      "SELECT f.to_seq AS seq, f.price, COALESCE(b.name, CONCAT('停', f.to_seq)) AS name
       FROM src_fare f LEFT JOIN m_busstop b ON b.busstop_id = f.to_busstop_id
       WHERE f.src_trip_id = ? AND f.from_seq = 1
       ORDER BY f.to_seq");
    $f->bind_param('i', $tripId); $f->execute();
    $res = $f->get_result();
    while ($row = $res->fetch_assoc()) {
        $fares[] = ['seq'=>(int)$row['seq'], 'name'=>$row['name'], 'price'=>(int)$row['price']];
    }
    $f->close();
    $mode = 'from_origin';
    $toName = $course['start_name'];
}
$mysqli->close();

$note = '';
if (!$fares) {
    $note = ($mode === 'to_current')
        ? 'この区間の運賃データがありません(区間運賃の取込が必要です)'
        : '運賃データがありません';
}
echo json_encode([
    'dia_course_id'=>$dia_course_id, 'mode'=>$mode,
    'to_name'=>$toName, 'to_seq'=>$toSeq,
    'start_name'=>$course['start_name'],
    'count'=>count($fares), 'fares'=>$fares, 'note'=>$note,
], JSON_UNESCAPED_UNICODE);
