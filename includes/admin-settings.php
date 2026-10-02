<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_m3ut_settings_save', function () {
    m3ut_cap(); check_admin_referer('m3ut_settings_save');
    $p = wp_unslash($_POST);
    $cb = function ($k) use ($p) { return empty($p[$k]) ? 0 : 1; };
    $new = [
        'allow_direct'     => $cb('allow_direct'),
        'unknown_mode'     => (isset($p['uFnknown_mode']) && $p['unknown_mode'] === 'block') ? 'block' : 'log',
        'blocked_msg'      => sanitize_text_field(isset($p['blocked_msg']) ? $p['blocked_msg'] : ''),
        'blocked_url'      => esc_url_raw(isset($p['blocked_url']) ? $p['blocked_url'] : ''),
        'rate_minutes'     => max(0, min(60, (int) (isset($p['rate_minutes']) ? $p['rate_minutes'] : 5))),
        'go_mode'          => $cb('go_mode'),
        'hide_dead'        => $cb('hide_dead'),
        'auto_scan'        => $cb('auto_scan'),
        'channels_path'    => sanitize_text_field(isset($p['channels_path']) ? $p['channels_path'] : ''),
        'link_base'        => esc_url_raw(isset($p['link_base']) ? $p['link_base'] : ''),
        'geo_provider'     => in_array(isset($p['geo_provider']) ? $p['geo_provider'] : '', ['ipapi', 'ipwho', 'none'], true) ? $p['geo_provider'] : 'ipapi',
        'ipapi_key'        => sanitize_text_field(isset($p['ipapi_key']) ? $p['ipapi_key'] : ''),
        'tg_token'         => sanitize_text_field(isset($p['tg_token']) ? $p['tg_token'] : ''),
        'tg_chat'          => sanitize_text_field(isset($p['tg_chat']) ? $p['tg_chat'] : ''),
        'alert_email'      => sanitize_email(isset($p['alert_email']) ? $p['alert_email'] : ''),
        'al_first_hit'     => $cb('al_first_hit'),
        'al_spike'         => $cb('al_spike'),
        'al_sharing'       => $cb('al_sharing'),
        'al_dead'          => $cb('al_dead'),
        'weekly_report'    => $cb('weekly_report'),
        'spike_min'        => max(1, (int) (isset($p['spike_min']) ? $p['spike_min'] : 20)),
        'spike_mult'       => max(1.5, (float) (isset($p['spike_mult']) ? $p['spike_mult'] : 3)),
        'default_ip_limit' => max(0, (int) (isset($p['default_ip_limit']) ? $p['default_ip_limit'] : 3)),
        'retention_months' => max(1, (int) (isset($p['retention_months']) ? $p['retention_months'] : 6)),
        'scan_interval_hours' => in_array((int) (isset($p['scan_interval_hours']) ? $p['scan_interval_hours'] : 3), [2, 3, 4, 6, 12, 24], true) ? (int) $p['scan_interval_hours'] : 3,
                'scan_at_hour' => in_array((int) (isset($p['scan_at_hour']) ? $p['scan_at_hour'] : 0), [0, 2, 3, 4], true) ? (int) $p['scan_at_hour'] : 0,
        'token_ttl_hours'  => max(1, min(720, (int) (isset($p['token_ttl_hours']) ? $p['token_ttl_hours'] : 24))),
        'token_ip_bind'    => $cb('token_ip_bind'),
        'link_protection'  => $cb('link_protection'),
        'link_expiry'      => $cb('link_expiry'),
        'hide_origin'      => $cb('hide_origin'),
        'seg_base_url'     => esc_url_raw(isset($p['seg_base_url']) ? $p['seg_base_url'] : ''),
        'seg_secret_path'  => sanitize_text_field(isset($p['seg_secret_path']) ? $p['seg_secret_path'] : ''),
    ];
    update_option('m3ut_settings', $new);
    if (!empty($new['hide_origin'])) m3ut_sync_secret_file(); // seg.php-er jonno secret-file ekhoni update kore dei
    m3ut_back('m3ut-settings', 'Settings save hoyeche');
});


