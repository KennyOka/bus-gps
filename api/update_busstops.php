<?php
/*
 * update_busstops.php — バス停の座標・フリガナ(kana)を更新する書き込みAPI。
 *   ・ドラサポの「補正」: 座標(lat/lng)のみ更新（従来どおり）
 *   ・バス停エディタ: フリガナ(kana)や座標を更新
 * 旧値・新値・日時を busstop_fix.log に追記(監査ログ・巻き戻し可)。
 *
 * 【リクエスト】 POST (application/json)
 *   { "secret":"…", "fixes":[ { "busstop_id":123, "lat":28.34, "lng":129.56, "kana":"かさり" }, ... ] }
 *   - lat/lng は両方あるときのみ座標更新。kana キーがあるときのみフリガナ更新。
 * 【安全】合言葉(secret)照合。指定項目のみ更新。全変更をログに残す。
 * 【配置】乗務記録アプリの api/ ディレクトリ(config.php と同じ場所)。
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
if (!count($fixes)) { echo json_encode(['error'=>'更新データがありません'], JSON_UNESCAPED_UNICODE); exit; }

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

$logf = __DIR__ . '/busstop_fix.log';
$sel      = $mysqli->prepare('SELECT name, latitude, longitude, kana FROM m_busstop WHERE busstop_id = ?');
$updBoth  = $mysqli->prepare('UPDATE m_busstop SET latitude = ?, longitude = ?, kana = ?, updated_at = NOW() WHERE busstop_id = ?');
$updCoord = $mysqli->prepare('UPDATE m_busstop SET latitude = ?, longitude = ?, updated_at = NOW() WHERE busstop_id = ?');
$updKana  = $mysqli->prepare('UPDATE m_busstop SET kana = ?, updated_at = NOW() WHERE busstop_id = ?');
if (!$sel || !$updBoth || !$updCoord || !$updKana) {
    http_response_code(500); echo json_encode(['error'=>'prepare失敗: ' . $mysqli->error], JSON_UNESCAPED_UNICODE); exit;
}

$updated = 0; $results = [];
foreach ($fixes as $f) {
    $id = isset($f['busstop_id']) ? (int)$f['busstop_id'] : 0;
    if ($id <= 0) continue;

    $hasCoord = isset($f['lat']) && isset($f['lng']) && is_numeric($f['lat']) && is_numeric($f['lng'])
                && (float)$f['lat'] != 0.0 && (float)$f['lng'] != 0.0;
    $hasKana  = array_key_exists('kana', $f);   // kana キーがあれば更新(空文字も可)
    if (!$hasCoord && !$hasKana) continue;

    $lat = $hasCoord ? (float)$f['lat'] : null;
    $lng = $hasCoord ? (float)$f['lng'] : null;
    $kana = $hasKana ? (string)$f['kana'] : null;

    // 旧値
    $sel->bind_param('i', $id); $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    if (!$row) continue;   // 存在しないIDはスキップ
    $oldLat = $row['latitude']; $oldLng = $row['longitude']; $oldKana = $row['kana']; $name = $row['name'];

    if ($hasCoord && $hasKana) {
        $updBoth->bind_param('ddsi', $lat, $lng, $kana, $id); $updBoth->execute();
    } elseif ($hasCoord) {
        $updCoord->bind_param('ddi', $lat, $lng, $id); $updCoord->execute();
    } else {
        $updKana->bind_param('si', $kana, $id); $updKana->execute();
    }
    $updated++;

    // 監査ログ(変更項目のみ)
    $parts = [date('c'), 'busstop_id=' . $id, $name];
    if ($hasCoord) $parts[] = 'coord old=' . $oldLat . ',' . $oldLng . ' new=' . $lat . ',' . $lng;
    if ($hasKana)  $parts[] = 'kana old=' . $oldKana . ' new=' . $kana;
    @file_put_contents($logf, implode("\t", $parts) . "\n", FILE_APPEND | LOCK_EX);

    $results[] = [
        'busstop_id' => $id, 'name' => $name,
        'coord' => $hasCoord ? ['old'=>[$oldLat,$oldLng], 'new'=>[$lat,$lng]] : null,
        'kana'  => $hasKana  ? ['old'=>$oldKana, 'new'=>$kana] : null,
    ];
}
$sel->close(); $updBoth->close(); $updCoord->close(); $updKana->close(); $mysqli->close();

echo json_encode(['updated'=>$updated, 'count'=>count($results), 'results'=>$results], JSON_UNESCAPED_UNICODE);
