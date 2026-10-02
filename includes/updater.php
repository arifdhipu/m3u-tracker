<?php
if (!defined('ABSPATH')) exit;

define('M3UT_GH_REPO', 'aridhipu/m3u-tracker');

function m3ut_update_info() {
    if (!isset($_GET['force-check'])) {
        $c = get_site_transient('m3ut_gh_info');
        if ($c !== false) return $c;
    }
    $info = [];
    $r = wp_remote_get('https://api.github.com/repos/' . M3UT_GH_REPO . '/releases/latest', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'm3u-tracker-updater'],
    ]);
    if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (!empty($j['tag_name'])) {
            $pkg = '';
            if (!empty($j['assets'])) {
                foreach ($j['assets'] as $a) {
                    if (substr($a['name'], -4) === '.zip') { $pkg = $a['browser_download_url']; break; }
                }
            }
            if (!$pkg && !empty($j['zipball_url'])) $pkg = $j['zipball_url'];
            if ($pkg) $info = ['version' => ltrim($j['tag_name'], 'vV'), 'package' => $pkg];
        }
    }
    set_site_transient('m3ut_gh_info', $info, $info ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS);
    return $info;
}

add_filter('pre_set_site_transient_update_plugins', function ($t) {
    if (!is_object($t)) return $t;
    $file = plugin_basename(M3UT_DIR . 'm3u-tracker.php');
    $info = m3ut_update_info();
    $has  = !empty($info['version']) && !empty($info['package']);
    $item = (object) [
        'id'          => $file,
        'slug'        => 'm3u-tracker',
        'plugin'      => $file,
        'new_version' => $has ? $info['version'] : M3UT_VERSION,
        'url'         => 'https://github.com/' . M3UT_GH_REPO,
        'package'     => $has ? $info['package'] : '',
    ];
    if ($has && version_compare($info['version'], M3UT_VERSION, '>')) {
        $t->response[$file] = $item;
    } else {
        $t->no_update[$file] = $item;
    }
    return $t;
});

/* GitHub zip-er folder-er naam alada hoy, update-er shomoy "m3u-tracker" kore dey */
add_filter('upgrader_source_selection', function ($source, $remote, $upgrader, $extra) {
    if (empty($extra['plugin']) || $extra['plugin'] !== plugin_basename(M3UT_DIR . 'm3u-tracker.php')) return $source;
    global $wp_filesystem;
    $want = trailingslashit(dirname($source)) . 'm3u-tracker/';
    if (trailingslashit($source) === $want) return $source;
    if ($wp_filesystem->move($source, $want, true)) return $want;
    return new WP_Error('m3ut_rename', 'Plugin folder rename kora jayni');
}, 10, 4);