add_action('admin_post_m3ut_reset_analytics', function () {
    m3ut_cap(); check_admin_referer('m3ut_reset_analytics');
    $confirm = isset($_POST['confirm_text']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['confirm_text'])))) : '';
    if ($confirm !== 'RESET') {
        m3ut_back('m3ut-settings', 'Reset hoyni - confirm box-e RESET likhte hobe', 'error');
    }
    global $wpdb;
    $logs = m3ut_t('logs');
    $chits = m3ut_t('chits');
    $n_logs = (int) $wpdb->get_var("SELECT COUNT(*) FROM $logs");
    $n_chits = (int) $wpdb->get_var("SELECT COUNT(*) FROM $chits");
    foreach ([$logs, $chits] as $tbl) {
        if ($wpdb->query("TRUNCATE TABLE $tbl") === false) $wpdb->query("DELETE FROM $tbl");
    }
    $l1 = $wpdb->esc_like('_transient_m3ut_rl_') . '%';
    $l2 = $wpdb->esc_like('_transient_timeout_m3ut_rl_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $l1, $l2));
    if (!empty($_POST['reset_first_hit'])) {
        $wpdb->query("UPDATE " . m3ut_t('resellers') . " SET first_hit_at = NULL");
    }
    m3ut_back('m3ut-settings', 'Analytics reset hoyeche: ' . $n_logs . ' ta hit/log ar ' . $n_chits . ' ta channel-play muche fela hoyeche');
});

add_action('admin_post_m3ut_test_alert', function () {
    m3ut_cap(); check_admin_referer('m3ut_test_alert');
    $r = m3ut_alert("✅ M3U Tracker test alert\n" . home_url() . "\n" . m3ut_now(), 'Test alert');
    m3ut_back('m3ut-settings', 'Test: ' . $r);
});

