<?php
/*
 * fares.php — 指定コースの「始発から各バス停までの運賃」一覧を返す。
 * 取込済みの src_fare(from_seq=1 起点の運賃) を利用。読み取り専用(SELECT)。
 *
 * 【リクエスト】 GET fares.php?dia_course_id=2898
 * 【突合】 m_dia_course の始発停(start_busstop_id)+発時刻(departure_time) で src_trip を特定。
 *          見つからない場合は始発停のみで最も近い便にフォールバック。
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$dia_course_id = isset($_GET['dia_course_id']) ? (int)$_GET['dia_course_id'] : 0;
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
    echo json_encode(['dia_course_id'=>$dia_course_id, 'start_name'=>$course['start_name'], 'count'=>0, 'fares'=>[],
                      'note'=>'この便の運賃データが見つかりませんでした'], JSON_UNESCAPED_UNICODE);
    $mysqli->close(); exit;
}

// 3) 始発からの運賃一覧
$f = $mysqli->prepare(
  "SELECT f.to_seq, f.price, COALESCE(b.name, CONCAT('停', f.to_seq)) AS name
   FROM src_fare f LEFT JOIN m_busstop b ON b.busstop_id = f.to_busstop_id
   WHERE f.src_trip_id = ? AND f.from_seq = 1
   ORDER BY f.to_seq");
$f->bind_param('i', $tripId); $f->execute();
$res = $f->get_result();
$fares = [];
while ($row = $res->fetch_assoc()) {
    $fares[] = ['seq'=>(int)$row['to_seq'], 'name'=>$row['name'], 'price'=>(int)$row['price']];
}
$f->close(); $mysqli->close();

echo json_encode(['dia_course_id'=>$dia_course_id, 'start_name'=>$course['start_name'],
                  'count'=>count($fares), 'fares'=>$fares], JSON_UNESCAPED_UNICODE);
