<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('M3U Analytics', 'M3U Analytics', 'manage_options', 'm3ut-dashboard', 'm3ut_page_dashboard', 'dashicons-chart-bar', 26);
    add_submenu_page('m3ut-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'm3ut-dashboard', 'm3ut_page_dashboard');
    add_submenu_page('m3ut-dashboard', 'Resellers', 'Resellers', 'manage_options', 'm3ut-resellers', 'm3ut_page_resellers');
    add_submenu_page('m3ut-dashboard', 'Customer Keys', 'Customer Keys', 'manage_options', 'm3ut-keys', 'm3ut_page_keys');
    add_submenu_page('m3ut-dashboard', 'Channels & Checker', 'Channels & Checker', 'manage_options', 'm3ut-channels', 'm3ut_page_channels');
    add_submenu_page('m3ut-dashboard', 'Settings & Alerts', 'Settings & Alerts', 'manage_options', 'm3ut-settings', 'm3ut_page_settings');
});

function m3ut_is_our_page() {
    return isset($_GET['page']) && strpos((string) $_GET['page'], 'm3ut-') === 0;
}

add_action('admin_head', function () {
    if (!m3ut_is_our_page()) return;
    echo '<style>
    .m3ut-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px}
    .m3ut-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;flex:1;min-width:240px;box-sizing:border-box}
    .m3ut-card h2,.m3ut-card h3{margin-top:0}
    .m3ut-big{font-size:28px;font-weight:700;margin:6px 0 0}
    .m3ut-scroll{overflow-x:auto}
    .m3ut-form label{display:block;margin:8px 0 2px;font-weight:600}
    .m3ut-link{width:100%;max-width:340px;font-size:12px}
        .m3ut-c-url code,.m3ut-dchk-url code,.m3ut-dup-url code{white-space:nowrap;word-break:normal;display:inline-block}
    .m3ut-c-url,.m3ut-dchk-url,.m3ut-dup-url{min-width:200px}
        .m3ut-c-url code,.m3ut-dchk-url code,.m3ut-dup-url code{font-size:14px;background:#fff;border:1px solid #2271b1;padding:4px 8px;border-radius:4px;color:#1d2327}
    @keyframes m3utPulse{0%{opacity:1}50%{opacity:.25}100%{opacity:1}}
    </style>';
});

add_action('admin_footer', function () {
    if (!m3ut_is_our_page()) return;
    echo '<script>
    function m3utCopy(b){var t=b.getAttribute("data-copy");
            function ok(){var o=b.getAttribute("data-l")||b.textContent;b.setAttribute("data-l",o);b.textContent="Copied";setTimeout(function(){b.textContent=o},1500)}
      if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(ok)}
      else{var a=document.createElement("textarea");a.value=t;document.body.appendChild(a);a.select();try{document.execCommand("copy")}catch(e){}document.body.removeChild(a);ok()}}
    </script>';
});

function m3ut_cap() { if (!current_user_can('manage_options')) wp_die('Permission denied'); }

function m3ut_notice() {
    if (!empty($_GET['m3ut_msg'])) {
        $t = (isset($_GET['m3ut_t']) && $_GET['m3ut_t'] === 'error') ? 'error' : 'success';
        echo '<div class="notice notice-' . $t . ' is-dismissible"><p>' . esc_html(wp_unslash($_GET['m3ut_msg'])) . '</p></div>';
    }
}

function m3ut_back($page, $msg = '', $type = 'success', $extra = []) {
    $args = array_merge(['page' => $page], $extra);
    if ($msg !== '') { $args['m3ut_msg'] = rawurlencode($msg); $args['m3ut_t'] = $type; }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

function m3ut_act($action, $args = []) {
    return wp_nonce_url(add_query_arg(array_merge(['action' => $action], $args), admin_url('admin-post.php')), $action);
}

function m3ut_badge($text, $color) {
    return '<span style="background:' . esc_attr($color) . ';color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;white-space:nowrap">' . esc_html($text) . '</span>';
}

function m3ut_copy_btn($text, $label = 'Copy') {
    return '<button type="button" class="button button-small" data-copy="' . esc_attr($text) . '" onclick="m3utCopy(this)">' . esc_html($label) . '</button>';
}

// Expiry status label (reseller/key duijoner-i)
function m3ut_status_badge($status, $expires) {
    if ($status !== 'active') return m3ut_badge('Disabled', '#8c8f94');
    if ($expires) {
        $d = m3ut_days_left($expires);
        if ($d < 0) return m3ut_badge('Expired', '#d63638');
                if ($d <= 7) return m3ut_badge('Active - ' . $d . ' din baki', '#dba617');
    }
    return m3ut_badge('Active', '#00a32a');
}

// WP main dashboard widget
add_action('wp_dashboard_setup', function () {
        if (current_user_can('manage_options')) wp_add_dashboard_widget('m3ut_widget', 'M3U Live', 'm3ut_widget_render');
});
function m3ut_widget_render() {
    global $wpdb;
    $logs = m3ut_t('logs');
        $live = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT CONCAT(ip, '|', LEFT(user_agent, 150))) FROM $logs WHERE status='ok' AND viewed_at >= %s", m3ut_ago(M3UT_LIVE_MINUTES * 60)));
    $t0 = m3ut_today() . ' 00:00:00';
    $tv = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $logs WHERE status='ok' AND viewed_at >= %s", $t0));
    $tu = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip) FROM $logs WHERE status='ok' AND viewed_at >= %s", $t0));
    $top = $wpdb->get_row($wpdb->prepare("SELECT source, COUNT(*) c FROM $logs WHERE status='ok' AND viewed_at >= %s GROUP BY source ORDER BY c DESC LIMIT 1", $t0));
    $dead = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . m3ut_t('links') . " WHERE status='dead'");
    $lim = m3ut_plus_days(m3ut_today(), 7);
    $exp = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . m3ut_t('keys') . " WHERE status='active' AND expires_at IS NOT NULL AND expires_at <= %s", $lim))
         + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . m3ut_t('resellers') . " WHERE status='active' AND expires_at IS NOT NULL AND expires_at <= %s", $lim));
    echo '<p style="font-size:34px;font-weight:800;margin:0;color:#d63638">' . $live . ' <span style="font-size:13px;font-weight:400;color:#50575e">live (shesh ' . (int) M3UT_LIVE_MINUTES . ' min)</span></p>';
        echo '<ul style="margin:10px 0 0"><li>Ajker hit: <strong>' . number_format_i18n($tv) . '</strong> - unique IP: <strong>' . number_format_i18n($tu) . '</strong></li>';
    echo '<li>Ajker top source: <strong>' . esc_html($top ? $top->source . ' (' . $top->c . ')' : '-') . '</strong></li>';
        echo '<li>Dead channel: <strong>' . $dead . '</strong> - 7 dine expire hobe: <strong>' . $exp . '</strong></li></ul>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=m3ut-dashboard')) . '">Full dashboard</a></p>';
}
