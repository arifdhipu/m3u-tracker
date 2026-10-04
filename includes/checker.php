<?php
if (!defined('ABSPATH')) exit;

/** Return [result('ok'|'dead'|'skip'), http_code, error] */
function m3ut_check_url($url) {
    if (!preg_match('~^https?://~i', $url)) return ['skip', 0, 'non-http (rtmp/udp etc)'];

    $body = '';
    $code = 0; $errno = 0; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'VLC/3.0.20 LibVLC/3.0.20',
            CURLOPT_ENCODING       => '',
            // shudhu prothom 2KB porbo, live stream download korbo na
            CURLOPT_WRITEFUNCTION  => function ($c, $d) use (&$body) {
                $body .= $d;
                return strlen($body) >= 2048 ? 0 : strlen($d);
            },
        ]);
        curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);
    } else {
        $r = wp_remote_get($url, ['timeout' => 8, 'redirection' => 4, 'sslverify' => false, 'limit_response_size' => 2048, 'user-agent' => 'VLC/3.0.20 LibVLC/3.0.20']);
        if (is_wp_error($r)) { $err = $r->get_error_message(); }
        else { $code = (int) wp_remote_retrieve_response_code($r); $body = (string) wp_remote_retrieve_body($r); }
    }

    if ($code === 0) return ['dead', 0, $err ? $err : 'no response'];
    if ($code >= 400) return ['dead', $code, 'HTTP ' . $code];

    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    if (strpos($path, '.m3u8') !== false) {
        if ($body === '') return ['dead', $code, 'empty response'];
        if (stripos($body, '#EXT') === false) return ['dead', $code, 'not a valid m3u8'];
    } elseif (strpos($path, '.mpd') !== false) {
        if ($body === '') return ['dead', $code, 'empty response'];
        if (stripos($body, '<MPD') === false) return ['dead', $code, 'not a valid mpd'];
    }
    return ['ok', $code, ''];
}

function m3ut_scan_start() {
    global $wpdb;
    $chs = m3ut_read_channels();
    if ($chs === null) return false;
    $t = m3ut_t('links');
    $queue = []; $seen = [];
    foreach ($chs as $c) {
        if (isset($seen[$c['uh']])) continue;
        $seen[$c['uh']] = 1;
                $wpdb->query($wpdb->prepare(
            "INSERT INTO $t (url_hash, chash, cname, cgroup, url, seq, status) VALUES (%s, %s, %s, %s, %s, %d, 'unknown')
             ON DUPLICATE KEY UPDATE chash=VALUES(chash), cname=VALUES(cname), cgroup=VALUES(cgroup), seq=VALUES(seq)",
            $c['uh'], $c['hash'], substr($c['name'], 0, 190), substr($c['group'], 0, 100), $c['url'], isset($c['no']) ? (int) $c['no'] : 0
        ));
        $queue[] = $c['uh'];
    }
        m3ut_dup_boot();
    foreach (m3ut_ds_get() as $c) {   // Duplicate Section-er channel gulo-o scan hobe (alada status, Dead checker list-e dekhabe na)
        if (isset($seen[$c['uh']])) continue;
        $seen[$c['uh']] = 1;
        $queue[] = $c['uh'];
    }
    if ($queue) {
        // list theke soriye fela channel-er row muche dei
        $in = "'" . implode("','", $queue) . "'";
        $wpdb->query("DELETE FROM $t WHERE url_hash NOT IN ($in)");
    }
    update_option('m3ut_scan', ['queue' => $queue, 'total' => count($queue), 'started' => m3ut_now(), 'finished' => '', 'retry' => [], 'newdead' => []], false);
    return true;
}

function m3ut_scan_state() {
    $st = get_option('m3ut_scan', []);
    return is_array($st) ? $st : [];
}

