<?php
if (!defined('ABSPATH')) exit;

/* ---------- Scan AJAX ---------- */
add_action('wp_ajax_m3ut_scan', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('denied', 403);
    check_ajax_referer('m3ut_scan', 'nonce');
    $do = isset($_POST['do']) ? sanitize_key($_POST['do']) : 'status';
    if ($do === 'start') {
        m3ut_scan_start();
        m3ut_scan_run_background(); // response die-i connection bondho, tarpor background-e cholte thake — ei call-ei exit hoy
    }
    $st = ($do === 'step') ? m3ut_scan_batch(6) : m3ut_scan_state();
    wp_send_json_success([
        'total' => isset($st['total']) ? (int) $st['total'] : 0,
        'left'  => isset($st['queue']) ? count($st['queue']) : 0,
        'finished' => isset($st['finished']) ? $st['finished'] : '',
    ]);
});

/* ---------- Editor helpers ---------- */
function m3ut_backup_channels($content) {
    $b = get_option('m3ut_backups', []);
    if (!is_array($b)) $b = [];
    if (!empty($b[0]) && $b[0]['c'] === $content) return;
    array_unshift($b, ['t' => m3ut_now(), 'c' => $content]);
    update_option('m3ut_backups', array_slice($b, 0, 8), false);
}
function m3ut_write_channels($content, $track = true) {
    $file = m3ut_channels_file();
    if (m3ut_is_remote_path($file)) return false; // remote source — WP theke shorashori likha jay na
    $exists = file_exists($file);
    $old = $exists ? (string) file_get_contents($file) : '';
    if ($exists) m3ut_backup_channels($old);
    $content = str_replace("\r\n", "\n", $content);
    $ok = @file_put_contents($file, $content, LOCK_EX) !== false;
    // Undo/Redo: kono change hole age-er obostha history-te rakhi ($track=false hole undo/redo nijei likhche, tai rakhi na)
    if ($ok && $track && $exists && str_replace("\r\n", "\n", $old) !== $content) m3ut_hist_push($old, $content);
    return $ok;
}

/* ---------- Undo / Redo (edit, delete, move, add, bulk — shob-i) ---------- */
function m3ut_hist_get() {
    $h = get_option('m3ut_history', []);
    if (!is_array($h)) $h = [];
    foreach (['undo', 'redo'] as $k) if (empty($h[$k]) || !is_array($h[$k])) $h[$k] = [];
    return $h;
}
function m3ut_hist_pack($c) {
    return function_exists('gzcompress') ? 'z:' . base64_encode(gzcompress((string) $c, 6)) : 'p:' . base64_encode((string) $c);
}
function m3ut_hist_unpack($s) {
    $s = (string) $s;
    if (strncmp($s, 'z:', 2) === 0) { $r = @gzuncompress((string) base64_decode(substr($s, 2))); return $r === false ? '' : $r; }
    if (strncmp($s, 'p:', 2) === 0) return (string) base64_decode(substr($s, 2));
    return '';
}
function m3ut_hist_label($old, $new) {
    $o = m3ut_parse((string) $old); $n = m3ut_parse((string) $new);
    $co = count($o); $cn = count($n);
    if ($cn < $co) return 'Delete ' . ($co - $cn) . ' ta';
    if ($cn > $co) return 'Add ' . ($cn - $co) . ' ta';
    $ou = array_column($o, 'uh'); $nu = array_column($n, 'uh');
    if ($ou !== $nu) { $x = $ou; $y = $nu; sort($x); sort($y); if ($x === $y) return 'Move'; }
    return 'Edit';
}
function m3ut_hist_push($old, $new) {
    $h = m3ut_hist_get();
    $h['undo'][] = ['t' => m3ut_now(), 'l' => m3ut_hist_label($old, $new), 'c' => m3ut_hist_pack($old)];
    $h['undo'] = array_slice($h['undo'], -20); // shesh 20 ta step
    $h['redo'] = [];                            // notun change hole redo muche jay
    update_option('m3ut_history', $h, false);
}

add_action('wp_ajax_m3ut_chan_history', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Permission nei');
    check_ajax_referer('m3ut_chan_nonce', 'nonce');
    if (m3ut_is_remote_path(m3ut_channels_file())) wp_send_json_error(m3ut_remote_block_msg());
    $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
    if (!in_array($op, ['undo', 'redo'], true)) wp_send_json_error('Invalid action');
    $from = $op; $to = ($op === 'undo') ? 'redo' : 'undo';
    $h = m3ut_hist_get();
    if (empty($h[$from])) wp_send_json_error($op === 'undo' ? 'Undo korar moto kichu nei' : 'Redo korar moto kichu nei');
    $entry = array_pop($h[$from]);
    $content = m3ut_hist_unpack(isset($entry['c']) ? $entry['c'] : '');
    if (trim($content) === '') wp_send_json_error('History data pawa jayni');
    $file = m3ut_channels_file();
    $cur = file_exists($file) ? (string) file_get_contents($file) : '';
    if (!m3ut_write_channels($content, false)) wp_send_json_error('File likha jayni. channels.txt file/folder writable kina check korun.');
    $label = isset($entry['l']) ? (string) $entry['l'] : 'Edit';
    $h[$to][] = ['t' => m3ut_now(), 'l' => $label, 'c' => m3ut_hist_pack($cur)];
    $h[$to] = array_slice($h[$to], -20);
    update_option('m3ut_history', $h, false);
    wp_send_json_success(['msg' => ($op === 'undo' ? 'Undo hoyeche: ' : 'Redo hoyeche: ') . $label]);
});

function m3ut_history_ui() {
    if (m3ut_is_remote_path(m3ut_channels_file())) return; // remote source-e edit hoy na, tai undo-o nei
    $h = m3ut_hist_get();
    $nu = count($h['undo']); $nr = count($h['redo']);
    $lu = $nu ? end($h['undo']) : null;
    $lr = $nr ? end($h['redo']) : null;
    ?>
    <div id="m3ut-hist-bar" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0 0;padding:8px 12px;background:#fff;border:1px solid #dcdcde;border-radius:6px">
        <strong>Undo / Redo:</strong>
        <button type="button" class="button" id="m3ut-hist-undo" data-on="<?php echo $nu ? 1 : 0; ?>" <?php echo $nu ? '' : 'disabled'; ?> title="<?php echo $lu ? esc_attr($lu['t']) : ''; ?>">↶ Undo<?php echo $lu ? ' — ' . esc_html($lu['l']) : ''; ?></button>
        <button type="button" class="button" id="m3ut-hist-redo" data-on="<?php echo $nr ? 1 : 0; ?>" <?php echo $nr ? '' : 'disabled'; ?> title="<?php echo $lr ? esc_attr($lr['t']) : ''; ?>">↷ Redo<?php echo $lr ? ' — ' . esc_html($lr['l']) : ''; ?></button>
        <span class="description"><?php echo (int) $nu; ?> step undo-te · <?php echo (int) $nr; ?> step redo-te (edit / delete / move / add shob-i dhora hoy)</span>
        <span id="m3ut-hist-msg" style="font-weight:600"></span>
    </div>
    <script>
    (function () {
        var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var NONCE = <?php echo wp_json_encode(wp_create_nonce('m3ut_chan_nonce')); ?>;
        var bu = document.getElementById('m3ut-hist-undo'), br = document.getElementById('m3ut-hist-redo'), msg = document.getElementById('m3ut-hist-msg');
        function run(op) {
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_history'); body.append('nonce', NONCE); body.append('op', op);
            bu.disabled = true; br.disabled = true;
            msg.style.color = '#2271b1'; msg.textContent = 'Kaj cholche…';
            fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) {
                        bu.disabled = bu.getAttribute('data-on') !== '1'; br.disabled = br.getAttribute('data-on') !== '1';
                        msg.style.color = '#d63638'; msg.textContent = (res && res.data) ? res.data : 'Fail hoyeche';
                        return;
                    }
                    msg.style.color = '#00a32a'; msg.textContent = res.data.msg;
                    setTimeout(function () { location.reload(); }, 600);
                })
                .catch(function () {
                    bu.disabled = bu.getAttribute('data-on') !== '1'; br.disabled = br.getAttribute('data-on') !== '1';
                    msg.style.color = '#d63638'; msg.textContent = 'Network error, abar try korun';
                });
        }
        bu.addEventListener('click', function () { run('undo'); });
        br.addEventListener('click', function () { run('redo'); });
    })();
    </script>
    <?php
}

function m3ut_remote_block_msg() {
    return 'Ei channels source ekta remote URL (.m3u/.m3u8/.php link) — WordPress theke shorashori add/edit/delete kora jay na. Remote file-e giye change korun.';
}

add_action('admin_post_m3ut_editor_save', function () {
    m3ut_cap(); check_admin_referer('m3ut_editor_save');
    if (m3ut_is_remote_path(m3ut_channels_file())) m3ut_back('m3ut-channels', m3ut_remote_block_msg(), 'error', ['tab' => 'editor']);
    $c = (string) wp_unslash(isset($_POST['content']) ? $_POST['content'] : '');
    if (trim($c) === '') m3ut_back('m3ut-channels', 'Content faka, save kora hoyni', 'error', ['tab' => 'editor']);
    if (!m3ut_write_channels($c)) m3ut_back('m3ut-channels', 'File likha jayni. File permission (writable) check korun.', 'error', ['tab' => 'editor']);
    m3ut_back('m3ut-channels', 'Save hoyeche (age-er version backup e ache). ' . count(m3ut_parse($c)) . ' ta channel.', 'success', ['tab' => 'editor']);
});

add_action('admin_post_m3ut_editor_add', function () {
    m3ut_cap(); check_admin_referer('m3ut_editor_add');
    $p = wp_unslash($_POST);
    $tab = (isset($p['tab']) && $p['tab'] === 'list') ? 'list' : 'editor';
    if (m3ut_is_remote_path(m3ut_channels_file())) m3ut_back('m3ut-channels', m3ut_remote_block_msg(), 'error', ['tab' => $tab]);
    $cl = function ($v) { return trim(str_replace(['"', "\r", "\n"], '', (string) $v)); };
    $name = $cl(isset($p['name']) ? $p['name'] : ''); $url = $cl(isset($p['url']) ? $p['url'] : '');
    $logo = $cl(isset($p['logo']) ? $p['logo'] : ''); $group = $cl(isset($p['group']) ? $p['group'] : '');
    if ($name === '' || !preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) m3ut_back('m3ut-channels', 'Naam ar valid stream URL dorkar', 'error', ['tab' => $tab]);
    $file = m3ut_channels_file();
    $cur = file_exists($file) ? (string) file_get_contents($file) : '';
    $line = '#EXTINF:-1' . ($logo !== '' ? ' tvg-logo="' . $logo . '"' : '') . ($group !== '' ? ' group-title="' . $group . '"' : '') . ',' . $name . "\n" . $url . "\n";
    $new = rtrim($cur) . "\n" . $line;
    if (!m3ut_write_channels($new)) m3ut_back('m3ut-channels', 'File likha jayni', 'error', ['tab' => $tab]);
    m3ut_back('m3ut-channels', "'$name' add hoyeche", 'success', ['tab' => $tab]);
});

/* ---------- AJAX: per-channel edit / delete (Channel Manager tab) ---------- */
add_action('wp_ajax_m3ut_chan_edit', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Permission nei');
    check_ajax_referer('m3ut_chan_nonce', 'nonce');
    if (m3ut_is_remote_path(m3ut_channels_file())) wp_send_json_error(m3ut_remote_block_msg());
    $p = wp_unslash($_POST);
    $cl = function ($v) { return trim(str_replace(['"', "\r", "\n"], '', (string) $v)); };
    $uh = isset($p['uh']) ? sanitize_text_field($p['uh']) : '';
    $name = $cl(isset($p['name']) ? $p['name'] : ''); $url = $cl(isset($p['url']) ? $p['url'] : '');
    $logo = $cl(isset($p['logo']) ? $p['logo'] : ''); $group = $cl(isset($p['group']) ? $p['group'] : '');
    if ($uh === '' || $name === '' || !preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) wp_send_json_error('Naam ar valid stream URL dorkar');
    $file = m3ut_channels_file();
    if (!is_readable($file)) wp_send_json_error('channels file pawa jayni (path/permission check korun)');
    $chs = m3ut_parse((string) file_get_contents($file));
    $found = false; $nc = null;
    foreach ($chs as &$c) {
        if ($c['uh'] === $uh) {
            $x = '#EXTINF:-1';
            if ($logo !== '')  $x .= ' tvg-logo="' . str_replace('"', '', $logo) . '"';
            if ($group !== '') $x .= ' group-title="' . str_replace('"', '', $group) . '"';
            $x .= ',' . $name;
            $c = m3ut_mk([$x], $url);
            $nc = $c;
            $found = true;
            break;
        }
    }
    unset($c);
    if (!$found) wp_send_json_error('Channel ta ekhon file-e nei (kew age-i change kore fele thakte pare) — page reload korun');
    if (!m3ut_write_channels(m3ut_channels_to_raw($chs))) wp_send_json_error('File likha jayni. channels.txt file/folder writable kina check korun.');
        // Dead link checker-er list (links table) o update rakhi, na hole reload-er por purono naam fire ashe
    global $wpdb;
    $lt = m3ut_t('links');
    if ($nc['uh'] === $uh) {
        // URL ager-i ache: shudhu naam/group update
        $wpdb->update($lt, ['cname' => substr($nc['name'], 0, 190), 'cgroup' => substr($nc['group'], 0, 100)], ['url_hash' => $uh]);
    } else {
        // URL bodleche (notun hash): purono row muche notun row ("Check hoyni") jog kori, position (seq) ager moto
        $oldseq = (int) $wpdb->get_var($wpdb->prepare("SELECT seq FROM $lt WHERE url_hash=%s", $uh));
        $wpdb->delete($lt, ['url_hash' => $uh]);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $lt (url_hash, chash, cname, cgroup, url, seq, status) VALUES (%s, %s, %s, %s, %s, %d, 'unknown')
             ON DUPLICATE KEY UPDATE chash=VALUES(chash), cname=VALUES(cname), cgroup=VALUES(cgroup), seq=VALUES(seq)",
            $nc['uh'], $nc['hash'], substr($nc['name'], 0, 190), substr($nc['group'], 0, 100), $nc['url'], $oldseq
        ));
    }
    wp_send_json_success(['uh' => $nc['uh'], 'no' => $nc['no'] ?? 0, 'name' => $nc['name'], 'group' => $nc['group'], 'logo' => $nc['logo'], 'url' => $nc['url']]);
});

add_action('wp_ajax_m3ut_chan_delete', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Permission nei');
    check_ajax_referer('m3ut_chan_nonce', 'nonce');
    if (m3ut_is_remote_path(m3ut_channels_file())) wp_send_json_error(m3ut_remote_block_msg());
    $uh = isset($_POST['uh']) ? sanitize_text_field(wp_unslash($_POST['uh'])) : '';
    $file = m3ut_channels_file();
    if (!is_readable($file)) wp_send_json_error('channels file pawa jayni');
    $chs = m3ut_parse((string) file_get_contents($file));
    $before = count($chs);
    $chs = array_values(array_filter($chs, function ($c) use ($uh) { return $c['uh'] !== $uh; }));
    if (count($chs) === $before) wp_send_json_error('Channel ta age theke-i nei — page reload korun');
    if (!m3ut_write_channels(m3ut_channels_to_raw($chs))) wp_send_json_error('File likha jayni. channels.txt file/folder writable kina check korun.');
        global $wpdb;
    $wpdb->delete(m3ut_t('links'), ['url_hash' => $uh]); // Dead link checker-er list-o theke muche dei
    wp_send_json_success(['uh' => $uh, 'hist' => m3ut_hist_summary()]);
});

/* =====================================================================
   BULK SELECT (checkbox) — Dead link checker + Sob channel + Duplicate tab
   Ei poro block ta admin-channels.php te PASTE korte hobe (STEP 2 dekho)
   ===================================================================== */
 
