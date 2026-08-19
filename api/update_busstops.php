<?php
/*
 * update_busstops.php — バス停座標の補正を反映する書き込みAPI(⑫)。
 * ドラサポの「補正」でその場取得した新座標を、停止時にまとめて m_busstop に UPDATE する。
 * 旧座標・新座標・日時は busstop_fix.log に追記(監査ログ)。
 *
 * 【リクエスト】 POST (application/json)
 *   { "secret":"…", "fixes":[ { "busstop_id":123, "lat":28.34, "lng":129.56 }, ... ] }
 * 【安全】合言葉(secret)照合。lat/lng のみ更新。全変更をログに残す(巻き戻し可)。
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)。
 * 【注意】合言葉はアプリ(公開JS)にも持たせるため完全な秘匿ではない。ログで追跡・復元できる前提の簡易保護。
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'POSTのみ']); exit; }

$SECRET = 'shimabus-fix-2026';   // ★アプリの CORRECT_SECRET と一致させる

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || (($body['secret'] ?? '') !== $SECRET)) {
    http_response_code(403); echo json_encode(['error'=>'認証に失敗しました'], JSON_UNESCAPED_UNICODE); exit;
}
$fixes = isset($body['fixes']) && is_array($body['fixes']) ? $body['fixes'] : [];
if (!count($fixes)) { echo json_encode(['error'=>'補正データがありません'], JSON_UNESCAPED_UNICODE); exit; }

// ==== config.php を再利用してDB接続(db: host/name/user/pass/charset) ====
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

$logf = __DIR__ . '/busstop_fix.log';
$sel = $mysqli->prepare('SELECT name, latitude, longitude FROM m_busstop WHERE busstop_id = ?');
$upd = $mysqli->prepare('UPDATE m_busstop SET latitude = ?, longitude = ?, updated_at = NOW() WHERE busstop_id = ?');
if (!$sel || !$upd) { http_response_code(500); echo json_encode(['error'=>'prepare失敗: ' . $mysqli->error], JSON_UNESCAPED_UNICODE); exit; }

$updated = 0; $results = [];
foreach ($fixes as $f) {
    $id  = isset($f['busstop_id']) ? (int)$f['busstop_id'] : 0;
    $lat = isset($f['lat']) ? (float)$f['lat'] : 0.0;
    $lng = isset($f['lng']) ? (float)$f['lng'] : 0.0;
    if ($id <= 0 || $lat == 0.0 || $lng == 0.0) continue;

    $sel->bind_param('i', $id); $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $oldLat = $row ? $row['latitude']  : null;
    $oldLng = $row ? $row['longitude'] : null;
    $name   = $row ? $row['name']      : '';

    $upd->bind_param('ddi', $lat, $lng, $id); $upd->execute();
    if ($mysqli->affected_rows >= 0 && $row) $updated++;

    // 監査ログ(旧→新・日時)
    $line = date('c') . "\tbusstop_id=" . $id . "\t" . $name
          . "\told=" . $oldLat . "," . $oldLng
          . "\tnew=" . $lat . "," . $lng . "\n";
    @file_put_contents($logf, $line, FILE_APPEND | LOCK_EX);

    $results[] = ['busstop_id'=>$id, 'name'=>$name, 'old'=>[$oldLat,$oldLng], 'new'=>[$lat,$lng]];
}
$sel->close(); $upd->close(); $mysqli->close();

echo json_encode(['updated'=>$updated, 'count'=>count($results), 'results'=>$results], JSON_UNESCAPED_UNICODE);
