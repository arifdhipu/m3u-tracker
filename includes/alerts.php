<?php
if (!defined('ABSPATH')) exit;

/** Telegram + email alert. Return: human readable result string */
function m3ut_alert($msg, $subject = 'M3U Alert') {
    $s = m3ut_settings();
    $res = [];
    if ($s['tg_token'] && $s['tg_chat']) {
        $r = wp_remote_post('https://api.telegram.org/bot' . $s['tg_token'] . '/sendMessage', [
            'timeout' => 8,
            'body' => ['chat_id' => $s['tg_chat'], 'text' => $msg, 'disable_web_page_preview' => 1],
        ]);
        if (is_wp_error($r)) $res[] = 'Telegram error: ' . $r->get_error_message();
        else $res[] = wp_remote_retrieve_response_code($r) === 200 ? 'Telegram OK' : 'Telegram failed (HTTP ' . wp_remote_retrieve_response_code($r) . ') - token/chat id check korun';
    }
    if ($s['alert_email']) {
        $res[] = wp_mail($s['alert_email'], '[' . get_bloginfo('name') . '] ' . $subject, $msg) ? 'Email OK' : 'Email failed';
    }
    return $res ? implode(' | ', $res) : 'Kono alert channel set kora nei';
}

add_action('m3ut_cron_hourly', 'm3ut_hourly_check');
function m3ut_hourly_check() {
    global $wpdb;
    $s = m3ut_settings();
    $logs = m3ut_t('logs');

    // Spike: shesh 1 ghontay kono source-er hit ager 23 ghontar gor-er 3x (default) er beshi
    if (!empty($s['al_spike'])) {
        $s1 = m3ut_ago(3600);
        $s24 = m3ut_ago(86400);
        $cur = $wpdb->get_results($wpdb->prepare("SELECT source, COUNT(*) c FROM $logs WHERE status='ok' AND viewed_at >= %s GROUP BY source", $s1));
        foreach ((array) $cur as $r) {
            $prev = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logs WHERE status='ok' AND source=%s AND viewed_at >= %s AND viewed_at < %s", $r->source, $s24, $s1));
            $avg = $prev / 23;
            $tk = 'm3ut_spk_' . md5($r->source);
            if ((int) $r->c >= (int) $s['spike_min'] && (int) $r->c >= $avg * (float) $s['spike_mult'] && !get_transient($tk)) {
                set_transient($tk, 1, 3 * HOUR_IN_SECONDS);
                m3ut_alert("📈 Traffic spike\nSource: {$r->source}\nShesh 1 ghontay: {$r->c} hit (ager gor: " . round($avg, 1) . "/ghonta)", 'Traffic spike');
            }
        }
    }

    // Sharing: 24 ghontay unique IP limit chharale
    if (!empty($s['al_sharing'])) {
        $kc = m3ut_ip_counts('key_code', 86400);
        foreach ((array) $wpdb->get_results("SELECT key_code, label, reseller, ip_limit FROM " . m3ut_t('keys') . " WHERE status='active'") as $k) {
            $lim = $k->ip_limit ? (int) $k->ip_limit : (int) $s['default_ip_limit'];
            $n = isset($kc[$k->key_code]) ? $kc[$k->key_code] : 0;
            $tk = 'm3ut_sh_' . $k->key_code;
            if ($lim > 0 && $n > $lim && !get_transient($tk)) {
                set_transient($tk, 1, DAY_IN_SECONDS);
                m3ut_alert("⚠️ Sharing shondeho (key)\nCustomer: {$k->label}\nKey: {$k->key_code}\nReseller: " . ($k->reseller ?: 'direct') . "\n24 ghontay unique IP: $n (limit $lim)", 'Sharing suspected');
            }
        }
        $sc = m3ut_ip_counts('source', 86400);
        foreach ((array) $wpdb->get_results("SELECT slug, name, ip_limit FROM " . m3ut_t('resellers') . " WHERE status='active' AND ip_limit > 0") as $r) {
            $n = isset($sc[$r->slug]) ? $sc[$r->slug] : 0;
            $tk = 'm3ut_shr_' . $r->slug;
            if ($n > (int) $r->ip_limit && !get_transient($tk)) {
                set_transient($tk, 1, DAY_IN_SECONDS);
                m3ut_alert("⚠️ Sharing shondeho (reseller)\nReseller: {$r->name} ({$r->slug})\n24 ghontay unique IP: $n (limit {$r->ip_limit})", 'Sharing suspected');
            }
        }
    }
}

add_action('m3ut_cron_weekly', function () {
    global $wpdb;
    $s = m3ut_settings();
    if (empty($s['weekly_report'])) return;
    $logs = m3ut_t('logs');
    $since = m3ut_ago(7 * 86400);
    $tot = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logs WHERE status='ok' AND viewed_at >= %s", $since));
    $uni = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip) FROM $logs WHERE status='ok' AND viewed_at >= %s", $since));
    $blk = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logs WHERE status='blocked' AND viewed_at >= %s", $since));
    $msg = "📊 Weekly M3U report (last 7 days)\nMot hit: $tot\nUnique IP: $uni\nBlocked hit: $blk\n\nTop source:\n";
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT source, COUNT(*) c FROM $logs WHERE status='ok' AND viewed_at >= %s GROUP BY source ORDER BY c DESC LIMIT 5", $since)) as $r) {
        $msg .= "- {$r->source}: {$r->c}\n";
    }
    $ch = (array) $wpdb->get_results($wpdb->prepare("SELECT cname, COUNT(*) c FROM " . m3ut_t('chits') . " WHERE viewed_at >= %s GROUP BY chash, cname ORDER BY c DESC LIMIT 5", $since));
    if ($ch) {
        $msg .= "\nTop channel:\n";
        foreach ($ch as $r) $msg .= "- {$r->cname}: {$r->c}\n";
    }
    $dead = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . m3ut_t('links') . " WHERE status='dead'");
    $msg .= "\nDead channel ekhon: $dead";
    m3ut_alert($msg, 'Weekly report');
});
