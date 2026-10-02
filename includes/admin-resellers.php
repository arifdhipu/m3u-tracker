<?php
if (!defined('ABSPATH')) exit;

/* =========================== RESELLERS =========================== */

add_action('admin_post_m3ut_reseller_save', function () {
    m3ut_cap();
    check_admin_referer('m3ut_reseller_save');
    global $wpdb;
    $t = m3ut_t('resellers');
    $p = wp_unslash($_POST);
    $id = isset($p['id']) ? (int) $p['id'] : 0;
    $name = sanitize_text_field(isset($p['name']) ? $p['name'] : '');
    $slug = sanitize_key(isset($p['slug']) ? $p['slug'] : '');
    if ($slug === '') $slug = sanitize_key(str_replace([' ', '.'], '_', strtolower($name)));
    if ($name === '') m3ut_back('m3ut-resellers', 'Naam din', 'error');
    if ($slug === '' || in_array($slug, ['direct', 'unknown', 'invalid_key'], true)) m3ut_back('m3ut-resellers', 'Slug (english letter/number/_ /-) din. direct/unknown reserved.', 'error');
    $exp = (isset($p['expires_at']) && m3ut_valid_date($p['expires_at'])) ? $p['expires_at'] : null;
    $data = [
        'name' => $name, 'phone' => sanitize_text_field(isset($p['phone']) ? $p['phone'] : ''),
        'note' => sanitize_textarea_field(isset($p['note']) ? $p['note'] : ''),
        'expires_at' => $exp, 'ip_limit' => max(0, (int) (isset($p['ip_limit']) ? $p['ip_limit'] : 0)),
    ];
    if ($id) {
        $wpdb->update($t, $data, ['id' => $id]);
        m3ut_back('m3ut-resellers', 'Update hoyeche');
    }
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE slug=%s", $slug))) m3ut_back('m3ut-resellers', "'$slug' slug ager theke ache", 'error');
    $data['slug'] = $slug;
    $data['status'] = 'active';
    $data['created_at'] = m3ut_now();
    $wpdb->insert($t, $data);
    m3ut_back('m3ut-resellers', "Reseller add hoyeche: $slug");
});

add_action('admin_post_m3ut_reseller_toggle', function () {
    m3ut_cap(); check_admin_referer('m3ut_reseller_toggle');
    global $wpdb; $t = m3ut_t('resellers'); $id = (int) $_GET['id'];
    $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM $t WHERE id=%d", $id));
    $wpdb->update($t, ['status' => $st === 'active' ? 'disabled' : 'active'], ['id' => $id]);
    m3ut_back('m3ut-resellers', $st === 'active' ? 'Link off kora hoyeche' : 'Link on kora hoyeche');
});

add_action('admin_post_m3ut_reseller_renew', function () {
    m3ut_cap(); check_admin_referer('m3ut_reseller_renew');
    global $wpdb; $t = m3ut_t('resellers'); $id = (int) $_GET['id']; $d = max(1, (int) $_GET['days']);
    $exp = $wpdb->get_var($wpdb->prepare("SELECT expires_at FROM $t WHERE id=%d", $id));
    $from = ($exp && $exp > m3ut_today()) ? $exp : m3ut_today();
    $wpdb->update($t, ['expires_at' => m3ut_plus_days($from, $d), 'status' => 'active'], ['id' => $id]);
    m3ut_back('m3ut-resellers', "+$d din renew hoyeche");
});

add_action('admin_post_m3ut_reseller_delete', function () {
    m3ut_cap(); check_admin_referer('m3ut_reseller_delete');
    global $wpdb;
    $wpdb->delete(m3ut_t('resellers'), ['id' => (int) $_GET['id']]);
    m3ut_back('m3ut-resellers', 'Delete hoyeche (log data thakbe)');
});

