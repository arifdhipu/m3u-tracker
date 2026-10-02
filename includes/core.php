<?php
if (!defined('ABSPATH')) exit;

/* ---------- Basic helpers ---------- */
function m3ut_t($n) { global $wpdb; return $wpdb->prefix . 'm3u_' . $n; }
function m3ut_now() { return current_time('mysql'); }            // WordPress timezone
function m3ut_ts() { return strtotime(m3ut_now() . ' UTC'); }
function m3ut_ago($sec) { return gmdate('Y-m-d H:i:s', m3ut_ts() - (int) $sec); }
function m3ut_today() { return substr(m3ut_now(), 0, 10); }
function m3ut_plus_days($date, $d) { return gmdate('Y-m-d', strtotime($date . ' UTC') + ((int) $d) * 86400); }
function m3ut_days_left($date) { return (int) floor((strtotime($date . ' UTC') - strtotime(m3ut_today() . ' UTC')) / 86400); }

/* ---------- Settings ---------- */
function m3ut_defaults() {
    return [
        'allow_direct'     => 1,
        'unknown_mode'     => 'log',      // log | block
        'blocked_msg'      => 'Link disabled - contact admin',
        'blocked_url'      => '',
        'rate_minutes'     => 5,
        'go_mode'          => 0,
        'hide_dead'        => 0,
        'auto_scan'        => 1,
        'channels_path'    => '',
        'link_base'        => '',
        'geo_provider'     => 'ipapi',    // ipapi | ipwho | none
        'ipapi_key'        => '',
        'tg_token'         => '',
        'tg_chat'          => '',
        'alert_email'      => '',
        'al_first_hit'     => 1,
        'al_spike'         => 1,
        'al_sharing'       => 1,
        'al_dead'          => 1,
        'weekly_report'    => 0,
        'spike_min'        => 20,
        'spike_mult'       => 3,
        'default_ip_limit' => 3,
        'retention_months' => 6,
        'scan_interval_hours' => 3, // auto dead-scan koto ghonta por por cholbe (2/3/4/6/12/24)
                'scan_at_hour'     => 0,
        'token_ttl_hours'  => 24,   // go-link koto ghonta por expire hobe (link kew copy kore rakhle beshi din chalbe na)
        'token_ip_bind'    => 0,    // on hole go-link shudhu shei IP theke-i kaj korbe jei IP playlist niyechilo
        'link_protection'  => 1,    // off korle go_mode on thakleo TTL/signature check bondho hoye jabe (khali redirect+stats cholbe)
        'link_expiry'      => 1,    // off korle go-link kokhono expire hobe na (signature check tobuo cholbe, shudhu TTL/deadline bondho)
        'hide_origin'      => 0,    // on hole go-link ashol upstream-e redirect na kore ekta standalone seg.php-e pathabe, jeta WP load na kore stream relay kore — origin URL kokhono client dekhbe na, ar WP site-eo extra load pore na
        'seg_base_url'     => '',   // faka = auto (tv.php-er pashe seg.php). hide_origin mode-e standalone proxy file-er URL
        'seg_secret_path'  => '',   // faka = auto (ABSPATH/m3ut-secret.php). seg.php ei file theke decrypt-secret porbe
    ];
}
function m3ut_settings() { return wp_parse_args(get_option('m3ut_settings', []), m3ut_defaults()); }
function m3ut_link_base() { $s = m3ut_settings(); return $s['link_base'] ? $s['link_base'] : home_url('/tv.php'); }
function m3ut_link($args) { return add_query_arg($args, m3ut_link_base()); }
// hide_origin mode-e ei standalone (non-WordPress) file-e redirect kora hoy — override na dile tv.php-er pashe seg.php dhore newa hoy
function m3ut_seg_base() {
    $s = m3ut_settings();
    if (!empty($s['seg_base_url'])) return $s['seg_base_url'];
    $base = m3ut_link_base();
    if (strpos($base, 'tv.php') !== false) return str_replace('tv.php', 'seg.php', $base);
    return home_url('/seg.php');
}

