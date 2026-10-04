<?php
if (!defined('ABSPATH')) exit;

define('M3UT_GH_REPO', 'arifdhipu/m3u-tracker');

function m3ut_update_info() {
    if (!isset($_GET['force-check']) && !isset($_GET['m3ut_debug2'])) {
        $c = get_site_transient('m3ut_gh_info');
        if ($c !== false) return $c;
    }
    $info = [];
    // api.github.com bad diye shorashori releases/latest redirect theke tag ber kora (rate limit nei)
    $r = wp_remote_head('https://github.com/' . M3UT_GH_REPO . '/releases/latest', [
        'timeout'     => 10,
        'redirection' => 0,
        'user-agent'  => 'm3u-tracker-updater',
    ]);
    if (!is_wp_error($r)) {
        $loc = (string) wp_remote_retrieve_header($r, 'location');
        if (preg_match('#/releases/tag/([^/?\s]+)#', $loc, $m)) {
            $tag  = rawurldecode($m[1]);
            $info = [
                'version' => ltrim($tag, 'vV'),
                'package' => 'https://github.com/' . M3UT_GH_REPO . '/archive/refs/tags/' . rawurlencode($tag) . '.zip',
            ];
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

/* Page kholar shomoy-i update info jure deya (check-timing-er upor nirbhor korte hobe na) */
add_filter('site_transient_update_plugins', function ($t) {
    if (!is_object($t)) return $t;
    $info = m3ut_update_info();
    if (empty($info['version']) || empty($info['package'])) return $t;
    if (!version_compare($info['version'], M3UT_VERSION, '>')) return $t;
    $file = plugin_basename(M3UT_DIR . 'm3u-tracker.php');
    if (!isset($t->response) || !is_array($t->response)) $t->response = [];
    $t->response[$file] = (object) [
        'id'          => $file,
        'slug'        => 'm3u-tracker',
        'plugin'      => $file,
        'new_version' => $info['version'],
        'url'         => 'https://github.com/' . M3UT_GH_REPO,
        'package'     => $info['package'],
    ];
    if (isset($t->no_update) && is_array($t->no_update)) unset($t->no_update[$file]);
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
