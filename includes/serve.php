<?php
if (!defined('ABSPATH')) exit;


if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        if (!function_exists('curl_init')) return ['body' => '', 'response' => ['code' => 0]];
        $t = isset($args['timeout']) ? (int) $args['timeout'] : 5;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $t, CURLOPT_CONNECTTIMEOUT => $t,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => isset($args['user-agent']) ? $args['user-agent'] : 'M3UTracker',
        ]);
        $b = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => ($b === false ? '' : $b), 'response' => ['code' => $code]];
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r) { return isset($r['body']) ? (string) $r['body'] : ''; }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($r) { return isset($r['response']['code']) ? (int) $r['response']['code'] : 0; }
}

function m3ut_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $l = explode(',', $_SERVER[$k]);
            $ip = trim($l[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

function m3ut_device($ua) {
    $u = strtolower((string) $ua);

    $players = [
        'tivimate' => 'TiviMate', 'ott navigator' => 'OTT Navigator', 'ottnavigator' => 'OTT Navigator',
        'smarters' => 'IPTV Smarters', 'kodi' => 'Kodi', 'vlc' => 'VLC Player', 'potplayer' => 'PotPlayer',
        'mxplayer' => 'MX Player', 'mx player' => 'MX Player', 'perfect player' => 'Perfect Player',
        'iptv extreme' => 'IPTV Extreme', 'gse iptv' => 'GSE IPTV', 'nplayer' => 'nPlayer', 'infuse' => 'Infuse',
        'stbemu' => 'STB Emulator', 'lavf' => 'FFmpeg Player', 'exoplayer' => 'Android App',
        'okhttp' => 'Android App', 'dalvik' => 'Android App', 'cfnetwork' => 'iOS App', 'iptv' => 'IPTV App',
    ];
    foreach ($players as $needle => $label) if (strpos($u, $needle) !== false) return $label;

    if (preg_match('/aftt|aftm|afts|afta|fire ?tv/', $u)) return 'Fire TV';
    if (preg_match('/appletv|tvos/', $u)) return 'Apple TV';
    if (preg_match('/android ?tv|googletv|bravia|mibox|shield/', $u)) return 'Android TV';
    if (preg_match('/roku|crkey|chromecast/', $u)) return 'Roku/Chromecast';
    if (preg_match('/smart-?tv|tizen|web0s|webos|netcast|hbbtv|vidaa|viera|aquos|philipstv|mag[0-9]{3}|\bstb\b/', $u)) return 'Smart TV';

    if (strpos($u, 'iphone') !== false || strpos($u, 'ipod') !== false) return 'iPhone';
    if (strpos($u, 'ipad') !== false) return 'iPad';
    if (strpos($u, 'android') !== false) {
        if (strpos($u, 'mobile') !== false) return 'Android Mobile';
        if (preg_match('/tablet|sm-t|sm-x|\btab\b|\bpad\b/', $u)) return 'Android Tablet';
        return 'Android';
    }

    $br = preg_match('/mozilla\/.*(chrome|firefox|safari|edg|opr|trident)/', $u) ? ' (Browser)' : '';
    if (strpos($u, 'windows') !== false) return 'Windows' . $br;
    if (strpos($u, 'macintosh') !== false || strpos($u, 'mac os') !== false) return 'Mac' . $br;
    if (strpos($u, 'cros') !== false) return 'Chromebook' . $br;
    if (strpos($u, 'linux') !== false) return 'Linux' . $br;
    return 'Unknown';
}

function m3ut_geo($ip) {
    $u = ['country' => 'Unknown', 'city' => 'Unknown', 'isp' => 'Unknown'];
    $s = m3ut_settings();
    if ($s['geo_provider'] === 'none') return $u;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return $u;

    $ck = 'm3ut_geo_' . md5($ip);
    $c = get_transient($ck);
    if ($c !== false) return $c;

    // ip-api free limit: 45/min, tai 40 er beshi lookup korbo na
    $bk = 'm3ut_geo_rl_' . gmdate('YmdHi');
    $n = (int) get_transient($bk);
    if ($n >= 40) return $u;
    set_transient($bk, $n + 1, 120);

    $geo = $u;
    if ($s['geo_provider'] === 'ipwho') {
        $r = wp_remote_get('https://ipwho.is/' . rawurlencode($ip), ['timeout' => 2]);
        if (!is_wp_error($r)) {
            $d = json_decode(wp_remote_retrieve_body($r), true);
            if (!empty($d['success'])) {
                $geo['country'] = sanitize_text_field(isset($d['country']) ? $d['country'] : 'Unknown');
                $geo['city']    = sanitize_text_field(isset($d['city']) ? $d['city'] : 'Unknown');
                $geo['isp']     = sanitize_text_field(isset($d['connection']['isp']) ? $d['connection']['isp'] : 'Unknown');
            }
        }
    } else {
        $fields = 'status,country,city,isp';
        $url = $s['ipapi_key']
            ? 'https://pro.ip-api.com/json/' . rawurlencode($ip) . '?fields=' . $fields . '&key=' . rawurlencode($s['ipapi_key'])
            : 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=' . $fields;
        $r = wp_remote_get($url, ['timeout' => 2]);
        if (!is_wp_error($r)) {
            $d = json_decode(wp_remote_retrieve_body($r), true);
            if (!empty($d['status']) && $d['status'] === 'success') {
                $geo['country'] = sanitize_text_field(isset($d['country']) ? $d['country'] : 'Unknown');
                $geo['city']    = sanitize_text_field(isset($d['city']) ? $d['city'] : 'Unknown');
                $geo['isp']     = sanitize_text_field(isset($d['isp']) ? $d['isp'] : 'Unknown');
            }
        }
    }
    $ok = $geo['country'] !== 'Unknown';
    set_transient($ck, $geo, $ok ? 30 * DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
    return $geo;
}

/**
 * Access check: src / key onujayi serve kora jabe kina.
 * status = ok | blocked
 */
function m3ut_resolve_access() {
    global $wpdb;
    $s = m3ut_settings();
    $today = m3ut_today();
    $src = isset($_GET['src']) ? sanitize_key(wp_unslash($_GET['src'])) : '';
    $key = isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '';
    $ctx = ['src' => 'direct', 'key' => '', 'status' => 'ok', 'reason' => '', 'rs' => null];
    $block = function ($why) use (&$ctx) { $ctx['status'] = 'blocked'; $ctx['reason'] = $why; };

    if ($key !== '') {
        $strict = false;
        $ctx['key'] = $key;
        $k = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . m3ut_t('keys') . " WHERE key_code=%s", $key));
        if (!$k) { $ctx['src'] = 'invalid_key'; $block('invalid key'); return $ctx; }
        $src = $k->reseller !== '' ? $k->reseller : 'direct';
        $ctx['src'] = $src;
        if ($k->status !== 'active') $block('key disabled');
        elseif ($k->expires_at && $k->expires_at < $today) $block('key expired');
        if ($ctx['status'] !== 'ok') return $ctx;
    } else {
        $strict = true;
        if ($src === '') $src = 'direct';
        $ctx['src'] = $src;
    }

    if ($src === 'direct') {
        if ($strict && empty($s['allow_direct'])) $block('direct disabled');
        return $ctx;
    }

    $rs = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . m3ut_t('resellers') . " WHERE slug=%s", $src));
    if (!$rs) {
        if ($strict) {
            $ctx['src'] = 'unknown';
            if ($s['unknown_mode'] === 'block') $block('unknown source');
        }
        return $ctx;
    }
    $ctx['rs'] = $rs;
    if ($rs->status !== 'active') $block('reseller disabled');
    elseif ($rs->expires_at && $rs->expires_at < $today) $block('reseller expired');
    return $ctx;
}