/* ---------- go-link token: expiry + (optional) IP-bind, HMAC diye sign kora, kono extra DB table lage na ---------- */
function m3ut_link_secret() {
    $sec = get_option('m3ut_link_secret');
    if (!$sec) {
        $sec = wp_generate_password(40, false, false);
        update_option('m3ut_link_secret', $sec, false);
    }
    return $sec;
}
// $ip khali string dile IP-bind off dhora hobe (jekono IP theke cholbe)
function m3ut_token_sign($hash, $exp, $src, $key, $ip = '') {
    $data = $hash . '|' . (int) $exp . '|' . $src . '|' . $key . '|' . $ip;
    return substr(hash_hmac('sha256', $data, m3ut_link_secret()), 0, 24);
}
function m3ut_token_valid($hash, $exp, $sig, $src, $key, $ip = '', $enforce_expiry = true) {
    $exp = (int) $exp;
    if ($enforce_expiry && ($exp <= 0 || $exp < time())) return false;
    if ($sig === '') return false;
    $expected = m3ut_token_sign($hash, $exp, $src, $key, $ip);
    return hash_equals($expected, (string) $sig);
}

/* ---------- hide_origin mode: real stream URL kokhono client-ke pathano hoy na,
   ei URL ta ekta encrypted token-e (AES-256-CBC, site secret diye) lukiye "seg.php?u=…" banano hoy.
   Token decrypt korte site-er secret lagbe, tai keu URL/token dekheo ashol upstream ber korte parbe na.
   Ei encode/decode WP-e (playlist banano/debug-er jonno) rakha hoyeche; ashol proxy request-gulo
   standalone seg.php nijei decode kore — WP-ke ottokkhon busy thakte hoy na. ---------- */
