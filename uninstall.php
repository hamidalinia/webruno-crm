<?php
// Fires only on actual "Delete" from the Plugins screen, not on deactivate.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('webruno_crm_api_token');
delete_option('webruno_crm_site_id');
delete_option('webruno_crm_connected_at');
delete_option('webruno_crm_customer_id');
delete_option('webruno_crm_domain');
delete_option('webruno_crm_custom_domain');
delete_option('webruno_crm_sync_orders');
delete_option('webruno_crm_sync_posts');
delete_option('webruno_crm_sync_leads');
delete_option('webruno_crm_sync_users');
delete_option('webruno_crm_region');
delete_option('webruno_crm_region_reasons');

// Content API (key, switches, user, tables, scheduled cleanup)
delete_option('webruno_crm_signing_secret');
delete_option('webruno_crm_content_user_id');
delete_option('webruno_crm_content_read');
delete_option('webruno_crm_content_write');
delete_option('webruno_crm_content_db_ver');
delete_option('webruno_crm_content_last_reject');
wp_clear_scheduled_hook('webruno_crm_prune_content_tables');

// Data kept by the CRM dashboard and by AI edits (snapshots), temporary values and per-user notice flags.
delete_option('webruno_crm_customer_meta');
delete_option('webruno_crm_menu_snapshots');
delete_option('webruno_crm_product_snapshots');
delete_transient('webruno_crm_challenge');
delete_transient('webruno_crm_leads_30d');
delete_transient('webruno_crm_new_users_30d');
delete_metadata('user', 0, 'webruno_crm_dismissed_upgrade_notice', '', true);
global $wpdb;
$webruno_tables = [$wpdb->prefix . 'webruno_nonces', $wpdb->prefix . 'webruno_audit'];
foreach ($webruno_tables as $webruno_table) {
    $wpdb->query("DROP TABLE IF EXISTS `{$webruno_table}`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