/* ---------- AJAX: bulk delete / bulk group change ---------- */
add_action('wp_ajax_m3ut_chan_bulk', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Permission nei');
    check_ajax_referer('m3ut_chan_nonce', 'nonce');
    if (m3ut_is_remote_path(m3ut_channels_file())) wp_send_json_error(m3ut_remote_block_msg());
 
    $op  = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
    $raw = isset($_POST['uhs']) ? sanitize_text_field(wp_unslash($_POST['uhs'])) : '';
    $uhs = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), function ($h) {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $h);
    })));
    if (!$uhs) wp_send_json_error('Kono channel select kora nei');
 
    $file = m3ut_channels_file();
    if (!is_readable($file)) wp_send_json_error('channels file pawa jayni');
    $chs = m3ut_parse((string) file_get_contents($file));
    $set = array_flip($uhs);
 
    global $wpdb;
    $t  = m3ut_t('links');
    $ph = implode(',', array_fill(0, count($uhs), '%s'));
 
    if ($op === 'delete') {
        $before = count($chs);
        $chs = array_values(array_filter($chs, function ($c) use ($set) { return !isset($set[$c['uh']]); }));
        $n = $before - count($chs);
        if ($n < 1) wp_send_json_error('Select kora channel gulo file-e pawa jayni — page reload korun');
        if (!m3ut_write_channels(m3ut_channels_to_raw($chs))) wp_send_json_error('File likha jayni. channels.txt file/folder writable kina check korun.');
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE url_hash IN ($ph)", $uhs));
        wp_send_json_success(['msg' => $n . ' ta channel delete hoyeche (auto-backup rakha hoyeche)']);
    }
 
    if ($op === 'group') {
        $g = trim(str_replace(['"', "\r", "\n"], '', (string) wp_unslash(isset($_POST['group']) ? $_POST['group'] : '')));
        if ($g === '') wp_send_json_error('Notun group naam likhun');
        $attr = 'group-title="' . $g . '"';
        $n = 0;
        foreach ($chs as &$c) {
            if (!isset($set[$c['uh']])) continue;
            foreach ($c['head'] as $i => $h) {
                if (stripos($h, '#EXTINF') !== 0) continue;
                if (preg_match('/group-title="[^"]*"/i', $h)) {
                    $h = preg_replace_callback('/group-title="[^"]*"/i', function () use ($attr) { return $attr; }, $h, 1);
                } else {
                    $h = preg_replace_callback('/^(#EXTINF:[^\s,]*)/i', function ($m) use ($attr) { return $m[1] . ' ' . $attr; }, $h, 1);
                }
                $c['head'][$i] = $h;
                $c['group'] = $g;
                $n++;
                break;
            }
        }
        unset($c);
        if ($n < 1) wp_send_json_error('Select kora channel gulo file-e pawa jayni — page reload korun');
        if (!m3ut_write_channels(m3ut_channels_to_raw($chs))) wp_send_json_error('File likha jayni. channels.txt file/folder writable kina check korun.');
        $wpdb->query($wpdb->prepare("UPDATE $t SET cgroup=%s WHERE url_hash IN ($ph)", array_merge([$g], $uhs)));
        wp_send_json_success(['msg' => $n . ' ta channel-er group "' . $g . '" hoyeche (auto-backup rakha hoyeche)']);
    }
 
    wp_send_json_error('Invalid action');
});
 
/* ---------- Checkbox + bulk bar UI (3ta tab-ei auto kaj kore) ---------- */
function m3ut_bulk_ui() {
    if (m3ut_is_remote_path(m3ut_channels_file())) return; // remote source-e edit/delete hoy na
    ?>
    <style>
        #m3ut-bk-bar { position:sticky; top:32px; z-index:50; background:#fff; border:1px solid #2271b1; border-radius:6px; padding:8px 12px; margin:0 0 12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; box-shadow:0 2px 8px rgba(0,0,0,.12) }
        @media (max-width:782px) { #m3ut-bk-bar { top:46px } }
        #m3ut-bk-bar input[type=text] { width:170px }
                #m3ut-bk-bar { transition:transform .25s ease, opacity .25s ease }
        #m3ut-bk-bar.m3ut-bk-hide { transform:translateY(-150%); opacity:0; pointer-events:none }
        .m3ut-bk-cb, .m3ut-bk-tball { margin:0 !important; width:18px; height:18px; cursor:pointer }
        .m3ut-bk-cardcb { position:absolute; top:8px; left:8px; z-index:2 }
        tr.m3ut-bk-on td { background:#eaf3fb !important }
        .m3ut-bk-on.m3ut-card { outline:2px solid #2271b1; outline-offset:-2px; background:#eaf3fb }
    </style>
    <script>
    (function () {
        var AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var NONCE = <?php echo wp_json_encode(wp_create_nonce('m3ut_chan_nonce')); ?>;
        var ROWS  = '.m3ut-dchk-row,.m3ut-chan-row,.m3ut-dup-row';
        var CARDS = '.m3ut-dchk-card,.m3ut-chan-card,.m3ut-dup-card';
        var sel = {}, lastCb = null;
 
        var anchor = document.querySelector('#m3ut-dchk-list-wrap, #m3ut-list-wrap, #m3ut-dup-list-wrap');
        if (!anchor || (!document.querySelector(ROWS) && !document.querySelector(CARDS))) return;
 
        function mkCb(uh, cls) {
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'm3ut-bk-cb ' + cls;
            cb.setAttribute('data-uh', uh);
            cb.title = 'Select';
            return cb;
        }
        function visible(el) { return el.offsetParent !== null; }
 
        /* list view: proti row-er shurute checkbox + header-e "select all" */
        document.querySelectorAll('table').forEach(function (tb) {
            var rows = tb.querySelectorAll(ROWS);
            if (!rows.length) return;
            var hr = tb.querySelector('thead tr');
            if (hr) {
                var th = document.createElement('th');
                th.style.width = '32px';
                var all = document.createElement('input');
                all.type = 'checkbox'; all.className = 'm3ut-bk-tball'; all.title = 'Ei table-er shob select';
                th.appendChild(all);
                hr.insertBefore(th, hr.firstChild);
            }
            rows.forEach(function (r) {
                var td = document.createElement('td');
                td.appendChild(mkCb(r.getAttribute('data-uh'), 'm3ut-bk-rowcb'));
                r.insertBefore(td, r.firstChild);
            });
            tb.querySelectorAll('tr[class$="edit-row"] > td').forEach(function (td) { td.colSpan = (td.colSpan || 1) + 1; });
        });
 
        /* grid view: proti card-er upore-bame checkbox */
        document.querySelectorAll(CARDS).forEach(function (c) {
            c.style.position = 'relative';
            c.insertBefore(mkCb(c.getAttribute('data-uh'), 'm3ut-bk-cardcb'), c.firstChild);
        });
 
        /* sticky action bar */
        var bar = document.createElement('div');
        bar.id = 'm3ut-bk-bar';
        bar.innerHTML =
            '<strong><span id="m3ut-bk-n">0</span> ta selected</strong>' +
            '<button type="button" class="button" id="m3ut-bk-all">☑ Shob dekha jachhe select</button>' +
            '<button type="button" class="button" id="m3ut-bk-clear">✖ Clear</button>' +
            '<span style="flex:1"></span>' +
            '<input type="text" id="m3ut-bk-group" placeholder="Notun group naam">' +
            '<button type="button" class="button" id="m3ut-bk-setgroup">📁 Group change</button>' +
            '<button type="button" class="button" id="m3ut-bk-del" style="color:#d63638;border-color:#d63638">🗑 Delete selected</button>' +
            '<span id="m3ut-bk-msg" style="color:#d63638"></span>';
        anchor.parentNode.insertBefore(bar, anchor);
        
         
        /* auto-hide: niche scroll korle lukabe, upore scroll korle abar ashbe */
        (function () {
            var lastY = window.pageYOffset || 0, ticking = false;
            function onScroll() {
                var y = window.pageYOffset || 0;
                if (bar.contains(document.activeElement)) { bar.classList.remove('m3ut-bk-hide'); lastY = y; return; }
                if (y < 120 || y < lastY - 4) bar.classList.remove('m3ut-bk-hide');      // upore scroll / page-er shurute
                else if (y > lastY + 4) bar.classList.add('m3ut-bk-hide');               // niche scroll
                lastY = y;
            }
            window.addEventListener('scroll', function () {
                if (ticking) return;
                ticking = true;
                window.requestAnimationFrame(function () { onScroll(); ticking = false; });
            }, { passive: true });
            bar.addEventListener('focusin', function () { bar.classList.remove('m3ut-bk-hide'); });
        })();
 
        function refresh() {
            var n = Object.keys(sel).length;
            document.getElementById('m3ut-bk-n').textContent = n;
            document.querySelectorAll('.m3ut-bk-cb').forEach(function (cb) {
                var on = !!sel[cb.getAttribute('data-uh')];
                cb.checked = on;
                var host = cb.closest(ROWS + ',' + CARDS);
                if (host) host.classList.toggle('m3ut-bk-on', on);
            });
            document.querySelectorAll('.m3ut-bk-tball').forEach(function (a) {
                var rs = a.closest('table').querySelectorAll('.m3ut-bk-rowcb'), all = rs.length > 0;
                rs.forEach(function (c) { if (!c.checked) all = false; });
                a.checked = all;
            });
            ['m3ut-bk-del', 'm3ut-bk-setgroup', 'm3ut-bk-clear'].forEach(function (id) { document.getElementById(id).disabled = (n === 0); });
        }
        function setSel(uh, on) { if (on) sel[uh] = 1; else delete sel[uh]; }
 
        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) return;
 
            var cb = t.closest('.m3ut-bk-cb');
            if (cb) {
                var on = cb.checked;
                if (e.shiftKey && lastCb && lastCb !== cb) {   // Shift + click = majkhaner shob select
                    var list = Array.prototype.filter.call(document.querySelectorAll('.m3ut-bk-cb'), visible);
                    var a = list.indexOf(lastCb), b = list.indexOf(cb);
                    if (a > -1 && b > -1) for (var i = Math.min(a, b); i <= Math.max(a, b); i++) setSel(list[i].getAttribute('data-uh'), on);
                } else {
                    setSel(cb.getAttribute('data-uh'), on);
                }
                lastCb = cb; refresh(); return;
            }
            var tball = t.closest('.m3ut-bk-tball');
            if (tball) {
                tball.closest('table').querySelectorAll('.m3ut-bk-rowcb').forEach(function (c) {
                    if (visible(c)) setSel(c.getAttribute('data-uh'), tball.checked);
                });
                refresh(); return;
            }
            if (t.closest('#m3ut-bk-all')) {
                document.querySelectorAll('.m3ut-bk-cb').forEach(function (c) { if (visible(c)) setSel(c.getAttribute('data-uh'), true); });
                refresh(); return;
            }
            if (t.closest('#m3ut-bk-clear')) { sel = {}; lastCb = null; refresh(); return; }
            if (t.closest('#m3ut-bk-del')) {
                var n = Object.keys(sel).length;
                if (n && confirm(n + ' ta channel delete korben? (channels file theke muche jabe, tobe auto-backup hobe)')) send('delete', {});
                return;
            }
            if (t.closest('#m3ut-bk-setgroup')) {
                var g = document.getElementById('m3ut-bk-group').value.trim();
                if (!g) { alert('Age "Notun group naam" box-e group-er naam likhun'); return; }
                var n2 = Object.keys(sel).length;
                if (n2 && confirm(n2 + ' ta channel-er group "' + g + '" kora hobe. Thik ache?')) send('group', { group: g });
            }
        });
 
        function busy(on) {
            ['m3ut-bk-del', 'm3ut-bk-setgroup', 'm3ut-bk-all', 'm3ut-bk-clear'].forEach(function (id) { document.getElementById(id).disabled = on; });
            document.getElementById('m3ut-bk-msg').textContent = on ? 'Kaj cholche…' : '';
        }
        function send(op, extra) {
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_bulk'); body.append('nonce', NONCE);
            body.append('op', op); body.append('uhs', Object.keys(sel).join(','));
            for (var k in extra) body.append(k, extra[k]);
            busy(true);
            fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { busy(false); refresh(); alert((res && res.data) ? res.data : 'Fail hoyeche'); return; }
                    alert(res.data.msg);
                    location.reload();
                })
                .catch(function () { busy(false); refresh(); alert('Network error, abar try korun'); });
        }
        refresh();
    })();
    </script>
    <?php
}

add_action('wp_ajax_m3ut_chan_move', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Permission nei');
    check_ajax_referer('m3ut_chan_nonce', 'nonce');
    if (m3ut_is_remote_path(m3ut_channels_file())) wp_send_json_error(m3ut_remote_block_msg());
    $uh  = isset($_POST['uh']) ? sanitize_text_field(wp_unslash($_POST['uh'])) : '';
    $dir = isset($_POST['dir']) ? sanitize_key(wp_unslash($_POST['dir'])) : '';
    $file = m3ut_channels_file();
    if (!is_readable($file)) wp_send_json_error('channels file pawa jayni');
    $chs = m3ut_parse((string) file_get_contents($file));
    $n = count($chs);
    $i = -1;
    foreach ($chs as $k => $c) {
        if ($c['uh'] === $uh) { $i = $k; break; }
    }
    if ($i < 0) wp_send_json_error('Channel ta file-e nei — page reload korun');
    if ($dir === 'up') {
        $j = $i - 1;
    } elseif ($dir === 'down') {
        $j = $i + 1;
    } elseif ($dir === 'to') {
        $pos = isset($_POST['pos']) ? (int) $_POST['pos'] : 0;
        $j = max(1, min($n, $pos)) - 1;
    } else {
        wp_send_json_error('Invalid direction');
    }
    if ($j < 0 || $j >= $n) wp_send_json_error($dir === 'up' ? 'Aro upore jawa jabe na' : 'Aro niche jawa jabe na');
    if ($j === $i) wp_send_json_success(['moved' => false]);
    $item = array_splice($chs, $i, 1);
    array_splice($chs, $j, 0, $item);
    if (!m3ut_write_channels(m3ut_channels_to_raw($chs))) wp_send_json_error('File likha jayni. channels.txt file/folder writable kina check korun.');
    wp_send_json_success(['moved' => true, 'from' => $i + 1, 'to' => $j + 1]);
});

add_action('admin_post_m3ut_editor_restore', function () {
    m3ut_cap(); check_admin_referer('m3ut_editor_restore');
    $b = get_option('m3ut_backups', []);
    $i = (int) $_GET['i'];
    if (!isset($b[$i])) m3ut_back('m3ut-channels', 'Backup pawa jayni', 'error', ['tab' => 'editor']);
    m3ut_write_channels($b[$i]['c']);
    m3ut_back('m3ut-channels', 'Restore hoyeche', 'success', ['tab' => 'editor']);
});

/* ---------- Page ---------- */
// Channel naam normalize kore (case + extra space bad diye) duplicate match korar jonno
function m3ut_norm_name($name) {
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
}

// URL-ke bujhar subidhar jonno rang kore dekhay: scheme (halka) + domain/host (bold, nil) + path (dhusor)
function m3ut_url_pretty($url, $max = 60) {
    $url = (string) $url;
    $short = mb_strlen($url) > $max ? mb_substr($url, 0, $max) . '…' : $url;
    if (preg_match('~^([a-z][a-z0-9+.\-]*://)([^/?#]*)(.*)$~is', $short, $m)) {
        $html = '<span style="color:#787c82">' . esc_html($m[1]) . '</span><strong style="background:#2271b1;color:#fff;padding:1px 6px;border-radius:3px;margin:0 2px">' . esc_html($m[2]) . '</strong><span style="color:#1d2327">' . esc_html($m[3]) . '</span>';
    } else {
        $html = esc_html($short);
    }
    return '<code title="' . esc_attr($url) . '">' . $html . '</code>';
}

// Channel-er last 24 ghontar uptime % (checks table theke)
function m3ut_chan_uptime_badge($uh) {
    static $map = null;
    if ($map === null) {
        global $wpdb;
        $map = [];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT url_hash, COUNT(*) total, SUM(ok) good FROM ' . m3ut_t('checks') . ' WHERE checked_at >= %s GROUP BY url_hash', m3ut_ago(86400)));
        foreach ((array) $rows as $r) $map[$r->url_hash] = [(int) $r->good, (int) $r->total];
    }
    if (empty($map[$uh]) || $map[$uh][1] < 1) return '';
    $good = $map[$uh][0]; $total = $map[$uh][1];
    $pct = (int) round($good * 100 / $total);
    $col = $pct >= 90 ? '#00a32a' : ($pct >= 60 ? '#dba617' : '#d63638');
    $tip = 'Last 24 ghonta: ' . $total . ' bar check-e ' . $good . ' bar online chhilo';
    return '<strong title="' . esc_attr($tip) . '" style="color:' . esc_attr($col) . ';font-size:14px;font-weight:600;margin-left:4px;white-space:nowrap">' . $pct . '%</strong>';
}

// Channel-er Dead/Active badge + uptime % (url_hash diye links table theke status ane)
function m3ut_chan_state_badge($uh) {
    static $map = null;
    if ($map === null) {
        global $wpdb;
        $map = [];
        foreach ((array) $wpdb->get_results('SELECT url_hash, status FROM ' . m3ut_t('links')) as $r) $map[$r->url_hash] = $r->status;
    }
    $st = isset($map[$uh]) ? $map[$uh] : '';
    $out = '';
    if ($st === 'dead') $out = m3ut_badge('Dead', '#d63638');
    elseif ($st === 'ok') $out = m3ut_badge('Active', '#00a32a');
    $up = m3ut_chan_uptime_badge($uh);
    if ($up !== '') $out .= ($out !== '' ? ' ' : '') . $up;
    return $out;
}