function m3ut_blocked_body() {
    $s = m3ut_settings();
    $u = $s['blocked_url'] ? $s['blocked_url'] : home_url('/');
    $m = str_replace(["\r", "\n"], ' ', $s['blocked_msg']);
    return "#EXTM3U\n#EXTINF:-1 group-title=\"Info\"," . $m . "\n" . $u . "\n";
}

// Response age pathiye dei, tarpor background e log kori (player ke geo API-er jonno wait korte hoy na)
function m3ut_send($body) {
    while (ob_get_level() > 0) ob_end_clean();
    ignore_user_abort(true);
    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: inline; filename="tv.m3u"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Robots-Tag: noindex');
    header('Content-Length: ' . strlen($body));
    echo $body;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else @flush();
}

function m3ut_log_hit($ctx, $ip, $ua) {
    global $wpdb;
    $s = m3ut_settings();
    $mins = (int) $s['rate_minutes'];
    if ($mins > 0) {
                $rk = 'm3ut_rl_' . md5($ip . '|' . $ua . '|' . $ctx['src'] . '|' . $ctx['key'] . '|' . $ctx['status']);
        if (get_transient($rk)) return;
        set_transient($rk, 1, $mins * MINUTE_IN_SECONDS);
    }
        try { $geo = m3ut_geo($ip); } catch (\Throwable $e) { $geo = ['country' => 'Unknown', 'city' => 'Unknown', 'isp' => 'Unknown']; }
    $wpdb->insert(m3ut_t('logs'), [
        'ip' => $ip, 'country' => $geo['country'], 'city' => $geo['city'], 'isp' => $geo['isp'],
        'device' => m3ut_device($ua), 'user_agent' => $ua, 'source' => $ctx['src'],
        'key_code' => $ctx['key'], 'status' => $ctx['status'], 'viewed_at' => m3ut_now(),
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    // Reseller-er prothom hit
    if ($ctx['status'] === 'ok' && !empty($ctx['rs']) && empty($ctx['rs']->first_hit_at)) {
        $wpdb->update(m3ut_t('resellers'), ['first_hit_at' => m3ut_now()], ['id' => $ctx['rs']->id]);
                if (!empty($s['al_first_hit'])) {
            try {
                m3ut_alert("NEW: Notun reseller-er prothom hit\nReseller: {$ctx['rs']->name} ({$ctx['rs']->slug})\nIP: $ip | {$geo['city']}, {$geo['country']}\nDevice: " . m3ut_device($ua));
            } catch (\Throwable $e) {}
        }
    }
}

// Channel-wise stats: playlist-e go link thakle ekhane ashe, log kore redirect kore
function m3ut_redirect_and_log($to, $ctx, $ip, $ua) {
    while (ob_get_level() > 0) ob_end_clean();
    ignore_user_abort(true);
    header('Cache-Control: no-store');
    header('Location: ' . $to, true, 302);
    header('Content-Length: 0');
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else @flush();
    m3ut_log_hit($ctx, $ip, $ua);
    exit;
}



function m3ut_go($chs) {
    global $wpdb;
    $s = m3ut_settings();
    $ctx = m3ut_resolve_access();
        $ip = sanitize_text_field(m3ut_ip());
    $ua = substr(sanitize_text_field(isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : 'Unknown'), 0, 255);
    while (ob_get_level() > 0) ob_end_clean();
    if ($ctx['status'] !== 'ok') {
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access disabled';
        exit;
    }
    $h = preg_replace('/[^a-f0-9]/', '', strtolower((string) wp_unslash($_GET['go'])));
    $protect = !empty($s['link_protection']); // off hole signature+expiry check bilkul skip hoye jay
    if ($protect) {
        $exp = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
        $sig = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';
        $ip  = m3ut_ip();
        $bind_ip = !empty($s['token_ip_bind']) ? $ip : '';
        $enforce_expiry = !empty($s['link_expiry']); // off hole link kokhono expire hobe na (signature check tobuo cholbe)
        if (!m3ut_token_valid($h, $exp, $sig, $ctx['src'], $ctx['key'], $bind_ip, $enforce_expiry)) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo ($enforce_expiry && $exp > 0 && $exp < time())
                                ? 'Ei link expire hoye geche - playlist notun kore reload/re-add korun (M3U app-e)'
                : 'Invalid link - playlist notun kore reload/re-add korun (M3U app-e)';
            exit;
        }
    }
    if ($h === '') {
        status_header(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Channel not found';
        exit;
    }
    foreach ($chs as $c) {
        if ($c['hash'] === $h) {
                        $chit_row = [
                'chash' => $c['hash'], 'cname' => substr($c['name'], 0, 190), 'cgroup' => substr($c['group'], 0, 100),
                'source' => substr($ctx['src'], 0, 60), 'ip' => $ip, 'viewed_at' => m3ut_now(),
                'ua_hash' => md5(mb_substr((string) $ua, 0, 150, 'UTF-8')),
            ];
            $chit_fmt = ['%s', '%s', '%s', '%s', '%s', '%s', '%s'];
            if ($wpdb->insert(m3ut_t('chits'), $chit_row, $chit_fmt) === false) {
                m3ut_col(m3ut_t('chits'), 'ua_hash', "CHAR(32) NOT NULL DEFAULT ''");
                $wpdb->insert(m3ut_t('chits'), $chit_row, $chit_fmt);
            }
            $url = str_replace([' ', "\r", "\n"], ['%20', '', ''], $c['url']);
            if (!empty($s['hide_origin'])) {
                // WP-er kaj ekhane-i shesh — ekta halka 302 diye nijer-i domain-er standalone seg.php-e pathiye dei.
                // seg.php WordPress LOAD KORE NA (DB/plugin kichu chhue na), tai stream jotokkhon-i cholok na keno,
                // WP site-er upor kono extra load pore na. Ashol upstream URL Location header-eo dekha jabe na
                // (Location-e nijer-i domain-er seg.php link thakbe).
                $prox = add_query_arg(['u' => m3ut_seg_encode($url)], m3ut_seg_base());
                                m3ut_redirect_and_log($prox, $ctx, $ip, $ua);
            }
                        m3ut_redirect_and_log($url, $ctx, $ip, $ua);
        }
    }
    status_header(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Channel not found';
    exit;
}

function m3ut_cron_ping() {
    if (!m3ut_autoscan_due()) return;
    if (get_transient('m3ut_cron_ping')) return;
    set_transient('m3ut_cron_ping', 1, 300);
    if (!function_exists('curl_init')) return;
    $ch = curl_init(home_url('/wp-cron.php?doing_wp_cron=' . time()));
    curl_setopt_array($ch, [
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_TIMEOUT_MS => 1000,
        CURLOPT_CONNECTTIMEOUT_MS => 700,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'M3UTracker-CronPing',
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function m3ut_serve() {
    $chs = m3ut_read_channels();
    if ($chs === null) {
        while (ob_get_level() > 0) ob_end_clean();
        status_header(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'channels file not found';
        exit;
    }
    if (isset($_GET['go'])) m3ut_go($chs);

    $ua = substr(sanitize_text_field(isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : 'Unknown'), 0, 255);
    $ip = sanitize_text_field(m3ut_ip());
    $ctx = m3ut_resolve_access();
    $ctx['ip'] = $ip; // go-link token-e IP-bind (optional) korar jonno lage
    $body = $ctx['status'] === 'ok' ? m3ut_build($chs, $ctx) : m3ut_blocked_body();
    m3ut_send($body);
    m3ut_log_hit($ctx, $ip, $ua);
        m3ut_cron_ping();
    exit;
}