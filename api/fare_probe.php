<?php
/*
 * fare_probe.php — しまバス fare.cgi の生レスポンスを確認する調査用(一時ファイル)。
 * 運賃(src_fare)が0件の原因を特定するため、複数のパラメータ変種で実際の応答を見る。
 * 原因特定後は削除してよい。外部APIへのGET/POSTのみで、DBは読み取り(SELECT)のみ。
 *
 * 【リクエスト】 GET fare_probe.php?key=shimabus-fix-2026[&trip_id=...&target_date=YYYYMMDD]
 *   trip_id 省略時は src_trip の最新1件を使用。
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if (($_GET['key'] ?? '') !== 'shimabus-fix-2026') { http_response_code(403); echo json_encode(['error'=>'key不正']); exit; }

$__cfg = require __DIR__ . '/config.php';
if (is_array($__cfg) && isset($__cfg['db'])) { $config = $__cfg; }
$db = $config['db'] ?? null;
$base = rtrim($config['shimabus']['base'] ?? 'https://shimabus.busplus.jp/api', '/');

$out = ['base' => $base];

// 1) trip_id / target_date を決める(指定が無ければ src_trip の最新)
$tripId = isset($_GET['trip_id']) ? (string)$_GET['trip_id'] : '';
$tdate  = isset($_GET['target_date']) ? preg_replace('/[^0-9]/','',$_GET['target_date']) : '';
if ($tripId === '' || $tdate === '') {
    $mysqli = @new mysqli($db['host'], $db['user'], $db['pass'], $db['name'] ?? ($db['dbname'] ?? null));
    if (!$mysqli->connect_errno) {
        $mysqli->set_charset($db['charset'] ?? 'utf8mb4');
        $r = $mysqli->query("SELECT trip_key, DATE_FORMAT(target_date,'%Y%m%d') d FROM src_trip ORDER BY src_trip_id DESC LIMIT 1");
        if ($r && ($x = $r->fetch_assoc())) {
            if ($tripId === '') $tripId = $x['trip_key'];
            if ($tdate  === '') $tdate  = $x['d'];
        }
        $mysqli->close();
    }
}
$out['trip_id'] = $tripId;
$out['target_date_used'] = $tdate;
$out['today'] = date('Ymd');

function probe(string $base, string $name, array $params, string $method='POST'): array {
    $url = $base . '/' . $name . '.cgi';
    $body = http_build_query($params);
    $ch = curl_init($method==='GET' ? ($url.'?'.$body) : $url);
    $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_USERAGENT=>'shimabus-rec-importer/1.0'];
    if ($method==='POST') { $opt[CURLOPT_POST]=true; $opt[CURLOPT_POSTFIELDS]=$body;
                            $opt[CURLOPT_HTTPHEADER]=['Content-Type: application/x-www-form-urlencoded']; }
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch); $errno=curl_errno($ch); $err=curl_error($ch);
    $code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $r = ['method'=>$method, 'params'=>$params, 'http'=>$code];
    if ($errno !== 0) { $r['curl_error']=$err; return $r; }
    $r['raw_len'] = strlen((string)$res);
    $r['raw_head'] = mb_substr((string)$res, 0, 900);      // 生レスポンス先頭
    $j = json_decode((string)$res, true);
    if (is_array($j)) {
        $r['top_keys'] = array_keys($j);
        foreach ($j as $k=>$v) { if (is_array($v)) $r['array_counts'][$k] = count($v); }
        // 配列の最初の要素を1つだけ見せる(構造把握用)
        foreach ($j as $k=>$v) {
            if (is_array($v) && $v) { $first = reset($v); $r['first_item_of'][$k] = is_array($first) ? $first : (string)$first; break; }
        }
    } else { $r['json'] = 'JSONではない'; }
    return $r;
}

// 2) 現在の実装と同じ呼び出し
$out['tests']['current'] = probe($base, 'fare', ['trip_id'=>$tripId, 'stop_sequence'=>1, 'target_date'=>$tdate]);
// 3) 変種
$out['tests']['no_target_date']   = probe($base, 'fare', ['trip_id'=>$tripId, 'stop_sequence'=>1]);
$out['tests']['seq0']             = probe($base, 'fare', ['trip_id'=>$tripId, 'stop_sequence'=>0, 'target_date'=>$tdate]);
$out['tests']['today']            = probe($base, 'fare', ['trip_id'=>$tripId, 'stop_sequence'=>1, 'target_date'=>date('Ymd')]);
$out['tests']['get']              = probe($base, 'fare', ['trip_id'=>$tripId, 'stop_sequence'=>1, 'target_date'=>$tdate], 'GET');
// 4) 参考: 同じtripのtripdetail(正常に取れている呼び出し)
$out['tests']['tripdetail_ref']   = probe($base, 'tripdetail', ['trip_id'=>$tripId, 'target_date'=>$tdate]);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