// Edit / Delete / Move save hoyar sathe sathe page nijei refresh hoy (hat-e reload korte hoy na).
// Scroll position, list/grid view, search filter — shob ager moto thake. Onno edit box khola thakle refresh hoy na (jeno likha haray na).
// Undo/Redo bar ke (reload chhara) update korar jonno chhoto summary
function m3ut_hist_summary() {
    $h = m3ut_hist_get();
    $lu = $h['undo'] ? end($h['undo']) : null;
    $lr = $h['redo'] ? end($h['redo']) : null;
    return [
        'nu' => count($h['undo']), 'nr' => count($h['redo']),
        'lu' => $lu ? ['l' => $lu['l'], 't' => $lu['t']] : null,
        'lr' => $lr ? ['l' => $lr['l'], 't' => $lr['t']] : null,
    ];
}

// Save hoyar sathe sathe page update:
//  - Duplicate naam tab-e DELETE korle page reload-i hoy na — row/group shorashori shore jay, apni jekhane chhilen sekhanei thakben.
//  - Onno shob edit/delete/move-e page nijei refresh hoy, ar THIK ager jaygay fire ashe (screen-e jei row dekhchilen sheta-ke anchor dhori).
//  Search filter, list/grid view ager moto thake. Onno edit box khola thakle refresh hoy na (jeno likha haray na).
function m3ut_live_ui() {
    if (m3ut_is_remote_path(m3ut_channels_file())) return; // remote source-e edit hoy na
    ?>
    <script>
    (function () {
        var KEY = 'm3ut_live_state', NAMEKEY = 'm3ut_live:';
        var WATCH = { m3ut_chan_edit: 700, m3ut_chan_delete: 700, m3ut_chan_move: 1500 };
        var ROWSEL = 'tr.m3ut-dup-row,tr.m3ut-chan-row,tr.m3ut-dchk-row,.m3ut-dup-card,.m3ut-chan-card,.m3ut-dchk-card';
        var timer = null, moved = false, toastTimer = null;

        function toast(txt, ok, ttl) {
            var d = document.getElementById('m3ut-live-toast');
            if (!d) {
                d = document.createElement('div'); d.id = 'm3ut-live-toast';
                d.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:100000;padding:10px 16px;border-radius:6px;color:#fff;font-weight:600;box-shadow:0 2px 10px rgba(0,0,0,.25)';
                document.body.appendChild(d);
            }
            d.style.display = 'block';
            d.style.background = ok ? '#00a32a' : '#dba617';
            d.textContent = txt;
            clearTimeout(toastTimer);
            if (ttl) toastTimer = setTimeout(function () { d.style.display = 'none'; }, ttl);
        }
        function editOpen() {
            var list = document.querySelectorAll('.m3ut-edit-row,.m3ut-dchk-edit-row,.m3ut-dup-edit-row,.m3ut-gcard-edit,.m3ut-dchk-gcard-edit,.m3ut-dup-gedit-box');
            for (var i = 0; i < list.length; i++) if (list[i].offsetParent !== null) return true;
            return false;
        }
        function gridId() {
            var ids = ['m3ut-grid-wrap', 'm3ut-dchk-grid-wrap', 'm3ut-dup-grid-wrap'];
            for (var i = 0; i < ids.length; i++) { var e = document.getElementById(ids[i]); if (e && e.offsetParent !== null) return ids[i]; }
            return '';
        }
        /* ekhon screen-e jei channel row/card gulo dekha jachhe tader "anchor" hishebe rakhi (id + screen-er upor theke koto px niche) */
        function anchors() {
            var out = [], list = document.querySelectorAll(ROWSEL);
            for (var i = 0; i < list.length && out.length < 8; i++) {
                var el = list[i], k = el.getAttribute('data-uh');
                if (!k || el.offsetParent === null) continue;
                var r = el.getBoundingClientRect();
                if (r.bottom <= 0 || r.top >= window.innerHeight) continue;
                out.push({ k: k, t: Math.round(r.top) });
            }
            return out;
        }
        function saveState() {
            var st = { q: location.search, y: window.pageYOffset || 0, grid: gridId(), f: {}, a: anchors() };
            ['m3ut-filter', 'm3ut-dchk-filter', 'm3ut-dup-filter'].forEach(function (id) {
                var e = document.getElementById(id); if (e && e.value) st.f[id] = e.value;
            });
            var raw = JSON.stringify(st);
            try { sessionStorage.setItem(KEY, raw); } catch (e) { try { window.name = NAMEKEY + raw; } catch (e2) {} }
            try { history.scrollRestoration = 'manual'; } catch (e) {}   // browser-er nijer scroll-restore bondho, amra-i korbo
        }
        function schedule(action) {
            toast('✔ Save hoyeche — page update hocche…', true);
            clearTimeout(timer);
            timer = setTimeout(function () {
                if (editOpen()) { toast('✔ Save hoyeche (onno edit box khola ache, tai auto-refresh holo na)', false); return; }
                saveState();
                location.reload();
            }, WATCH[action]);
        }

        /* ---- Duplicate naam tab: delete hole reload chhara-i update ---- */
        function setText(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; }
        function dupRecount() {
            var left = {}, total = 0;
            ['m3ut-dup-list-wrap', 'm3ut-dup-grid-wrap'].forEach(function (wid, idx) {
                var w = document.getElementById(wid); if (!w) return;
                var sel = idx === 0 ? '.m3ut-dup-row' : '.m3ut-dup-card';
                [].slice.call(w.querySelectorAll('.m3ut-dup-group')).forEach(function (g) {
                    var n = g.querySelectorAll(sel).length;
                    if (n < 2) { g.remove(); return; }                       // ar duplicate nei — group shore jay
                    var bd = g.querySelector('h3 span'); if (bd) bd.textContent = n + 'x duplicate';
                    if (idx === 0) { left[g.getAttribute('data-name')] = n; total += n; }
                });
            });
            var N = Object.keys(left).length;
            setText('m3ut-dup-n', N); setText('m3ut-dup-m', total);
            var kEl = document.getElementById('m3ut-dup-k');
            if (kEl) kEl.textContent = Math.max(0, (parseInt(kEl.textContent, 10) || 0) - 1);
            var nav = document.querySelector('.nav-tab-wrapper a[href*="tab=duplicate"] span');
            if (nav) { if (N) nav.textContent = N; else nav.remove(); }
            if (!N) {
                var lw = document.getElementById('m3ut-dup-list-wrap'), gw = document.getElementById('m3ut-dup-grid-wrap');
                if (lw) lw.innerHTML = '<div class="m3ut-card"><p>🎉 Kono duplicate naam nei — shob channel-er naam unique.</p></div>';
                if (gw) gw.style.display = 'none';
                var f = document.getElementById('m3ut-dup-filter'), card = f && f.closest('.m3ut-card');
                if (card) card.style.display = 'none';
            }
        }
        function histUpdate(h) {
            if (!h) return;
            [['m3ut-hist-undo', '↶ Undo', h.nu, h.lu], ['m3ut-hist-redo', '↷ Redo', h.nr, h.lr]].forEach(function (x) {
                var b = document.getElementById(x[0]); if (!b) return;
                b.textContent = x[1] + (x[3] ? ' — ' + x[3].l : '');
                b.disabled = !x[2]; b.setAttribute('data-on', x[2] ? '1' : '0'); b.title = x[3] ? x[3].t : '';
            });
            var d = document.querySelector('#m3ut-hist-bar .description');
            if (d) d.textContent = h.nu + ' step undo-te · ' + h.nr + ' step redo-te (edit / delete / move / add shob-i dhora hoy)';
        }
        function dupRemove(uh, hist) {
            setTimeout(function () {                                         // tab-er nijer handler-er por cholbe
                [].slice.call(document.querySelectorAll('.m3ut-dup-row,.m3ut-dup-card')).forEach(function (el) {
                    if (el.getAttribute('data-uh') !== uh) return;
                    var nx = el.nextElementSibling;
                    if (el.tagName === 'TR' && nx && nx.classList.contains('m3ut-dup-edit-row')) nx.remove();
                    el.remove();
                });
                dupRecount();
                histUpdate(hist);
                toast('✔ Delete hoyeche', true, 1800);
            }, 40);
        }

        /* fetch-er upor nojor rakhi: channel edit/delete/move shofol hole kaj kori */
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var p = origFetch.apply(this, arguments);
            try {
                var body = init && init.body;
                var action = (body && typeof body.get === 'function') ? body.get('action') : '';
                if (action === 'm3ut_chan_delete' && document.getElementById('m3ut-dup-list-wrap')) {
                    var duh = body.get('uh');                                // Duplicate tab: reload nai
                    p.then(function (resp) {
                        return resp.clone().json().then(function (res) { if (res && res.success) dupRemove(duh, res.data && res.data.hist); });
                    }).catch(function () {});
                } else if (action && WATCH.hasOwnProperty(action)) {
                    p.then(function (resp) {
                        return resp.clone().json().then(function (res) { if (res && res.success) schedule(action); });
                    }).catch(function () {});
                }
            } catch (e) {}
            return p;
        };

        /* ---- refresh-er por ager jaygay fire ana ---- */
        function findByKey(k) {
            var list = document.querySelectorAll(ROWSEL);
            for (var i = 0; i < list.length; i++) if (list[i].offsetParent !== null && list[i].getAttribute('data-uh') === k) return list[i];
            return null;
        }
        function place(st) {
            var a = st.a || [];
            for (var i = 0; i < a.length; i++) {              // prothom je anchor ekhono ache, sheta ager screen-position-e ana
                var el = findByKey(a[i].k);
                if (el) { window.scrollTo(0, (window.pageYOffset || 0) + el.getBoundingClientRect().top - a[i].t); return; }
            }
            window.scrollTo(0, st.y || 0);                    // kono anchor-i nei hole ager scroll-er jaygay
        }
        function loadState() {
            var raw = null;
            try { raw = sessionStorage.getItem(KEY); sessionStorage.removeItem(KEY); } catch (e) {}
            if (!raw && typeof window.name === 'string' && window.name.indexOf(NAMEKEY) === 0) { raw = window.name.slice(NAMEKEY.length); window.name = ''; }
            if (!raw) return null;
            try { var st = JSON.parse(raw); return (st && st.q === location.search) ? st : null; } catch (e) { return null; }
        }

        var st = loadState();
        try { history.scrollRestoration = 'auto'; } catch (e) {}
        if (!st) return;
        ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(function (t) {
            window.addEventListener(t, function () { moved = true; }, { passive: true, once: true });   // apni nije scroll korle ar jor kori na
        });

        var fkeys = Object.keys(st.f || {}), settled = false, ticks = 0;
        /* page poro-poro ses hoyar age-i (CDN script dhimi hole-o) jaygay niye jai; list/search chhara obosthay */
        var poll = setInterval(function () {
            ticks++;
            if (moved || settled || ticks > 150) { clearInterval(poll); return; }
            if (!st.grid && !fkeys.length) place(st);
        }, 100);

        function afterReady() {
            fkeys.forEach(function (id) {
                var e = document.getElementById(id);
                if (e) { e.value = st.f[id]; e.dispatchEvent(new Event('input', { bubbles: true })); }
            });
            if (st.grid) {
                var map = { 'm3ut-grid-wrap': 'm3ut-view-grid', 'm3ut-dchk-grid-wrap': 'm3ut-dchk-view-grid', 'm3ut-dup-grid-wrap': 'm3ut-dup-view-grid' };
                var b = map[st.grid] ? document.getElementById(map[st.grid]) : null;
                if (b) b.click();
            }
            [0, 150, 500, 1200].forEach(function (d) { setTimeout(function () { if (!moved) place(st); }, d); });
            setTimeout(function () { settled = true; }, 1500);
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', afterReady); else afterReady();
        window.addEventListener('load', function () { if (!moved) place(st); });
    })();
    </script>
    <?php
}

function m3ut_page_channels() {
    m3ut_cap();
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'checker';
    if (!in_array($tab, ['checker', 'editor', 'list', 'duplicate'], true)) $tab = 'checker';
    $u = admin_url('admin.php?page=m3ut-channels');

    // nav tab-e "koyta duplicate naam ache" ta dekhanor jonno dhoro count
    $dupNameCount = 0;
    $chs_for_count = (array) m3ut_read_channels();
    if ($chs_for_count) {
        $cnt = [];
        foreach ($chs_for_count as $c) {
            $k = m3ut_norm_name($c['name']);
            if ($k === '') continue;
            $cnt[$k] = (isset($cnt[$k]) ? $cnt[$k] : 0) + 1;
        }
        foreach ($cnt as $n) if ($n > 1) $dupNameCount++;
    }

    echo '<div class="wrap"><h1>📡 Channels</h1>';
    m3ut_notice();
    echo '<h2 class="nav-tab-wrapper"><a class="nav-tab ' . ($tab === 'checker' ? 'nav-tab-active' : '') . '" href="' . esc_url($u) . '">Dead link checker</a>'
       . '<a class="nav-tab ' . ($tab === 'list' ? 'nav-tab-active' : '') . '" href="' . esc_url($u . '&tab=list') . '">Sob channel (list/grid)</a>'
       . '<a class="nav-tab ' . ($tab === 'duplicate' ? 'nav-tab-active' : '') . '" href="' . esc_url($u . '&tab=duplicate') . '">🧬 Duplicate naam' . ($dupNameCount ? ' <span style="background:#8e44ad;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:2px">' . $dupNameCount . '</span>' : '') . '</a>'
       . '<a class="nav-tab ' . ($tab === 'editor' ? 'nav-tab-active' : '') . '" href="' . esc_url($u . '&tab=editor') . '">channels.txt editor</a></h2>';
           m3ut_history_ui();
               m3ut_live_ui();
    if ($tab === 'editor') m3ut_tab_editor();
    elseif ($tab === 'list') m3ut_tab_list();
    elseif ($tab === 'duplicate') m3ut_tab_duplicate();
    else m3ut_tab_checker();
        if ($tab !== 'editor') m3ut_bulk_ui();
    echo '</div>';
}

// Dead link checker-er list (links table) shob shomoy channels file-er shathe mil kore rakhi.
// Edit / delete / move / undo / bulk — ja-i hok, file-ei source of truth; ekhane shudhu sheta DB-te protifolito hoy.
// File bodlay ni hole kichu-i kori na (shesh sync-er signature mile gele skip), tai page dhimi hoy na.
function m3ut_links_sync() {
    global $wpdb;
    $chs = m3ut_read_channels();
    if ($chs === null) return;                                                  // file pawa jayni — kichu mucha jabe na
    if (!$chs && m3ut_is_remote_path(m3ut_channels_file())) return;             // remote khali ashle bhul dhore nei, kichu muchi na
    $t = m3ut_t('links');

    $seen = [];
    foreach ($chs as $c) {
        if (isset($seen[$c['uh']])) continue;                                   // ek-i URL duibar thakle prothomta
        $seen[$c['uh']] = [
            'chash' => $c['hash'],
            'name'  => mb_strcut((string) $c['name'], 0, 190, 'UTF-8'),
            'group' => mb_strcut((string) $c['group'], 0, 100, 'UTF-8'),
            'url'   => $c['url'],
            'seq'   => isset($c['no']) ? (int) $c['no'] : 0,
        ];
    }
    $sig = md5(wp_json_encode($seen));
    $cnt = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    if ($cnt === count($seen) && get_option('m3ut_links_sig') === $sig) return; // kono poriborton nei

    $ex = $wpdb->get_results("SELECT url_hash, cname, cgroup, seq FROM $t", OBJECT_K);
    $ex = is_array($ex) ? $ex : [];

    // 1) notun channel / URL bodle gele (notun hash) — "Check hoyni" hishebe jog
    $new = [];
    foreach ($seen as $uh => $r) if (!isset($ex[$uh])) $new[$uh] = $r;
    foreach (array_chunk($new, 100, true) as $chunk) {
        $vals = [];
        foreach ($chunk as $uh => $r) $vals[] = $wpdb->prepare("(%s, %s, %s, %s, %s, %d, 'unknown')", $uh, $r['chash'], $r['name'], $r['group'], $r['url'], $r['seq']);
        $wpdb->query("INSERT INTO $t (url_hash, chash, cname, cgroup, url, seq, status) VALUES " . implode(',', $vals)
            . " ON DUPLICATE KEY UPDATE chash=VALUES(chash), cname=VALUES(cname), cgroup=VALUES(cgroup), seq=VALUES(seq)");
    }

    // 2) naam / group / position bodlale update
    foreach ($seen as $uh => $r) {
        if (!isset($ex[$uh])) continue;
        $o = $ex[$uh];
        if ($o->cname !== $r['name'] || $o->cgroup !== $r['group'] || (int) $o->seq !== $r['seq']) {
            $wpdb->update($t, ['cname' => $r['name'], 'cgroup' => $r['group'], 'seq' => $r['seq']], ['url_hash' => $uh]);
        }
    }

    // 3) file-e ar nei emon channel muche dei
    $stale = array_values(array_diff(array_keys($ex), array_keys($seen)));
    foreach (array_chunk($stale, 200) as $chunk) {
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE url_hash IN (" . implode(',', array_fill(0, count($chunk), '%s')) . ")", $chunk));
    }
    update_option('m3ut_links_sig', $sig, false);
}