function m3ut_page_resellers() {
    m3ut_cap();
    global $wpdb;
    $t = m3ut_t('resellers'); $logs = m3ut_t('logs');
    $edit = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
    $er = $edit ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $edit)) : null;
    $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC");

    $v30 = []; $last = [];
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT source, COUNT(*) c FROM $logs WHERE status='ok' AND viewed_at >= %s GROUP BY source", m3ut_ago(30 * 86400))) as $r) $v30[$r->source] = (int) $r->c;
    foreach ((array) $wpdb->get_results("SELECT source, MAX(viewed_at) m FROM $logs WHERE status='ok' GROUP BY source") as $r) $last[$r->source] = $r->m;
    $u24 = m3ut_ip_counts('source', 86400);
    ?>
    <div class="wrap">
        <h1>👥 Reseller Manager</h1>
        <?php m3ut_notice(); ?>

        <div class="m3ut-card m3ut-form" style="max-width:720px;margin:14px 0">
            <h2><?php echo $er ? 'Reseller edit' : 'Notun reseller add'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('m3ut_reseller_save'); ?>
                <input type="hidden" name="action" value="m3ut_reseller_save">
                <input type="hidden" name="id" value="<?php echo $er ? (int) $er->id : 0; ?>">
                <label>Naam</label><input type="text" name="name" class="regular-text" required value="<?php echo esc_attr($er ? $er->name : ''); ?>">
                <label>Slug (link-er ?src= er naam; faka rakhle naam theke auto hobe)</label>
                <input type="text" name="slug" class="regular-text" pattern="[a-z0-9_\-]*" value="<?php echo esc_attr($er ? $er->slug : ''); ?>" <?php echo $er ? 'readonly' : ''; ?>>
                <label>Phone</label><input type="text" name="phone" value="<?php echo esc_attr($er ? $er->phone : ''); ?>">
                <label>Expiry date (faka = kokhono expire hobe na)</label><input type="date" name="expires_at" value="<?php echo esc_attr($er ? $er->expires_at : ''); ?>">
                <label>Sharing alert limit (24 ghontay unique IP; 0 = check off)</label><input type="number" min="0" name="ip_limit" value="<?php echo (int) ($er ? $er->ip_limit : 0); ?>">
                <label>Note</label><textarea name="note" rows="2" class="large-text"><?php echo esc_textarea($er ? $er->note : ''); ?></textarea>
                <p><button class="button button-primary"><?php echo $er ? 'Update' : 'Add reseller'; ?></button>
                <?php if ($er): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=m3ut-resellers')); ?>">Cancel</a><?php endif; ?></p>
            </form>
        </div>

        <div class="m3ut-scroll"><table class="widefat striped">
            <thead><tr><th>Reseller</th><th>Link</th><th>Status</th><th>Expiry</th><th>Hit (30 din)</th><th>Unique IP (24h)</th><th>Last hit</th><th>Action</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="8">Kono reseller nei. Upore add korun.</td></tr><?php endif; ?>
            <?php foreach ((array) $rows as $r):
                $link = m3ut_link(['src' => $r->slug]);
                $n = isset($u24[$r->slug]) ? $u24[$r->slug] : 0;
                $warn = $r->ip_limit > 0 && $n > $r->ip_limit; ?>
                <tr>
                    <td><strong><?php echo esc_html($r->name); ?></strong><br><code><?php echo esc_html($r->slug); ?></code><?php if ($r->phone) echo '<br>' . esc_html($r->phone); ?></td>
                    <td><input type="text" readonly class="m3ut-link" value="<?php echo esc_attr($link); ?>" onclick="this.select()"> <?php echo m3ut_copy_btn($link); ?></td>
                    <td><?php echo m3ut_status_badge($r->status, $r->expires_at); ?></td>
                    <td><?php echo $r->expires_at ? esc_html($r->expires_at) : '—'; ?></td>
                    <td><?php echo number_format_i18n(isset($v30[$r->slug]) ? $v30[$r->slug] : 0); ?></td>
                    <td><?php echo $n; ?><?php if ($r->ip_limit) echo ' / ' . (int) $r->ip_limit; ?> <?php if ($warn) echo m3ut_badge('⚠ Sharing?', '#d63638'); ?></td>
                    <td><?php echo isset($last[$r->slug]) ? esc_html($last[$r->slug]) : '—'; ?></td>
                    <td style="white-space:nowrap">
                        <a class="button button-small" href="<?php echo esc_url(m3ut_act('m3ut_reseller_toggle', ['id' => $r->id])); ?>"><?php echo $r->status === 'active' ? '⛔ Off' : '✅ On'; ?></a>
                        <a class="button button-small" href="<?php echo esc_url(m3ut_act('m3ut_reseller_renew', ['id' => $r->id, 'days' => 30])); ?>">+30d</a>
                        <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=m3ut-resellers&edit=' . (int) $r->id)); ?>">Edit</a>
                        <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=m3ut-dashboard&source=' . rawurlencode($r->slug))); ?>">Stats</a>
                        <a class="button button-small" style="color:#b32d2e" onclick="return confirm('Delete korben?')" href="<?php echo esc_url(m3ut_act('m3ut_reseller_delete', ['id' => $r->id])); ?>">✕</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <p class="description">Off/expired reseller-er link-e khulle player-e "<?php echo esc_html(m3ut_settings()['blocked_msg']); ?>" message dekhabe. Player playlist cache rakhle reload na howa porjonto puran channel chalte pare. Fully atkate Settings-e "Channel stats mode" on korun, tokhon prottek channel click-e access check hoy.</p>
    </div>
    <?php
}

