<?php
// TEST ONLY, never shipped (tests/ is excluded from the release zip). Test WordPress (SQLite, 127.0.0.1:8081).
// Run: /home/wordpress/bin/wp eval-file tests/test-uninstall.php
// Creates fake plugin state, runs uninstall.php exactly as WordPress does on Delete, prints LEFTOVER lines (none expected), restores.
if (!defined('DB_NAME') || strpos(DB_NAME, 'wptest_') !== 0) { fwrite(STDERR, "refusing: not wptest_\n"); exit(1); }
if (strpos(home_url(), '127.0.0.1:8081') === false) { fwrite(STDERR, "refusing: not the local test site\n"); exit(1); }
global $wpdb;

$like = $wpdb->esc_like('webruno') . '%';
$tlike1 = $wpdb->esc_like('_transient_webruno') . '%';
$tlike2 = $wpdb->esc_like('_transient_timeout_webruno') . '%';
$snapshot = function () use ($wpdb, $like, $tlike1, $tlike2) {
    $opts = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, $tlike1, $tlike2), ARRAY_A);
    $um = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like), ARRAY_A);
    $tables = [];
    foreach (['webruno_nonces', 'webruno_audit'] as $t) {
        $exists = $wpdb->get_var($wpdb->prepare('SELECT name FROM sqlite_master WHERE type="table" AND name=%s', $wpdb->prefix . $t));
        if ($exists) $tables[] = $wpdb->prefix . $t;
    }
    return ['options' => $opts, 'usermeta' => $um, 'tables' => $tables, 'cron' => (bool) wp_next_scheduled('webruno_crm_prune_content_tables')];
};

// make sure a realistic connected state exists (fake values) so there is something to remove
update_option('webruno_crm_api_token', 'ENC-FAKE-TEST-VALUE');
update_option('webruno_crm_signing_secret', 'FAKE-SECRET-FOR-TEST');
set_transient('webruno_crm_challenge', ['code' => 'x', 'challenge' => 'y'], 600);
set_transient('webruno_crm_leads_30d', 3, 300); set_transient('webruno_crm_new_users_30d', 2, 300);
update_option('webruno_crm_customer_meta', ['1' => ['vip' => true, 'note' => 'fake']], false);
update_option('webruno_crm_menu_snapshots', ['fake' => 1], false); update_option('webruno_crm_product_snapshots', ['fake' => 1], false);
update_user_meta(1, 'webruno_crm_dismissed_upgrade_notice', 1);
if (!wp_next_scheduled('webruno_crm_prune_content_tables')) wp_schedule_event(time() + 3600, 'daily', 'webruno_crm_prune_content_tables');
if (class_exists('Webruno_Content_API')) Webruno_Content_API::maybe_install();

$before = $snapshot();
echo "BEFORE: options=" . count($before['options']) . " usermeta=" . count($before['usermeta']) . " tables=" . count($before['tables']) . " cron=" . ($before['cron'] ? 'yes' : 'no') . "\n";

// run the shipped uninstall.php exactly as WordPress does on Delete
define('WP_UNINSTALL_PLUGIN', 'webruno-crm/webruno-crm.php');
include getenv('UNINSTALL_FILE') ?: dirname(__DIR__) . '/uninstall.php';
wp_cache_flush();

$after = $snapshot();
echo "AFTER:  options=" . count($after['options']) . " usermeta=" . count($after['usermeta']) . " tables=" . count($after['tables']) . " cron=" . ($after['cron'] ? 'yes' : 'no') . "\n";
foreach ($after['options'] as $o) echo "LEFTOVER option: " . $o['option_name'] . "\n";
foreach ($after['usermeta'] as $m) echo "LEFTOVER usermeta: " . $m['meta_key'] . " (user " . $m['user_id'] . ")\n";
foreach ($after['tables'] as $t) echo "LEFTOVER table: $t\n";

// restore the test site to its previous state
foreach ($before['options'] as $o) {
    if (strpos($o['option_name'], '_transient_') === 0) continue;
    add_option($o['option_name'], maybe_unserialize($o['option_value']), '', $o['autoload']);
}
foreach ($before['usermeta'] as $m) add_user_meta($m['user_id'], $m['meta_key'], maybe_unserialize($m['meta_value']));
delete_option('webruno_crm_content_db_ver');
if (class_exists('Webruno_Content_API')) Webruno_Content_API::maybe_install();
echo "restored; tables now: " . implode(',', $snapshot()['tables']) . "\n";
exit(count($after['options']) + count($after['usermeta']) + count($after['tables']) + ($after['cron'] ? 1 : 0) > 0 ? 1 : 0);