function m3ut_tab_checker() {
    global $wpdb;
        m3ut_links_sync(); // Dead link checker-er list ke channels file-er shathe mil kori
    $t = m3ut_t('links');
    $st = m3ut_scan_state();
    $left = isset($st['queue']) ? count($st['queue']) : 0;
    $total = isset($st['total']) ? (int) $st['total'] : 0;
    $counts = ['ok' => 0, 'dead' => 0, 'skip' => 0, 'unknown' => 0];
    foreach ((array) $wpdb->get_results("SELECT status, COUNT(*) c FROM $t GROUP BY status") as $r) $counts[$r->status] = (int) $r->c;
    $dupNames = array_flip((array) $wpdb->get_col("SELECT cname FROM $t GROUP BY cname HAVING COUNT(*) > 1"));
    $dupCount = $dupNames ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $t t1 WHERE (SELECT COUNT(*) FROM $t t2 WHERE t2.cname = t1.cname) > 1") : 0;
    $filter = isset($_GET['st']) ? sanitize_key($_GET['st']) : 'dead';
    if (!in_array($filter, ['all', 'dead', 'ok', 'skip', 'unknown', 'duplicate'], true)) $filter = 'dead';
    if ($filter === 'duplicate') {
        $rows = $dupNames ? $wpdb->get_results("SELECT * FROM $t t1 WHERE (SELECT COUNT(*) FROM $t t2 WHERE t2.cname = t1.cname) > 1 ORDER BY cname, seq LIMIT 500") : [];
    } else {
        $rows = $filter === 'all'
            ? $wpdb->get_results("SELECT * FROM $t ORDER BY FIELD(status,'dead','unknown','skip','ok'), cname LIMIT 500")
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status=%s ORDER BY cname LIMIT 500", $filter));
    }
    $s = m3ut_settings();
    $base = admin_url('admin.php?page=m3ut-channels');
    $remote = m3ut_is_remote_path(m3ut_channels_file());
    $logoMap = [];
    foreach ((array) m3ut_read_channels() as $c) $logoMap[$c['uh']] = $c['logo'];
    ?>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js" onerror="window.m3utHlsFailed=true"></script>
    <style>
        #m3ut-dchk-table.m3ut-hide-no [data-col="no"],
        #m3ut-dchk-table.m3ut-hide-logo [data-col="logo"],
        #m3ut-dchk-table.m3ut-hide-name [data-col="name"],
        #m3ut-dchk-table.m3ut-hide-group [data-col="group"],
        #m3ut-dchk-table.m3ut-hide-status [data-col="status"],
        #m3ut-dchk-table.m3ut-hide-http [data-col="http"],
        #m3ut-dchk-table.m3ut-hide-error [data-col="error"],
        #m3ut-dchk-table.m3ut-hide-lastok [data-col="lastok"],
        #m3ut-dchk-table.m3ut-hide-url [data-col="url"],
        #m3ut-dchk-table.m3ut-hide-action [data-col="action"] { display:none }
        #m3ut-dchk-grid-wrap { gap:14px; grid-template-columns:repeat(var(--m3ut-dchk-cols,5), minmax(0,1fr)); }
        #m3ut-dchk-grid-wrap .m3ut-dchk-card { min-width:0; max-width:none; box-sizing:border-box }

    </style>
    <div class="m3ut-row" style="margin-top:16px">
        <div class="m3ut-card"><h3>✅ Cholche</h3><p class="m3ut-big" style="color:#00a32a"><?php echo $counts['ok']; ?></p></div>
        <div class="m3ut-card"><h3>💀 Dead</h3><p class="m3ut-big" style="color:#d63638"><?php echo $counts['dead']; ?></p></div>
        <div class="m3ut-card"><h3>Check hoyni</h3><p class="m3ut-big"><?php echo $counts['unknown']; ?></p></div>
        <div class="m3ut-card"><h3>Skip (rtmp/udp)</h3><p class="m3ut-big"><?php echo $counts['skip']; ?></p></div>
        <div class="m3ut-card"><h3>🧬 Duplicate</h3><p class="m3ut-big" style="color:#8e44ad"><?php echo $dupCount; ?></p></div>
    </div>

    <div class="m3ut-card" style="margin-bottom:16px">
        <button id="m3ut-scan-btn" class="button button-primary"><?php echo $left > 0 ? '▶ Scan continue' : '🔍 Ekhoni scan korun'; ?></button>
        <span style="margin-left:10px">Auto scan: <?php echo $s['auto_scan'] ? 'proti ' . (int) $s['scan_interval_hours'] . ' ghonta por por (cron)' : 'off'; ?> · Shesh scan: <?php echo !empty($st['finished']) ? esc_html($st['finished']) : ($left > 0 ? 'cholche…' : '—'); ?></span>
        <div id="m3ut-prog" style="display:<?php echo $left > 0 ? 'block' : 'none'; ?>;margin-top:10px">
            <div style="background:#e0e0e0;border-radius:4px;height:14px;overflow:hidden"><div id="m3ut-bar" style="background:#2271b1;height:14px;width:<?php echo $total ? round(($total - $left) / $total * 100) : 0; ?>%"></div></div>
            <p id="m3ut-txt" style="margin:6px 0 0"><?php echo ($total - $left) . ' / ' . $total; ?></p>
        </div>
        <p class="description" style="margin-bottom:0">Ekta channel prothome fail korle abar ekbar try kore, tarpor "dead" dhore. Server theke check kora hoy, tai geo-blocked ba token/portal (mac=…) link kokhono bhul kore dead dekhate pare. Settings-e "Dead channel playlist theke lukao" on korle dead gulo player-e ar jabe na.</p>
    </div>

    <div class="m3ut-card" style="margin:16px 0">
        <p>
            <button type="button" class="button" id="m3ut-dchk-view-list">☰ List view</button>
            <button type="button" class="button" id="m3ut-dchk-view-grid">▦ Grid view</button>
            <input type="text" id="m3ut-dchk-filter" placeholder="Naam/group diye khujun…" class="regular-text" style="margin-left:10px">
            <label style="margin-left:10px;font-weight:400">Grid-e ek row-e: <select id="m3ut-dchk-grid-cols">
                <?php foreach ([4, 5, 6, 8, 10] as $n): ?><option value="<?php echo (int) $n; ?>" <?php selected($n, 5); ?>><?php echo (int) $n; ?> ta channel</option><?php endforeach; ?>
            </select></label>
        </p>
        <p id="m3ut-dchk-col-box" style="border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;margin-bottom:0">
            <strong style="margin-right:8px">Column dekhaben:</strong>
            <?php foreach (['no' => 'No', 'logo' => 'Logo', 'name' => 'Naam', 'group' => 'Group', 'status' => 'Status', 'http' => 'HTTP', 'error' => 'Error', 'lastok' => 'Last OK', 'url' => 'URL', 'action' => 'Action'] as $k => $label): ?>
                <label style="margin-right:14px;font-weight:400"><input type="checkbox" class="m3ut-dchk-col-toggle" data-col="<?php echo esc_attr($k); ?>" checked> <?php echo esc_html($label); ?></label>
            <?php endforeach; ?>
        </p>
    </div>

    <p>
        <?php foreach (['dead' => 'Dead', 'unknown' => 'Check hoyni', 'ok' => 'OK', 'skip' => 'Skip', 'duplicate' => '🧬 Duplicate', 'all' => 'All'] as $k => $l): ?>
            <a class="button <?php echo $filter === $k ? 'button-primary' : ''; ?>" href="<?php echo esc_url($base . '&st=' . $k); ?>"><?php echo esc_html($l); ?></a>
        <?php endforeach; ?>
    </p>
        <div id="m3ut-dchk-list-wrap" class="m3ut-scroll"><table class="widefat striped" id="m3ut-dchk-table">
        <thead><tr>
            <th data-col="no">No</th><th data-col="logo">Logo</th><th data-col="name">Channel</th><th data-col="group">Group</th>
            <th data-col="status">Status</th><th data-col="http">HTTP</th><th data-col="error">Error</th><th data-col="lastok">Last OK</th>
            <th data-col="url">URL</th><th data-col="action">Action</th>
        </tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="10">Ei filter-e kichu nei.</td></tr><?php endif; ?>
        <?php foreach ((array) $rows as $r):
            $col = ['ok' => '#00a32a', 'dead' => '#d63638', 'skip' => '#8c8f94', 'unknown' => '#dba617'][$r->status];
            $hidden = ($r->status === 'dead' && !empty($s['hide_dead']));
            $rlogo = isset($logoMap[$r->url_hash]) ? $logoMap[$r->url_hash] : ''; ?>
            <tr class="m3ut-dchk-row" data-uh="<?php echo esc_attr($r->url_hash); ?>" data-name="<?php echo esc_attr(strtolower($r->cname . ' ' . $r->cgroup)); ?>">
            <td data-col="no"><strong style="font-size:18px;font-weight:800"><?php echo $r->seq ? (int) $r->seq : '—'; ?></strong><?php if ($hidden): ?><br><?php echo m3ut_badge('Playlist-e lukano', '#8c8f94'); ?><?php elseif ($r->status === 'dead'): ?><br><span style="font-size:11px;color:#b32d2e">Playlist-e ache (lukano off)</span><?php endif; ?></td>
            <td data-col="logo"><img class="m3ut-dchk-logo-img" src="<?php echo esc_url($rlogo); ?>" style="width:32px;height:32px;object-fit:cover;border-radius:4px;<?php echo $rlogo ? '' : 'display:none'; ?>" onerror="this.style.display='none'"></td>
            <td data-col="name" class="m3ut-dchk-name"><strong><?php echo esc_html($r->cname); ?></strong><?php if (isset($dupNames[$r->cname])): ?><br><?php echo m3ut_badge('🧬 Duplicate', '#8e44ad'); ?><?php endif; ?></td><td data-col="group" class="m3ut-dchk-group"><?php echo esc_html($r->cgroup); ?></td>
            <td data-col="status"><?php echo m3ut_badge($r->status, $col); ?> <?php echo m3ut_chan_uptime_badge($r->url_hash); ?></td><td data-col="http"><?php echo (int) $r->http_code ?: '—'; ?></td>
            <td data-col="error"><?php echo esc_html($r->error); ?></td><td data-col="lastok"><?php echo $r->last_ok ? esc_html($r->last_ok) : '—'; ?></td>
            <td data-col="url" class="m3ut-dchk-url"><?php echo m3ut_url_pretty($r->url, 60); ?></td>
            <td data-col="action">
                <button type="button" class="button button-small m3ut-btn-test" data-url="<?php echo esc_attr($r->url); ?>" data-name="<?php echo esc_attr($r->cname); ?>">▶ Play</button>
                <?php if (!$remote): ?>
                <button type="button" class="button button-small m3ut-dchk-edit">✏ Edit</button>
                <button type="button" class="button button-small m3ut-dchk-del" style="color:#d63638" data-name="<?php echo esc_attr($r->cname); ?>">🗑 Delete</button>
                <?php endif; ?>
            </td>
            </tr>
            <tr class="m3ut-dchk-edit-row" style="display:none"><td colspan="10">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                    <div><label>Naam</label><br><input type="text" class="m3ut-dchk-e-name" value="<?php echo esc_attr($r->cname); ?>"></div>
                    <div><label>Group</label><br><input type="text" class="m3ut-dchk-e-group" value="<?php echo esc_attr($r->cgroup); ?>"></div>
                    <div><label>Logo URL</label><br><input type="url" class="m3ut-dchk-e-logo" value="<?php echo esc_attr(isset($logoMap[$r->url_hash]) ? $logoMap[$r->url_hash] : ''); ?>" style="width:220px"></div>
                    <div><label>Stream URL</label><br><input type="text" class="m3ut-dchk-e-url" value="<?php echo esc_attr($r->url); ?>" style="width:280px"></div>
                    <button type="button" class="button button-primary m3ut-dchk-e-save">💾 Save</button>
                    <button type="button" class="button m3ut-dchk-e-cancel">Cancel</button>
                    <span class="m3ut-dchk-e-msg" style="color:#d63638"></span>
                </div>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>

        <div id="m3ut-dchk-grid-wrap" style="display:none">
            <?php foreach ((array) $rows as $r):
                $col = ['ok' => '#00a32a', 'dead' => '#d63638', 'skip' => '#8c8f94', 'unknown' => '#dba617'][$r->status];
                $rlogo = isset($logoMap[$r->url_hash]) ? $logoMap[$r->url_hash] : ''; ?>
                <div class="m3ut-card m3ut-dchk-card" data-uh="<?php echo esc_attr($r->url_hash); ?>" data-name="<?php echo esc_attr(strtolower($r->cname . ' ' . $r->cgroup)); ?>" style="text-align:center">
                    <div class="m3ut-dchk-gcard-view">
                        <img class="m3ut-dchk-g-logo-img" src="<?php echo esc_url($rlogo); ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px;<?php echo $rlogo ? '' : 'display:none'; ?>" onerror="this.style.display='none'">
                        <p class="m3ut-dchk-g-namep" style="font-weight:700;margin:8px 0 2px;word-break:break-word"><?php echo esc_html($r->cname); ?></p>
                        <?php if (isset($dupNames[$r->cname])): ?><p style="margin:0 0 4px"><?php echo m3ut_badge('🧬 Duplicate', '#8e44ad'); ?></p><?php endif; ?>
                        <p style="margin:0 0 6px;font-size:12px"><strong style="font-size:18px;font-weight:800"><?php echo $r->seq ? (int) $r->seq : '—'; ?></strong> <span style="opacity:.7">· <?php echo esc_html($r->cgroup ?: '—'); ?></span></p>
                                                <p style="margin:0 0 8px"><?php echo m3ut_badge($r->status, $col); ?> <?php echo m3ut_chan_uptime_badge($r->url_hash); ?> <?php if ((int) $r->http_code): ?><span style="font-size:11px;opacity:.7;margin-left:4px">HTTP <?php echo (int) $r->http_code; ?></span><?php endif; ?></p>
                        <?php if ($r->error): ?><p style="margin:0 0 8px;font-size:11px;color:#b32d2e"><?php echo esc_html($r->error); ?></p><?php endif; ?>
                        <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                            <button type="button" class="button button-small m3ut-btn-test" data-url="<?php echo esc_attr($r->url); ?>" data-name="<?php echo esc_attr($r->cname); ?>">▶ Play</button>
                            <?php if (!$remote): ?>
                            <button type="button" class="button button-small m3ut-dchk-gbtn-edit">✏ Edit</button>
                            <button type="button" class="button button-small m3ut-dchk-gbtn-del" style="color:#d63638" data-name="<?php echo esc_attr($r->cname); ?>">🗑 Delete</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$remote): ?>
                    <div class="m3ut-dchk-gcard-edit" style="display:none;text-align:left;margin-top:10px">
                        <label style="font-size:12px;font-weight:600">Naam</label>
                        <input type="text" class="m3ut-dchk-g-name regular-text" value="<?php echo esc_attr($r->cname); ?>" style="width:100%">
                        <label style="font-size:12px;font-weight:600">Group</label>
                        <input type="text" class="m3ut-dchk-g-group regular-text" value="<?php echo esc_attr($r->cgroup); ?>" style="width:100%">
                        <label style="font-size:12px;font-weight:600">Logo URL</label>
                        <input type="url" class="m3ut-dchk-g-logo regular-text" value="<?php echo esc_attr($rlogo); ?>" style="width:100%">
                        <label style="font-size:12px;font-weight:600">Stream URL</label>
                        <input type="text" class="m3ut-dchk-g-url regular-text" value="<?php echo esc_attr($r->url); ?>" style="width:100%">
                        <p style="margin:8px 0 0">
                            <button type="button" class="button button-primary button-small m3ut-dchk-gbtn-save">💾 Save</button>
                            <button type="button" class="button button-small m3ut-dchk-gbtn-cancel">Cancel</button>
                        </p>
                        <p class="m3ut-dchk-g-msg" style="color:#d63638;font-size:12px;margin:4px 0 0"></p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$rows): ?><p>Ei filter-e kichu nei.</p><?php endif; ?>
        </div>

    <div id="m3ut-play-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;align-items:center;justify-content:center;padding:10px;box-sizing:border-box">
        <div style="background:#1d2327;padding:10px;border-radius:8px;width:100%;max-width:360px;max-height:95vh;overflow:auto;box-sizing:border-box">
            <p style="color:#fff;margin:0 0 8px;font-size:12px;word-break:break-word" id="m3ut-play-title"></p>
            <div style="position:relative;width:100%;aspect-ratio:16/9;background:#000">
                <video id="m3ut-play-video" controls autoplay playsinline style="position:absolute;inset:0;width:100%;height:100%;background:#000"></video>
            </div>
            <p id="m3ut-play-err" style="color:#f86368;font-size:11px;display:none;margin:8px 0 0"></p>
            <p style="margin:10px 0 0;display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="button button-small" id="m3ut-play-close" style="background:#d63638;border-color:#d63638;color:#fff">Bondho korun</button>
                <a id="m3ut-play-open" class="button button-small" target="_blank" rel="noopener" style="background:#2271b1;border-color:#2271b1;color:#fff">Notun tab-e khulun</a>
                <button type="button" class="button button-small" id="m3ut-play-copy" style="background:#00a32a;border-color:#00a32a;color:#fff">Copy URL</button>
            </p>
            <p style="color:#dba617;font-size:11px;max-width:100%;margin:8px 0 0">Note: browser shob format (ts/rtmp/mac-portal) play korte pare na — na cholle "notun tab-e khulun" diye VLC/player-e test korun.</p>
        </div>
    </div>

    <script>
    (function(){
        var nonce=<?php echo wp_json_encode(wp_create_nonce('m3ut_scan')); ?>, url=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

        /* -- test play (mini player modal) -- */
        var hls = null;
        function testPlay(purl, name) {
            document.getElementById('m3ut-play-title').textContent = name;
            document.getElementById('m3ut-play-open').href = purl;
            document.getElementById('m3ut-play-err').style.display = 'none';
            document.getElementById('m3ut-play-copy').onclick = function () { if (navigator.clipboard) navigator.clipboard.writeText(purl); };
            var v = document.getElementById('m3ut-play-video');
            v.removeAttribute('src'); v.load();
            if (hls) { hls.destroy(); hls = null; }
            function showErr(msg) { var e = document.getElementById('m3ut-play-err'); e.textContent = msg; e.style.display = 'block'; }
            if (purl.indexOf('.m3u8') > -1) {
                if (window.Hls && Hls.isSupported()) {
                    hls = new Hls();
                    hls.on(Hls.Events.ERROR, function (ev, data) { if (data && data.fatal) showErr('Stream load hoyni (' + data.type + ') — "notun tab-e khulun" diye VLC-e test korun.'); });
                    hls.loadSource(purl); hls.attachMedia(v);
                } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
                    v.src = purl;
                } else {
                    showErr('Ei browser-e HLS play support nei (hls.js load hoyni ba unsupported) — "notun tab-e khulun" diye VLC/player-e test korun.');
                }
            } else {
                v.src = purl;
                v.play().catch(function () { showErr('Ei format shorasori browser-e play hoy na — "notun tab-e khulun" diye VLC/player-e test korun.'); });
            }
            document.getElementById('m3ut-play-modal').style.display = 'flex';
        }
        document.getElementById('m3ut-play-close').onclick = function () {
            var v = document.getElementById('m3ut-play-video'); v.pause(); v.removeAttribute('src'); v.load();
            if (hls) { hls.destroy(); hls = null; }
            document.getElementById('m3ut-play-modal').style.display = 'none';
        };
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-btn-test');
            if (b) testPlay(b.getAttribute('data-url'), b.getAttribute('data-name'));
        });

        /* -- search filter (naam/group) -- */
        var dchkFilter = document.getElementById('m3ut-dchk-filter');
        if (dchkFilter) {
            dchkFilter.addEventListener('input', function () {
                var q = dchkFilter.value.toLowerCase();
                document.querySelectorAll('.m3ut-dchk-row').forEach(function (row) {
                    var show = row.getAttribute('data-name').indexOf(q) > -1;
                    row.style.display = show ? '' : 'none';
                    var editRow = row.nextElementSibling;
                    if (editRow && editRow.classList.contains('m3ut-dchk-edit-row') && !show) editRow.style.display = 'none';
                });
                document.querySelectorAll('.m3ut-dchk-card').forEach(function (card) {
                    card.style.display = card.getAttribute('data-name').indexOf(q) > -1 ? '' : 'none';
                });
            });
        }

        /* -- list/grid toggle -- */
        var dchkListWrap = document.getElementById('m3ut-dchk-list-wrap');
        var dchkGridWrap = document.getElementById('m3ut-dchk-grid-wrap');
        document.getElementById('m3ut-dchk-view-list').onclick = function () { dchkListWrap.style.display = 'block'; dchkGridWrap.style.display = 'none'; };
        document.getElementById('m3ut-dchk-view-grid').onclick = function () { dchkListWrap.style.display = 'none'; dchkGridWrap.style.display = 'grid'; };

        /* -- grid columns (koyta channel ek row-e), saved in this browser -- */
        var dchkColsSel = document.getElementById('m3ut-dchk-grid-cols');
        function applyDchkGridCols() { dchkGridWrap.style.setProperty('--m3ut-dchk-cols', parseInt(dchkColsSel.value, 10) || 5); }
        try {
            var savedDchkGridCols = localStorage.getItem('m3ut_dchk_grid_cols');
            if (savedDchkGridCols) dchkColsSel.value = savedDchkGridCols;
        } catch (e) {}
        applyDchkGridCols();
        dchkColsSel.addEventListener('change', function () {
            applyDchkGridCols();
            try { localStorage.setItem('m3ut_dchk_grid_cols', dchkColsSel.value); } catch (e) {}
        });

        /* -- column show/hide, saved in this browser -- */
        var dchkTable = document.getElementById('m3ut-dchk-table');
        function applyDchkCols() {
            document.querySelectorAll('.m3ut-dchk-col-toggle').forEach(function (cb) {
                dchkTable.classList.toggle('m3ut-hide-' + cb.getAttribute('data-col'), !cb.checked);
            });
        }
        try {
            var savedDchkCols = JSON.parse(localStorage.getItem('m3ut_dchk_cols') || '{}');
            document.querySelectorAll('.m3ut-dchk-col-toggle').forEach(function (cb) {
                var k = cb.getAttribute('data-col');
                if (savedDchkCols.hasOwnProperty(k)) cb.checked = savedDchkCols[k];
            });
        } catch (e) {}
        applyDchkCols();
        document.querySelectorAll('.m3ut-dchk-col-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                applyDchkCols();
                var state = {};
                document.querySelectorAll('.m3ut-dchk-col-toggle').forEach(function (x) { state[x.getAttribute('data-col')] = x.checked; });
                try { localStorage.setItem('m3ut_dchk_cols', JSON.stringify(state)); } catch (e) {}
            });
        });

        var left=<?php echo (int) $left; ?>, btn=document.getElementById('m3ut-scan-btn');
        function call(d){var p=new URLSearchParams();p.append('action','m3ut_scan');p.append('do',d);p.append('nonce',nonce);
            return fetch(url,{method:'POST',credentials:'same-origin',body:p}).then(function(r){return r.json();});}
        function upd(s){document.getElementById('m3ut-prog').style.display='block';
            var done=Math.max(0,s.total-s.left);document.getElementById('m3ut-bar').style.width=(s.total?Math.round(done/s.total*100):0)+'%';
            document.getElementById('m3ut-txt').textContent=done+' / '+s.total;}
        function loop(){call('step').then(function(r){
            if(!r.success){document.getElementById('m3ut-txt').textContent='Error, page reload diye abar try korun';return;}
            upd(r.data); if(r.data.left>0){loop();}else{location.href=<?php echo wp_json_encode($base . '&st=dead'); ?>;}
        }).catch(function(){document.getElementById('m3ut-txt').textContent='Network error';btn.disabled=false;});}
        btn.onclick=function(){btn.disabled=true;
            if(left>0){loop();}else{call('start').then(function(r){if(r.success){upd(r.data);loop();}});}};

        /* -- per-row edit/delete (Dead link checker table) -- */
        var CNONCE = <?php echo wp_json_encode(wp_create_nonce('m3ut_chan_nonce')); ?>;
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dchk-edit, .m3ut-dchk-e-cancel');
            if (!b) return;
            var row = b.closest('tr').classList.contains('m3ut-dchk-edit-row') ? b.closest('tr') : b.closest('tr').nextElementSibling;
            if (row && row.classList.contains('m3ut-dchk-edit-row')) row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
        });
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dchk-e-save');
            if (!b) return;
            var editRow = b.closest('tr');
            var mainRow = editRow.previousElementSibling;
            var uh = mainRow.getAttribute('data-uh');
            var msg = editRow.querySelector('.m3ut-dchk-e-msg');
            var name = editRow.querySelector('.m3ut-dchk-e-name').value.trim();
            var group = editRow.querySelector('.m3ut-dchk-e-group').value.trim();
            var logo = editRow.querySelector('.m3ut-dchk-e-logo').value.trim();
            var curl = editRow.querySelector('.m3ut-dchk-e-url').value.trim();
            msg.textContent = 'Saving…'; b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_edit'); body.append('nonce', CNONCE); body.append('uh', uh);
            body.append('name', name); body.append('group', group); body.append('logo', logo); body.append('url', curl);
            fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    b.disabled = false;
                    if (!res || !res.success) { msg.textContent = (res && res.data) ? res.data : 'Update fail hoyeche'; return; }
                    var d = res.data;
                    mainRow.setAttribute('data-uh', d.uh);
                    mainRow.setAttribute('data-name', (d.name + ' ' + d.group).toLowerCase());
                    mainRow.querySelector('.m3ut-dchk-name strong').textContent = d.name;
                    mainRow.querySelector('.m3ut-dchk-group').textContent = d.group;
                    var img = mainRow.querySelector('.m3ut-dchk-logo-img');
                    if (img) { if (d.logo) { img.src = d.logo; img.style.display = ''; } else { img.removeAttribute('src'); img.style.display = 'none'; } }
                    var uc = mainRow.querySelector('.m3ut-dchk-url code');
                    uc.textContent = d.url.length > 60 ? d.url.slice(0, 60) + '…' : d.url;
                    uc.title = d.url;
                    var delBtn = mainRow.querySelector('.m3ut-dchk-del');
                    if (delBtn) delBtn.setAttribute('data-name', d.name);
                    msg.textContent = '';
                    editRow.style.display = 'none';
                })
                .catch(function () { b.disabled = false; msg.textContent = 'Network error, abar try korun'; });
        });
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dchk-del');
            if (!b) return;
            var name = b.getAttribute('data-name');
            if (!confirm(name + ' delete korben? (channels file theke-o muche jabe)')) return;
            var row = b.closest('.m3ut-dchk-row');
            var uh = row.getAttribute('data-uh');
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_delete'); body.append('nonce', CNONCE); body.append('uh', uh);
            fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { b.disabled = false; alert((res && res.data) ? res.data : 'Delete fail hoyeche'); return; }
                    var editRow = row.nextElementSibling;
                    row.remove();
                    if (editRow && editRow.classList.contains('m3ut-dchk-edit-row')) editRow.remove();
                })
                .catch(function () { b.disabled = false; alert('Network error, abar try korun'); });
        });

        /* -- grid card: edit toggle -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dchk-gbtn-edit, .m3ut-dchk-gbtn-cancel');
            if (!b) return;
            var card = b.closest('.m3ut-dchk-card');
            var edit = card.querySelector('.m3ut-dchk-gcard-edit');
            if (edit) edit.style.display = (edit.style.display === 'none') ? 'block' : 'none';
        });

        /* -- grid card: save edit (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dchk-gbtn-save');
            if (!b) return;
            var card = b.closest('.m3ut-dchk-card');
            var uh = card.getAttribute('data-uh');
            var msg = card.querySelector('.m3ut-dchk-g-msg');
            var name = card.querySelector('.m3ut-dchk-g-name').value.trim();
            var group = card.querySelector('.m3ut-dchk-g-group').value.trim();
            var logo = card.querySelector('.m3ut-dchk-g-logo').value.trim();
            var curl = card.querySelector('.m3ut-dchk-g-url').value.trim();
            msg.textContent = 'Saving…';
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_edit'); body.append('nonce', CNONCE); body.append('uh', uh);
            body.append('name', name); body.append('group', group); body.append('logo', logo); body.append('url', curl);
            fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    b.disabled = false;
                    if (!res || !res.success) { msg.textContent = (res && res.data) ? res.data : 'Update fail hoyeche'; return; }
                    var d = res.data;
                    card.setAttribute('data-uh', d.uh);
                    card.setAttribute('data-name', (d.name + ' ' + d.group).toLowerCase());
                    card.querySelector('.m3ut-dchk-g-namep').textContent = d.name;
                    var img = card.querySelector('.m3ut-dchk-g-logo-img');
                    if (img) { if (d.logo) { img.src = d.logo; img.style.display = ''; } else { img.removeAttribute('src'); img.style.display = 'none'; } }
                    var testBtn = card.querySelector('.m3ut-btn-test');
                    testBtn.setAttribute('data-url', d.url); testBtn.setAttribute('data-name', d.name);
                    var delBtn = card.querySelector('.m3ut-dchk-gbtn-del');
                    if (delBtn) delBtn.setAttribute('data-name', d.name);
                    msg.textContent = '';
                    card.querySelector('.m3ut-dchk-gcard-edit').style.display = 'none';
                })
                .catch(function () { b.disabled = false; msg.textContent = 'Network error, abar try korun'; });
        });

        /* -- grid card: delete (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dchk-gbtn-del');
            if (!b) return;
            var name = b.getAttribute('data-name');
            if (!confirm(name + ' delete korben? (channels file theke-o muche jabe)')) return;
            var card = b.closest('.m3ut-dchk-card');
            var uh = card.getAttribute('data-uh');
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_delete'); body.append('nonce', CNONCE); body.append('uh', uh);
            fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { b.disabled = false; alert((res && res.data) ? res.data : 'Delete fail hoyeche'); return; }
                    card.remove();
                })
                .catch(function () { b.disabled = false; alert('Network error, abar try korun'); });
        });
    })();
    </script>
    <?php
}

function m3ut_tab_list() {
    $file = m3ut_channels_file();
    $remote = m3ut_is_remote_path($file);
    $chs = (array) m3ut_read_channels();
    $groups = [];
    foreach ($chs as $c) if ($c['group'] !== '') $groups[$c['group']] = 1;
    $cols = ['no' => 'No', 'logo' => 'Logo', 'name' => 'Naam', 'group' => 'Group', 'url' => 'URL', 'action' => 'Action'];

    // duplicate naam detect (case + extra space bad diye match)
    $nameCounts = [];
    foreach ($chs as $c) {
        $k = m3ut_norm_name($c['name']);
        if ($k === '') continue;
        $nameCounts[$k] = (isset($nameCounts[$k]) ? $nameCounts[$k] : 0) + 1;
    }
    $dupNameTotal = 0;
    foreach ($nameCounts as $n) if ($n > 1) $dupNameTotal++;
    ?>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js" onerror="window.m3utHlsFailed=true"></script>
    <style>
        #m3ut-chan-table.m3ut-hide-no [data-col="no"],
        #m3ut-chan-table.m3ut-hide-logo [data-col="logo"],
        #m3ut-chan-table.m3ut-hide-name [data-col="name"],
        #m3ut-chan-table.m3ut-hide-group [data-col="group"],
        #m3ut-chan-table.m3ut-hide-url [data-col="url"],
        #m3ut-chan-table.m3ut-hide-action [data-col="action"] { display:none }
        #m3ut-grid-wrap { gap:14px; grid-template-columns:repeat(var(--m3ut-cols,5), minmax(0,1fr)); }
        #m3ut-grid-wrap .m3ut-chan-card { min-width:0; max-width:none; box-sizing:border-box }

    </style>

    <div class="m3ut-card" style="margin-top:16px">
        <p><strong><?php echo count($chs); ?></strong> ta channel. Ekhan theke shorashori add / edit / delete / test-play kora jabe. Save korar age auto-backup hoy (editor tab-er "Backups" e pawa jabe).
        <?php if ($dupNameTotal): ?> · <a href="<?php echo esc_url(admin_url('admin.php?page=m3ut-channels&tab=duplicate')); ?>" style="color:#8e44ad;font-weight:600">🧬 <?php echo $dupNameTotal; ?> ta naam duplicate paowa gyeche — dekhun</a><?php endif; ?>
        </p>
        <?php if ($remote): ?>
            <p class="description" style="color:#b32d2e"><?php echo esc_html(m3ut_remote_block_msg()); ?> (view/test-play cholbe, add/edit/delete noy)</p>
        <?php endif; ?>
        <p>
            <button type="button" class="button" id="m3ut-view-list">☰ List view</button>
            <button type="button" class="button" id="m3ut-view-grid">▦ Grid view</button>
            <?php if (!$remote): ?><button type="button" class="button button-primary" onclick="document.getElementById('m3ut-add-box').style.display='block';this.style.display='none'">+ Notun channel add</button><?php endif; ?>
            <input type="text" id="m3ut-filter" placeholder="Naam/group diye khujun…" class="regular-text" style="margin-left:10px">
            <label style="margin-left:10px;font-weight:400">Grid-e ek row-e: <select id="m3ut-grid-cols">
                <?php foreach ([4, 5, 6, 8, 10] as $n): ?><option value="<?php echo (int) $n; ?>" <?php selected($n, 5); ?>><?php echo (int) $n; ?> ta channel</option><?php endforeach; ?>
            </select></label>
        </p>

        <p id="m3ut-col-box" style="border:1px solid #dcdcde;border-radius:6px;padding:8px 12px">
            <strong style="margin-right:8px">Column dekhaben:</strong>
            <?php foreach ($cols as $k => $label): ?>
                <label style="margin-right:14px;font-weight:400"><input type="checkbox" class="m3ut-col-toggle" data-col="<?php echo esc_attr($k); ?>" checked> <?php echo esc_html($label); ?></label>
            <?php endforeach; ?>
        </p>

        <?php if (!$remote): ?>
        <div id="m3ut-add-box" class="m3ut-form" style="display:none;border:1px solid #dcdcde;border-radius:6px;padding:12px;margin:16px 0;max-width:640px">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('m3ut_editor_add'); ?>
                <input type="hidden" name="action" value="m3ut_editor_add">
                <input type="hidden" name="tab" value="list">
                <label>Naam</label><input type="text" name="name" class="regular-text" required>
                <label>Group</label><input type="text" name="group" class="regular-text" list="m3ut-groups">
                <datalist id="m3ut-groups"><?php foreach (array_keys($groups) as $g) echo '<option value="' . esc_attr($g) . '">'; ?></datalist>
                <label>Logo URL</label><input type="url" name="logo" class="large-text">
                <label>Stream URL</label><input type="text" name="url" class="large-text" required>
                <p><button class="button button-primary">Add</button></p>
            </form>
        </div>
        <?php endif; ?>

        <div id="m3ut-list-wrap" class="m3ut-scroll"><table class="widefat striped" id="m3ut-chan-table">
            <thead><tr>
                <th data-col="no" style="width:40px">No</th><th data-col="logo" style="width:50px">Logo</th>
                <th data-col="name">Naam</th><th data-col="group">Group</th><th data-col="url">URL</th><th data-col="action" style="width:230px">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($chs as $c): ?>
                <tr class="m3ut-chan-row" data-uh="<?php echo esc_attr($c['uh']); ?>" data-name="<?php echo esc_attr(strtolower($c['name'] . ' ' . $c['group'])); ?>">
                    <td data-col="no" style="font-size:18px;font-weight:800"><?php echo (int) $c['no']; ?></td>
                    <td data-col="logo"><?php echo $c['logo'] ? '<img src="' . esc_url($c['logo']) . '" style="width:32px;height:32px;object-fit:cover;border-radius:4px" onerror="this.style.display=\'none\'">' : '—'; ?></td>
                    <td data-col="name" class="m3ut-c-name"><strong><?php echo esc_html($c['name']); ?></strong> <?php echo m3ut_chan_state_badge($c['uh']); ?>
                        <?php $__k = m3ut_norm_name($c['name']); if ($__k !== '' && !empty($nameCounts[$__k]) && $nameCounts[$__k] > 1): ?>
                            <?php echo m3ut_badge('🧬 ' . $nameCounts[$__k] . 'x dup', '#8e44ad'); ?>
                        <?php endif; ?>
                    </td>
                    <td data-col="group" class="m3ut-c-group"><?php echo esc_html($c['group']); ?></td>
                    <td data-col="url" class="m3ut-c-url"><?php echo m3ut_url_pretty($c['url'], 60); ?></td>
                    <td data-col="action">
                        <button type="button" class="button button-small m3ut-btn-test" data-url="<?php echo esc_attr($c['url']); ?>" data-name="<?php echo esc_attr($c['name']); ?>">▶ Test</button>
                        <?php if (!$remote): ?>
                                                <button type="button" class="button button-small m3ut-btn-up" title="Upore nao">▲</button>
                        <button type="button" class="button button-small m3ut-btn-down" title="Niche nao">▼</button>
                        <button type="button" class="button button-small m3ut-btn-pos" title="Number-e nao">🔢</button>
                        <button type="button" class="button button-small m3ut-btn-edit">✏ Edit</button>
                        <button type="button" class="button button-small m3ut-btn-del" style="color:#d63638" data-name="<?php echo esc_attr($c['name']); ?>">🗑 Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="m3ut-edit-row" style="display:none"><td colspan="6">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                        <div><label>Naam</label><br><input type="text" class="m3ut-e-name" value="<?php echo esc_attr($c['name']); ?>"></div>
                        <div><label>Group</label><br><input type="text" class="m3ut-e-group" value="<?php echo esc_attr($c['group']); ?>"></div>
                        <div><label>Logo URL</label><br><input type="url" class="m3ut-e-logo" value="<?php echo esc_attr($c['logo']); ?>" style="width:220px"></div>
                        <div><label>Stream URL</label><br><input type="text" class="m3ut-e-url" value="<?php echo esc_attr($c['url']); ?>" style="width:280px"></div>
                        <button type="button" class="button button-primary m3ut-btn-save">💾 Save</button>
                        <button type="button" class="button m3ut-btn-cancel">Cancel</button>
                        <span class="m3ut-edit-msg" style="color:#d63638"></span>
                    </div>
                </td></tr>
            <?php endforeach; ?>
            <?php if (!$chs): ?><tr><td colspan="6">Kono channel nei.</td></tr><?php endif; ?>
            </tbody>
        </table></div>

        <div id="m3ut-grid-wrap" style="display:none">
            <?php foreach ($chs as $c): ?>
                <div class="m3ut-card m3ut-chan-card" data-uh="<?php echo esc_attr($c['uh']); ?>" data-name="<?php echo esc_attr(strtolower($c['name'] . ' ' . $c['group'])); ?>" style="text-align:center">
                    <div class="m3ut-gcard-view">
                        <img class="m3ut-g-logo-img" src="<?php echo esc_url($c['logo']); ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px;<?php echo $c['logo'] ? '' : 'display:none'; ?>" onerror="this.style.display='none'">
                        <p class="m3ut-g-namep" style="font-weight:700;margin:8px 0 2px;word-break:break-word"><?php echo esc_html($c['name']); ?></p>
                        <?php $__sb = m3ut_chan_state_badge($c['uh']); if ($__sb) echo '<p style="margin:0 0 4px">' . $__sb . '</p>'; ?>
                        <?php $__k = m3ut_norm_name($c['name']); if ($__k !== '' && !empty($nameCounts[$__k]) && $nameCounts[$__k] > 1): ?>
                            <p style="margin:0 0 4px"><?php echo m3ut_badge('🧬 ' . $nameCounts[$__k] . 'x dup', '#8e44ad'); ?></p>
                        <?php endif; ?>
                        <p style="margin:0 0 8px;font-size:12px"><strong style="font-size:18px;font-weight:800"><?php echo (int) $c['no']; ?></strong> <span style="opacity:.7">· <?php echo esc_html($c['group'] ?: '—'); ?></span></p>
                        <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                            <button type="button" class="button button-small m3ut-btn-test" data-url="<?php echo esc_attr($c['url']); ?>" data-name="<?php echo esc_attr($c['name']); ?>">▶ Test</button>
                            <?php if (!$remote): ?>
                            <button type="button" class="button button-small m3ut-gbtn-edit">✏ Edit</button>
                            <button type="button" class="button button-small m3ut-gbtn-del" style="color:#d63638" data-name="<?php echo esc_attr($c['name']); ?>">🗑 Delete</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$remote): ?>
                    <div class="m3ut-gcard-edit" style="display:none;text-align:left;margin-top:10px">
                        <label style="font-size:12px;font-weight:600">Naam</label>
                        <input type="text" class="m3ut-g-name regular-text" value="<?php echo esc_attr($c['name']); ?>" style="width:100%">
                        <label style="font-size:12px;font-weight:600">Group</label>
                        <input type="text" class="m3ut-g-group regular-text" value="<?php echo esc_attr($c['group']); ?>" style="width:100%">
                        <label style="font-size:12px;font-weight:600">Logo URL</label>
                        <input type="url" class="m3ut-g-logo regular-text" value="<?php echo esc_attr($c['logo']); ?>" style="width:100%">
                        <label style="font-size:12px;font-weight:600">Stream URL</label>
                        <input type="text" class="m3ut-g-url regular-text" value="<?php echo esc_attr($c['url']); ?>" style="width:100%">
                        <p style="margin:8px 0 0">
                            <button type="button" class="button button-primary button-small m3ut-gbtn-save">💾 Save</button>
                            <button type="button" class="button button-small m3ut-gbtn-cancel">Cancel</button>
                        </p>
                        <p class="m3ut-g-msg" style="color:#d63638;font-size:12px;margin:4px 0 0"></p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="m3ut-play-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;align-items:center;justify-content:center;padding:10px;box-sizing:border-box">
        <div style="background:#1d2327;padding:10px;border-radius:8px;width:100%;max-width:360px;max-height:95vh;overflow:auto;box-sizing:border-box">
            <p style="color:#fff;margin:0 0 8px;font-size:12px;word-break:break-word" id="m3ut-play-title"></p>
            <div style="position:relative;width:100%;aspect-ratio:16/9;background:#000">
                <video id="m3ut-play-video" controls autoplay playsinline style="position:absolute;inset:0;width:100%;height:100%;background:#000"></video>
            </div>
            <p id="m3ut-play-err" style="color:#f86368;font-size:11px;display:none;margin:8px 0 0"></p>
            <p style="margin:10px 0 0;display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="button button-small" id="m3ut-play-close" style="background:#d63638;border-color:#d63638;color:#fff">Bondho korun</button>
                <a id="m3ut-play-open" class="button button-small" target="_blank" rel="noopener" style="background:#2271b1;border-color:#2271b1;color:#fff">Notun tab-e khulun</a>
                <button type="button" class="button button-small" id="m3ut-play-copy" style="background:#00a32a;border-color:#00a32a;color:#fff">Copy URL</button>
            </p>
            <p style="color:#dba617;font-size:11px;max-width:100%;margin:8px 0 0">Note: browser shob format (ts/rtmp/mac-portal) play korte pare na — na cholle "notun tab-e khulun" diye VLC/player-e test korun.</p>
        </div>
    </div>

    <script>
    (function(){
        var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var NONCE = <?php echo wp_json_encode(wp_create_nonce('m3ut_chan_nonce')); ?>;

        /* -- filter -- */
        var f = document.getElementById('m3ut-filter');
        f.addEventListener('input', function () {
            var q = f.value.toLowerCase();
            document.querySelectorAll('.m3ut-chan-row,.m3ut-chan-card').forEach(function (el) {
                el.style.display = el.getAttribute('data-name').indexOf(q) > -1 ? '' : 'none';
            });
        });

        /* -- list/grid toggle -- */
        var gridWrap = document.getElementById('m3ut-grid-wrap');
        document.getElementById('m3ut-view-list').onclick = function () { document.getElementById('m3ut-list-wrap').style.display = 'block'; gridWrap.style.display = 'none'; };
        document.getElementById('m3ut-view-grid').onclick = function () { document.getElementById('m3ut-list-wrap').style.display = 'none'; gridWrap.style.display = 'grid'; };

        /* -- grid columns (koyta channel ek row-e), saved in this browser -- */
        var colsSel = document.getElementById('m3ut-grid-cols');
        function applyGridCols() { gridWrap.style.setProperty('--m3ut-cols', parseInt(colsSel.value, 10) || 5); }
        try {
            var savedCols = localStorage.getItem('m3ut_grid_cols');
            if (savedCols) colsSel.value = savedCols;
        } catch (e) {}
        applyGridCols();
        colsSel.addEventListener('change', function () {
            applyGridCols();
            try { localStorage.setItem('m3ut_grid_cols', colsSel.value); } catch (e) {}
        });

        /* -- column show/hide, saved in this browser -- */
        var table = document.getElementById('m3ut-chan-table');
        function applyCols() {
            document.querySelectorAll('.m3ut-col-toggle').forEach(function (cb) {
                table.classList.toggle('m3ut-hide-' + cb.getAttribute('data-col'), !cb.checked);
            });
        }
        try {
            var saved = JSON.parse(localStorage.getItem('m3ut_cols') || '{}');
            document.querySelectorAll('.m3ut-col-toggle').forEach(function (cb) {
                var k = cb.getAttribute('data-col');
                if (saved.hasOwnProperty(k)) cb.checked = saved[k];
            });
        } catch (e) {}
        applyCols();
        document.querySelectorAll('.m3ut-col-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                applyCols();
                var state = {};
                document.querySelectorAll('.m3ut-col-toggle').forEach(function (x) { state[x.getAttribute('data-col')] = x.checked; });
                try { localStorage.setItem('m3ut_cols', JSON.stringify(state)); } catch (e) {}
            });
        });

        /* -- test play (event delegation, no inline-string escaping issues) -- */
        var hls = null;
        function testPlay(url, name) {
            document.getElementById('m3ut-play-title').textContent = name;
            document.getElementById('m3ut-play-open').href = url;
            document.getElementById('m3ut-play-err').style.display = 'none';
            document.getElementById('m3ut-play-copy').onclick = function () { if (navigator.clipboard) navigator.clipboard.writeText(url); };
            var v = document.getElementById('m3ut-play-video');
            v.removeAttribute('src'); v.load();
            if (hls) { hls.destroy(); hls = null; }
            function showErr(msg) { var e = document.getElementById('m3ut-play-err'); e.textContent = msg; e.style.display = 'block'; }
            if (url.indexOf('.m3u8') > -1) {
                if (window.Hls && Hls.isSupported()) {
                    hls = new Hls();
                    hls.on(Hls.Events.ERROR, function (ev, data) { if (data && data.fatal) showErr('Stream load hoyni (' + data.type + ') — "notun tab-e khulun" diye VLC-e test korun.'); });
                    hls.loadSource(url); hls.attachMedia(v);
                } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
                    v.src = url;
                } else {
                    showErr('Ei browser-e HLS play support nei (hls.js load hoyni ba unsupported) — "notun tab-e khulun" diye VLC/player-e test korun.');
                }
            } else {
                v.src = url;
                v.play().catch(function () { showErr('Ei format shorasori browser-e play hoy na — "notun tab-e khulun" diye VLC/player-e test korun.'); });
            }
            document.getElementById('m3ut-play-modal').style.display = 'flex';
        }
        document.getElementById('m3ut-play-close').onclick = function () {
            var v = document.getElementById('m3ut-play-video'); v.pause(); v.removeAttribute('src'); v.load();
            if (hls) { hls.destroy(); hls = null; }
            document.getElementById('m3ut-play-modal').style.display = 'none';
        };
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-btn-test');
            if (b) testPlay(b.getAttribute('data-url'), b.getAttribute('data-name'));
        });

        /* -- edit toggle -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-btn-edit, .m3ut-btn-cancel');
            if (!b) return;
            var row = b.closest('tr').classList.contains('m3ut-edit-row') ? b.closest('tr') : b.closest('tr').nextElementSibling;
            if (row && row.classList.contains('m3ut-edit-row')) row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
        });

        /* -- save edit (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-btn-save');
            if (!b) return;
            var editRow = b.closest('tr');
            var mainRow = editRow.previousElementSibling;
            var uh = mainRow.getAttribute('data-uh');
            var msg = editRow.querySelector('.m3ut-edit-msg');
            var name = editRow.querySelector('.m3ut-e-name').value.trim();
            var group = editRow.querySelector('.m3ut-e-group').value.trim();
            var logo = editRow.querySelector('.m3ut-e-logo').value.trim();
            var url = editRow.querySelector('.m3ut-e-url').value.trim();
            msg.textContent = 'Saving…';
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_edit'); body.append('nonce', NONCE); body.append('uh', uh);
            body.append('name', name); body.append('group', group); body.append('logo', logo); body.append('url', url);
            fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    b.disabled = false;
                    if (!res || !res.success) { msg.textContent = (res && res.data) ? res.data : 'Update fail hoyeche'; return; }
                    var d = res.data;
                    mainRow.setAttribute('data-uh', d.uh);
                    mainRow.setAttribute('data-name', (d.name + ' ' + d.group).toLowerCase());
                    mainRow.querySelector('.m3ut-c-name').innerHTML = '<strong></strong>';
                    mainRow.querySelector('.m3ut-c-name strong').textContent = d.name;
                    mainRow.querySelector('.m3ut-c-group').textContent = d.group;
                    var urlCell = mainRow.querySelector('.m3ut-c-url code');
                    urlCell.textContent = d.url.length > 40 ? d.url.slice(0, 40) + '…' : d.url;
                    urlCell.title = d.url;
                    mainRow.querySelector('.m3ut-btn-test').setAttribute('data-url', d.url);
                    mainRow.querySelector('.m3ut-btn-test').setAttribute('data-name', d.name);
                    msg.textContent = '';
                    editRow.style.display = 'none';
                })
                .catch(function () { b.disabled = false; msg.textContent = 'Network error, abar try korun'; });
        });

        /* -- delete (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-btn-del');
            if (!b) return;
            var name = b.getAttribute('data-name');
            if (!confirm(name + ' delete korben?')) return;
            var row = b.closest('.m3ut-chan-row');
            var uh = row.getAttribute('data-uh');
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_delete'); body.append('nonce', NONCE); body.append('uh', uh);
            fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { b.disabled = false; alert((res && res.data) ? res.data : 'Delete fail hoyeche'); return; }
                    var editRow = row.nextElementSibling;
                    row.remove();
                    if (editRow && editRow.classList.contains('m3ut-edit-row')) editRow.remove();
                })
                .catch(function () { b.disabled = false; alert('Network error, abar try korun'); });
        });
        
        
        /* -- channel move: up / down / position (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-btn-up, .m3ut-btn-down, .m3ut-btn-pos');
            if (!b) return;
            var row = b.closest('.m3ut-chan-row');
            var uh = row.getAttribute('data-uh');
            var dir = b.classList.contains('m3ut-btn-up') ? 'up' : (b.classList.contains('m3ut-btn-down') ? 'down' : 'to');
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_move'); body.append('nonce', NONCE); body.append('uh', uh); body.append('dir', dir);
            if (dir === 'to') {
                var total = document.querySelectorAll('#m3ut-chan-table tbody tr.m3ut-chan-row').length;
                var p = prompt('Ei channel-ke koto number position-e nite chan? (1 theke ' + total + ')');
                if (p === null) return;
                p = parseInt(p, 10);
                if (!p || p < 1) { alert('Sothik number din'); return; }
                body.append('pos', p);
            }
            b.disabled = true;
            fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    b.disabled = false;
                    if (!res || !res.success) { alert((res && res.data) ? res.data : 'Move fail hoyeche'); return; }
                    if (dir === 'to') { location.reload(); return; }
                    var editRow = row.nextElementSibling;
                    var hasEdit = editRow && editRow.classList.contains('m3ut-edit-row');
                    var tbody = row.parentNode;
                    if (dir === 'up') {
                        var prev = row.previousElementSibling;
                        var prevMain = (prev && prev.classList.contains('m3ut-edit-row')) ? prev.previousElementSibling : prev;
                        if (prevMain) {
                            tbody.insertBefore(row, prevMain);
                            if (hasEdit) tbody.insertBefore(editRow, prevMain);
                        }
                    } else {
                        var next = hasEdit ? editRow.nextElementSibling : row.nextElementSibling;
                        if (next && next.classList.contains('m3ut-chan-row')) {
                            var nextEdit = next.nextElementSibling;
                            var nextHasEdit = nextEdit && nextEdit.classList.contains('m3ut-edit-row');
                            var anchor = nextHasEdit ? nextEdit.nextElementSibling : next.nextElementSibling;
                            tbody.insertBefore(row, anchor);
                            if (hasEdit) tbody.insertBefore(editRow, anchor);
                        }
                    }
                    var n = 0;
                    document.querySelectorAll('#m3ut-chan-table tbody tr.m3ut-chan-row').forEach(function (r) {
                        n++;
                        var c = r.querySelector('td[data-col="no"]');
                        if (c) c.textContent = n;
                    });
                })
                .catch(function () { b.disabled = false; alert('Network error, abar try korun'); });
        });

        /* -- grid card: edit toggle -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-gbtn-edit, .m3ut-gbtn-cancel');
            if (!b) return;
            var card = b.closest('.m3ut-chan-card');
            var edit = card.querySelector('.m3ut-gcard-edit');
            if (edit) edit.style.display = (edit.style.display === 'none') ? 'block' : 'none';
        });

        /* -- grid card: save edit (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-gbtn-save');
            if (!b) return;
            var card = b.closest('.m3ut-chan-card');
            var uh = card.getAttribute('data-uh');
            var msg = card.querySelector('.m3ut-g-msg');
            var name = card.querySelector('.m3ut-g-name').value.trim();
            var group = card.querySelector('.m3ut-g-group').value.trim();
            var logo = card.querySelector('.m3ut-g-logo').value.trim();
            var curl = card.querySelector('.m3ut-g-url').value.trim();
            msg.textContent = 'Saving…';
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_edit'); body.append('nonce', NONCE); body.append('uh', uh);
            body.append('name', name); body.append('group', group); body.append('logo', logo); body.append('url', curl);
            fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    b.disabled = false;
                    if (!res || !res.success) { msg.textContent = (res && res.data) ? res.data : 'Update fail hoyeche'; return; }
                    var d = res.data;
                    card.setAttribute('data-uh', d.uh);
                    card.setAttribute('data-name', (d.name + ' ' + d.group).toLowerCase());
                    card.querySelector('.m3ut-g-namep').textContent = d.name;
                    var img = card.querySelector('.m3ut-g-logo-img');
                    if (img) { if (d.logo) { img.src = d.logo; img.style.display = ''; } else { img.removeAttribute('src'); img.style.display = 'none'; } }
                    var testBtn = card.querySelector('.m3ut-btn-test');
                    testBtn.setAttribute('data-url', d.url); testBtn.setAttribute('data-name', d.name);
                    var delBtn = card.querySelector('.m3ut-gbtn-del');
                    if (delBtn) delBtn.setAttribute('data-name', d.name);
                    msg.textContent = '';
                    card.querySelector('.m3ut-gcard-edit').style.display = 'none';
                })
                .catch(function () { b.disabled = false; msg.textContent = 'Network error, abar try korun'; });
        });

        /* -- grid card: delete (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-gbtn-del');
            if (!b) return;
            var name = b.getAttribute('data-name');
            if (!confirm(name + ' delete korben?')) return;
            var card = b.closest('.m3ut-chan-card');
            var uh = card.getAttribute('data-uh');
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_delete'); body.append('nonce', NONCE); body.append('uh', uh);
            fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { b.disabled = false; alert((res && res.data) ? res.data : 'Delete fail hoyeche'); return; }
                    card.remove();
                })
                .catch(function () { b.disabled = false; alert('Network error, abar try korun'); });
        });
    })();
    </script>
    <?php
}

function m3ut_tab_duplicate() {
    $file = m3ut_channels_file();
    $remote = m3ut_is_remote_path($file);
    $chs = (array) m3ut_read_channels();

    // naam normalize kore (case + extra space bad diye) group kora
    $groups = [];
    foreach ($chs as $c) {
        $key = m3ut_norm_name($c['name']);
        if ($key === '') continue;
        if (!isset($groups[$key])) $groups[$key] = [];
        $groups[$key][] = $c;
    }
    $dupGroups = array_filter($groups, function ($g) { return count($g) > 1; });
    uasort($dupGroups, function ($a, $b) { return count($b) <=> count($a); });

    $dupChannelCount = 0;
    foreach ($dupGroups as $g) $dupChannelCount += count($g);
    ?>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js" onerror="window.m3utHlsFailed=true"></script>
    <style>
        .m3ut-dup-table.m3ut-dup-hide-no [data-col="no"],
        .m3ut-dup-table.m3ut-dup-hide-logo [data-col="logo"],
        .m3ut-dup-table.m3ut-dup-hide-name [data-col="name"],
        .m3ut-dup-table.m3ut-dup-hide-group [data-col="group"],
        .m3ut-dup-table.m3ut-dup-hide-url [data-col="url"],
        .m3ut-dup-table.m3ut-dup-hide-action [data-col="action"] { display:none }
        .m3ut-dup-cardgrid { display:grid; gap:14px; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)) }
        .m3ut-dup-card { min-width:0; max-width:none; box-sizing:border-box }
    </style>

    <div class="m3ut-card" style="margin-top:16px">
        <p><strong id="m3ut-dup-n"><?php echo count($dupGroups); ?></strong> ta naam-e duplicate paowa gyeche · total <strong id="m3ut-dup-m"><?php echo $dupChannelCount; ?></strong> ta channel affected (shob <span id="m3ut-dup-k"><?php echo count($chs); ?></span> ta channel-er moddhe).</p>
        <p class="description">Naam-er case ar extra space bad diye match kora hoyeche (jemon "BTV" ar " btv" ekই dhora hobe). Niche prottek group-e shei naam-er shob-koyta channel dekhano hocche — shadharonoto ekta rekhe baki-gulo delete kore dile hoye jay.</p>
        <?php if ($remote): ?>
            <p class="description" style="color:#b32d2e"><?php echo esc_html(m3ut_remote_block_msg()); ?> (test-play cholbe, edit/delete noy)</p>
        <?php endif; ?>
    </div>

    <?php if (!$dupGroups): ?>
        <div class="m3ut-card"><p>🎉 Kono duplicate naam nei — shob channel-er naam unique.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <div class="m3ut-card" style="margin:16px 0">
        <p>
            <button type="button" class="button" id="m3ut-dup-view-list">☰ List view</button>
            <button type="button" class="button" id="m3ut-dup-view-grid">▦ Grid view</button>
            <input type="text" id="m3ut-dup-filter" placeholder="Naam diye khujun…" class="regular-text" style="margin-left:10px">
        </p>
        <p id="m3ut-dup-col-box" style="border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;margin-bottom:0">
            <strong style="margin-right:8px">Column dekhaben:</strong>
            <?php foreach (['no' => 'No', 'logo' => 'Logo', 'name' => 'Naam', 'group' => 'Group', 'url' => 'URL', 'action' => 'Action'] as $k => $label): ?>
                <label style="margin-right:14px;font-weight:400"><input type="checkbox" class="m3ut-dup-col-toggle" data-col="<?php echo esc_attr($k); ?>" checked> <?php echo esc_html($label); ?></label>
            <?php endforeach; ?>
        </p>
    </div>

    <div id="m3ut-dup-list-wrap">
    <?php foreach ($dupGroups as $key => $g): ?>
    <div class="m3ut-card m3ut-dup-group" data-name="<?php echo esc_attr($key); ?>" style="margin-bottom:14px">
        <h3 style="margin-top:0"><?php echo esc_html($g[0]['name']); ?> <?php echo m3ut_badge(count($g) . 'x duplicate', '#8e44ad'); ?></h3>
        <div class="m3ut-scroll"><table class="widefat striped m3ut-dup-table">
        <thead><tr><th data-col="no" style="width:40px">No</th><th data-col="logo" style="width:50px">Logo</th><th data-col="name">Naam</th><th data-col="group">Group</th><th data-col="url">URL</th><th data-col="action" style="width:340px">Action</th></tr></thead>
        <tbody>
        <?php foreach ($g as $c): ?>
            <tr class="m3ut-dup-row" data-uh="<?php echo esc_attr($c['uh']); ?>">
                <td data-col="no" style="font-size:18px;font-weight:800"><?php echo (int) $c['no']; ?></td>
                <td data-col="logo"><?php echo $c['logo'] ? '<img src="' . esc_url($c['logo']) . '" style="width:32px;height:32px;object-fit:cover;border-radius:4px" onerror="this.style.display=\'none\'">' : '—'; ?></td>
                <td data-col="name" class="m3ut-dup-name"><strong><?php echo esc_html($c['name']); ?></strong> <?php echo m3ut_chan_state_badge($c['uh']); ?></td>
                <td data-col="group" class="m3ut-dup-groupc"><?php echo esc_html($c['group']); ?></td>
                <td data-col="url" class="m3ut-dup-url"><?php echo m3ut_url_pretty($c['url'], 60); ?></td>
                <td data-col="action">
                    <button type="button" class="button button-small m3ut-btn-test" data-url="<?php echo esc_attr($c['url']); ?>" data-name="<?php echo esc_attr($c['name']); ?>">▶ Test</button>
                    <?php if (!$remote): ?>
                    <button type="button" class="button button-small m3ut-dup-edit">✏ Edit</button>
                    <button type="button" class="button button-small m3ut-dup-del" style="color:#d63638" data-name="<?php echo esc_attr($c['name']); ?>">🗑 Delete</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (!$remote): ?>
            <tr class="m3ut-dup-edit-row" style="display:none"><td colspan="6">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                    <div><label>Naam</label><br><input type="text" class="m3ut-dup-e-name" value="<?php echo esc_attr($c['name']); ?>"></div>
                    <div><label>Group</label><br><input type="text" class="m3ut-dup-e-group" value="<?php echo esc_attr($c['group']); ?>"></div>
                    <div><label>Logo URL</label><br><input type="url" class="m3ut-dup-e-logo" value="<?php echo esc_attr($c['logo']); ?>" style="width:220px"></div>
                    <div><label>Stream URL</label><br><input type="text" class="m3ut-dup-e-url" value="<?php echo esc_attr($c['url']); ?>" style="width:280px"></div>
                    <button type="button" class="button button-primary m3ut-dup-e-save">💾 Save</button>
                    <button type="button" class="button m3ut-dup-e-cancel">Cancel</button>
                    <span class="m3ut-dup-e-msg" style="color:#d63638"></span>
                </div>
            </td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
        </table></div>
    </div>
    <?php endforeach; ?>
    </div>

    <div id="m3ut-dup-grid-wrap" style="display:none">
    <?php foreach ($dupGroups as $key => $g): ?>
    <div class="m3ut-card m3ut-dup-group" data-name="<?php echo esc_attr($key); ?>" style="margin-bottom:14px">
        <h3 style="margin-top:0"><?php echo esc_html($g[0]['name']); ?> <?php echo m3ut_badge(count($g) . 'x duplicate', '#8e44ad'); ?></h3>
        <div class="m3ut-dup-cardgrid">
        <?php foreach ($g as $c): ?>
            <div class="m3ut-card m3ut-dup-card" data-uh="<?php echo esc_attr($c['uh']); ?>" style="text-align:center">
                <div class="m3ut-dup-gview">
                    <img class="m3ut-dup-g-logo-img" src="<?php echo esc_url($c['logo']); ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px;<?php echo $c['logo'] ? '' : 'display:none'; ?>" onerror="this.style.display='none'">
                    <p class="m3ut-dup-g-namep" style="font-weight:700;margin:8px 0 2px;word-break:break-word"><?php echo esc_html($c['name']); ?></p>
                    <?php $__sb = m3ut_chan_state_badge($c['uh']); if ($__sb) echo '<p style="margin:0 0 4px">' . $__sb . '</p>'; ?>
                    <p style="margin:0 0 8px;font-size:12px"><strong style="font-size:18px;font-weight:800"><?php echo (int) $c['no']; ?></strong> <span style="opacity:.7">· <?php echo esc_html($c['group'] ?: '—'); ?></span></p>
                    <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                        <button type="button" class="button button-small m3ut-btn-test" data-url="<?php echo esc_attr($c['url']); ?>" data-name="<?php echo esc_attr($c['name']); ?>">▶ Test</button>
                        <?php if (!$remote): ?>
                        <button type="button" class="button button-small m3ut-dup-gedit">✏ Edit</button>
                        <button type="button" class="button button-small m3ut-dup-gdel" style="color:#d63638" data-name="<?php echo esc_attr($c['name']); ?>">🗑 Delete</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$remote): ?>
                <div class="m3ut-dup-gedit-box" style="display:none;text-align:left;margin-top:10px">
                    <label style="font-size:12px;font-weight:600">Naam</label>
                    <input type="text" class="m3ut-dup-g-name regular-text" value="<?php echo esc_attr($c['name']); ?>" style="width:100%">
                    <label style="font-size:12px;font-weight:600">Group</label>
                    <input type="text" class="m3ut-dup-g-group regular-text" value="<?php echo esc_attr($c['group']); ?>" style="width:100%">
                    <label style="font-size:12px;font-weight:600">Logo URL</label>
                    <input type="url" class="m3ut-dup-g-logo regular-text" value="<?php echo esc_attr($c['logo']); ?>" style="width:100%">
                    <label style="font-size:12px;font-weight:600">Stream URL</label>
                    <input type="text" class="m3ut-dup-g-url regular-text" value="<?php echo esc_attr($c['url']); ?>" style="width:100%">
                    <p style="margin:8px 0 0">
                        <button type="button" class="button button-primary button-small m3ut-dup-gsave">💾 Save</button>
                        <button type="button" class="button button-small m3ut-dup-gcancel">Cancel</button>
                    </p>
                    <p class="m3ut-dup-g-msg" style="color:#d63638;font-size:12px;margin:4px 0 0"></p>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div id="m3ut-play-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;align-items:center;justify-content:center;padding:10px;box-sizing:border-box">
        <div style="background:#1d2327;padding:10px;border-radius:8px;width:100%;max-width:360px;max-height:95vh;overflow:auto;box-sizing:border-box">
            <p style="color:#fff;margin:0 0 8px;font-size:12px;word-break:break-word" id="m3ut-play-title"></p>
            <div style="position:relative;width:100%;aspect-ratio:16/9;background:#000">
                <video id="m3ut-play-video" controls autoplay playsinline style="position:absolute;inset:0;width:100%;height:100%;background:#000"></video>
            </div>
            <p id="m3ut-play-err" style="color:#f86368;font-size:11px;display:none;margin:8px 0 0"></p>
            <p style="margin:10px 0 0;display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="button button-small" id="m3ut-play-close" style="background:#d63638;border-color:#d63638;color:#fff">Bondho korun</button>
                <a id="m3ut-play-open" class="button button-small" target="_blank" rel="noopener" style="background:#2271b1;border-color:#2271b1;color:#fff">Notun tab-e khulun</a>
                <button type="button" class="button button-small" id="m3ut-play-copy" style="background:#00a32a;border-color:#00a32a;color:#fff">Copy URL</button>
            </p>
            <p style="color:#dba617;font-size:11px;max-width:100%;margin:8px 0 0">Note: browser shob format (ts/rtmp/mac-portal) play korte pare na — na cholle "notun tab-e khulun" diye VLC/player-e test korun.</p>
        </div>
    </div>

    <script>
    (function () {
        var url = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var CNONCE = <?php echo wp_json_encode(wp_create_nonce('m3ut_chan_nonce')); ?>;

        /* -- search filter (group card level) -- */
        var f = document.getElementById('m3ut-dup-filter');
        if (f) {
            f.addEventListener('input', function () {
                var q = f.value.toLowerCase();
                document.querySelectorAll('.m3ut-dup-group').forEach(function (grp) {
                    grp.style.display = grp.getAttribute('data-name').indexOf(q) > -1 ? '' : 'none';
                });
            });
        }

        /* -- list/grid view toggle -- */
        var dupListWrap = document.getElementById('m3ut-dup-list-wrap');
        var dupGridWrap = document.getElementById('m3ut-dup-grid-wrap');
        var dupViewList = document.getElementById('m3ut-dup-view-list');
        var dupViewGrid = document.getElementById('m3ut-dup-view-grid');
        if (dupViewList && dupViewGrid) {
            dupViewList.onclick = function () { dupListWrap.style.display = 'block'; dupGridWrap.style.display = 'none'; };
            dupViewGrid.onclick = function () { dupListWrap.style.display = 'none'; dupGridWrap.style.display = 'block'; };
        }

        /* -- column show/hide (list view tables), saved in this browser -- */
        function applyDupCols() {
            document.querySelectorAll('.m3ut-dup-col-toggle').forEach(function (cb) {
                var cls = 'm3ut-dup-hide-' + cb.getAttribute('data-col');
                document.querySelectorAll('.m3ut-dup-table').forEach(function (t) {
                    t.classList.toggle(cls, !cb.checked);
                });
            });
        }
        try {
            var savedDupCols = JSON.parse(localStorage.getItem('m3ut_dup_cols') || '{}');
            document.querySelectorAll('.m3ut-dup-col-toggle').forEach(function (cb) {
                var k = cb.getAttribute('data-col');
                if (savedDupCols.hasOwnProperty(k)) cb.checked = savedDupCols[k];
            });
        } catch (e) {}
        applyDupCols();
        document.querySelectorAll('.m3ut-dup-col-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                applyDupCols();
                var state = {};
                document.querySelectorAll('.m3ut-dup-col-toggle').forEach(function (x) { state[x.getAttribute('data-col')] = x.checked; });
                try { localStorage.setItem('m3ut_dup_cols', JSON.stringify(state)); } catch (e) {}
            });
        });

        /* -- test play (mini player modal) -- */
        var hls = null;
        function testPlay(purl, name) {
            document.getElementById('m3ut-play-title').textContent = name;
            document.getElementById('m3ut-play-open').href = purl;
            document.getElementById('m3ut-play-err').style.display = 'none';
            document.getElementById('m3ut-play-copy').onclick = function () { if (navigator.clipboard) navigator.clipboard.writeText(purl); };
            var v = document.getElementById('m3ut-play-video');
            v.removeAttribute('src'); v.load();
            if (hls) { hls.destroy(); hls = null; }
            function showErr(msg) { var e = document.getElementById('m3ut-play-err'); e.textContent = msg; e.style.display = 'block'; }
            if (purl.indexOf('.m3u8') > -1) {
                if (window.Hls && Hls.isSupported()) {
                    hls = new Hls();
                    hls.on(Hls.Events.ERROR, function (ev, data) { if (data && data.fatal) showErr('Stream load hoyni (' + data.type + ') — "notun tab-e khulun" diye VLC-e test korun.'); });
                    hls.loadSource(purl); hls.attachMedia(v);
                } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
                    v.src = purl;
                } else {
                    showErr('Ei browser-e HLS play support nei (hls.js load hoyni ba unsupported) — "notun tab-e khulun" diye VLC/player-e test korun.');
                }
            } else {
                v.src = purl;
                v.play().catch(function () { showErr('Ei format shorasori browser-e play hoy na — "notun tab-e khulun" diye VLC/player-e test korun.'); });
            }
            document.getElementById('m3ut-play-modal').style.display = 'flex';
        }
        document.getElementById('m3ut-play-close').onclick = function () {
            var v = document.getElementById('m3ut-play-video'); v.pause(); v.removeAttribute('src'); v.load();
            if (hls) { hls.destroy(); hls = null; }
            document.getElementById('m3ut-play-modal').style.display = 'none';
        };
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-btn-test');
            if (b) testPlay(b.getAttribute('data-url'), b.getAttribute('data-name'));
        });

        /* -- edit toggle -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dup-edit, .m3ut-dup-e-cancel');
            if (!b) return;
            var row = b.closest('tr').classList.contains('m3ut-dup-edit-row') ? b.closest('tr') : b.closest('tr').nextElementSibling;
            if (row && row.classList.contains('m3ut-dup-edit-row')) row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
        });

        /* -- save edit (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dup-e-save');
            if (!b) return;
            var editRow = b.closest('tr');
            var mainRow = editRow.previousElementSibling;
            var uh = mainRow.getAttribute('data-uh');
            var msg = editRow.querySelector('.m3ut-dup-e-msg');
            var name = editRow.querySelector('.m3ut-dup-e-name').value.trim();
            var group = editRow.querySelector('.m3ut-dup-e-group').value.trim();
            var logo = editRow.querySelector('.m3ut-dup-e-logo').value.trim();
            var curl = editRow.querySelector('.m3ut-dup-e-url').value.trim();
            msg.textContent = 'Saving…'; b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_edit'); body.append('nonce', CNONCE); body.append('uh', uh);
            body.append('name', name); body.append('group', group); body.append('logo', logo); body.append('url', curl);
            fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    b.disabled = false;
                    if (!res || !res.success) { msg.textContent = (res && res.data) ? res.data : 'Update fail hoyeche'; return; }
                    var d = res.data;
                    mainRow.setAttribute('data-uh', d.uh);
                    mainRow.querySelector('.m3ut-dup-name strong').textContent = d.name;
                    mainRow.querySelector('.m3ut-dup-groupc').textContent = d.group;
                    var uc = mainRow.querySelector('.m3ut-dup-url code');
                    uc.textContent = d.url.length > 45 ? d.url.slice(0, 45) + '…' : d.url;
                    uc.title = d.url;
                    var testBtn = mainRow.querySelector('.m3ut-btn-test');
                    testBtn.setAttribute('data-url', d.url); testBtn.setAttribute('data-name', d.name);
                    var delBtn = mainRow.querySelector('.m3ut-dup-del');
                    if (delBtn) delBtn.setAttribute('data-name', d.name);
                    msg.textContent = '';
                    editRow.style.display = 'none';
                })
                .catch(function () { b.disabled = false; msg.textContent = 'Network error, abar try korun'; });
        });

        /* -- delete (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dup-del');
            if (!b) return;
            var name = b.getAttribute('data-name');
            if (!confirm(name + ' delete korben? (channels file theke-o muche jabe)')) return;
            var row = b.closest('.m3ut-dup-row');
            var uh = row.getAttribute('data-uh');
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_delete'); body.append('nonce', CNONCE); body.append('uh', uh);
            fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { b.disabled = false; alert((res && res.data) ? res.data : 'Delete fail hoyeche'); return; }
                    var editRow = row.nextElementSibling;
                    row.remove();
                    if (editRow && editRow.classList.contains('m3ut-dup-edit-row')) editRow.remove();
                })
                .catch(function () { b.disabled = false; alert('Network error, abar try korun'); });
        });

        /* -- grid card: edit toggle -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dup-gedit, .m3ut-dup-gcancel');
            if (!b) return;
            var card = b.closest('.m3ut-dup-card');
            var box = card.querySelector('.m3ut-dup-gedit-box');
            if (box) box.style.display = (box.style.display === 'none') ? 'block' : 'none';
        });

        /* -- grid card: save edit (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dup-gsave');
            if (!b) return;
            var card = b.closest('.m3ut-dup-card');
            var uh = card.getAttribute('data-uh');
            var msg = card.querySelector('.m3ut-dup-g-msg');
            var name = card.querySelector('.m3ut-dup-g-name').value.trim();
            var group = card.querySelector('.m3ut-dup-g-group').value.trim();
            var logo = card.querySelector('.m3ut-dup-g-logo').value.trim();
            var curl = card.querySelector('.m3ut-dup-g-url').value.trim();
            msg.textContent = 'Saving…';
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_edit'); body.append('nonce', CNONCE); body.append('uh', uh);
            body.append('name', name); body.append('group', group); body.append('logo', logo); body.append('url', curl);
            fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    b.disabled = false;
                    if (!res || !res.success) { msg.textContent = (res && res.data) ? res.data : 'Update fail hoyeche'; return; }
                    var d = res.data;
                    card.setAttribute('data-uh', d.uh);
                    var img = card.querySelector('.m3ut-dup-g-logo-img');
                    if (img) { if (d.logo) { img.src = d.logo; img.style.display = ''; } else { img.removeAttribute('src'); img.style.display = 'none'; } }
                    var namep = card.querySelector('.m3ut-dup-g-namep');
                    if (namep) namep.textContent = d.name;
                    var testBtn = card.querySelector('.m3ut-btn-test');
                    testBtn.setAttribute('data-url', d.url); testBtn.setAttribute('data-name', d.name);
                    var delBtn = card.querySelector('.m3ut-dup-gdel');
                    if (delBtn) delBtn.setAttribute('data-name', d.name);
                    msg.textContent = '';
                    card.querySelector('.m3ut-dup-gedit-box').style.display = 'none';
                })
                .catch(function () { b.disabled = false; msg.textContent = 'Network error, abar try korun'; });
        });

        /* -- grid card: delete (AJAX) -- */
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.m3ut-dup-gdel');
            if (!b) return;
            var name = b.getAttribute('data-name');
            if (!confirm(name + ' delete korben? (channels file theke-o muche jabe)')) return;
            var card = b.closest('.m3ut-dup-card');
            var uh = card.getAttribute('data-uh');
            b.disabled = true;
            var body = new URLSearchParams();
            body.append('action', 'm3ut_chan_delete'); body.append('nonce', CNONCE); body.append('uh', uh);
            fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) { b.disabled = false; alert((res && res.data) ? res.data : 'Delete fail hoyeche'); return; }
                    card.remove();
                })
                .catch(function () { b.disabled = false; alert('Network error, abar try korun'); });
        });
    })();
    </script>
    <?php
}

