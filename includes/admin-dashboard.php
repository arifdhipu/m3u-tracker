<?php
if (!defined('ABSPATH')) exit;

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_m3ut-dashboard') return;
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
});

function m3ut_valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }

function m3ut_filters() {
    $t = m3ut_today();
    $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
    $to   = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
    if (!m3ut_valid_date($from)) $from = m3ut_plus_days($t, -29);
    if (!m3ut_valid_date($to)) $to = $t;
    return [
        'from'   => $from,
        'to'     => $to,
        'source' => isset($_GET['source']) ? sanitize_text_field(wp_unslash($_GET['source'])) : '',
        'q'      => isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '',
    ];
}

function m3ut_where($f, $status = 'ok') {
    global $wpdb;
    $w = $wpdb->prepare("WHERE status=%s AND viewed_at BETWEEN %s AND %s", $status, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59');
    if ($f['source'] !== '') $w .= $wpdb->prepare(' AND source=%s', $f['source']);
    if ($f['q'] !== '') {
        $l = '%' . $wpdb->esc_like($f['q']) . '%';
        $w .= $wpdb->prepare(' AND (ip LIKE %s OR city LIKE %s OR country LIKE %s OR isp LIKE %s)', $l, $l, $l, $l);
    }
    return $w;
}

/* ---------- Live AJAX ---------- */
add_action('wp_ajax_m3ut_live', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('denied', 403);
    check_ajax_referer('m3ut_live', 'nonce');
    global $wpdb;
    $logs = m3ut_t('logs');
    $since = m3ut_ago(M3UT_LIVE_MINUTES * 60);
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT CONCAT(ip, '|', LEFT(user_agent, 150))) FROM $logs WHERE status='ok' AND viewed_at >= %s", $since));
    $by = $wpdb->get_results($wpdb->prepare("SELECT source, COUNT(DISTINCT CONCAT(ip, '|', LEFT(user_agent, 150))) viewers FROM $logs WHERE status='ok' AND viewed_at >= %s GROUP BY source ORDER BY viewers DESC", $since));
    $v = $wpdb->get_results($wpdb->prepare(
        "SELECT ip, MAX(LEFT(user_agent, 150)) ua, MAX(city) city, MAX(country) country, MAX(isp) isp, MAX(device) device, MAX(source) source, MAX(viewed_at) last_seen
                  FROM $logs WHERE status='ok' AND viewed_at >= %s GROUP BY ip, LEFT(user_agent, 150) ORDER BY last_seen DESC LIMIT 200", $since));

    // chits table-e ua_hash column na thakle nijei bosiye nei (ekbar-i cholbe)
    if (!get_option('m3ut_chits_ua_ok')) {
        m3ut_col(m3ut_t('chits'), 'ua_hash', "CHAR(32) NOT NULL DEFAULT ''");
        update_option('m3ut_chits_ua_ok', 1, false);
    }

    // -- kon viewer (IP + device) ekhon kon channel dekhche --
    $chit_since = m3ut_ago(30 * 60); // shesh 30 minute-er moddhe shuru kora channel
    $chmap = []; $byip = [];
    $ips = array_values(array_unique(array_filter(array_map(function ($r) { return $r->ip; }, (array) $v))));
    if ($ips) {
        $ph = implode(',', array_fill(0, count($ips), '%s'));
        $ct = m3ut_t('chits');
        $hits = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.ip, c.ua_hash, c.chash, c.cname FROM $ct c
             INNER JOIN (SELECT ip, ua_hash, MAX(id) mid FROM $ct WHERE viewed_at >= %s AND ip IN ($ph) GROUP BY ip, ua_hash) m ON c.id = m.mid",
            array_merge([$chit_since], $ips)));
        $logoByHash = [];
        foreach ((array) m3ut_read_channels() as $ch) $logoByHash[$ch['hash']] = $ch['logo'];
        foreach ($hits as $h) {
            $info = [
                'id'   => (int) $h->id,
                'name' => $h->cname,
                'logo' => isset($logoByHash[$h->chash]) ? esc_url_raw($logoByHash[$h->chash]) : '',
            ];
            $chmap[$h->ip . '|' . $h->ua_hash] = $info;
            $byip[$h->ip][$h->ua_hash] = $info;
        }
    }
    // 1) age IP + device exact match
    $claimed = []; $unmatched = [];
    foreach ($v as $i => $row) {
        $k = $row->ip . '|' . md5(mb_substr((string) $row->ua, 0, 150, 'UTF-8'));
        if (isset($chmap[$k])) {
            $row->channel = $chmap[$k]['name'];
            $row->logo    = $chmap[$k]['logo'];
            $claimed[$k]  = 1;
        } else {
            $row->channel = ''; $row->logo = '';
            $unmatched[$row->ip][] = $i;
        }
        unset($row->ua);
    }
    // 2) jader match hoyni, oi IP-r "kono row-e use hoyni" channel diye milai
    foreach ($unmatched as $uip => $idxs) {
        $free = [];
        if (!empty($byip[$uip])) {
            foreach ($byip[$uip] as $uh => $info) if (empty($claimed[$uip . '|' . $uh])) $free[] = $info;
        }
        if (!$free) continue;
        usort($free, function ($a, $b) { return $b['id'] <=> $a['id']; });
        if (count($idxs) === 1 || count($idxs) === count($free)) {
            foreach ($idxs as $n => $i) {
                $info = isset($free[$n]) ? $free[$n] : $free[0];
                $v[$i]->channel = $info['name'];
                $v[$i]->logo    = $info['logo'];
            }
        }
    }
    wp_send_json_success(['live_count' => $count, 'by_source' => $by, 'viewers' => $v]);
});