function m3ut_seg_key() { return hash('sha256', m3ut_link_secret(), true); }
function m3ut_seg_iv()  { return substr(hash('sha256', 'm3ut_seg_iv|' . m3ut_link_secret(), true), 0, 16); }
function m3ut_seg_encode($url) {
    if (!function_exists('openssl_encrypt')) return '';
    $enc = openssl_encrypt((string) $url, 'aes-256-cbc', m3ut_seg_key(), OPENSSL_RAW_DATA, m3ut_seg_iv());
    if ($enc === false) return '';
    return rtrim(strtr(base64_encode($enc), '+/', '-_'), '=');
}
function m3ut_seg_decode($tok) {
    if (!function_exists('openssl_decrypt') || $tok === '') return '';
    $b64 = strtr($tok, '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    $raw = base64_decode($b64, true);
    if ($raw === false) return '';
    $url = openssl_decrypt($raw, 'aes-256-cbc', m3ut_seg_key(), OPENSSL_RAW_DATA, m3ut_seg_iv());
    return $url === false ? '' : $url;
}

// seg.php (standalone, WP-hin) file-tar decrypt-secret dorkar — WP-er secret-i ekta chhoto plain PHP
// file-e likhe rakhi jate seg.php DB/WP na chhuyeo shei-i secret pore token decode korte pare.
function m3ut_seg_secret_path() {
    $s = m3ut_settings();
    $p = trim((string) $s['seg_secret_path']);
    if ($p === '') return ABSPATH . 'm3ut-secret.php';
    if ($p[0] !== '/' && !preg_match('~^[A-Za-z]:~', $p)) $p = ABSPATH . $p;
    return $p;
}
function m3ut_sync_secret_file() {
    $path = m3ut_seg_secret_path();
    $content = "<?php\n// Auto-generated by M3U Tracker plugin — seg.php ei file theke decrypt-secret pore.\nreturn " . var_export(m3ut_link_secret(), true) . ";\n";
    $cur = is_readable($path) ? @file_get_contents($path) : false;
    if ($cur === $content) return true; // already up-to-date, proti request-e disk write kori na
    return (bool) @file_put_contents($path, $content);
}

/* ---------- channels file ---------- */
// channels_path ekta remote link (http/https) hole true - extension (.m3u/.m3u8/.php ba kichu nei) kono bepar na
function m3ut_is_remote_path($p) {
    return (bool) preg_match('~^https?://~i', trim((string) $p));
}
function m3ut_channels_file() {
    $s = m3ut_settings();
    $p = trim($s['channels_path']);
    if ($p !== '') {
        if (m3ut_is_remote_path($p)) return $p; // remote .m3u/.m3u8/.php/extension-chhara link
        if ($p[0] !== '/' && !preg_match('~^[A-Za-z]:~', $p)) $p = ABSPATH . $p;
        return $p;
    }
    foreach (['channels.txt', 'channels.m3u', 'channels.m3u8'] as $f) {
        if (file_exists(ABSPATH . $f)) return ABSPATH . $f;
    }
    return ABSPATH . 'channels.txt';
}

// Remote channels source fetch kore, 5 min cache rakhi (proti request-e remote server-e hit na jay).
// Remote server fail/faka dile purono kaj-kora copy return kore, jate playlist hothat faka na hoye jay.
function m3ut_fetch_remote_channels($url) {
    $ck = 'm3ut_remote_ch_' . md5($url);
    $c = get_transient($ck);
    if ($c !== false) return $c;
    $ok = get_option('m3ut_remote_ch_last_' . md5($url), null);
    $r = wp_remote_get($url, ['timeout' => 10, 'redirection' => 4, 'sslverify' => false, 'user-agent' => 'VLC/3.0.20 LibVLC/3.0.20']);
    if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) >= 400) return $ok;
    $body = (string) wp_remote_retrieve_body($r);
    if (trim($body) === '') return $ok;
    set_transient($ck, $body, 5 * MINUTE_IN_SECONDS);
    update_option('m3ut_remote_ch_last_' . md5($url), $body, false);
    return $body;
}

function m3ut_read_channels() {
    $f = m3ut_channels_file();
    if (m3ut_is_remote_path($f)) {
        $raw = m3ut_fetch_remote_channels($f);
        return $raw === null ? null : m3ut_parse((string) $raw);
    }
    if (!is_readable($f)) return null;
    return m3ut_parse((string) file_get_contents($f));
}

/* ---------- Parser: standard M3U + Name|Logo|Group|URL, mixed thakleo cholbe ---------- */
function m3ut_mk($head, $url) {
    $info = '';
    foreach ($head as $h) if (stripos($h, '#EXTINF') === 0) $info = $h;
    $name = ''; $logo = ''; $group = '';
    if ($info !== '') {
        if (preg_match('/^#EXTINF:(?:[^",]|"[^"]*")*,(.*)$/s', $info, $m)) {
            $name = trim($m[1]);
        } elseif (($p = strrpos($info, ',')) !== false) {
            $name = trim(substr($info, $p + 1));
        }
        if (preg_match('/tvg-logo="([^"]*)"/i', $info, $m)) $logo = $m[1];
        if (preg_match('/group-title="([^"]*)"/i', $info, $m)) $group = $m[1];
    } else {
        $host = parse_url($url, PHP_URL_HOST);
        $name = $host ? $host : 'Channel';
        $head[] = '#EXTINF:-1,' . $name;
    }
    if ($name === '') $name = 'Unknown';
    $uh = md5($url);
    return ['name' => $name, 'logo' => $logo, 'group' => $group, 'url' => $url, 'head' => $head, 'uh' => $uh, 'hash' => substr($uh, 0, 10)];
}

