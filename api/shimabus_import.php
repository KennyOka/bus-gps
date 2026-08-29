<?php
/**
 * しまバス「時刻表プラス」非公式JSON APIからの取り込み（管理者=role9 専用）。
 *
 *   action=shimabus.importRoute  … 指定バス停(=signage id=busstop_id, 数値)を通る便を
 *                                   丸ごと取り込む。signageは始発便に限らずその停を通る便を返す。
 *   params:
 *     origin(必須, 数値ID)      取り込みの起点/ハブ停。ここのsignageに載る便を取り込む。
 *     keito (任意, 系統番号)     指定時は該当系統のみ。
 *     target_date(YYYYMMDD)     既定=当日
 *     mode(preview|commit)      既定=preview
 *
 *   ※ 起点signageが空の系統(例: 山羊島ホテル前 i=330 の系統2801)は、経路途中のハブ停を
 *      origin に、keito で系統を指定して取り込む。例) origin=80(和光園前) keito=2801
 *   ※ busstop.cgi が座標を返さない停留所(i=330等)は tripdetail 情報で m_busstop を補完(座標NULL)。
 *
 * 取り込み先（db/migration_20260808_add_shimabus_source.sql）:
 *   m_busstop(標柱/緯度経度, src_stop_idで突合) / src_route / src_trip /
 *   src_trip_stop / src_fare / src_import_batch
 *
 * 方針:
 *   - preview: 取得と検証のみ、DB書込なし。commit: トランザクションで一括UPSERT。
 *   - 再取込は「置換」：同一(trip_key,target_date)の停留所時刻・運賃は消して入れ直す。
 *   - 非公式APIにつきリクエスト間隔を空ける（SLEEP_US）。低頻度実行前提。
 *   - スナップショット: target_date とセットで保存。
 *
 * ※ 詳細なAPI仕様はメモ shimabus-busplus-api を参照。
 */
declare(strict_types=1);

const SHIMABUS_SLEEP_US = 200000; // 0.2秒/リクエスト

/** しまバスAPIのベースURL（config で上書き可） */
function shimabus_base(): string
{
    return rtrim($GLOBALS['CONFIG']['shimabus']['base'] ?? 'https://shimabus.busplus.jp/api', '/');
}

/** POST して JSON を連想配列で返す。失敗時は例外。 */
function shimabus_post(string $name, array $params): array
{
    $url  = shimabus_base() . '/' . $name . '.cgi';
    $body = http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT      => 'shimabus-rec-importer/1.0',
        ]);
        $res  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0)         throw new RuntimeException("通信失敗({$name}): {$err}");
        if ($code < 200 || $code >= 300) throw new RuntimeException("HTTP {$code} ({$name})");
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 20,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) throw new RuntimeException("通信失敗({$name})");
    }

    $json = json_decode((string) $res, true);
    if (!is_array($json)) throw new RuntimeException("JSON解析失敗({$name})");
    return $json;
}

/** 標柱ID(例 "286_02")のサフィックスから方向を推定。_01→1(上) _02→2(下) 他→9。
 *  ※しまバスの _01/_02 は必ずしも上下と一致しない。真の方向は運用で補正前提。 */
function shimabus_dir_from_pole(string $poleId): int
{
    if (preg_match('/_0*(\d+)$/', $poleId, $m)) {
        $n = (int) $m[1];
        if ($n === 1) return 1;
        if ($n === 2) return 2;
    }
    return 9;
}

/** "HH:MM" or "HH:MM:SS" を TIME 文字列へ。空は null。 */
function shimabus_time($v): ?string
{
    $v = trim((string) $v);
    return preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v) ? $v : null;
}