/* =========================== CUSTOMER KEYS =========================== */

add_action('admin_post_m3ut_key_save', function () {
    m3ut_cap();
    check_admin_referer('m3ut_key_save');
    global $wpdb;
    $t = m3ut_t('keys');
    $p = wp_unslash($_POST);
    $label = sanitize_text_field(isset($p['label']) ? $p['label'] : '');
    $res = sanitize_key(isset($p['reseller']) ? $p['reseller'] : '');
    if ($res === 'direct') $res = '';
    $days = max(0, (int) (isset($p['days']) ? $p['days'] : 0));
    $cnt = max(1, min(50, (int) (isset($p['count']) ? $p['count'] : 1)));
    $lim = max(0, (int) (isset($p['ip_limit']) ? $p['ip_limit'] : 0));
    $exp = $days ? m3ut_plus_days(m3ut_today(), $days) : null;
    for ($i = 1; $i <= $cnt; $i++) {
        do {
            $code = sanitize_key(strtolower(wp_generate_password(12, false, false)));
        } while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE key_code=%s", $code)));
        $wpdb->insert($t, [
            'key_code' => $code, 'label' => $cnt > 1 ? trim($label . ' #' . $i) : $label, 'reseller' => $res,
            'status' => 'active', 'expires_at' => $exp, 'ip_limit' => $lim, 'created_at' => m3ut_now(),
        ]);
    }
    m3ut_back('m3ut-keys', "$cnt ta key toiri hoyeche");
});

add_action('admin_post_m3ut_key_toggle', function () {
    m3ut_cap(); check_admin_referer('m3ut_key_toggle');
    global $wpdb; $t = m3ut_t('keys'); $id = (int) $_GET['id'];
    $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM $t WHERE id=%d", $id));
    $wpdb->update($t, ['status' => $st === 'active' ? 'disabled' : 'active'], ['id' => $id]);
    m3ut_back('m3ut-keys', $st === 'active' ? 'Key off' : 'Key on');
});

add_action('admin_post_m3ut_key_renew', function () {
    m3ut_cap(); check_admin_referer('m3ut_key_renew');
    global $wpdb; $t = m3ut_t('keys'); $id = (int) $_GET['id']; $d = max(1, (int) $_GET['days']);
    $exp = $wpdb->get_var($wpdb->prepare("SELECT expires_at FROM $t WHERE id=%d", $id));
    $from = ($exp && $exp > m3ut_today()) ? $exp : m3ut_today();
    $wpdb->update($t, ['expires_at' => m3ut_plus_days($from, $d), 'status' => 'active'], ['id' => $id]);
    m3ut_back('m3ut-keys', "+$d din renew hoyeche");
});

add_action('admin_post_m3ut_key_delete', function () {
    m3ut_cap(); check_admin_referer('m3ut_key_delete');
    global $wpdb;
    $wpdb->delete(m3ut_t('keys'), ['id' => (int) $_GET['id']]);
    m3ut_back('m3ut-keys', 'Key delete hoyeche');
});