function m3ut_parse($raw) {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $lines = preg_split('/\r\n|\r|\n/', $raw);
        $chs = [];
    $pending = [];
    $no = 0; // channels.txt e ja order ache, shei onujayi serial number
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (stripos($line, '#EXTM3U') === 0) continue;
        if ($line[0] === '#') { $pending[] = $line; continue; }

                if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $line)) {   // stream URL line
            $c = m3ut_mk($pending, $line);
            $c['no'] = ++$no;
            $chs[] = $c;
            $pending = [];
            continue;
        }

        if (strpos($line, '|') === false) continue;             // pipe format
        $parts = explode('|', $line);
        $url = trim(array_pop($parts));
        if ($url === '' || empty($parts)) continue;
        $name = 'Unknown Channel'; $logo = ''; $group = '';
        $n = count($parts);
        if ($n === 1) {
            $name = trim($parts[0]);
        } elseif ($n === 2) {
            $name = trim($parts[0]);
            $mid = trim($parts[1]);
            if (preg_match('~^https?://~i', $mid)) $logo = $mid; else $group = $mid;
        } else {
            $group = trim(array_pop($parts));
            $logo  = trim(array_pop($parts));
            $name  = trim(implode('|', $parts));
        }
        if ($name === '') $name = 'Unknown Channel';
        $x = '#EXTINF:-1';
        if ($logo !== '')  $x .= ' tvg-logo="' . str_replace('"', '', $logo) . '"';
        if ($group !== '') $x .= ' group-title="' . str_replace('"', '', $group) . '"';
                $x .= ',' . $name;
        $c = m3ut_mk([$x], $url);
        $c['no'] = ++$no;
        $chs[] = $c;
        $pending = [];
    }
    return $chs;
}

/* ---------- Parsed array theke abar raw channels.txt banano (add/edit/delete er por save korte) ---------- */
function m3ut_channels_to_raw($chs) {
    $out = "#EXTM3U\n";
    foreach ($chs as $c) {
        foreach ($c['head'] as $h) $out .= $h . "\n";
        $out .= $c['url'] . "\n";
    }
    return $out;
}

/* ---------- Channel-wise play count + approx watch-time ---------- */
// Note: stream sorasori provider theke customer-er kache jay (WordPress er moddhe diye jay na),
// tai exact watch-duration WP theke mapa jay na. Ekhane IP-wise consecutive channel-switch-er
// somoyer gap diye ekta approximate/estimate hishab kora hoy.
function m3ut_channel_watch_stats($from, $to, $source = '') {
    global $wpdb;
    $t = m3ut_t('chits');
    $where = $wpdb->prepare("WHERE viewed_at BETWEEN %s AND %s", $from . ' 00:00:00', $to . ' 23:59:59');
    if ($source !== '') $where .= $wpdb->prepare(' AND source=%s', $source);
    $rows = $wpdb->get_results("SELECT chash, cname, cgroup, ip, viewed_at FROM $t $where ORDER BY ip ASC, viewed_at ASC LIMIT 20000");

    $max_gap = 4 * HOUR_IN_SECONDS;      // er beshi gap hole notun/alada session dhora hobe
    $default_est = 6 * MINUTE_IN_SECONDS; // session-er shesh chit ba boro gap-er jonno approx dhora hoy

    $stat = []; // chash => [name, group, plays, ips, seconds]
    $prev = null;
    foreach ((array) $rows as $r) {
        if (!isset($stat[$r->chash])) $stat[$r->chash] = ['name' => $r->cname, 'group' => $r->cgroup, 'plays' => 0, 'ips' => [], 'seconds' => 0];
        $stat[$r->chash]['plays']++;
        $stat[$r->chash]['ips'][$r->ip] = 1;

        if ($prev && $prev->ip === $r->ip) {
            $gap = strtotime($r->viewed_at . ' UTC') - strtotime($prev->viewed_at . ' UTC');
            $stat[$prev->chash]['seconds'] += ($gap > 0 && $gap <= $max_gap) ? $gap : $default_est;
        }
        $prev = $r;
    }
    if ($prev) $stat[$prev->chash]['seconds'] += $default_est; // proti IP-r shesh chit

    $out = [];
    foreach ($stat as $k => $v) {
        $out[] = (object) ['chash' => $k, 'cname' => $v['name'], 'cgroup' => $v['group'], 'plays' => $v['plays'], 'unique' => count($v['ips']), 'seconds' => $v['seconds']];
    }
    usort($out, function ($a, $b) { return $b->plays <=> $a->plays; });
    return $out;
}