function m3ut_page_settings() {
    m3ut_cap();
    $s = m3ut_settings();
    $chk = function ($k, $label, $desc = '') use ($s) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr($k) . '" value="1" ' . checked(!empty($s[$k]), true, false) . '> On</label>';
        if ($desc) echo '<p class="description">' . wp_kses($desc, ['code' => [], 'strong' => []]) . '</p>';
        echo '</td></tr>';
    };
    $txt = function ($k, $label, $desc = '', $type = 'text', $w = 'regular-text') use ($s) {
        echo '<tr><th scope="row"><label for="' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td><input type="' . esc_attr($type) . '" id="' . esc_attr($k) . '" name="' . esc_attr($k) . '" class="' . esc_attr($w) . '" value="' . esc_attr($s[$k]) . '">';
        if ($desc) echo '<p class="description">' . wp_kses($desc, ['code' => [], 'strong' => []]) . '</p>';
        echo '</td></tr>';
    };
    ?>
    <div class="wrap">
        <h1>⚙️ Settings & Alerts</h1>
        <?php m3ut_notice(); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('m3ut_settings_save'); ?><input type="hidden" name="action" value="m3ut_settings_save">

            <h2>Access control</h2>
            <table class="form-table"><?php
                $chk('allow_direct', 'Direct link allow', '<code>?src=</code> ba <code>?key=</code> chhara khulleo chalbe (source = direct).');
            ?>
                <tr><th scope="row">Unknown source</th><td>
                    <select name="unknown_mode"><option value="log" <?php selected($s['unknown_mode'], 'log'); ?>>Serve kore, "unknown" hishebe log</option><option value="block" <?php selected($s['unknown_mode'], 'block'); ?>>Block kore (reseller list-e na thakle chalbe na)</option></select>
                    <p class="description">"Block" dile ashol reseller-er baire keu naam bananor chesta korleo kaj hobe na.</p></td></tr>
                <?php
                $txt('blocked_msg', 'Blocked message', 'Off/expired link-e player-e ei naam-er ekta channel dekhabe.');
                $txt('blocked_url', 'Blocked message-er stream URL', 'Optional. Kono ekta chhoto video/stream URL dile setai chalbe. Faka thakle site-er home URL jabe.', 'url', 'large-text');
                $txt('rate_minutes', 'Duplicate hit gonona', 'Ek-i IP ekhono minute-er moddhe barbar khulle ekbar-i count. 0 = sob hit count.', 'number', 'small-text');
            ?></table>

            <h2>Playlist</h2>
            <table class="form-table"><?php
                $chk('go_mode', 'Channel stats mode', 'Playlist-er prottek channel URL <code>tv.php?go=…</code> hoye jabe, ar server redirect kore ashol stream-e pathabe. Ete channel-wise click stats ar <strong>sotti kill switch</strong> pawa jay. <strong>Sotorkota:</strong> kichu player redirect thik mane na, ar stream start-e ektu deri hote pare. Age nijer player-e test korun.');
                $chk('hide_dead', 'Dead channel lukao', 'Dead checker jei channel dead dhorbe, playlist theke ta soriye dibe (channels.txt file-e haat dey na).');
                $chk('auto_scan', 'Auto dead scan on/off', 'WP-Cron diye niche dewa interval onujayi cholbe. Site-e visitor/hit thakle cron cholbe; nahole server cron-e <code>wp-cron.php</code> boshan.');
                ?>
                <tr><th scope="row">Auto scan koto ghonta por por</th><td>
                    <select name="scan_interval_hours">
                        <?php foreach ([2 => '2 ghonta', 3 => '3 ghonta', 4 => '4 ghonta', 6 => '6 ghonta', 12 => '12 ghonta', 24 => '24 ghonta'] as $hv => $hl): ?>
                            <option value="<?php echo (int) $hv; ?>" <?php selected((int) $s['scan_interval_hours'], $hv); ?>><?php echo esc_html($hl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Upore "Auto dead scan" on thakle, ei interval-e sob channel abar automatic scan hoye dead link ber hobe.</p></td></tr>
                                    <tr><th scope="row">Raate fixed shomoy-e scan</th><td>
                    <select name="scan_at_hour">
                        <?php foreach ([0 => 'Off (upore-r interval onujayi)', 2 => 'Protidin raat 2:00 (BD time)', 3 => 'Protidin raat 3:00 (BD time)', 4 => 'Protidin raat 4:00 (BD time)'] as $hv => $hl): ?>
                            <option value="<?php echo (int) $hv; ?>" <?php selected((int) $s['scan_at_hour'], $hv); ?>><?php echo esc_html($hl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Shomoy select korle interval bondho, protidin oi ghontay ekbar scan hobe. Oi shomoy kono trigger na ashle, ajker moddhei pratham trigger-e scan hoye jabe (miss hobe na).</p></td></tr>
                <?php
                $txt('channels_path', 'channels file path', 'Faka = auto (WordPress folder-er channels.txt). Local file hole full path din, jemon <code>/home/user/private/channels.txt</code>. Remote M3U link-o deya jay — <code>.m3u</code>, <code>.m3u8</code>, <code>.php</code> ba extension-chhara link (jemon <code>https://example.com/tv</code>) shob-i cholbe, plugin URL dhore live fetch korbe (5 min cache). Remote link dile ekhan theke edit/delete kora jabe na, shudhu view/test.', 'text', 'large-text');
                $txt('link_base', 'Reseller link-er base URL', 'Faka = ' . esc_html(home_url('/tv.php')) . ' . Chaile <code>' . esc_html(home_url('/tv.m3u')) . '</code> dite paren (.htaccess rule thakle).', 'url', 'large-text');
            ?></table>

            <h2>Go-link protection <span style="font-weight:400;font-size:13px">(shudhu "Channel stats mode" on thakle kaj kore)</span></h2>
            <table class="form-table"><?php
                $chk('link_protection', 'Protection (TTL + signature check)', 'On (recommended): go-link koto shomoy por expire hobe (TTL) ebong link-er signature thik ache kina check hobe. <strong>Off korle:</strong> "Channel stats mode" (go_mode) chalu thakleo go-link ar kokhono expire hobe na ebong signature check hobe na — mane redirect + channel-wise click stats age moto cholbe, khali expiry/IP-bind protection ta thakbe na. Off obosthay nicher shob shetting-er kono effect thakbe na.');
                $chk('link_expiry', 'Link expire hobe', 'On (default): nicher "koto ghonta por expire hobe" onujayi go-link expire hobe. <strong>Off korle:</strong> go-link ar kokhono expire hobe na (kew copy/share kore rakhleo shetate stream cholte thakbe) — kintu link-er signature check tarpor-o cholte thakbe (mane keu URL-er ভেতরের value ekhane-shekhane change kore access nite parbe na). Ei checkbox shudhu "Protection" upore On thakle kaj kore.');
                $txt('token_ttl_hours', 'Go-link koto ghonta por expire hobe', 'Playlist-e deya <code>tv.php?go=…</code> link-gulo eto ghonta por-e ar cholbe na. Kew link copy kore rakhle/share korle, ei shomoy par hoye gele shetate ar stream chalu hobe na — abar notun kore playlist load korte hobe. (1–720 ghonta) — <strong>shudhu "Protection" ar "Link expire hobe" dutoi On thakle kaj kore.</strong>', 'number', 'small-text');
                $chk('token_ip_bind', 'Go-link IP-bind', 'On korle ekta go-link shudhu shei IP theke-i kaj korbe jei IP playlist ta niyechilo. <strong>Sotorkota:</strong> customer-er mobile-data/CGNAT IP maje-majhe change hole tader stream majhpothe bondho hoye jete pare — sadharonoto off rakha nirapod, khali TTL (expiry) diyei besh valo protection pawa jay. — <strong>shudhu "Protection" on thakle kaj kore.</strong>');
            ?></table>

            <h2>Origin URL hide (standalone proxy) <span style="font-weight:400;font-size:13px">(shudhu "Channel stats mode" on thakle kaj kore)</span></h2>
            <table class="form-table"><?php
                $chk('hide_origin', 'Ashol stream URL customer-er kach theke sompurno hide korun', 'Off (default): tv.php redirect (302) diye customer-ke shorashori ashol stream URL-e pathiye dey — Location header/network tab-e ashol URL dekha jay. <strong>On korle:</strong> customer-ke ekta <code>seg.php</code> (WordPress-er baire, standalone) file-e pathano hobe, jeta ashol stream fetch kore customer-ke pass-through kore dey. Customer/player kokhonoi ashol upstream URL/host dekhbe na. <br><strong>WP site-e load porbe na keno:</strong> <code>seg.php</code> WordPress/DB kichu-i chhue na (shudhu ekta chhoto secret file pore) — tai stream jotokkhon-i cholok, apnar WordPress admin/DB-er upor kono chap pore na. Shudhu redirect-tuku (khub kom shomoy-er jonno) WP-e hoy, baki shob kaj <code>seg.php</code>-e.<br><strong>Korte hobe:</strong> plugin-er dewa <code>seg.php</code> file-ta <code>tv.php</code>-er pashe (shadharonoto <code>public_html</code>-e) upload korun. Save korar shathe shathe plugin nijei ekta secret file (<code>m3ut-secret.php</code>, default-e WordPress root-e) likhe rakhbe — <code>seg.php</code> ei file theke porei kaj korbe.');
                $txt('seg_base_url', 'seg.php-er URL (optional override)', 'Faka rakhle auto: <code>tv.php</code>-er jei folder shekhaneo <code>seg.php</code> ache dhore neya hobe (jemon ' . esc_html(str_replace('tv.php', 'seg.php', m3ut_link_base())) . ' ). Onno kono subdomain/path-e seg.php rakhle ekhane full URL din.', 'url', 'large-text');
                $txt('seg_secret_path', 'Secret file path (optional override)', 'Faka rakhle auto: WordPress root-e <code>m3ut-secret.php</code>. Onno kono path dile <code>seg.php</code>-eo shei-i path bosate hobe (seg.php-er upore ekta constant/variable-e).', 'text', 'large-text');
            ?></table>

            <h2>GeoIP</h2>
            <table class="form-table">
                <tr><th scope="row">Provider</th><td>
                    <select name="geo_provider">
                        <option value="ipapi" <?php selected($s['geo_provider'], 'ipapi'); ?>>ip-api.com (free = HTTP only, 45/min, non-commercial)</option>
                        <option value="ipwho" <?php selected($s['geo_provider'], 'ipwho'); ?>>ipwho.is (HTTPS, free limit ache)</option>
                        <option value="none" <?php selected($s['geo_provider'], 'none'); ?>>Off (country/city/ISP dekhabe na)</option>
                    </select>
                    <p class="description">Business hole ip-api-er paid key (nicher field) ba onno provider nin. Result 30 din cache hoy, ar lookup playlist pathanor pore hoy tai player-ke wait korte hoy na.</p></td></tr>
                <?php $txt('ipapi_key', 'ip-api Pro key (optional)', 'Dile HTTPS pro endpoint use hobe.'); ?>
            </table>

            <h2>Alerts (Telegram / Email)</h2>
            <table class="form-table"><?php
                $txt('tg_token', 'Telegram bot token', '@BotFather theke bot baniye token nin.', 'text', 'large-text');
                $txt('tg_chat', 'Telegram chat ID', 'Bot-ke ekta message pathiye <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> theke chat id nin.');
                $txt('alert_email', 'Alert email', 'Faka rakhle email jabe na.', 'email');
                $chk('al_first_hit', 'Notun reseller-er first hit');
                $chk('al_spike', 'Traffic spike', 'Shesh 1 ghontay kono source-er hit shadharon-er cheye onek beshi hole.');
                $chk('al_sharing', 'Link sharing shondeho', '24 ghontay unique IP limit chharale.');
                $chk('al_dead', 'Notun dead channel');
                $chk('weekly_report', 'Shaptahik report');
                $txt('spike_min', 'Spike: minimum hit/ghonta', '', 'number', 'small-text');
                $txt('spike_mult', 'Spike: koto gun beshi', '', 'number', 'small-text');
                $txt('default_ip_limit', 'Default key IP limit', 'Prottek customer key-er jonno 24h-e max unique IP (0 = check off).', 'number', 'small-text');
            ?></table>

            <h2>Data</h2>
            <table class="form-table"><?php $txt('retention_months', 'Log koto mash rakhbe', '', 'number', 'small-text'); ?></table>

            <p><button class="button button-primary">💾 Save settings</button>
            <a class="button" href="<?php echo esc_url(m3ut_act('m3ut_test_alert')); ?>">📨 Test alert pathan (age save korun)</a></p>
        </form>
        
        <style>
.m3ut-acc-h{cursor:pointer;user-select:none;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px!important;margin:8px 0 0!important}
.m3ut-acc-h:hover{background:#f6f7f7;border-color:#2271b1}
.m3ut-acc-h:before{content:"\25B6";display:inline-block;font-size:12px;margin-right:8px;transition:transform .2s}
.m3ut-acc-h.m3ut-open:before{transform:rotate(90deg)}
</style>
<script>
(function(){
  var f=document.querySelector('.wrap form'); if(!f) return;
  f.querySelectorAll('h2').forEach(function(h){
    var t=h.nextElementSibling;
    if(!t || !t.classList.contains('form-table')) return;
    h.classList.add('m3ut-acc-h'); t.style.display='none';
    h.addEventListener('click',function(){
      var open=t.style.display==='none';
      t.style.display=open?'':'none';
      h.classList.toggle('m3ut-open',open);
    });
  });
})();
</script>
        
        <?php
        global $wpdb;
        $rs_logs = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . m3ut_t('logs'));
        $rs_chits = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . m3ut_t('chits'));
        ?>
        <div style="margin-top:30px;max-width:760px;border:1px solid #d63638;border-radius:8px;padding:16px 22px;background:#fff">
            <h2 style="margin-top:0;color:#d63638">DANGER ZONE - Dashboard / Analytics reset</h2>
            <p>Ekhon ache: <strong><?php echo (int) $rs_logs; ?></strong> ta hit/log, <strong><?php echo (int) $rs_chits; ?></strong> ta channel-play.</p>
            <p><strong>Muchhe jabe:</strong> dashboard-er sob hit, viewer/IP, country/city/ISP/device stats, channel-wise play stats, live count.<br>
            <strong>Muchhe jabe na:</strong> reseller, customer key, channels file, settings, dead-link scan result.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Sotti-i puro dashboard/analytics data muchhe felben? Eta ar fire pawa jabe na.');">
                <?php wp_nonce_field('m3ut_reset_analytics'); ?><input type="hidden" name="action" value="m3ut_reset_analytics">
                <p><label><input type="checkbox" name="reset_first_hit" value="1"> Reseller-der "first hit" marker-o reset korun (abar notun kore first-hit alert ashbe)</label></p>
                <p>Confirm korte box-e <code>RESET</code> likhun:
                    <input type="text" name="confirm_text" class="regular-text" placeholder="RESET" autocomplete="off" style="max-width:140px">
                    <button class="button" style="background:#d63638;color:#fff;border-color:#b32d2e">Sob analytics muchhe felun</button></p>
            </form>
        </div>
    </div>
    <?php
}