/* ================================================================== */
/*  起点バス停を始発とする便を取り込む                                  */
/* ================================================================== */
function handle_shimabus_import_route(): void
{
    require_admin();

    $origin = (string) require_param('origin');
    if (!preg_match('/^\d+$/', $origin)) {
        json_err('bad_origin', 'origin は数値のバス停ID(=signage id)で指定してください。', 422);
    }
    $targetDate = (string) param('target_date', date('Ymd'));
    if (!preg_match('/^\d{8}$/', $targetDate)) {
        json_err('bad_date', 'target_date は YYYYMMDD で指定してください。', 422);
    }
    $mode  = (string) param('mode', 'preview');
    // 任意: 系統番号で絞り込み。起点signageが空の系統(例 山羊島 i=330 の系統2801)を、
    //       経路途中のハブ停(例 和光園前 80)を origin に指定して取り込むための絞り込み。
    $keito = (string) param('keito', '');
    if ($keito !== '' && !preg_match('/^\d+$/', $keito)) {
        json_err('bad_keito', 'keito は系統番号(数値)で指定してください。', 422);
    }

    /* ---- 1) 取得（preview/commit 共通） ---- */
    $base = shimabus_post('signagebase', ['id' => $origin]);
    $originName = (string) ($base['stop_name'] ?? '');
    $service    = (string) ($base['service'] ?? '');
    $trips      = is_array($base['trips'] ?? null) ? $base['trips'] : [];
    usleep(SHIMABUS_SLEEP_US);

    $tripData = [];   // trip_key => ['route_key','route_name','headsign','stops'=>[...]]
    foreach ($trips as $t) {
        $tripKey = (string) ($t['trip_id'] ?? '');
        if ($tripKey === '') continue;
        // keito 指定時は該当系統のみ（系統2801 が 系統28011 等に部分一致しないよう後方境界を付ける）
        if ($keito !== '' && !preg_match('/系統' . $keito . '(?!\d)/u', $tripKey)) continue;
        $d = shimabus_post('tripdetail', ['trip_id' => $tripKey, 'target_date' => $targetDate]);
        usleep(SHIMABUS_SLEEP_US);
        $stops = [];
        foreach (($d['data'] ?? []) as $s) {
            $stops[] = [
                'seq'        => (int) ($s['stop_sequence'] ?? 0),
                'busstop_id' => (string) ($s['busstop_id'] ?? ''),
                'stop_id'    => (string) ($s['stop_id'] ?? ''),   // 標柱ID
                'stop_name'  => (string) ($s['stop_name'] ?? ''),
                'arr'        => shimabus_time($s['arrival_time'] ?? ''),
                'dep'        => shimabus_time($s['departure_time'] ?? ''),
            ];
        }
        $tripData[$tripKey] = [
            'route_key'  => (string) ($t['route_id'] ?? ''),
            'route_name' => (string) ($t['route'] ?? ''),
            'headsign'   => (string) ($t['headsign'] ?? ''),
            'stops'      => $stops,
        ];
    }

    // 出現する全バス停の緯度経度（標柱別）
    $busstopIds = [];
    foreach ($tripData as $td) {
        foreach ($td['stops'] as $s) {
            if ($s['busstop_id'] !== '') $busstopIds[$s['busstop_id']] = true;
        }
    }
    $busstopIds = array_keys($busstopIds);

    $poles = [];  // pole stop_id => ['name','kana','dir','lat','lon','busstop_id']
    foreach ($busstopIds as $bid) {
        $b = shimabus_post('busstop', ['id' => $bid]);
        usleep(SHIMABUS_SLEEP_US);
        foreach (($b['stops'] ?? []) as $poleId => $p) {
            $poles[(string) $poleId] = [
                'name'       => (string) ($p['stop_name'] ?? ($b['stop_name'] ?? '')),
                'kana'       => (string) ($p['hrkt'] ?? ($b['hrkt'] ?? '')),
                'dir'        => shimabus_dir_from_pole((string) $poleId),
                'lat'        => isset($p['stop_lat']) ? (float) $p['stop_lat'] : null,
                'lon'        => isset($p['stop_lon']) ? (float) $p['stop_lon'] : null,
                'busstop_id' => (string) $bid,
            ];
        }
    }

    // i= 停留所フォールバック: busstop.cgi が標柱(座標)を返さない停留所(例 山羊島ホテル前 i=330)は、
    // tripdetail の停留所情報から標柱を補う(座標はNULL)。これが無いと起点停等が欠落する。
    $poleFallback = 0;
    foreach ($tripData as $td) {
        foreach ($td['stops'] as $s) {
            $pid = $s['stop_id'];
            if ($pid !== '' && !isset($poles[$pid])) {
                $poles[$pid] = [
                    'name'       => $s['stop_name'],
                    'kana'       => '',
                    'dir'        => shimabus_dir_from_pole($pid),
                    'lat'        => null,
                    'lon'        => null,
                    'busstop_id' => $s['busstop_id'],
                ];
                $poleFallback++;
            }
        }
    }

    // 運賃（始発=stop_sequence 1 から）
    // ※ fare.cgi は target_date が「YYYY-MM-DD」でないと内部エラー(ok:false)を返す。
    //   tripdetail 等の YYYYMMDD とは形式が異なるので必ず変換する。
    //   応答は fare が stop_sequence をキーにした連想配列: {"11":{stop_sequence,price,stop_id,...}, ...}
    $fareDate = (new DateTime($targetDate))->format('Y-m-d');
    $fares = [];  // trip_key => [ ['to_seq','to_stop_id','to_busstop_id','price'], ... ]
    $fareErrors = [];
    foreach ($tripData as $tripKey => $td) {
        $f = shimabus_post('fare', ['trip_id' => $tripKey, 'stop_sequence' => 1, 'target_date' => $fareDate]);
        usleep(SHIMABUS_SLEEP_US);
        if (isset($f['ok']) && !$f['ok']) {   // 取得失敗は記録して次へ(取込全体は止めない)
            $fareErrors[$tripKey] = (string) ($f['errmsg'] ?? 'unknown');
            $fares[$tripKey] = [];
            continue;
        }
        $rows = [];
        foreach (($f['fare'] ?? []) as $seqKey => $x) {
            if (!is_array($x)) continue;
            $rows[] = [
                'to_seq'        => (int) ($x['stop_sequence'] ?? $seqKey),   // キー側もフォールバックに使う
                'to_stop_id'    => (string) ($x['stop_id'] ?? ''),
                'to_busstop_id' => (string) ($x['busstop_id'] ?? ''),
                'price'         => (int) ($x['price'] ?? 0),
            ];
        }
        $fares[$tripKey] = $rows;
    }

    $summary = [
        'origin'        => $origin,
        'origin_name'   => $originName,
        'keito'         => $keito ?: null,
        'service'       => $service,
        'target_date'   => $targetDate,
        'trips'         => count($tripData),
        'busstops'      => count($busstopIds),
        'poles'         => count($poles),
        'pole_fallback' => $poleFallback, // i=停留所等の座標なし補完数
        'fare_rows'     => array_sum(array_map('count', $fares)),
        'fare_errors'   => count($fareErrors),                       // 運賃が取れなかった便の数
        'fare_error_sample' => array_slice($fareErrors, 0, 3, true), // 原因確認用(先頭3件)
    ];

    if ($mode !== 'commit') {
        json_ok(['mode' => 'preview', 'summary' => $summary, 'committed' => false]);
    }

    /* ---- 2) 書込（commit） ---- */
    $db = pdo();
    $db->beginTransaction();
    try {
        // 取り込みバッチ
        $db->prepare(
            'INSERT INTO src_import_batch (target_date, service_name, scope_note, stop_count, trip_count, fetched_at)
             VALUES (?,?,?,?,?,NOW())'
        )->execute([
            (new DateTime($targetDate))->format('Y-m-d'),
            $service ?: null,
            'origin:' . $origin . ($keito !== '' ? ' keito:' . $keito : ''),
            count($busstopIds),
            count($tripData),
        ]);
        $batchId = (int) $db->lastInsertId();

        // m_busstop UPSERT（標柱単位）＋ pole→busstop_id 解決
        $upBs = $db->prepare(
            'INSERT INTO m_busstop (name, direction, kana, src_stop_id, src_busstop_id, latitude, longitude)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                kana = VALUES(kana), src_busstop_id = VALUES(src_busstop_id),
                latitude = VALUES(latitude), longitude = VALUES(longitude),
                src_stop_id = VALUES(src_stop_id)'
        );
        $selBs = $db->prepare('SELECT busstop_id FROM m_busstop WHERE src_stop_id = ?');
        $poleToBusstop = [];  // pole stop_id => m_busstop.busstop_id
        foreach ($poles as $poleId => $p) {
            $upBs->execute([$p['name'], $p['dir'], $p['kana'] ?: null, $poleId, $p['busstop_id'], $p['lat'], $p['lon']]);
            $selBs->execute([$poleId]);
            $poleToBusstop[$poleId] = (int) $selBs->fetchColumn();
        }

        // src_route / src_trip / src_trip_stop / src_fare
        $selRoute = $db->prepare('SELECT src_route_id FROM src_route WHERE route_key = ?');
        $insRoute = $db->prepare('INSERT INTO src_route (route_key, route_name) VALUES (?,?)
                                  ON DUPLICATE KEY UPDATE route_name = VALUES(route_name)');
        $selTrip  = $db->prepare('SELECT src_trip_id FROM src_trip WHERE trip_key = ? AND target_date = ?');
        $insTrip  = $db->prepare(
            'INSERT INTO src_trip (trip_key, src_route_id, keito_no, service_name, headsign, origin_busstop_id, first_departure, target_date, import_batch_id)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                src_route_id = VALUES(src_route_id), keito_no = VALUES(keito_no), service_name = VALUES(service_name),
                headsign = VALUES(headsign), origin_busstop_id = VALUES(origin_busstop_id),
                first_departure = VALUES(first_departure), import_batch_id = VALUES(import_batch_id)'
        );
        $delStops = $db->prepare('DELETE FROM src_trip_stop WHERE src_trip_id = ?');
        $insStop  = $db->prepare('INSERT INTO src_trip_stop (src_trip_id, seq, busstop_id, arrival_time, departure_time) VALUES (?,?,?,?,?)');
        $delFare  = $db->prepare('DELETE FROM src_fare WHERE src_trip_id = ? AND from_seq = 1');
        $insFare  = $db->prepare('INSERT INTO src_fare (src_trip_id, from_seq, to_seq, to_busstop_id, price) VALUES (?,1,?,?,?)');

        $tgt = (new DateTime($targetDate))->format('Y-m-d');
        $fareSkipped = 0;   // 標柱未解決などで登録できなかった運賃行
        foreach ($tripData as $tripKey => $td) {
            // route
            $insRoute->execute([$td['route_key'], $td['route_name'] ?: null]);
            $selRoute->execute([$td['route_key']]);
            $routeId = (int) $selRoute->fetchColumn();

            // origin(seq=1)の標柱→busstop_id
            $originBusstopId = null;
            $firstDep = null;
            foreach ($td['stops'] as $s) {
                if ($s['seq'] === 1) {
                    $originBusstopId = $poleToBusstop[$s['stop_id']] ?? null;
                    $firstDep = $s['dep'] ?? $s['arr'];
                    break;
                }
            }

            // trip（trip_idから系統番号を抽出: 例「…_系統3403」→3403）
            $keito = preg_match('/系統(\d+)/u', $tripKey, $mk) ? $mk[1] : null;
            $insTrip->execute([$tripKey, $routeId, $keito, $service ?: null, $td['headsign'] ?: null, $originBusstopId, $firstDep, $tgt, $batchId]);
            $selTrip->execute([$tripKey, $tgt]);
            $tripId = (int) $selTrip->fetchColumn();

            // stops（置換）
            $delStops->execute([$tripId]);
            foreach ($td['stops'] as $s) {
                $bsId = $poleToBusstop[$s['stop_id']] ?? null;
                if ($bsId === null) continue; // 標柱未解決はスキップ（通常起きない）
                $insStop->execute([$tripId, $s['seq'], $bsId, $s['arr'], $s['dep']]);
            }

            // fare（置換, from_seq=1）
            // 標柱→busstop_id が解決できない運賃行はスキップ(停留所ループと同様)。
            // 未解決のまま NULL/0 を入れると to_busstop_id の制約違反でDBエラーになる。
            $delFare->execute([$tripId]);
            foreach (($fares[$tripKey] ?? []) as $fr) {
                $bsId = $poleToBusstop[$fr['to_stop_id']] ?? null;
                if (empty($bsId)) {                                  // null / 0 はスキップ
                    if (!empty($fr['to_busstop_id'])) {              // 応答の busstop_id で代替できる場合のみ使う
                        $bsId = (int) $fr['to_busstop_id'];
                    } else {
                        $fareSkipped++; continue;
                    }
                }
                if ((int) $fr['to_seq'] <= 0) { $fareSkipped++; continue; }
                $insFare->execute([$tripId, (int) $fr['to_seq'], $bsId, (int) $fr['price']]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $summary['fare_skipped'] = $fareSkipped;   // 登録できなかった運賃行(標柱未解決など)
    json_ok(['mode' => 'commit', 'summary' => $summary, 'committed' => true, 'import_batch_id' => $batchId]);
}