function m3ut_page_keys() {
    m3ut_cap();
    global $wpdb;
    $t = m3ut_t('keys'); $logs = m3ut_t('logs'); $s = m3ut_settings();
    $fr = isset($_GET['reseller']) ? sanitize_key(wp_unslash($_GET['reseller'])) : '';
    $resellers = $wpdb->get_results("SELECT slug, name FROM " . m3ut_t('resellers') . " ORDER BY name");
    $rows = $fr !== ''
        ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE reseller=%s ORDER BY id DESC", $fr === 'direct' ? '' : $fr))
        : $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC");
    $u24 = m3ut_ip_counts('key_code', 86400);
    $last = [];
    foreach ((array) $wpdb->get_results("SELECT key_code, MAX(viewed_at) m FROM $logs WHERE status='ok' AND key_code<>'' GROUP BY key_code") as $r) $last[$r->key_code] = $r->m;
    ?>
    <div class="wrap">
        <h1>🔑 Customer Keys</h1>
        <?php m3ut_notice(); ?>
        <p class="description">Prottek customer-er jonno alada link (<code>?key=...</code>). Expiry, on/off, ar IP-sharing check protita key-te alada.</p>

        <div class="m3ut-card m3ut-form" style="max-width:720px;margin:14px 0">
            <h2>Notun key</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('m3ut_key_save'); ?>
                <input type="hidden" name="action" value="m3ut_key_save">
                <label>Customer naam / label</label><input type="text" name="label" class="regular-text">
                <label>Reseller</label>
                <select name="reseller"><option value="">direct (kono reseller na)</option>
                    <?php foreach ((array) $resellers as $r): ?><option value="<?php echo esc_attr($r->slug); ?>"><?php echo esc_html($r->name . ' (' . $r->slug . ')'); ?></option><?php endforeach; ?>
                </select>
                <label>Validity (din; 0 = expire hobe na)</label><input type="number" min="0" name="days" value="30">
                <label>IP limit (0 = Settings-er default: <?php echo (int) $s['default_ip_limit']; ?>)</label><input type="number" min="0" name="ip_limit" value="0">
                <label>Koyta key (1–50)</label><input type="number" min="1" max="50" name="count" value="1">
                <p><button class="button button-primary">Key toiri korun</button></p>
            </form>
        </div>

        <form method="get" style="margin-bottom:10px"><input type="hidden" name="page" value="m3ut-keys">
            Filter: <select name="reseller"><option value="">All</option><option value="direct" <?php selected($fr, 'direct'); ?>>direct</option>
                <?php foreach ((array) $resellers as $r): ?><option value="<?php echo esc_attr($r->slug); ?>" <?php selected($fr, $r->slug); ?>><?php echo esc_html($r->name); ?></option><?php endforeach; ?>
            </select> <button class="button">Filter</button></form>

        <div class="m3ut-scroll"><table class="widefat striped">
            <thead><tr><th>Customer</th><th>Link</th><th>Reseller</th><th>Status</th><th>Expiry</th><th>IP (24h)</th><th>Last seen</th><th>Action</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="8">Kono key nei.</td></tr><?php endif; ?>
            <?php foreach ((array) $rows as $k):
                $link = m3ut_link(['key' => $k->key_code]);
                $lim = $k->ip_limit ? (int) $k->ip_limit : (int) $s['default_ip_limit'];
                $n = isset($u24[$k->key_code]) ? $u24[$k->key_code] : 0;
                $warn = $lim > 0 && $n > $lim; ?>
                <tr>
                    <td><strong><?php echo esc_html($k->label ? $k->label : '—'); ?></strong><br><code><?php echo esc_html($k->key_code); ?></code></td>
                    <td><input type="text" readonly class="m3ut-link" value="<?php echo esc_attr($link); ?>" onclick="this.select()"> <?php echo m3ut_copy_btn($link); ?></td>
                    <td><?php echo esc_html($k->reseller ? $k->reseller : 'direct'); ?></td>
                    <td><?php echo m3ut_status_badge($k->status, $k->expires_at); ?></td>
                    <td><?php echo $k->expires_at ? esc_html($k->expires_at) : '—'; ?></td>
                    <td><?php echo $n . ' / ' . ($lim ? $lim : '∞'); ?> <?php if ($warn) echo m3ut_badge('⚠ Sharing?', '#d63638'); ?></td>
                    <td><?php echo isset($last[$k->key_code]) ? esc_html($last[$k->key_code]) : '—'; ?></td>
                    <td style="white-space:nowrap">
                        <a class="button button-small" href="<?php echo esc_url(m3ut_act('m3ut_key_toggle', ['id' => $k->id])); ?>"><?php echo $k->status === 'active' ? '⛔ Off' : '✅ On'; ?></a>
                        <a class="button button-small" href="<?php echo esc_url(m3ut_act('m3ut_key_renew', ['id' => $k->id, 'days' => 30])); ?>">+30d</a>
                        <a class="button button-small" href="<?php echo esc_url(m3ut_act('m3ut_key_renew', ['id' => $k->id, 'days' => 90])); ?>">+90d</a>
                        <a class="button button-small" style="color:#b32d2e" onclick="return confirm('Delete korben?')" href="<?php echo esc_url(m3ut_act('m3ut_key_delete', ['id' => $k->id])); ?>">✕</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </div>
    <?php
}
