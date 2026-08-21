<?php
/*
 * diagrams.php — 指定 day_type(1=平日/2=土日祝) の全ダイヤ一覧。
 * 各ダイヤの「最初の実車コースの始発地(発時刻)」と「最後の実車コースの到着地(着時刻)」を返す。
 * 読み取り専用(SELECT)。config.php 再利用。
 *
 * 【リクエスト】 GET diagrams.php?day_type=1
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$day_type = isset($_GET['day_type']) ? (int)$_GET['day_type'] : 0;
if ($day_type !== 1 && $day_type !== 2) {
    http_response_code(400); echo json_encode(['error'=>'day_type(1=平日/2=土日祝)を指定してください'], JSON_UNESCAPED_UNICODE); exit;
}

// ==== config.php を再利用してDB接続 ====
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

// 全ダイヤ
$dstmt = $mysqli->prepare("SELECT dia_id, dia_no FROM m_dia WHERE day_type = ? AND is_active = 1 ORDER BY dia_no");
$dstmt->bind_param('i', $day_type); $dstmt->execute();
$dres = $dstmt->get_result();
$dias = [];
while ($row = $dres->fetch_assoc()) { $dias[] = $row; }
$dstmt->close();

// 各ダイヤの最初/最後の実車(category=3)コース
$qFirst = $mysqli->prepare(
  "SELECT COALESCE(c.start_name, s.name) AS place, TIME_FORMAT(c.departure_time,'%H:%i') AS t
   FROM m_dia_course c LEFT JOIN m_busstop s ON s.busstop_id = c.start_busstop_id
   WHERE c.dia_id = ? AND c.category = 3 ORDER BY c.seq ASC LIMIT 1");
$qLast = $mysqli->prepare(
  "SELECT c.dia_course_id, COALESCE(c.end_name, e.name) AS place
   FROM m_dia_course c LEFT JOIN m_busstop e ON e.busstop_id = c.end_busstop_id
   WHERE c.dia_id = ? AND c.category = 3 ORDER BY c.seq DESC LIMIT 1");
$qArr = $mysqli->prepare(
  "SELECT TIME_FORMAT(MAX(scheduled_time),'%H:%i') AS t FROM m_dia_course_stop WHERE dia_course_id = ?");

$out = [];
foreach ($dias as $d) {
    $diaId = (int)$d['dia_id'];
    $startPlace = null; $startTime = null; $endPlace = null; $endTime = null;

    $qFirst->bind_param('i', $diaId); $qFirst->execute();
    if ($fr = $qFirst->get_result()->fetch_assoc()) { $startPlace = $fr['place']; $startTime = $fr['t']; }

    $qLast->bind_param('i', $diaId); $qLast->execute();
    if ($lr = $qLast->get_result()->fetch_assoc()) {
        $endPlace = $lr['place']; $lastCourseId = (int)$lr['dia_course_id'];
        $qArr->bind_param('i', $lastCourseId); $qArr->execute();
        if ($ar = $qArr->get_result()->fetch_assoc()) { $endTime = $ar['t']; }
    }

    $out[] = [
        'dia_no'      => $d['dia_no'],
        'start_place' => $startPlace,
        'start_time'  => $startTime,
        'end_place'   => $endPlace,
        'end_time'    => $endTime,
    ];
}
$qFirst->close(); $qLast->close(); $qArr->close(); $mysqli->close();

echo json_encode(['day_type'=>$day_type, 'count'=>count($out), 'diagrams'=>$out], JSON_UNESCAPED_UNICODE);