/* ---------- CSV export (filter mane chole) ---------- */
add_action('admin_post_m3ut_export', function () {
    m3ut_cap();
    check_admin_referer('m3ut_export');
    global $wpdb;
    $f = m3ut_filters();
    $where = m3ut_where($f);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=m3u_views_' . $f['from'] . '_' . $f['to'] . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'IP', 'Country', 'City', 'ISP', 'Device', 'Source', 'Key', 'Viewed At']);
    $off = 0;
    do {
        $rows = $wpdb->get_results("SELECT * FROM " . m3ut_t('logs') . " $where ORDER BY id DESC LIMIT 5000 OFFSET $off", ARRAY_A);
        foreach ((array) $rows as $r) fputcsv($out, [$r['id'], $r['ip'], $r['country'], $r['city'], $r['isp'], $r['device'], $r['source'], $r['key_code'], $r['viewed_at']]);
        $off += 5000;
    } while (count((array) $rows) === 5000);
    fclose($out);
    exit;
});

/* ---------- Page ---------- */
function m3ut_page_dashboard() {
    m3ut_cap();
    global $wpdb;
    $logs = m3ut_t('logs');
    $s = m3ut_settings();
    $f = m3ut_filters();
    $where = m3ut_where($f);
    $t = m3ut_today();

    $total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $logs $where");
    $unique  = (int) $wpdb->get_var("SELECT COUNT(DISTINCT ip) FROM $logs $where");
    $today_v = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logs WHERE status='ok' AND viewed_at >= %s", $t . ' 00:00:00'));
    $blocked = (int) $wpdb->get_var("SELECT COUNT(*) FROM $logs " . m3ut_where($f, 'blocked'));
    $new = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT ip FROM $logs $where GROUP BY ip) a
        JOIN (SELECT ip, MIN(viewed_at) fs FROM $logs WHERE status='ok' GROUP BY ip) b ON a.ip = b.ip
        WHERE b.fs >= '" . esc_sql($f['from'] . ' 00:00:00') . "'");
    $returning = max(0, $unique - $new);

    $sources = $wpdb->get_col("SELECT DISTINCT source FROM $logs ORDER BY source");
    $by_country = $wpdb->get_results("SELECT country, COUNT(*) views FROM $logs $where GROUP BY country ORDER BY views DESC LIMIT 10");
    $by_device  = $wpdb->get_results("SELECT device, COUNT(*) views FROM $logs $where GROUP BY device ORDER BY views DESC");
    $by_isp     = $wpdb->get_results("SELECT isp, COUNT(*) views FROM $logs $where GROUP BY isp ORDER BY views DESC LIMIT 10");
    $by_city    = $wpdb->get_results("SELECT city, country, COUNT(*) views, COUNT(DISTINCT ip) u FROM $logs $where GROUP BY city, country ORDER BY views DESC LIMIT 100");
    $by_source  = $wpdb->get_results("SELECT source, COUNT(*) views, COUNT(DISTINCT ip) u FROM $logs $where GROUP BY source ORDER BY views DESC");
    $by_date    = $wpdb->get_results("SELECT DATE(viewed_at) d, COUNT(*) views FROM $logs $where GROUP BY DATE(viewed_at) ORDER BY d ASC");
    $heat_rows  = $wpdb->get_results("SELECT DAYOFWEEK(viewed_at) d, HOUR(viewed_at) h, COUNT(*) c FROM $logs $where GROUP BY d, h");

        $by_chan = array_slice(m3ut_channel_watch_stats($f['from'], $f['to'], $f['source']), 0, 100);
        // Beshi-view channel-er logo dekhanor jonno channels file theke hash => logo map bananu
        $chan_logo = [];
        if (!empty($s['go_mode']) && $by_chan) {
            foreach ((array) m3ut_read_channels() as $cc) if (!empty($cc['logo'])) $chan_logo[$cc['hash']] = $cc['logo'];
        }

    $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
    $per = 25;
    $pages = max(1, (int) ceil($unique / $per));
    $off = ($paged - 1) * $per;
    $by_ip = $wpdb->get_results("SELECT ip, MAX(city) city, MAX(country) country, MAX(isp) isp, MAX(device) device, MAX(source) source, COUNT(*) views, MAX(viewed_at) last_seen
        FROM $logs $where GROUP BY ip ORDER BY views DESC, last_seen DESC LIMIT $per OFFSET $off");

    $dead = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . m3ut_t('links') . " WHERE status='dead'");
    $base = admin_url('admin.php?page=m3ut-dashboard');
    $presets = [
        'Today' => [$t, $t], '7 din' => [m3ut_plus_days($t, -6), $t], '30 din' => [m3ut_plus_days($t, -29), $t],
        'This month' => [substr($t, 0, 8) . '01', $t], 'All time' => ['2000-01-01', $t],
    ];
    $export = wp_nonce_url(add_query_arg([
        'action' => 'm3ut_export', 'from' => $f['from'], 'to' => $f['to'],
        'source' => rawurlencode($f['source']), 'q' => rawurlencode($f['q']),
    ], admin_url('admin-post.php')), 'm3ut_export');

    $grid = []; $hmax = 1;
    foreach ((array) $heat_rows as $r) { $grid[(int) $r->d - 1][(int) $r->h] = (int) $r->c; if ($r->c > $hmax) $hmax = (int) $r->c; }
    $dn = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    ?>
    <div class="wrap">
        <h1>📺 M3U View Analytics</h1>
        <?php m3ut_notice(); ?>

        <?php if ($dead > 0): ?>
            <div class="notice notice-warning"><p>⚠️ <strong><?php echo $dead; ?></strong> ta channel dead. <a href="<?php echo esc_url(admin_url('admin.php?page=m3ut-channels&st=dead')); ?>">Dekhun →</a></p></div>
        <?php endif; ?>

        <div class="m3ut-card" style="margin:14px 0">
            <div style="margin-bottom:10px">
                <?php foreach ($presets as $lbl => $r): ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['from' => $r[0], 'to' => $r[1], 'source' => rawurlencode($f['source'])], $base)); ?>"><?php echo esc_html($lbl); ?></a>
                <?php endforeach; ?>
            </div>
            <form method="get">
                <input type="hidden" name="page" value="m3ut-dashboard">
                From <input type="date" name="from" value="<?php echo esc_attr($f['from']); ?>">
                To <input type="date" name="to" value="<?php echo esc_attr($f['to']); ?>">
                Source <select name="source"><option value="">All</option>
                    <?php foreach ($sources as $src): ?><option value="<?php echo esc_attr($src); ?>" <?php selected($f['source'], $src); ?>><?php echo esc_html($src); ?></option><?php endforeach; ?>
                </select>
                Search <input type="search" name="q" placeholder="IP / city / ISP" value="<?php echo esc_attr($f['q']); ?>">
                <button class="button button-primary">Filter</button>
                <a class="button" href="<?php echo esc_url($export); ?>">⬇ Export CSV (filter onujayi)</a>
            </form>
        </div>

        <div style="background:linear-gradient(135deg,#9b51e0,#6a1b9a);color:#fff;padding:22px 24px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div>
                <h2 style="margin:0 0 4px;color:#fff"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#fff;animation:m3utPulse 1.4s infinite"></span> Ekhon live</h2>
                <p style="margin:0;opacity:.9;font-size:13px">Shesh <?php echo (int) M3UT_LIVE_MINUTES; ?> minute-e playlist load koreche · 15 sec-e auto update</p>
            </div>
            <div style="text-align:right"><div id="m3ut-live-count" style="font-size:44px;font-weight:800;line-height:1">…</div><div style="font-size:12px;opacity:.85">active viewer</div></div>
        </div>

        <div class="m3ut-row">
            <div class="m3ut-card" style="max-width:340px">
                <h2>Source onujayi live</h2>
                <table class="widefat striped" id="m3ut-live-src"><thead><tr><th>Source</th><th>Live</th></tr></thead><tbody><tr><td colspan="2">Loading…</td></tr></tbody></table>
            </div>
            <div class="m3ut-card" style="flex:2">
                <h2>Ekhon je-ra dekhche</h2>
                <div class="m3ut-scroll"><table class="widefat striped" id="m3ut-live-viewers"><thead><tr><th>Channel</th><th>IP</th><th>City</th><th>Country</th><th>ISP</th><th>Device</th><th>Source</th><th>Last ping</th></tr></thead><tbody><tr><td colspan="8">Loading…</td></tr></tbody></table></div>
            </div>
        </div>

        <div class="m3ut-row">
            <div class="m3ut-card"><h3>Mot hit</h3><p class="m3ut-big"><?php echo number_format_i18n($total); ?></p></div>
            <div class="m3ut-card"><h3>Unique IP</h3><p class="m3ut-big"><?php echo number_format_i18n($unique); ?></p></div>
            <div class="m3ut-card"><h3>Ajker hit</h3><p class="m3ut-big"><?php echo number_format_i18n($today_v); ?></p></div>
            <div class="m3ut-card"><h3>New vs Returning</h3><p class="m3ut-big"><span style="color:#00a32a"><?php echo number_format_i18n($new); ?></span> / <span style="color:#2271b1"><?php echo number_format_i18n($returning); ?></span></p><small>notun IP / ager theke ashe</small></div>
            <div class="m3ut-card"><h3>Blocked hit</h3><p class="m3ut-big" style="color:#d63638"><?php echo number_format_i18n($blocked); ?></p><small>disabled/expired/invalid link</small></div>
        </div>

        <div class="m3ut-row">
            <div class="m3ut-card" style="flex:2;min-width:380px"><h2>Daily trend</h2><canvas id="dateChart" height="90"></canvas></div>
            <div class="m3ut-card"><h2>Country</h2><canvas id="countryChart"></canvas></div>
            <div class="m3ut-card"><h2>Device</h2><canvas id="deviceChart"></canvas></div>
            <div class="m3ut-card"><h2>ISP (top 10)</h2><canvas id="ispChart"></canvas></div>
        </div>

        <div class="m3ut-card" style="margin-bottom:20px">
            <h2>Peak time heatmap (ghonta × bar)</h2>
            <div class="m3ut-scroll"><table style="border-collapse:collapse;font-size:10px">
                <tr><td></td><?php for ($h = 0; $h < 24; $h++) echo '<td style="text-align:center;padding:2px 5px">' . $h . '</td>'; ?></tr>
                <?php for ($d = 0; $d < 7; $d++): ?>
                    <tr><td style="padding:2px 8px 2px 0"><?php echo $dn[$d]; ?></td>
                    <?php for ($h = 0; $h < 24; $h++):
                        $c = isset($grid[$d][$h]) ? $grid[$d][$h] : 0;
                        $a = $c ? round(0.12 + 0.88 * ($c / $hmax), 2) : 0;
                        echo '<td title="' . esc_attr($dn[$d] . ' ' . $h . ':00 — ' . $c . ' hit') . '" style="width:26px;height:24px;text-align:center;border:1px solid #fff;background:rgba(34,113,177,' . $a . ');color:' . ($a > 0.55 ? '#fff' : '#1d2327') . '">' . ($c ?: '') . '</td>';
                    endfor; ?></tr>
                <?php endfor; ?>
            </table></div>
        </div>

        <div class="m3ut-row">
            <div class="m3ut-card">
                <h2>Source / Distributor</h2>
                <table class="widefat striped"><thead><tr><th>Source</th><th>Hit</th><th>Unique IP</th></tr></thead><tbody>
                <?php foreach ((array) $by_source as $r): ?><tr><td><?php echo esc_html($r->source); ?></td><td><?php echo number_format_i18n($r->views); ?></td><td><?php echo number_format_i18n($r->u); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
            <div class="m3ut-card">
                <h2>City ranking</h2>
                <table class="widefat striped"><thead><tr><th>City</th><th>Country</th><th>Hit</th><th>Unique</th></tr></thead><tbody>
                <?php foreach ((array) $by_city as $r): ?><tr><td><?php echo esc_html($r->city); ?></td><td><?php echo esc_html($r->country); ?></td><td><?php echo number_format_i18n($r->views); ?></td><td><?php echo number_format_i18n($r->u); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
                        <div class="m3ut-card">
                <h2>Top channels (beshi play + koto shomoy)</h2>
                <?php if (empty($s['go_mode'])): ?>
                    <p>Channel-wise stats off ache. <a href="<?php echo esc_url(admin_url('admin.php?page=m3ut-settings')); ?>">Settings</a>-e "Channel stats mode" on korun.</p>
                <?php elseif (empty($by_chan)): ?>
                    <p>Ei range-e kono channel click nei.</p>
                <?php else: ?>
                                <table class="widefat striped"><thead><tr><th>Channel</th><th>Group</th><th>Play</th><th>Unique</th><th>Moth somoy (approx)</th></tr></thead><tbody>
                <?php foreach ($by_chan as $r):
                    $logo = isset($chan_logo[$r->chash]) ? $chan_logo[$r->chash] : ''; ?>
                    <tr><td><?php if ($logo): ?><img src="<?php echo esc_url($logo); ?>" style="width:24px;height:24px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:6px" onerror="this.style.display='none'"><?php endif; ?><?php echo esc_html($r->cname); ?></td><td><?php echo esc_html($r->cgroup); ?></td><td><strong><?php echo number_format_i18n($r->plays); ?></strong></td><td><?php echo number_format_i18n($r->unique); ?></td><td><?php echo esc_html(m3ut_fmt_duration($r->seconds)); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <p class="description" style="margin-bottom:0">Somoy ta approximate — stream ta shorasori provider theke jay, WordPress-er moddhe diye na, tai channel-switch-er gap theke hishab kora hoy.</p>
                <?php endif; ?>
            </div>

        <h2>Viewer detail (IP onujayi) — <?php echo number_format_i18n($unique); ?> IP</h2>
        <div class="m3ut-scroll"><table class="widefat striped">
            <thead><tr><th>IP</th><th>City</th><th>Country</th><th>ISP</th><th>Device</th><th>Source</th><th>Hit</th><th>Last seen</th></tr></thead><tbody>
            <?php foreach ((array) $by_ip as $r): ?>
                <tr><td><?php echo esc_html($r->ip); ?></td><td><?php echo esc_html($r->city); ?></td><td><?php echo esc_html($r->country); ?></td><td><?php echo esc_html($r->isp); ?></td><td><?php echo esc_html($r->device); ?></td><td><?php echo esc_html($r->source); ?></td><td><?php echo number_format_i18n($r->views); ?></td><td><?php echo esc_html($r->last_seen); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <div class="tablenav"><div class="tablenav-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages, 'type' => 'plain']); ?></div></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.Chart) {
            var pal = ['#2271b1','#72aee6','#d63638','#dba617','#00a32a','#8c8f94','#a7aaad','#f0c33c','#9b51e0','#ff6b6b'];
            new Chart(document.getElementById('dateChart'), {type:'line', data:{labels:<?php echo wp_json_encode(wp_list_pluck((array) $by_date, 'd')); ?>, datasets:[{label:'Hit', data:<?php echo wp_json_encode(array_map('intval', wp_list_pluck((array) $by_date, 'views'))); ?>, borderColor:'#2271b1', backgroundColor:'rgba(34,113,177,.15)', fill:true, tension:.3}]}});
            new Chart(document.getElementById('countryChart'), {type:'pie', data:{labels:<?php echo wp_json_encode(wp_list_pluck((array) $by_country, 'country')); ?>, datasets:[{data:<?php echo wp_json_encode(array_map('intval', wp_list_pluck((array) $by_country, 'views'))); ?>, backgroundColor:pal}]}});
            new Chart(document.getElementById('deviceChart'), {type:'bar', data:{labels:<?php echo wp_json_encode(wp_list_pluck((array) $by_device, 'device')); ?>, datasets:[{label:'Hit', data:<?php echo wp_json_encode(array_map('intval', wp_list_pluck((array) $by_device, 'views'))); ?>, backgroundColor:'#00a32a'}]}, options:{indexAxis:'y'}});
            new Chart(document.getElementById('ispChart'), {type:'bar', data:{labels:<?php echo wp_json_encode(wp_list_pluck((array) $by_isp, 'isp')); ?>, datasets:[{label:'Hit', data:<?php echo wp_json_encode(array_map('intval', wp_list_pluck((array) $by_isp, 'views'))); ?>, backgroundColor:'#9b51e0'}]}, options:{indexAxis:'y'}});
        }

        var nonce = <?php echo wp_json_encode(wp_create_nonce('m3ut_live')); ?>;
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        function esc(v){var d=document.createElement('div');d.textContent=(v===null||v===undefined)?'':v;return d.innerHTML;}
        function poll(){
            fetch(ajax+'?action=m3ut_live&nonce='+nonce,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
                if(!res||!res.success) return;
                var d=res.data;
                document.getElementById('m3ut-live-count').textContent=d.live_count;
                var sb=document.querySelector('#m3ut-live-src tbody');
                sb.innerHTML=d.by_source.length?d.by_source.map(function(r){return '<tr><td>'+esc(r.source)+'</td><td>'+esc(r.viewers)+'</td></tr>';}).join(''):'<tr><td colspan="2">Ekhon keu nei</td></tr>';
                var vb=document.querySelector('#m3ut-live-viewers tbody');
                
                function chCell(r){
                    if(!r.channel) return '<span style="opacity:.55">— (playlist dekhche)</span>';
                    var img=(r.logo&&/^https?:\/\//i.test(r.logo))?'<img src="'+esc(r.logo).replace(/"/g,'&quot;')+'" style="width:28px;height:28px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px" onerror="this.style.display=\'none\'">':'';
                    return img+'<strong style="vertical-align:middle">'+esc(r.channel)+'</strong>';
                }
                vb.innerHTML=d.viewers.length?d.viewers.map(function(r){return '<tr><td>'+chCell(r)+'</td><td>'+esc(r.ip)+'</td><td>'+esc(r.city)+'</td><td>'+esc(r.country)+'</td><td>'+esc(r.isp)+'</td><td>'+esc(r.device)+'</td><td>'+esc(r.source)+'</td><td>'+esc(r.last_seen)+'</td></tr>';}).join(''):'<tr><td colspan="8">Ekhon keu nei</td></tr>';
 
            }).catch(function(){document.getElementById('m3ut-live-count').textContent='!';});
        }
        poll(); setInterval(poll, 15000);
    });
    </script>
    
    <style>
    .m3ut-pg { display:flex; align-items:center; justify-content:flex-end; gap:6px; flex-wrap:wrap; margin-top:10px; font-size:12px }
    .m3ut-pg .m3ut-pg-info { margin-right:auto; color:#50575e }
    .m3ut-pg select { min-height:28px; line-height:1.2; padding:0 24px 0 8px }
    table.m3ut-paged.striped > tbody > tr { background:#fff !important }
    table.m3ut-paged.striped > tbody > tr.m3ut-alt { background:#f6f7f7 !important }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var SIZES = [10, 25, 50, 100];

    function setup(tb) {
        var body = tb.tBodies[0];
        if (!body || tb.getAttribute('data-m3ut-pg')) return;
        tb.setAttribute('data-m3ut-pg', '1');
        tb.classList.add('m3ut-paged');

        var st = { page: 1, per: 10 };
        var nav = document.createElement('div');
        nav.className = 'm3ut-pg';
        var host = tb.closest('.m3ut-scroll') || tb;
        host.parentNode.insertBefore(nav, host.nextSibling);

        function render() {
            var rows = Array.prototype.slice.call(body.rows);
            // "Loading…" / "Ekhon keu nei" jatiyo ekta-matro colspan row hole pagination lagbe na
            var placeholder = rows.length === 1 && rows[0].cells.length === 1 && rows[0].cells[0].colSpan > 1;
            var n = placeholder ? 0 : rows.length;
            var pages = Math.max(1, Math.ceil(n / st.per));
            if (st.page > pages) st.page = pages;
            if (st.page < 1) st.page = 1;
            var start = (st.page - 1) * st.per, end = start + st.per, vis = 0;
            rows.forEach(function (r, i) {
                var show = placeholder || (i >= start && i < end);
                r.style.display = show ? '' : 'none';
                if (show) { vis++; r.classList.toggle('m3ut-alt', vis % 2 === 0); }
            });
            if (n <= SIZES[0]) { nav.style.display = 'none'; return; }
            nav.style.display = '';
            var from = start + 1, to = Math.min(end, n);
            nav.innerHTML =
                '<span class="m3ut-pg-info">' + from + '–' + to + ' / ' + n + ' ta</span>' +
                '<select class="m3ut-pg-per">' + SIZES.map(function (s) { return '<option value="' + s + '"' + (s === st.per ? ' selected' : '') + '>' + s + ' ta / page</option>'; }).join('') + '</select>' +
                '<button type="button" class="button m3ut-pg-first"' + (st.page <= 1 ? ' disabled' : '') + '>«</button>' +
                '<button type="button" class="button m3ut-pg-prev"' + (st.page <= 1 ? ' disabled' : '') + '>‹ Prev</button>' +
                '<span>Page ' + st.page + ' / ' + pages + '</span>' +
                '<button type="button" class="button m3ut-pg-next"' + (st.page >= pages ? ' disabled' : '') + '>Next ›</button>' +
                '<button type="button" class="button m3ut-pg-last"' + (st.page >= pages ? ' disabled' : '') + '>»</button>';
        }

        nav.addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b || b.disabled) return;
            var pages = Math.max(1, Math.ceil(Math.max(0, body.rows.length) / st.per));
            if (b.classList.contains('m3ut-pg-first')) st.page = 1;
            else if (b.classList.contains('m3ut-pg-prev')) st.page--;
            else if (b.classList.contains('m3ut-pg-next')) st.page++;
            else if (b.classList.contains('m3ut-pg-last')) st.page = pages;
            render();
        });
        nav.addEventListener('change', function (e) {
            if (e.target.classList.contains('m3ut-pg-per')) { st.per = parseInt(e.target.value, 10) || 10; st.page = 1; render(); }
        });

        // live table 15 sec-e nijei bodle jay — tokhon-o page thik rakhi
        new MutationObserver(render).observe(body, { childList: true });
        render();
    }

    document.querySelectorAll('.m3ut-card table.widefat, #m3ut-live-viewers').forEach(setup);
});
</script>
    
    <?php
}
