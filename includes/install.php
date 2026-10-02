<?php
if (!defined('ABSPATH')) exit;

function m3ut_col($table, $col, $def) {
    global $wpdb;
    $ex = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $col));
    if (empty($ex)) $wpdb->query("ALTER TABLE $table ADD COLUMN $col $def");
}
function m3ut_idx($table, $name, $cols) {
    global $wpdb;
    $ex = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM $table WHERE Key_name = %s", $name));
    if (empty($ex)) $wpdb->query("ALTER TABLE $table ADD INDEX $name ($cols)");
}

function m3ut_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $cc = $wpdb->get_charset_collate();

    // Purono logs table thakle data ager moto thakbe, shudhu notun column jog hobe
    $logs = m3ut_t('logs');
    $wpdb->query("CREATE TABLE IF NOT EXISTS $logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        country VARCHAR(100) DEFAULT 'Unknown',
        city VARCHAR(100) DEFAULT 'Unknown',
        isp VARCHAR(150) DEFAULT 'Unknown',
        device VARCHAR(50) DEFAULT 'Unknown',
        user_agent TEXT,
        source VARCHAR(100) DEFAULT 'direct',
        viewed_at DATETIME NOT NULL,
        KEY ip_idx (ip),
        KEY country_idx (country),
        KEY source_idx (source),
        KEY viewed_idx (viewed_at)
    ) $cc");
    m3ut_col($logs, 'source', "VARCHAR(100) DEFAULT 'direct'");
    m3ut_col($logs, 'key_code', "VARCHAR(40) NOT NULL DEFAULT ''");
    m3ut_col($logs, 'status', "VARCHAR(12) NOT NULL DEFAULT 'ok'");
    m3ut_idx($logs, 'key_idx', 'key_code');

    dbDelta("CREATE TABLE " . m3ut_t('resellers') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(60) NOT NULL,
        name VARCHAR(120) NOT NULL DEFAULT '',
        phone VARCHAR(40) NOT NULL DEFAULT '',
        note TEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        expires_at DATE NULL,
        ip_limit INT NOT NULL DEFAULT 0,
        first_hit_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug)
    ) $cc;");

    dbDelta("CREATE TABLE " . m3ut_t('keys') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        key_code VARCHAR(40) NOT NULL,
        label VARCHAR(120) NOT NULL DEFAULT '',
        reseller VARCHAR(60) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        expires_at DATE NULL,
        ip_limit INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY key_code (key_code),
        KEY reseller (reseller)
    ) $cc;");

    dbDelta("CREATE TABLE " . m3ut_t('chits') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chash VARCHAR(16) NOT NULL,
        cname VARCHAR(190) NOT NULL DEFAULT '',
        cgroup VARCHAR(100) NOT NULL DEFAULT '',
        source VARCHAR(60) NOT NULL DEFAULT 'direct',
        ip VARCHAR(45) NOT NULL,
        viewed_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY chash (chash),
        KEY viewed_at (viewed_at)
    ) $cc;");
    
        m3ut_col(m3ut_t('chits'), 'ua_hash', "CHAR(32) NOT NULL DEFAULT ''");

    dbDelta("CREATE TABLE " . m3ut_t('links') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        url_hash CHAR(32) NOT NULL,
        chash VARCHAR(16) NOT NULL DEFAULT '',
        cname VARCHAR(190) NOT NULL DEFAULT '',
        cgroup VARCHAR(100) NOT NULL DEFAULT '',
        url TEXT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'unknown',
        http_code INT NOT NULL DEFAULT 0,
        fail_count INT NOT NULL DEFAULT 0,
        error VARCHAR(190) NOT NULL DEFAULT '',
                last_checked DATETIME NULL,
        last_ok DATETIME NULL,
        seq INT NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY url_hash (url_hash)
    ) $cc;");
    m3ut_col(m3ut_t('links'), 'seq', 'INT NOT NULL DEFAULT 0'); // purono install-e column add hobe
    
        // uptime %-er jonno: prottek check-er result (ok=1 / dead=0)
    dbDelta("CREATE TABLE " . m3ut_t('checks') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        url_hash CHAR(32) NOT NULL,
        ok TINYINT(1) NOT NULL DEFAULT 0,
        checked_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY uh_time (url_hash, checked_at)
    ) $cc;");

    wp_clear_scheduled_hook('m3u_cleanup_old_logs'); // purono plugin-er cron
    update_option('m3ut_db_version', M3UT_VERSION);
    m3ut_schedule();
}

// Plugin file replace korle activation hook cholbe na, tai version mile na gele auto upgrade
add_action('plugins_loaded', function () {
    if (get_option('m3ut_db_version') !== M3UT_VERSION) m3ut_install();
});

// uptime-er checks table na thakle nijei toiri kore nei (version bump na korleo kaj korbe)
add_action('plugins_loaded', function () {
    if (get_option('m3ut_checks_ok')) return;
    global $wpdb;
    $ct = m3ut_t('checks');
    if ($wpdb->get_var("SHOW TABLES LIKE '$ct'") !== $ct) m3ut_install();
    if ($wpdb->get_var("SHOW TABLES LIKE '$ct'") === $ct) update_option('m3ut_checks_ok', 1, true);
}, 20);