function m3ut_tab_editor() {
    $file = m3ut_channels_file();
    $remote = m3ut_is_remote_path($file);
    $exists = $remote ? true : file_exists($file);
    $writable = $remote ? false : ($exists ? is_writable($file) : is_writable(dirname($file)));
    $raw = $remote ? (string) m3ut_fetch_remote_channels($file) : ($exists ? (string) file_get_contents($file) : '');
    $chs = m3ut_parse($raw);
    $names = array_count_values(wp_list_pluck($chs, 'name'));
    $dups = array_keys(array_filter($names, function ($c) { return $c > 1; }));
    $nogroup = 0; $nologo = 0;
    foreach ($chs as $c) { if ($c['group'] === '') $nogroup++; if ($c['logo'] === '') $nologo++; }
    $backups = get_option('m3ut_backups', []);
    ?>
    <div class="m3ut-card" style="margin-top:16px">
        <p><strong>File:</strong> <code><?php echo esc_html($file); ?></code>
        <?php if ($remote): ?> <?php echo m3ut_badge('Remote URL source', '#2271b1'); ?>
        <?php else: ?>
            <?php echo $exists ? '' : m3ut_badge('pawa jayni', '#d63638'); ?> <?php echo $writable ? m3ut_badge('writable', '#00a32a') : m3ut_badge('read-only', '#d63638'); ?>
        <?php endif; ?>
        </p>
        <p><strong><?php echo count($chs); ?></strong> ta channel · group nei: <?php echo $nogroup; ?> · logo nei: <?php echo $nologo; ?><?php if ($dups): ?> · <span style="color:#b32d2e">Ek-i naam ekadhik bar: <?php echo esc_html(implode(', ', array_slice($dups, 0, 10))); ?></span><?php endif; ?></p>
        <?php if ($remote): ?>
            <p class="description" style="color:#b32d2e">Ei channels source ekta remote link (<code>.m3u</code> / <code>.m3u8</code> / <code>.php</code> ba extension chhara link — shob-i support kore). Content shei server theke live fetch hoy (5 min cache), tai ekhan theke shorashori add/edit/delete/save kora jay na — content change korte remote file-e giye korun. Niche shudhu preview dekhano hocche.</p>
        <?php else: ?>
            <p class="description">M3U format (#EXTINF) ba <code>Name|Logo|Group|URL</code> format, dui-i cholbe, mix-o cholbe. Save korar age auto backup hoy (shesh 8 ta).</p>
        <?php endif; ?>
    </div>

    <?php if (!$remote): ?>
    <div class="m3ut-card m3ut-form" style="margin:16px 0;max-width:720px">
        <h2>Quick add channel</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('m3ut_editor_add'); ?><input type="hidden" name="action" value="m3ut_editor_add">
            <label>Naam</label><input type="text" name="name" class="regular-text" required>
            <label>Group (Bangla, Sports, Kids …)</label><input type="text" name="group" class="regular-text">
            <label>Logo URL</label><input type="url" name="logo" class="large-text">
            <label>Stream URL</label><input type="text" name="url" class="large-text" required>
            <p><button class="button button-primary">Add</button></p>
        </form>
    </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('m3ut_editor_save'); ?><input type="hidden" name="action" value="m3ut_editor_save">
        <textarea name="content" rows="26" class="large-text code" style="font-family:monospace;white-space:pre;overflow:auto" spellcheck="false" <?php echo $remote ? 'readonly' : ''; ?>><?php echo esc_textarea($raw); ?></textarea>
        <p><button class="button button-primary" <?php echo $writable ? '' : 'disabled'; ?>>💾 Save channels file</button></p>
    </form>

    <?php if (!$remote && !empty($backups) && is_array($backups)): ?>
        <h2>Backups</h2>
        <table class="widefat striped" style="max-width:600px"><tbody>
        <?php foreach ($backups as $i => $b): ?>
            <tr><td><?php echo esc_html($b['t']); ?></td><td><?php echo count(m3ut_parse($b['c'])); ?> channel</td>
            <td><a class="button button-small" onclick="return confirm('Ei version restore korben?')" href="<?php echo esc_url(m3ut_act('m3ut_editor_restore', ['i' => $i])); ?>">Restore</a></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif;
}