function m3ut_scan_batch($n = 6) {
    global $wpdb;
    $st = m3ut_scan_state();
    if (empty($st['queue'])) return $st;
    if (get_transient('m3ut_scan_lock')) return $st;
    set_transient('m3ut_scan_lock', 1, 45);

    $t0 = microtime(true);
    $t = m3ut_t('links');
    for ($i = 0; $i < $n && !empty($st['queue']) && (microtime(true) - $t0) < 20; $i++) {
        $h = array_shift($st['queue']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE url_hash=%s", $h));
                if (!$row) { m3ut_dup_boot(); m3ut_ds_check($h, $st); continue; } // Duplicate Section-er channel hole alada check
        list($res, $code, $err) = m3ut_check_url($row->url);
        $now = m3ut_now();
        if ($res === 'ok') {
            $wpdb->update($t, ['status' => 'ok', 'http_code' => $code, 'fail_count' => 0, 'error' => '', 'last_checked' => $now, 'last_ok' => $now], ['id' => $row->id]);
                        $wpdb->insert(m3ut_t('checks'), ['url_hash' => $h, 'ok' => 1, 'checked_at' => $now]);
        } elseif ($res === 'skip') {
            $wpdb->update($t, ['status' => 'skip', 'error' => $err, 'last_checked' => $now], ['id' => $row->id]);
        } else {
            // false alarm kamate: prothom fail-e list-er shesh-e ekbar retry
            if (empty($st['retry'][$h])) { $st['retry'][$h] = 1; $st['queue'][] = $h; continue; }
            $wpdb->update($t, ['status' => 'dead', 'http_code' => $code, 'fail_count' => ((int) $row->fail_count) + 1, 'error' => substr($err, 0, 180), 'last_checked' => $now], ['id' => $row->id]);
                        $wpdb->insert(m3ut_t('checks'), ['url_hash' => $h, 'ok' => 0, 'checked_at' => $now]);
            if ($row->status !== 'dead') $st['newdead'][] = $row->cname;
        }
    }

    if (empty($st['queue'])) {
        $st['finished'] = m3ut_now();
        $s = m3ut_settings();
        if (!empty($st['newdead']) && !empty($s['al_dead'])) {
            $total_dead = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='dead'");
            $names = array_slice(array_unique($st['newdead']), 0, 20);
            m3ut_alert("💀 Notun dead channel (" . count($st['newdead']) . "ta)\n- " . implode("\n- ", $names) . "\n\nMot dead ekhon: $total_dead", 'Dead channels found');
        }
        $st['newdead'] = [];
                try { m3ut_dup_boot(); m3ut_dup_autosort(); } catch (\Throwable $e) {} // scan shesh: prothom active ta main-e, baki duplicate Section-e
    }
    update_option('m3ut_scan', $st, false);
    delete_transient('m3ut_scan_lock');
    return $st;
}

add_action('m3ut_cron_scan', function () { m3ut_scan_batch(12); });

/**
 * Background self-chaining scan: admin "Ekhoni scan korun" e click korar por
 * browser theke ekta link click kore chole gele-o (ba tab bondho korleo)
 * scan background-e automatic cholte thake.
 *
 * Kono external HTTP loopback-er upor nirbhor kore na (onek shared hosting-e
 * server nijeke nijei call korte pare na / block thake). Ebar client-ke response
 * pathiye connection bondho kore deoya hoy (fastcgi_finish_request), kintu SHOMOY
 * PHP process-ta background-e cholte thake ar shesh porjonto queue process kore
 * fele — ekhane loopback lagei na. Onek boro list-er jonno shesh-e loopback +
 * 5-minute recurring cron-o safety-net hisebe rekhe deya hoyeche.
 */
function m3ut_scan_drain($budget_sec = 45) {
    $t0 = microtime(true);
    do {
        $st = m3ut_scan_batch(12);
    } while (!empty($st['queue']) && (microtime(true) - $t0) < $budget_sec);
    return $st;
}

// "Start" click-e AJAX-e call hoy: client-ke ekhoni response pathiye dei, tarpor
// connection bondho kore-o process-ta background-e continue kore.
function m3ut_scan_run_background() {
    $st = m3ut_scan_state();
    while (ob_get_level() > 0) ob_end_clean();
    ignore_user_abort(true);
    @set_time_limit(0);
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(['success' => true, 'data' => [
        'total' => isset($st['total']) ? (int) $st['total'] : 0,
        'left'  => isset($st['queue']) ? count($st['queue']) : 0,
        'finished' => '',
    ]]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else @flush();

    // Client shonge connection ekhon bondho, browser tab bondho/link click hoyeo
    // kono effect nei — PHP process ekhono cholche.
    $st = m3ut_scan_drain(45);
    if (!empty($st['queue'])) m3ut_trigger_next_step(); // boro list hole loopback diye continue
    exit;
}

function m3ut_scan_token() {
    return substr(wp_hash('m3ut_scan_step_' . wp_salt()), 0, 20);
}

function m3ut_trigger_next_step() {
    $u = add_query_arg(['action' => 'm3ut_scan_step', 'tok' => m3ut_scan_token()], admin_url('admin-ajax.php'));
    wp_remote_post($u, ['timeout' => 0.5, 'blocking' => false, 'sslverify' => false, 'user-agent' => 'M3UTracker-BG']);
    if (function_exists('spawn_cron')) spawn_cron(); // WP core cron-o try kori, extra safety net
}

add_action('wp_ajax_m3ut_scan_step', 'm3ut_scan_step_endpoint');
add_action('wp_ajax_nopriv_m3ut_scan_step', 'm3ut_scan_step_endpoint'); // server nijei nijeke call kore, login thake na
function m3ut_scan_step_endpoint() {
    $tok = isset($_GET['tok']) ? sanitize_text_field(wp_unslash($_GET['tok'])) : '';
    if (!hash_equals(m3ut_scan_token(), $tok)) { status_header(403); exit; }
    ignore_user_abort(true);
    @set_time_limit(0);
    $st = m3ut_scan_drain(45);
    if (!empty($st['queue'])) m3ut_trigger_next_step();
    exit;
}


// Duplicate Section-er code admin-channels.php-te; cron/tv.php (non-admin) request-eo lage tai dorkar hole load kori
function m3ut_dup_boot() {
    if (!function_exists('m3ut_dup_autosort')) require_once M3UT_DIR . 'includes/admin-channels.php';
}