/* ---------- Cron ---------- */
add_filter('cron_schedules', function ($s) {
    $s['m3ut_5min']   = ['interval' => 300,    'display' => 'Every 5 minutes'];
    $s['m3ut_weekly'] = ['interval' => 604800, 'display' => 'Weekly'];
    $s['m3ut_2h']     = ['interval' => 2 * HOUR_IN_SECONDS, 'display' => 'Every 2 hours'];
    $s['m3ut_3h']     = ['interval' => 3 * HOUR_IN_SECONDS, 'display' => 'Every 3 hours'];
    $s['m3ut_4h']     = ['interval' => 4 * HOUR_IN_SECONDS, 'display' => 'Every 4 hours'];
    $s['m3ut_6h']     = ['interval' => 6 * HOUR_IN_SECONDS, 'display' => 'Every 6 hours'];
    $s['m3ut_12h']    = ['interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Every 12 hours'];
    $s['m3ut_24h']    = ['interval' => 24 * HOUR_IN_SECONDS, 'display' => 'Every 24 hours'];
    return $s;
});
add_action('init', 'm3ut_schedule');
function m3ut_schedule() {
    // tv.php (playlist/go-link) request-e ei cron-scheduling logic-er kono dorkar nei — eta shudhu
    // background job-er jonno, ar age proti ekta stream/playlist hit-eo options-table check hocchilo
    // (wp_next_scheduled + wp_get_scheduled_event, prottekta ekta DB read). tv.php nijei
    // M3UT_STREAM_REQUEST constant define kore dey, tar mane normal WP page-visit-e eta thik-i cholbe,
    // shudhu high-frequency streaming hit-gulo-e ei extra kaj bad jabe.
    if (defined('M3UT_STREAM_REQUEST')) return;
    $ev = ['m3ut_cron_scan' => 'm3ut_5min', 'm3ut_cron_hourly' => 'hourly', 'm3ut_cron_daily' => 'daily', 'm3ut_cron_weekly' => 'm3ut_weekly'];
    foreach ($ev as $hook => $rec) {
        if (!wp_next_scheduled($hook)) wp_schedule_event(time() + 60, $rec, $hook);
    }
    // Auto dead-scan: Settings-e bola 2/3/4/6/12/24 ghonta por por cholbe. Setting change hole cron-o re-schedule hoy.
        $s = m3ut_settings();
    if ((int) $s['scan_at_hour'] > 0) {
        $want = 'm3ut_5min';
    } else {
        $hrs = (int) $s['scan_interval_hours'];
        if (!in_array($hrs, [2, 3, 4, 6, 12, 24], true)) $hrs = 3;
        $want = 'm3ut_' . $hrs . 'h';
    }
    $cur = wp_get_scheduled_event('m3ut_cron_autoscan');
    if (!$cur) {
        wp_schedule_event(time() + 300, $want, 'm3ut_cron_autoscan');
    } elseif ($cur->schedule !== $want) {
        wp_clear_scheduled_hook('m3ut_cron_autoscan');
        wp_schedule_event(time() + 300, $want, 'm3ut_cron_autoscan');
    }
    // hide_origin on thakle seg.php (standalone, WP-hin) er jonno secret-file always sync rakhi.
    // File-er content mile gele disk-e re-write hoy na (m3ut_sync_secret_file nijei check kore), tai
    // proti page-load-e extra I/O prai kono kichu-i na (shudhu ekta file_get_contents compare).
    if (!empty($s['hide_origin'])) m3ut_sync_secret_file();
}

add_action('m3ut_cron_autoscan', function () {
    $s = m3ut_settings();
    if (empty($s['auto_scan'])) return;
    $fixed = (int) $s['scan_at_hour'] > 0;
    if ($fixed) {
        if (!m3ut_autoscan_due()) return;
        if (get_transient('m3ut_autoscan_lock')) return;
        set_transient('m3ut_autoscan_lock', 1, 120);
    }
    if (!m3ut_scan_start()) return;
    if ($fixed) {
        $today = (new DateTime('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d');
        update_option('m3ut_autoscan_last', $today, true);
    }
    $st = m3ut_scan_drain(45);
    if (!empty($st['queue'])) m3ut_trigger_next_step();
});

add_action('m3ut_cron_daily', function () {
    global $wpdb;
    $s = m3ut_settings();
    $cut = gmdate('Y-m-d H:i:s', m3ut_ts() - max(1, (int) $s['retention_months']) * 30 * 86400);
        $wpdb->query($wpdb->prepare("DELETE FROM " . m3ut_t('logs') . " WHERE viewed_at < %s", $cut));
    $wpdb->query($wpdb->prepare("DELETE FROM " . m3ut_t('chits') . " WHERE viewed_at < %s", $cut));
    $wpdb->query($wpdb->prepare("DELETE FROM " . m3ut_t('checks') . " WHERE checked_at < %s", m3ut_ago(5 * 86400)));
});