<?php
/**
 * Plugin Name: M3U Tracker
 * Description: m3u/iptv playlist tracker: reseller manager, customer keys, kill switch, live counter, channel stats, dead link checker, Telegram/email alerts, channels.txt editor.
 * Version: 3.5.0
 * Author: Goodly BD
 */

if (!defined('ABSPATH')) exit;
if (defined('M3UT_VERSION')) return;

define('M3UT_VERSION', '3.5.0');
define('M3UT_DIR', plugin_dir_path(__FILE__));
define('M3UT_LIVE_MINUTES', 6); // shesh koto minute-er hit "live" dhora hobe

require_once M3UT_DIR . 'includes/core.php';
require_once M3UT_DIR . 'includes/install.php';
require_once M3UT_DIR . 'includes/serve.php';
require_once M3UT_DIR . 'includes/alerts.php';
require_once M3UT_DIR . 'includes/checker.php';
require_once M3UT_DIR . 'includes/updater.php';

if (is_admin()) {
    require_once M3UT_DIR . 'includes/admin-common.php';
    require_once M3UT_DIR . 'includes/admin-dashboard.php';
    require_once M3UT_DIR . 'includes/admin-resellers.php';
    require_once M3UT_DIR . 'includes/admin-channels.php';
    require_once M3UT_DIR . 'includes/admin-settings.php';
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=m3ut-settings');
    array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
    return $links;
});

register_activation_hook(__FILE__, 'm3ut_install');
register_deactivation_hook(__FILE__, function () {
    foreach (['m3ut_cron_scan', 'm3ut_cron_scan_step', 'm3ut_cron_hourly', 'm3ut_cron_daily', 'm3ut_cron_weekly', 'm3ut_cron_autoscan'] as $h) {
        wp_clear_scheduled_hook($h);
    }
});