function m3ut_fmt_duration($sec) {
    $sec = max(0, (int) $sec);
    $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    if ($m > 0) return $m . 'm';
    return $sec . 's';
}

/* ---------- Playlist builder ---------- */
function m3ut_build($chs, $ctx = []) {
    global $wpdb;
    $s = m3ut_settings();
    $dead = [];
    if (!empty($s['hide_dead'])) {
        $dead = array_flip((array) $wpdb->get_col("SELECT url_hash FROM " . m3ut_t('links') . " WHERE status='dead'"));
    }
    $go = !empty($s['go_mode']);
    $protect = !empty($s['link_protection']); // off hole go-link-e TTL/signature check bondho thakbe
    $q = [];
    if ($go) {
        if (!empty($ctx['src']) && $ctx['src'] !== 'direct') $q['src'] = $ctx['src'];
        if (!empty($ctx['key'])) $q['key'] = $ctx['key'];
    }
    $ttl_sec = max(1, (int) $s['token_ttl_hours']) * HOUR_IN_SECONDS;
    $bind_ip = !empty($s['token_ip_bind']) && !empty($ctx['ip']) ? $ctx['ip'] : '';
    $exp = time() + $ttl_sec; // ekই playlist-er shob channel-er jonno ekই expiry — playlist reload dile notun expiry pabe
    $ctx_src = isset($ctx['src']) ? $ctx['src'] : '';
    $ctx_key = isset($ctx['key']) ? $ctx['key'] : '';
    $out = "#EXTM3U\n";
    foreach ($chs as $c) {
        if (isset($dead[$c['uh']])) continue;
        foreach ($c['head'] as $h) $out .= $h . "\n";
        $url = $c['url'];
        if ($go && preg_match('~^https?://~i', $url)) {
            $params = array_merge(['go' => $c['hash']], $q);
            if ($protect) { // protection off thakle exp/sig link-e jog kori na, tv.php-o check korbe na (niche m3ut_go dekhun)
                $sig = m3ut_token_sign($c['hash'], $exp, $ctx_src, $ctx_key, $bind_ip);
                $params = array_merge($params, ['exp' => $exp, 'sig' => $sig]);
            }
            $url = add_query_arg($params, home_url('/tv.php'));
        }
        $out .= $url . "\n";
    }
    return $out;
}

/* ---------- Unique IP count (sharing detect) ---------- */
function m3ut_ip_counts($col, $sec) {
    global $wpdb;
    if (!in_array($col, ['key_code', 'source'], true)) return [];
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT $col AS k, COUNT(DISTINCT ip) AS n FROM " . m3ut_t('logs') . " WHERE status='ok' AND viewed_at >= %s GROUP BY $col",
        m3ut_ago($sec)
    ));
    $o = [];
    foreach ((array) $rows as $r) $o[$r->k] = (int) $r->n;
    return $o;
}


// Fixed-hour mode: ajker scan ki ekhono baki? (target ghonta pouchhe gele o ajker scan na hole true)
function m3ut_autoscan_due() {
    $s = m3ut_settings();
    $h = (int) $s['scan_at_hour'];
    if (empty($s['auto_scan']) || $h < 1) return false;
    $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    if ((int) $now->format('G') < $h) return false;
    return get_option('m3ut_autoscan_last', '') !== $now->format('Y-m-d');
}